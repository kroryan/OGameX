<?php

namespace OGame\Enums;

enum BotPersonality: string
{
    case AGGRESSIVE = 'aggressive';
    case DEFENSIVE = 'defensive';
    case ECONOMIC = 'economic';
    case BALANCED = 'balanced';
    case RAIDER = 'raider';
    case TURTLE = 'turtle';
    case SCIENTIST = 'scientist';
    case DIPLOMAT = 'diplomat';
    case EXPLORER = 'explorer';

    /**
     * Get action weights for this personality.
     * Returns weights for: [build, fleet, attack, research]
     */
    public function getActionWeights(): array
    {
        return match ($this) {
            self::AGGRESSIVE => [20, 35, 35, 10],
            self::DEFENSIVE => [40, 25, 10, 25],
            self::ECONOMIC => [50, 15, 5, 30],
            self::BALANCED => [30, 25, 20, 25],
            self::RAIDER => [15, 30, 45, 10],
            self::TURTLE => [50, 10, 5, 35],
            self::SCIENTIST => [25, 10, 5, 60],
            self::DIPLOMAT => [35, 20, 10, 35],
            self::EXPLORER => [30, 30, 10, 30],
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
            self::RAIDER => 'Raider',
            self::TURTLE => 'Turtle',
            self::SCIENTIST => 'Scientist',
            self::DIPLOMAT => 'Diplomat',
            self::EXPLORER => 'Explorer',
        };
    }

    /**
     * Get default risk tolerance for this personality.
     */
    public function getDefaultRiskTolerance(): int
    {
        return match ($this) {
            self::AGGRESSIVE => 75,
            self::DEFENSIVE => 25,
            self::ECONOMIC => 35,
            self::BALANCED => 50,
            self::RAIDER => 80,
            self::TURTLE => 15,
            self::SCIENTIST => 30,
            self::DIPLOMAT => 40,
            self::EXPLORER => 65,
        };
    }

    /**
     * Get default traits for this personality.
     */
    public function getDefaultTraits(): array
    {
        return match ($this) {
            self::AGGRESSIVE => ['vengeful', 'impatient'],
            self::DEFENSIVE => ['cautious', 'patient'],
            self::ECONOMIC => ['opportunistic', 'patient'],
            self::BALANCED => ['adaptable'],
            self::RAIDER => ['opportunistic', 'impatient', 'vengeful'],
            self::TURTLE => ['cautious', 'patient', 'stubborn'],
            self::SCIENTIST => ['patient', 'methodical'],
            self::DIPLOMAT => ['cautious', 'social'],
            self::EXPLORER => ['adventurous', 'opportunistic'],
        };
    }

    /**
     * Whether this personality is one of the original four.
     */
    public function isClassic(): bool
    {
        return in_array($this, [self::AGGRESSIVE, self::DEFENSIVE, self::ECONOMIC, self::BALANCED]);
    }
}
