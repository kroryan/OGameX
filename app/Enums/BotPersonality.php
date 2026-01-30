<?php

namespace OGame\Enums;

enum BotPersonality: string
{
    case AGGRESSIVE = 'aggressive';
    case DEFENSIVE = 'defensive';
    case ECONOMIC = 'economic';
    case BALANCED = 'balanced';

    /**
     * Get action weights for this personality.
     * Returns weights for: [build, fleet, attack, research]
     *
     * @return array<int, int>
     */
    public function getActionWeights(): array
    {
        return match ($this) {
            self::AGGRESSIVE => [20, 35, 35, 10],
            self::DEFENSIVE => [40, 25, 10, 25],
            self::ECONOMIC => [50, 15, 5, 30],
            self::BALANCED => [30, 25, 20, 25],
        };
    }

    /**
     * Get the display label for this personality.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::AGGRESSIVE => 'Aggressive',
            self::DEFENSIVE => 'Defensive',
            self::ECONOMIC => 'Economic',
            self::BALANCED => 'Balanced',
        };
    }
}
