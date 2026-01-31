<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Cache;

/**
 * BotStrategicMetrics - lightweight KPI tracking for adaptive behavior.
 */
class BotStrategicMetrics
{
    public function getGrowthRate(array $state, int $botId): float
    {
        $key = "bot:{$botId}:metrics:points_snapshot";
        $now = now()->timestamp;
        $currentPoints = (int) ($state['total_points'] ?? 0);

        $snapshot = Cache::get($key);
        if (!$snapshot) {
            Cache::put($key, ['points' => $currentPoints, 'time' => $now], now()->addHours(24));
            return 0.0;
        }

        $elapsed = max(1, $now - (int) $snapshot['time']);
        $delta = $currentPoints - (int) $snapshot['points'];

        return ($delta / $elapsed) * 3600; // points per hour
    }

    public function getResourceEfficiency(array $state): float
    {
        $production = $state['total_production'] ?? ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        $points = (int) ($state['total_points'] ?? 0);
        $prodSum = max(1, (int) ($production['metal'] + $production['crystal'] + $production['deuterium']));

        return $points / $prodSum;
    }
}
