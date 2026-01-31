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
        };
    }

    /**
     * Get priority action weights for this objective.
     */
    public function getActionWeights(): array
    {
        return match ($this) {
            self::ECONOMIC_GROWTH => ['build' => 55, 'research' => 25, 'fleet' => 10, 'attack' => 0, 'trade' => 10],
            self::FLEET_ACCUMULATION => ['build' => 15, 'research' => 15, 'fleet' => 50, 'attack' => 20, 'trade' => 0],
            self::DEFENSIVE_FORTIFICATION => ['build' => 60, 'research' => 20, 'fleet' => 10, 'attack' => 5, 'trade' => 5],
            self::TERRITORIAL_EXPANSION => ['build' => 35, 'research' => 30, 'fleet' => 30, 'attack' => 0, 'trade' => 5],
            self::RAIDING_AND_PROFIT => ['build' => 10, 'research' => 10, 'fleet' => 40, 'attack' => 35, 'trade' => 5],
        };
    }
}
