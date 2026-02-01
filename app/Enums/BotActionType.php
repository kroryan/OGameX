<?php

namespace OGame\Enums;

enum BotActionType: string
{
    case BUILD = 'build';
    case RESEARCH = 'research';
    case FLEET = 'fleet';
    case ATTACK = 'attack';
    case TRADE = 'trade';
    case ESPIONAGE = 'espionage';
    case DEFENSE = 'defense';
    case DIPLOMACY = 'diplomacy';

    /**
     * Get the display label for this action type.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::BUILD => 'Build Structure',
            self::RESEARCH => 'Research Technology',
            self::FLEET => 'Build Fleet',
            self::ATTACK => 'Attack',
            self::TRADE => 'Trade',
            self::ESPIONAGE => 'Espionage',
            self::DEFENSE => 'Build Defense',
            self::DIPLOMACY => 'Diplomacy',
        };
    }
}
