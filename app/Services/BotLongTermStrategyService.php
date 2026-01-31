<?php

namespace OGame\Services;

use OGame\Enums\BotPersonality;

/**
 * BotLongTermStrategyService - multi-day strategy selection and modifiers.
 */
class BotLongTermStrategyService
{
    public function getStrategy(BotService $botService, array $state): array
    {
        $bot = $botService->getBot();
        $cacheKey = 'bot_long_term_strategy_' . $bot->id;
        $cached = cache()->get($cacheKey);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > now()->timestamp) {
            return $cached;
        }

        $personality = $bot->getPersonalityEnum();
        $phase = $state['game_phase'] ?? 'early';

        $strategies = match ($personality) {
            BotPersonality::AGGRESSIVE => ['rush', 'raid', 'balanced'],
            BotPersonality::DEFENSIVE => ['turtle', 'balanced', 'eco_boom'],
            BotPersonality::ECONOMIC => ['eco_boom', 'tech_rush', 'balanced'],
            default => ['balanced', 'eco_boom', 'tech_rush', 'raid'],
        };

        $strategy = $strategies[array_rand($strategies)];
        if ($phase === 'early' && $strategy === 'raid') {
            $strategy = 'eco_boom';
        }

        $weights = match ($strategy) {
            'rush' => ['build' => 0.9, 'fleet' => 1.4, 'attack' => 1.3, 'research' => 0.7, 'trade' => 0.8],
            'raid' => ['build' => 0.8, 'fleet' => 1.3, 'attack' => 1.5, 'research' => 0.8, 'trade' => 0.9],
            'turtle' => ['build' => 1.3, 'fleet' => 0.8, 'attack' => 0.6, 'research' => 1.0, 'trade' => 1.0],
            'tech_rush' => ['build' => 0.9, 'fleet' => 0.7, 'attack' => 0.7, 'research' => 1.5, 'trade' => 1.0],
            'eco_boom' => ['build' => 1.4, 'fleet' => 0.8, 'attack' => 0.6, 'research' => 1.1, 'trade' => 1.1],
            default => ['build' => 1.0, 'fleet' => 1.0, 'attack' => 1.0, 'research' => 1.0, 'trade' => 1.0],
        };

        $economy = match ($strategy) {
            'rush' => ['save_for_upgrade_percent' => 0.2],
            'raid' => ['save_for_upgrade_percent' => 0.25],
            'turtle' => ['save_for_upgrade_percent' => 0.4],
            'tech_rush' => ['save_for_upgrade_percent' => 0.45],
            'eco_boom' => ['save_for_upgrade_percent' => 0.35],
            default => [],
        };

        $expiresAt = now()->addHours(24 + rand(0, 24))->timestamp;
        $payload = [
            'strategy' => $strategy,
            'weights' => $weights,
            'economy' => $economy,
            'expires_at' => $expiresAt,
        ];
        cache()->put($cacheKey, $payload, now()->addHours(48));

        return $payload;
    }
}
