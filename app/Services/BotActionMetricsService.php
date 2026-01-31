<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Cache;
use OGame\Models\BotLog;

/**
 * BotActionMetricsService - aggregates real bot action metrics for tuning.
 */
class BotActionMetricsService
{
    public function getAttackMetrics(): array
    {
        $windowDays = (int) config('bots.bot_metrics_window_days', 7);
        $minSamples = (int) config('bots.bot_metrics_min_samples', 20);
        $cacheKey = "bot_metrics_attack_{$windowDays}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $from = now()->subDays($windowDays);
        $logs = BotLog::where('action_type', 'attack')
            ->where('created_at', '>=', $from)
            ->get();

        $total = $logs->count();
        if ($total < $minSamples) {
            $payload = ['sampled' => false, 'total' => $total];
            Cache::put($cacheKey, $payload, now()->addMinutes(30));
            return $payload;
        }

        $success = $logs->where('result', 'success')->count();
        $successRate = $total > 0 ? $success / $total : 0.0;

        $consumption = $logs->where('result', 'success')
            ->map(fn ($l) => $l->resources_spended['consumption'] ?? null)
            ->filter()
            ->values()
            ->sort()
            ->values();

        $loot = $logs->filter(fn ($l) => str_contains((string) $l->action_description, 'loot'))
            ->map(fn ($l) => $l->resources_spended['loot'] ?? null)
            ->filter()
            ->values()
            ->sort()
            ->values();

        $payload = [
            'sampled' => true,
            'total' => $total,
            'success_rate' => $successRate,
            'consumption_median' => $this->median($consumption->all()),
            'loot_median' => $this->median($loot->all()),
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(30));
        return $payload;
    }

    public function getTradeMetrics(): array
    {
        $windowDays = (int) config('bots.bot_metrics_window_days', 7);
        $minSamples = (int) config('bots.bot_metrics_min_samples', 20);
        $cacheKey = "bot_metrics_trade_{$windowDays}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $from = now()->subDays($windowDays);
        $logs = BotLog::where('action_type', 'trade')
            ->where('created_at', '>=', $from)
            ->get();

        $total = $logs->count();
        if ($total < $minSamples) {
            $payload = ['sampled' => false, 'total' => $total];
            Cache::put($cacheKey, $payload, now()->addMinutes(30));
            return $payload;
        }

        $success = $logs->where('result', 'success')->count();
        $successRate = $total > 0 ? $success / $total : 0.0;

        $payload = [
            'sampled' => true,
            'total' => $total,
            'success_rate' => $successRate,
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(30));
        return $payload;
    }

    /**
     * @param array<int, int|float> $values
     */
    private function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        sort($values);
        $middle = (int) floor(($count - 1) / 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle] + (float) $values[$middle + 1]) / 2;
    }
}
