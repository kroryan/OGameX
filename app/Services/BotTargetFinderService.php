<?php

namespace OGame\Services;

use OGame\Enums\BotTargetType;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\BotBattleHistory;
use OGame\Models\Planet;
use OGame\Models\User;

/**
 * BotTargetFinderService - Finds target planets for bots to attack.
 */
class BotTargetFinderService
{
    private PlanetServiceFactory $planetFactory;

    public function __construct(PlanetServiceFactory $planetFactory)
    {
        $this->planetFactory = $planetFactory;
    }

    /**
     * Find a target planet based on the bot's target type preference.
     * Now uses persistent intelligence data for better targeting.
     */
    public function findTarget(BotService $bot, BotTargetType $targetType): ?PlanetService
    {
        $botUserIds = [];
        if (!config('bots.allow_target_bots', true)) {
            $botUserIds = \OGame\Models\Bot::pluck('user_id')->toArray();
        }
        $avoidUserIds = $bot->getAvoidTargetUserIds();
        if (!empty($avoidUserIds)) {
            $botUserIds = array_values(array_unique(array_merge($botUserIds, $avoidUserIds)));
        }

        // System 5: Avoid targets we lost to multiple times recently
        $historyAvoid = $this->getAvoidListFromBattleHistory($bot->getBot()->id);
        if (!empty($historyAvoid)) {
            $botUserIds = array_values(array_unique(array_merge($botUserIds, $historyAvoid)));
        }

        // Also avoid NAP and ally targets
        try {
            $intel = new BotIntelligenceService();
            $threatMap = $intel->getThreatMap($bot->getBot()->id);
            foreach ($threatMap as $entry) {
                if ($entry->is_nap || $entry->is_ally) {
                    $botUserIds[] = $entry->threat_user_id;
                }
            }
            $botUserIds = array_values(array_unique($botUserIds));
        } catch (\Exception $e) {
            // Non-critical
        }

        $selfId = $bot->getPlayer()->getId();
        $avoidStronger = (bool) (($bot->getBot()->behavior_flags['avoid_stronger_players'] ?? false));
        $botScore = $this->getBotScore($bot);
        $ratio = (float) config('bots.avoid_stronger_player_ratio', 1.2);
        $maxScore = $avoidStronger && $botScore > 0 ? (int) ($botScore * $ratio) : null;

        // System 7: 40% chance to prioritize hostile/enemy targets
        try {
            if (mt_rand(1, 100) <= 40) {
                $intel = new BotIntelligenceService();
                $threatMap = $intel->getThreatMap($bot->getBot()->id);
                $hostile = $threatMap->filter(function ($entry) use ($botUserIds) {
                    return $entry->threat_score > 20 && !in_array($entry->threat_user_id, $botUserIds, true);
                })->sortByDesc('threat_score');
                if ($hostile->isNotEmpty()) {
                    $targetUserId = $hostile->first()->threat_user_id;
                    $planet = Planet::where('user_id', $targetUserId)->first();
                    if ($planet) {
                        return $this->planetFactory->make($planet->id);
                    }
                }
            }
        } catch (\Exception $e) {
            // Non-critical
        }

        // First try: known profitable targets from intelligence
        try {
            $intel = new BotIntelligenceService();
            $profitableTargets = $intel->getProfitableTargets($bot->getBot()->id, 5, $botUserIds);
            if ($profitableTargets->isNotEmpty()) {
                $best = null;
                $bestScore = -1;
                foreach ($profitableTargets as $target) {
                    $bonus = $this->getTargetPriorityFromHistory($bot->getBot()->id, $target->target_user_id);
                    $score = (int) ($target->profitability_score ?? 0) * (1 + $bonus);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $target;
                    }
                }
                $planet = $best ? \OGame\Models\Planet::where('user_id', $best->target_user_id)->first() : null;
                if ($planet && $planet->user_id !== $selfId) {
                    $candidate = $this->planetFactory->make($planet->id);
                    if ($this->isTargetAcceptable($bot, $candidate)) {
                        return $candidate;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fall through to standard targeting
        }

        // Try nearby targets first (saves deuterium = better ROI)
        if (config('bots.prefer_nearby_targets', true)) {
            $nearby = $this->findNearbyTarget($bot, $botUserIds, $maxScore);
            if ($nearby !== null) {
                if ($this->isTargetAcceptable($bot, $nearby)) {
                    return $nearby;
                }
            }
        }

        $candidate = match ($targetType) {
            BotTargetType::RANDOM => $this->findRandomTarget($botUserIds, $selfId, $maxScore),
            BotTargetType::WEAK => $this->findWeakTarget($bot, $botUserIds, $maxScore),
            BotTargetType::RICH => $this->findRichTarget($bot, $botUserIds, $maxScore),
            BotTargetType::SIMILAR => $this->findSimilarTarget($bot, $botUserIds, $maxScore),
        };

        if ($candidate && $this->isTargetAcceptable($bot, $candidate)) {
            return $candidate;
        }

        return null;
    }

    /**
     * Find a random target planet.
     */
    private function findRandomTarget(array $excludeUserIds = [], int $selfUserId = 0, int|null $maxScore = null): ?PlanetService
    {
        $inactiveThreshold = now()->subMinutes(45)->timestamp;
        $query = Planet::query()
            ->whereHas('user', function ($q) use ($maxScore) {
                $q->where('vacation_mode', false);
                if ($maxScore !== null && $maxScore > 0) {
                    $q->whereHas('highscore', function ($hq) use ($maxScore) {
                        $hq->where('general', '<=', $maxScore);
                    });
                }
            })
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->select('planets.*')
            ->whereNotIn('user_id', $excludeUserIds)
            ->when($selfUserId > 0, function ($q) use ($selfUserId) {
                $q->where('user_id', '!=', $selfUserId);
            })
            ->orderByRaw('users.time < ? desc', [$inactiveThreshold])
            ->inRandomOrder()
            ->limit(10);

        $planets = $query->get();
        if ($planets->isEmpty()) {
            return null;
        }

        $planet = $planets->first();
        return $this->planetFactory->make($planet->id);
    }

    /**
     * Find a weak target (lower score).
     */
    private function findWeakTarget(BotService $bot, array $excludeUserIds = [], int|null $maxScore = null): ?PlanetService
    {
        $botScore = $this->getBotScore($bot);
        $inactiveThreshold = now()->subMinutes(45)->timestamp;

        $planets = Planet::query()
            ->whereHas('user', function ($q) use ($botScore, $maxScore) {
                $q->where('vacation_mode', false)
                  ->whereHas('highscore', function ($hq) use ($botScore) {
                      $hq->where('general', '<', $botScore * 0.8); // 20% weaker
                  });
                if ($maxScore !== null && $maxScore > 0) {
                    $q->whereHas('highscore', function ($hq) use ($maxScore) {
                        $hq->where('general', '<=', $maxScore);
                    });
                }
            })
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->select('planets.*')
            ->whereNotIn('user_id', $excludeUserIds)
            ->where('user_id', '!=', $bot->getPlayer()->getId())
            ->orderByRaw('users.time < ? desc', [$inactiveThreshold])
            ->inRandomOrder()
            ->limit(10)
            ->get();

        if ($planets->isEmpty()) {
            return $this->findRandomTarget($excludeUserIds);
        }

        $planet = $planets->first();
        return $this->planetFactory->make($planet->id);
    }

    /**
     * Find a rich target (more resources).
     */
    private function findRichTarget(BotService $bot, array $excludeUserIds = [], int|null $maxScore = null): ?PlanetService
    {
        // Find planets with high resource production
        $inactiveThreshold = now()->subMinutes(45)->timestamp;
        $planets = Planet::query()
            ->whereHas('user', function ($q) use ($maxScore) {
                $q->where('vacation_mode', false);
                if ($maxScore !== null && $maxScore > 0) {
                    $q->whereHas('highscore', function ($hq) use ($maxScore) {
                        $hq->where('general', '<=', $maxScore);
                    });
                }
            })
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->select('planets.*')
            ->whereNotIn('user_id', $excludeUserIds)
            ->where('user_id', '!=', $bot->getPlayer()->getId())
            ->orderByRaw('users.time < ? desc', [$inactiveThreshold])
            ->orderByRaw('(metal_production + crystal_production + deuterium_production) DESC')
            ->limit(20)
            ->get();

        if ($planets->isEmpty()) {
            return $this->findRandomTarget($excludeUserIds);
        }

        // Pick from top 5 randomly
        $planet = $planets->take(5)->random();
        return $this->planetFactory->make($planet->id);
    }

    /**
     * Find a target with similar strength.
     */
    private function findSimilarTarget(BotService $bot, array $excludeUserIds = [], int|null $maxScore = null): ?PlanetService
    {
        $botScore = $this->getBotScore($bot);
        $inactiveThreshold = now()->subMinutes(45)->timestamp;

        $planets = Planet::query()
            ->whereHas('user', function ($q) use ($botScore, $maxScore) {
                $q->where('vacation_mode', false)
                  ->whereHas('highscore', function ($hq) use ($botScore) {
                      // Within 20% of bot score
                      $hq->where('general', '>=', $botScore * 0.8)
                         ->where('general', '<=', $botScore * 1.2);
                  });
                if ($maxScore !== null && $maxScore > 0) {
                    $q->whereHas('highscore', function ($hq) use ($maxScore) {
                        $hq->where('general', '<=', $maxScore);
                    });
                }
            })
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->select('planets.*')
            ->whereNotIn('user_id', $excludeUserIds)
            ->where('user_id', '!=', $bot->getPlayer()->getId())
            ->orderByRaw('users.time < ? desc', [$inactiveThreshold])
            ->inRandomOrder()
            ->limit(10)
            ->get();

        if ($planets->isEmpty()) {
            return $this->findRandomTarget($excludeUserIds);
        }

        $planet = $planets->first();
        return $this->planetFactory->make($planet->id);
    }

    /**
     * Find a nearby target (same galaxy, close system) to minimize fuel costs.
     */
    private function findNearbyTarget(BotService $bot, array $excludeUserIds = [], int|null $maxScore = null): ?PlanetService
    {
        $source = $bot->getFleetPlanet() ?? $bot->getRichestPlanet();
        if ($source === null) {
            return null;
        }

        $coords = $source->getPlanetCoordinates();
        $range = (int) config('bots.nearby_target_system_range', 50);
        $minSystem = max(1, $coords->system - $range);
        $maxSystem = $coords->system + $range;
        $inactiveThreshold = now()->subMinutes(45)->timestamp;
        $selfId = $bot->getPlayer()->getId();

        $planets = Planet::where('galaxy', $coords->galaxy)
            ->whereBetween('system', [$minSystem, $maxSystem])
            ->where('destroyed', 0)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $selfId)
            ->whereNotIn('user_id', $excludeUserIds)
            ->whereHas('user', function ($q) use ($maxScore) {
                $q->where('vacation_mode', false);
                if ($maxScore !== null && $maxScore > 0) {
                    $q->whereHas('highscore', function ($hq) use ($maxScore) {
                        $hq->where('general', '<=', $maxScore);
                    });
                }
            })
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->select('planets.*')
            ->orderByRaw('users.time < ? desc', [$inactiveThreshold])
            ->orderByRaw('ABS(planets.system - ?) ASC', [$coords->system])
            ->limit(10)
            ->get();

        if ($planets->isEmpty()) {
            return null;
        }

        // Prefer inactive players (better ROI)
        $planet = $planets->first();
        return $this->planetFactory->make($planet->id);
    }

    /**
     * Get the bot's general score.
     */
    private function getBotScore(BotService $bot): int
    {
        $highscore = $bot->getPlayer()->getUser()->highscore;
        return $highscore ? $highscore->general : 0;
    }

    /**
     * Get a score for a planet (for ranking).
     */
    public function getPlanetScore(PlanetService $planet): int
    {
        $resources = $planet->getResources();
        $production = $planet->getProduction()->getHourlyProduction();

        // Score = resources + 24h production estimate
        return $resources->metal + $resources->crystal + $resources->deuterium +
               ($production->metal->production + $production->crystal->production + $production->deuterium->production) * 24;
    }

    /**
     * System 12: Filter targets by highscore relative to bot's score.
     * Returns the maximum score a target should have based on bot's own score.
     */
    public function getHighscoreFilteredMaxScore(BotService $bot): ?int
    {
        try {
            $context = $bot->getHighscoreContext();
            $botScore = $context['score'] ?? 0;

            if ($botScore <= 0) {
                return null;
            }

            // Allow attacking players up to 1.5x our score
            $modifiers = $bot->getHighscoreStrategyModifiers();
            $attackMod = $modifiers['attack_modifier'] ?? 1.0;

            // More aggressive bots target stronger players
            $ratio = 1.2 + ($attackMod - 1.0) * 0.5;
            return (int) ($botScore * $ratio);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * System 5: Avoid targets we lost to multiple times in the last 24 hours.
     *
     * @return int[]
     */
    public function getAvoidListFromBattleHistory(int $botId): array
    {
        try {
            return BotBattleHistory::where('bot_id', $botId)
                ->where('result', 'loss')
                ->where('created_at', '>=', now()->subHours(24))
                ->selectRaw('target_user_id, COUNT(*) as loss_count')
                ->groupBy('target_user_id')
                ->having('loss_count', '>=', 2)
                ->pluck('target_user_id')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get win rate against a specific target (wins / total).
     */
    public function getWinRateAgainst(int $botId, int $targetUserId): ?float
    {
        try {
            $wins = BotBattleHistory::where('bot_id', $botId)
                ->where('target_user_id', $targetUserId)
                ->where('result', 'win')
                ->count();
            $losses = BotBattleHistory::where('bot_id', $botId)
                ->where('target_user_id', $targetUserId)
                ->where('result', 'loss')
                ->count();
            $total = $wins + $losses;
            if ($total === 0) {
                return null;
            }
            return $wins / $total;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get priority bonus for targets with profitable history.
     * Returns a 0-1 bonus multiplier.
     */
    public function getTargetPriorityFromHistory(int $botId, int $targetUserId): float
    {
        try {
            $history = BotBattleHistory::where('bot_id', $botId)
                ->where('target_user_id', $targetUserId)
                ->where('created_at', '>=', now()->subDays(7))
                ->get(['loot_gained', 'fleet_lost_value']);

            if ($history->isEmpty()) {
                return 0.0;
            }

            $net = 0;
            foreach ($history as $entry) {
                $net += (int) ($entry->loot_gained ?? 0) - (int) ($entry->fleet_lost_value ?? 0);
            }

            if ($net <= 0) {
                return 0.0;
            }

            return min(1.0, $net / 500000);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function isTargetAcceptable(BotService $bot, PlanetService $planet): bool
    {
        $botId = $bot->getBot()->id;
        $targetUserId = $planet->getPlayer()->getId();
        $winRate = $this->getWinRateAgainst($botId, $targetUserId);
        if ($winRate !== null && $winRate < 0.30) {
            return false;
        }
        return true;
    }
}
