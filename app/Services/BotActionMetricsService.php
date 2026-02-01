<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // Use SQL aggregation instead of loading all logs into memory.
        $stats = BotLog::where('action_type', 'attack')
            ->where('created_at', '>=', $from)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as success_count', ['success'])
            ->first();

        $total = (int) ($stats->total ?? 0);
        if ($total < $minSamples) {
            $payload = ['sampled' => false, 'total' => $total];
            Cache::put($cacheKey, $payload, now()->addMinutes(30));
            return $payload;
        }

        $successCount = (int) ($stats->success_count ?? 0);
        $successRate = $total > 0 ? $successCount / $total : 0.0;

        // For medians, use a limited query to avoid loading everything.
        $consumptionValues = BotLog::where('action_type', 'attack')
            ->where('created_at', '>=', $from)
            ->where('result', 'success')
            ->whereNotNull('resources_spended')
            ->pluck('resources_spended')
            ->map(fn ($r) => is_array($r) ? ($r['consumption'] ?? null) : null)
            ->filter()
            ->sort()
            ->values()
            ->all();

        $lootValues = BotLog::where('action_type', 'attack')
            ->where('created_at', '>=', $from)
            ->where('action_description', 'like', '%loot%')
            ->whereNotNull('resources_spended')
            ->pluck('resources_spended')
            ->map(fn ($r) => is_array($r) ? ($r['loot'] ?? null) : null)
            ->filter()
            ->sort()
            ->values()
            ->all();

        $payload = [
            'sampled' => true,
            'total' => $total,
            'success_rate' => $successRate,
            'consumption_median' => $this->median($consumptionValues),
            'loot_median' => $this->median($lootValues),
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

        // Use SQL aggregation instead of loading all logs into memory.
        $stats = BotLog::where('action_type', 'trade')
            ->where('created_at', '>=', $from)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as success_count', ['success'])
            ->first();

        $total = (int) ($stats->total ?? 0);
        if ($total < $minSamples) {
            $payload = ['sampled' => false, 'total' => $total];
            Cache::put($cacheKey, $payload, now()->addMinutes(30));
            return $payload;
        }

        $successCount = (int) ($stats->success_count ?? 0);
        $successRate = $total > 0 ? $successCount / $total : 0.0;

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
