<?php

namespace OGame\Enums;

/**
 * Strategic objectives for bot decision-making.
 */
enum BotObjective: string
{
    case ECONOMIC_GROWTH = 'economic_growth';
    case FLEET_ACCUMULATION = 'fleet_accumulation';
    case DEFENSIVE_FORTIFICATION = 'defensive_fortification';
    case TERRITORIAL_EXPANSION = 'territorial_expansion';
    case RAIDING_AND_PROFIT = 'raiding_and_profit';
    case TECH_RUSH = 'tech_rush';
    case INTELLIGENCE_GATHERING = 'intelligence_gathering';
    case ALLIANCE_WARFARE = 'alliance_warfare';

    /**
     * Get the label for this objective.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::ECONOMIC_GROWTH => 'Economic Growth',
            self::FLEET_ACCUMULATION => 'Fleet Accumulation',
            self::DEFENSIVE_FORTIFICATION => 'Defensive Fortification',
            self::TERRITORIAL_EXPANSION => 'Territorial Expansion',
            self::RAIDING_AND_PROFIT => 'Raiding and Profit',
            self::TECH_RUSH => 'Technology Rush',
            self::INTELLIGENCE_GATHERING => 'Intelligence Gathering',
            self::ALLIANCE_WARFARE => 'Alliance Warfare',
        };
    }

    /**
     * Get the description for this objective.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::ECONOMIC_GROWTH => 'Focus on maximizing resource production and economy',
            self::FLEET_ACCUMULATION => 'Build and maintain a powerful military fleet',
            self::DEFENSIVE_FORTIFICATION => 'Fortify planets with strong defenses',
            self::TERRITORIAL_EXPANSION => 'Colonize new planets and expand territory',
            self::RAIDING_AND_PROFIT => 'Launch profitable attacks and gather resources',
            self::TECH_RUSH => 'Rush technology research for advanced capabilities',
            self::INTELLIGENCE_GATHERING => 'Spy on neighbors and gather intelligence',
            self::ALLIANCE_WARFARE => 'Coordinate with allies for warfare',
        };
    }

    /**
     * Get priority action weights for this objective.
     */
    public function getActionWeights(): array
    {
        return match ($this) {
            self::ECONOMIC_GROWTH => ['build' => 55, 'research' => 25, 'fleet' => 10, 'attack' => 0, 'trade' => 10, 'espionage' => 0, 'defense' => 0, 'diplomacy' => 0],
            self::FLEET_ACCUMULATION => ['build' => 15, 'research' => 15, 'fleet' => 50, 'attack' => 20, 'trade' => 0, 'espionage' => 0, 'defense' => 0, 'diplomacy' => 0],
            self::DEFENSIVE_FORTIFICATION => ['build' => 40, 'research' => 15, 'fleet' => 5, 'attack' => 0, 'trade' => 5, 'espionage' => 5, 'defense' => 25, 'diplomacy' => 5],
            self::TERRITORIAL_EXPANSION => ['build' => 35, 'research' => 30, 'fleet' => 30, 'attack' => 0, 'trade' => 5, 'espionage' => 0, 'defense' => 0, 'diplomacy' => 0],
            self::RAIDING_AND_PROFIT => ['build' => 10, 'research' => 5, 'fleet' => 30, 'attack' => 35, 'trade' => 5, 'espionage' => 10, 'defense' => 0, 'diplomacy' => 5],
            self::TECH_RUSH => ['build' => 20, 'research' => 55, 'fleet' => 5, 'attack' => 0, 'trade' => 5, 'espionage' => 0, 'defense' => 10, 'diplomacy' => 5],
            self::INTELLIGENCE_GATHERING => ['build' => 15, 'research' => 15, 'fleet' => 10, 'attack' => 10, 'trade' => 5, 'espionage' => 35, 'defense' => 5, 'diplomacy' => 5],
            self::ALLIANCE_WARFARE => ['build' => 10, 'research' => 10, 'fleet' => 25, 'attack' => 30, 'trade' => 0, 'espionage' => 10, 'defense' => 5, 'diplomacy' => 10],
        };
    }
}
