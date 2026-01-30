<?php

namespace OGame\Services;

use OGame\Enums\BotPersonality;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * BotFleetBuilderService - Builds fleets for bot actions.
 */
class BotFleetBuilderService
{
    /**
     * Build an attack fleet for the given bot and target.
     */
    public function buildAttackFleet(BotService $bot, PlanetService $target): UnitCollection
    {
        $planet = $bot->getRichestPlanet();
        if ($planet === null) {
            return new UnitCollection();
        }

        // Get available units
        $availableUnits = $planet->getShipUnits();
        if ($availableUnits->count() === 0) {
            return new UnitCollection();
        }

        $personality = $bot->getPersonality();
        $fleet = new UnitCollection();

        // Fleet composition based on personality
        // Keep some units for defense, send rest to attack
        $attackPercentage = match ($personality) {
            BotPersonality::AGGRESSIVE => 0.9, // Send 90%
            BotPersonality::DEFENSIVE => 0.5, // Send 50%
            BotPersonality::ECONOMIC => 0.3,   // Send 30%
            BotPersonality::BALANCED => 0.7,   // Send 70%
        };

        // Prioritize combat ships
        $combatPriority = [
            'deathstar' => 10,
            'bomber' => 9,
            'destroyer' => 8,
            'battlecruiser' => 7,
            'battleship' => 6,
            'cruiser' => 5,
            'heavy_fighter' => 4,
            'light_fighter' => 3,
            'small_transporter' => 1,
            'large_transporter' => 1,
            'colony_ship' => 0,
            'recycler' => 0,
            'espionage_probe' => 0,
        ];

        // Build fleet based on priority and available units
        foreach ($availableUnits->units as $unit) {
            $machineName = $unit->unitObject->machine_name;
            $priority = $combatPriority[$machineName] ?? 1;

            if ($priority === 0) {
                continue; // Skip non-combat ships
            }

            // Send percentage based on priority
            $sendAmount = (int)($unit->amount * $attackPercentage * ($priority / 10));

            if ($sendAmount > 0) {
                // Keep at least 1 for defense
                $sendAmount = min($sendAmount, $unit->amount - 1);
                if ($sendAmount > 0) {
                    $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $sendAmount);
                }
            }
        }

        // Add some recyclers if available (for debris)
        $recyclers = $availableUnits->getUnitCountByMachineName('recycler');
        if ($recyclers > 0) {
            $sendRecyclers = max(1, (int)($recyclers * 0.2)); // Send up to 20%
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('recycler'), $sendRecyclers);
        }

        return $fleet;
    }

    /**
     * Build an expedition fleet for the bot.
     */
    public function buildExpeditionFleet(BotService $bot): UnitCollection
    {
        $planet = $bot->getRichestPlanet();
        if ($planet === null) {
            return new UnitCollection();
        }

        $availableUnits = $planet->getShipUnits();
        if ($availableUnits->count() === 0) {
            return new UnitCollection();
        }

        $personality = $bot->getPersonality();
        $fleet = new UnitCollection();

        // Expedition fleet composition
        // Smaller, balanced fleets for expeditions
        $maxPoints = 5000; // Max fleet points for expedition

        // Calculate fleet composition
        $composition = $this->calculateExpeditionComposition($personality, $maxPoints);

        foreach ($composition as $machineName => $amount) {
            $available = $availableUnits->getUnitCountByMachineName($machineName);
            $sendAmount = min($amount, $available);
            if ($sendAmount > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $sendAmount);
            }
        }

        // Add espionage probes
        $probes = $availableUnits->getUnitCountByMachineName('espionage_probe');
        if ($probes > 0) {
            $sendProbes = min(10, $probes);
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), $sendProbes);
        }

        return $fleet;
    }

    /**
     * Calculate fleet composition for expedition based on personality.
     */
    private function calculateExpeditionComposition(BotPersonality $personality, int $maxPoints): array
    {
        return match ($personality) {
            BotPersonality::AGGRESSIVE => [
                'battlecruiser' => 5,
                'battleship' => 10,
                'cruiser' => 20,
                'light_fighter' => 50,
            ],
            BotPersonality::DEFENSIVE => [
                'battleship' => 15,
                'cruiser' => 10,
                'heavy_fighter' => 30,
            ],
            BotPersonality::ECONOMIC => [
                'large_transporter' => 20,
                'small_transporter' => 50,
                'cruiser' => 5,
                'light_fighter' => 20,
            ],
            BotPersonality::BALANCED => [
                'battleship' => 8,
                'cruiser' => 15,
                'heavy_fighter' => 25,
                'light_fighter' => 40,
            ],
        };
    }
}
