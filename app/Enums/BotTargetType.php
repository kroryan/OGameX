<?php

namespace OGame\Enums;

enum BotTargetType: string
{
    case RANDOM = 'random';
    case WEAK = 'weak';
    case RICH = 'rich';
    case SIMILAR = 'similar';

    /**
     * Get the display label for this target type.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::RANDOM => 'Random',
            self::WEAK => 'Weak Players',
            self::RICH => 'Rich Players',
            self::SIMILAR => 'Similar Strength',
        };
    }
}
