<?php

namespace OGame\Services;

use OGame\Enums\BotObjective;
use OGame\Enums\BotPersonality;
use OGame\Models\Bot;

/**
 * BotObjectiveService - Determines dynamic strategic objectives for bots.
 * Enhanced with new personalities and objectives.
 */
class BotObjectiveService
{
    public function determineObjective(Bot $bot, array $state): BotObjective
    {
        $personality = $bot->getPersonalityEnum();
        $phase = $state['game_phase'] ?? 'early';

        // Immediate threat overrides everything
        if (!empty($state['is_under_threat'])) {
            return BotObjective::DEFENSIVE_FORTIFICATION;
        }

        // Vengeful trait: if recently attacked, switch to raiding
        if ($bot->hasTrait('vengeful') && ($bot->espionage_counter ?? 0) > 3) {
            if (($state['has_significant_fleet'] ?? false)) {
                return BotObjective::RAIDING_AND_PROFIT;
            }
            return BotObjective::FLEET_ACCUMULATION;
        }

        // Colonization opportunity
        if (!empty($state['can_colonize']) && $phase !== 'late') {
            return BotObjective::TERRITORIAL_EXPANSION;
        }

        // Attack opportunity
        if (!empty($state['has_attack_target']) && !empty($state['has_significant_fleet']) && $phase !== 'early') {
            if (in_array($personality, [BotPersonality::AGGRESSIVE, BotPersonality::RAIDER])) {
                return BotObjective::RAIDING_AND_PROFIT;
            }
        }

        // Storage pressure: need to spend
        if (!empty($state['is_storage_pressure_high'])) {
            return BotObjective::ECONOMIC_GROWTH;
        }

        // Phase + personality driven objective
        return match ($phase) {
            'early' => match ($personality) {
                BotPersonality::SCIENTIST => BotObjective::TECH_RUSH,
                BotPersonality::RAIDER => BotObjective::ECONOMIC_GROWTH, // Must eco first
                default => BotObjective::ECONOMIC_GROWTH,
            },
            'mid' => match ($personality) {
                BotPersonality::AGGRESSIVE => BotObjective::FLEET_ACCUMULATION,
                BotPersonality::DEFENSIVE, BotPersonality::TURTLE => BotObjective::DEFENSIVE_FORTIFICATION,
                BotPersonality::ECONOMIC => BotObjective::ECONOMIC_GROWTH,
                BotPersonality::BALANCED => BotObjective::TERRITORIAL_EXPANSION,
                BotPersonality::RAIDER => BotObjective::RAIDING_AND_PROFIT,
                BotPersonality::SCIENTIST => BotObjective::TECH_RUSH,
                BotPersonality::DIPLOMAT => BotObjective::ALLIANCE_WARFARE,
                BotPersonality::EXPLORER => BotObjective::TERRITORIAL_EXPANSION,
            },
            default => match ($personality) {
                BotPersonality::AGGRESSIVE, BotPersonality::RAIDER => BotObjective::RAIDING_AND_PROFIT,
                BotPersonality::DEFENSIVE, BotPersonality::TURTLE => BotObjective::DEFENSIVE_FORTIFICATION,
                BotPersonality::ECONOMIC => BotObjective::ECONOMIC_GROWTH,
                BotPersonality::BALANCED => BotObjective::FLEET_ACCUMULATION,
                BotPersonality::SCIENTIST => BotObjective::TECH_RUSH,
                BotPersonality::DIPLOMAT => BotObjective::ALLIANCE_WARFARE,
                BotPersonality::EXPLORER => BotObjective::INTELLIGENCE_GATHERING,
            },
        };
    }
}
