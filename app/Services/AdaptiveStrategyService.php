<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Cache;
use OGame\Enums\BotActionType;

/**
 * AdaptiveStrategyService - Adjust bot behavior based on metrics.
 *
 * Overrides are stored in cache rather than overwriting the bot's DB settings.
 * Enhanced with new action types and more nuanced adaptation.
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

        $economy = $bot->economy_settings ?? [];
        $actionProbs = $bot->action_probabilities ?? [];

        $longTerm = app(BotLongTermStrategyService::class)->getStrategy($botService, $state);
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
            $actionProbs['espionage'] = min(15, ($actionProbs['espionage'] ?? 5) + 2);
            $changed = true;
        }

        if (!empty($state['is_under_threat'])) {
            $actionProbs['attack'] = max(5, ($actionProbs['attack'] ?? 20) - 5);
            $actionProbs['build'] = min(70, ($actionProbs['build'] ?? 30) + 5);
            $actionProbs['defense'] = min(30, ($actionProbs['defense'] ?? 10) + 10);
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

        // Defense adaptation based on defense/building ratio
        $defenseRatio = ($state['building_points'] ?? 0) > 0
            ? ($state['defense_points'] ?? 0) / max(1, $state['building_points'])
            : 0;
        if ($defenseRatio < 0.05 && ($state['game_phase'] ?? 'early') !== 'early') {
            $actionProbs['defense'] = min(25, ($actionProbs['defense'] ?? 5) + 5);
            $changed = true;
        }

        // System 1: Class-aware adaptation
        try {
            $classBonuses = $botService->getClassBonuses();
            if (!empty($classBonuses['prefer_economy'])) {
                // Collector: boost build and trade
                $actionProbs['build'] = min(60, ($actionProbs['build'] ?? 30) + 3);
                $actionProbs['trade'] = min(20, ($actionProbs['trade'] ?? 5) + 2);
                $changed = true;
            } elseif (!empty($classBonuses['prefer_attacks'])) {
                // General: boost attack and fleet
                $actionProbs['attack'] = min(40, ($actionProbs['attack'] ?? 20) + 3);
                $actionProbs['fleet'] = min(40, ($actionProbs['fleet'] ?? 25) + 2);
                $changed = true;
            } elseif (!empty($classBonuses['prefer_expeditions'])) {
                // Discoverer: boost fleet (expeditions) and research
                $actionProbs['fleet'] = min(40, ($actionProbs['fleet'] ?? 25) + 3);
                $actionProbs['research'] = min(40, ($actionProbs['research'] ?? 25) + 3);
                $changed = true;
            }
        } catch (\Exception $e) {
            // Non-critical
        }

        // System 12: Highscore-aware adaptation
        try {
            $hsModifiers = $botService->getHighscoreStrategyModifiers();
            if ($hsModifiers['attack_modifier'] > 1.2) {
                // Lower ranked: more aggressive
                $actionProbs['attack'] = min(40, ($actionProbs['attack'] ?? 20) + 3);
                $actionProbs['fleet'] = min(40, ($actionProbs['fleet'] ?? 25) + 2);
                $changed = true;
            } elseif ($hsModifiers['defense_modifier'] > 1.2) {
                // Top ranked: more defensive
                $actionProbs['defense'] = min(25, ($actionProbs['defense'] ?? 10) + 3);
                $actionProbs['build'] = min(60, ($actionProbs['build'] ?? 30) + 2);
                $changed = true;
            }
        } catch (\Exception $e) {
            // Non-critical
        }

        if ($changed) {
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

    public static function getAdaptiveEconomy(int $botId): array
    {
        return Cache::get("bot:{$botId}:adaptive_economy", []);
    }

    public static function getAdaptiveActionProbs(int $botId): array
    {
        return Cache::get("bot:{$botId}:adaptive_action_probs", []);
    }
}
