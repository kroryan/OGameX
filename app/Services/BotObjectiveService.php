<?php

namespace OGame\Services;

use OGame\Enums\BotObjective;
use OGame\Enums\BotPersonality;
use OGame\Models\Bot;

/**
 * BotObjectiveService - Determines dynamic strategic objectives for bots.
 */
class BotObjectiveService
{
    public function determineObjective(Bot $bot, array $state): BotObjective
    {
        $personality = $bot->getPersonalityEnum();
        $phase = $state['game_phase'] ?? 'early';

        if (!empty($state['is_under_threat'])) {
            return BotObjective::DEFENSIVE_FORTIFICATION;
        }

        if (!empty($state['can_colonize']) && $phase !== 'late') {
            return BotObjective::TERRITORIAL_EXPANSION;
        }

        if (!empty($state['has_attack_target']) && !empty($state['has_significant_fleet']) && $phase !== 'early') {
            return BotObjective::RAIDING_AND_PROFIT;
        }

        if (!empty($state['is_storage_pressure_high'])) {
            return BotObjective::ECONOMIC_GROWTH;
        }

        return match ($phase) {
            'early' => BotObjective::ECONOMIC_GROWTH,
            'mid' => match ($personality) {
                BotPersonality::AGGRESSIVE => BotObjective::FLEET_ACCUMULATION,
                BotPersonality::DEFENSIVE => BotObjective::DEFENSIVE_FORTIFICATION,
                BotPersonality::ECONOMIC => BotObjective::ECONOMIC_GROWTH,
                BotPersonality::BALANCED => BotObjective::TERRITORIAL_EXPANSION,
            },
            default => match ($personality) {
                BotPersonality::AGGRESSIVE => BotObjective::RAIDING_AND_PROFIT,
                BotPersonality::DEFENSIVE => BotObjective::DEFENSIVE_FORTIFICATION,
                BotPersonality::ECONOMIC => BotObjective::ECONOMIC_GROWTH,
                BotPersonality::BALANCED => BotObjective::FLEET_ACCUMULATION,
            },
        };
    }
}
