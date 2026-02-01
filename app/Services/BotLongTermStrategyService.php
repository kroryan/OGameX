<?php

namespace OGame\Services;

use OGame\Enums\BotPersonality;

/**
 * BotLongTermStrategyService - multi-day strategy selection and modifiers.
 * Enhanced with new personalities and strategy evolution.
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
            BotPersonality::DEFENSIVE, BotPersonality::TURTLE => ['turtle', 'balanced', 'eco_boom'],
            BotPersonality::ECONOMIC => ['eco_boom', 'tech_rush', 'balanced'],
            BotPersonality::RAIDER => ['raid', 'rush', 'eco_boom'],
            BotPersonality::SCIENTIST => ['tech_rush', 'eco_boom', 'balanced'],
            BotPersonality::DIPLOMAT => ['balanced', 'eco_boom', 'turtle'],
            BotPersonality::EXPLORER => ['eco_boom', 'balanced', 'tech_rush'],
            default => ['balanced', 'eco_boom', 'tech_rush', 'raid'],
        };

        $strategy = $strategies[array_rand($strategies)];
        if ($phase === 'early' && $strategy === 'raid') {
            $strategy = 'eco_boom';
        }

        // Personality evolution: in late game, shift strategies
        if ($phase === 'late') {
            if ($personality === BotPersonality::ECONOMIC && mt_rand(1, 3) === 1) {
                $strategy = 'raid'; // Rich economy starts raiding
            }
            if ($personality === BotPersonality::SCIENTIST && mt_rand(1, 3) === 1) {
                $strategy = 'balanced'; // Scientists become more active
            }
        }

        $weights = match ($strategy) {
            'rush' => ['build' => 0.9, 'fleet' => 1.4, 'attack' => 1.3, 'research' => 0.7, 'trade' => 0.8, 'espionage' => 1.0, 'defense' => 0.6, 'diplomacy' => 0.7],
            'raid' => ['build' => 0.8, 'fleet' => 1.3, 'attack' => 1.5, 'research' => 0.8, 'trade' => 0.9, 'espionage' => 1.3, 'defense' => 0.5, 'diplomacy' => 0.8],
            'turtle' => ['build' => 1.3, 'fleet' => 0.8, 'attack' => 0.6, 'research' => 1.0, 'trade' => 1.0, 'espionage' => 1.1, 'defense' => 1.6, 'diplomacy' => 1.2],
            'tech_rush' => ['build' => 0.9, 'fleet' => 0.7, 'attack' => 0.7, 'research' => 1.5, 'trade' => 1.0, 'espionage' => 0.9, 'defense' => 0.8, 'diplomacy' => 0.9],
            'eco_boom' => ['build' => 1.4, 'fleet' => 0.8, 'attack' => 0.6, 'research' => 1.1, 'trade' => 1.1, 'espionage' => 0.8, 'defense' => 0.7, 'diplomacy' => 1.0],
            default => ['build' => 1.0, 'fleet' => 1.0, 'attack' => 1.0, 'research' => 1.0, 'trade' => 1.0, 'espionage' => 1.0, 'defense' => 1.0, 'diplomacy' => 1.0],
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
