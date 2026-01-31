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
        $selfId = $bot->getPlayer()->getId();
        $avoidStronger = (bool) (($bot->getBot()->behavior_flags['avoid_stronger_players'] ?? false));
        $botScore = $this->getBotScore($bot);

        return match ($targetType) {
            BotTargetType::RANDOM => $this->findRandomTarget($botUserIds, $selfId, $avoidStronger ? $botScore : null),
            BotTargetType::WEAK => $this->findWeakTarget($bot, $botUserIds, $avoidStronger ? $botScore : null),
            BotTargetType::RICH => $this->findRichTarget($bot, $botUserIds, $avoidStronger ? $botScore : null),
            BotTargetType::SIMILAR => $this->findSimilarTarget($bot, $botUserIds, $avoidStronger ? $botScore : null),
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
                        $hq->where('general', '<=', $maxScore * 1.2);
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
                        $hq->where('general', '<=', $maxScore * 1.2);
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
                        $hq->where('general', '<=', $maxScore * 1.2);
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
                        $hq->where('general', '<=', $maxScore * 1.2);
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
