<?php

namespace OGame\Services;

use OGame\Enums\BotTargetType;
use OGame\Factories\PlanetServiceFactory;
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

        // First try: known profitable targets from intelligence
        try {
            $intel = new BotIntelligenceService();
            $profitableTargets = $intel->getProfitableTargets($bot->getBot()->id, 5, $botUserIds);
            if ($profitableTargets->isNotEmpty()) {
                $best = $profitableTargets->first();
                $planet = \OGame\Models\Planet::where('user_id', $best->target_user_id)->first();
                if ($planet && $planet->user_id !== $selfId) {
                    return $this->planetFactory->make($planet->id);
                }
            }
        } catch (\Exception $e) {
            // Fall through to standard targeting
        }

        // Try nearby targets first (saves deuterium = better ROI)
        if (config('bots.prefer_nearby_targets', true)) {
            $nearby = $this->findNearbyTarget($bot, $botUserIds, $maxScore);
            if ($nearby !== null) {
                return $nearby;
            }
        }

        return match ($targetType) {
            BotTargetType::RANDOM => $this->findRandomTarget($botUserIds, $selfId, $maxScore),
            BotTargetType::WEAK => $this->findWeakTarget($bot, $botUserIds, $maxScore),
            BotTargetType::RICH => $this->findRichTarget($bot, $botUserIds, $maxScore),
            BotTargetType::SIMILAR => $this->findSimilarTarget($bot, $botUserIds, $maxScore),
        };
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
}
