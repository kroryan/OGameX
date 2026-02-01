<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Cache;
use OGame\Enums\BotActionType;

/**
 * AdaptiveStrategyService - Adjust bot behavior based on metrics.
 *
 * Overrides are stored in cache rather than overwriting the bot's DB settings,
 * so admin-configured values are preserved. The Bot model's getActionProbabilities()
 * and getEconomySettings() remain the admin baseline; adaptive adjustments are
 * layered on top via cache and applied by BotDecisionService scoring.
 */
class AdaptiveStrategyService
{
    private BotStrategicMetrics $metrics;

    public function __construct()
    {
        $this->metrics = new BotStrategicMetrics();
    }

    public function adaptIfNeeded(BotService $botService, array $state): void
    {
        $bot = $botService->getBot();
        $botId = $bot->id;
        $cooldownKey = "bot:{$botId}:metrics:adapted_at";
        if (Cache::has($cooldownKey)) {
            return;
        }

        $growth = $this->metrics->getGrowthRate($state, $botId);
        $efficiency = $this->metrics->getResourceEfficiency($state, $botId);

        // Start from the admin-configured base values (not previously adapted values).
        $economy = $bot->economy_settings ?? [];
        $actionProbs = $bot->action_probabilities ?? [];

        $longTerm = app(\OGame\Services\BotLongTermStrategyService::class)->getStrategy($botService, $state);
        $economy = array_merge($economy, $longTerm['economy'] ?? []);

        $changed = false;

        if ($growth < 5 && $efficiency < 0.6) {
            // Stagnation: spend more and build more.
            $economy['save_for_upgrade_percent'] = max(0.1, ($economy['save_for_upgrade_percent'] ?? 0.3) - 0.05);
            $economy['min_resources_for_actions'] = max(200, (int) (($economy['min_resources_for_actions'] ?? 500) * 0.9));
            $actionProbs['build'] = min(60, ($actionProbs['build'] ?? 30) + 5);
            $actionProbs['fleet'] = max(10, ($actionProbs['fleet'] ?? 25) - 3);
            $changed = true;
        } elseif ($growth > 25 && $efficiency > 1.0) {
            // Strong growth: shift to fleet/attack.
            $actionProbs['fleet'] = min(45, ($actionProbs['fleet'] ?? 25) + 4);
            $actionProbs['attack'] = min(35, ($actionProbs['attack'] ?? 20) + 3);
            $actionProbs['build'] = max(20, ($actionProbs['build'] ?? 30) - 3);
            $changed = true;
        }

        if (!empty($state['is_under_threat'])) {
            $actionProbs['attack'] = max(5, ($actionProbs['attack'] ?? 20) - 5);
            $actionProbs['build'] = min(70, ($actionProbs['build'] ?? 30) + 5);
            $changed = true;
        }

        if (!empty($state['is_storage_pressure_high'])) {
            $actionProbs['build'] = min(70, ($actionProbs['build'] ?? 30) + 4);
            $actionProbs['trade'] = min(20, ($actionProbs['trade'] ?? 5) + 3);
            $changed = true;
        }

        if (!empty($state['resource_imbalance']) && $state['resource_imbalance'] > 0.5) {
            $actionProbs['trade'] = min(25, ($actionProbs['trade'] ?? 5) + 5);
            $changed = true;
        }

        if ($changed) {
            // Store overrides in cache instead of overwriting DB settings.
            Cache::put("bot:{$botId}:adaptive_economy", $economy, now()->addMinutes(60));
            Cache::put("bot:{$botId}:adaptive_action_probs", $actionProbs, now()->addMinutes(60));

            $botService->logAction(
                BotActionType::BUILD,
                "Adaptive strategy updated (growth {$growth}, efficiency {$efficiency})",
                []
            );
        }

        Cache::put($cooldownKey, true, now()->addMinutes(30));
    }

    /**
     * Get the adaptive economy overrides for a bot (or empty array if none).
     */
    public static function getAdaptiveEconomy(int $botId): array
    {
        return Cache::get("bot:{$botId}:adaptive_economy", []);
    }

    /**
     * Get the adaptive action probability overrides for a bot (or empty array if none).
     */
    public static function getAdaptiveActionProbs(int $botId): array
    {
        return Cache::get("bot:{$botId}:adaptive_action_probs", []);
    }
}
