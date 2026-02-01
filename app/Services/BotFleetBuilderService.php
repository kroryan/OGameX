<?php

namespace OGame\Services;

use OGame\Enums\BotPersonality;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * BotFleetBuilderService - Builds fleets for bot actions.
 * Enhanced with new personalities and adaptive composition.
 */
class BotFleetBuilderService
{
    /**
     * Build an attack fleet for the given bot and target.
     * Now adapts composition based on target defenses from intelligence.
     */
    public function buildAttackFleet(BotService $bot, PlanetService $target): UnitCollection
    {
        $planet = $bot->getRichestPlanet();
        if ($planet === null) {
            return new UnitCollection();
        }

        $availableUnits = $planet->getShipUnits();
        if ($availableUnits->getAmount() === 0) {
            return new UnitCollection();
        }

        $personality = $bot->getPersonality();
        $fleet = new UnitCollection();

        $settings = $bot->getBot()->getFleetSettings();
        $attackPercentage = $settings['attack_fleet_percentage'] ?? match ($personality) {
            BotPersonality::AGGRESSIVE, BotPersonality::RAIDER => 0.9,
            BotPersonality::DEFENSIVE, BotPersonality::TURTLE => 0.5,
            BotPersonality::ECONOMIC, BotPersonality::SCIENTIST => 0.3,
            BotPersonality::EXPLORER => 0.6,
            BotPersonality::DIPLOMAT => 0.5,
            BotPersonality::BALANCED => 0.7,
        };
        $attackPercentage = max(0.1, min(0.95, (float) $attackPercentage));

        // Check intelligence for target defense composition
        $targetIntel = null;
        try {
            $intel = new BotIntelligenceService();
            $targetIntel = $intel->getTargetIntel($bot->getBot()->id, $target->getPlayer()->getId());
        } catch (\Exception $e) {
            // Non-critical
        }

        // Adaptive combat priority based on target defenses
        $combatPriority = $this->getCombatPriority($personality, $targetIntel);

        foreach ($availableUnits->units as $unit) {
            $machineName = $unit->unitObject->machine_name;
            $priority = $combatPriority[$machineName] ?? 1;

            if ($priority === 0) {
                continue;
            }

            $sendAmount = (int)($unit->amount * $attackPercentage * ($priority / 10));
            if ($sendAmount > 0) {
                $sendAmount = min($sendAmount, $unit->amount - 1);
                if ($sendAmount > 0) {
                    $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $sendAmount);
                }
            }
        }

        // Add recyclers for debris
        $recyclers = $availableUnits->getAmountByMachineName('recycler');
        if ($recyclers > 0) {
            $sendRecyclers = max(1, (int)($recyclers * 0.3));
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('recycler'), $sendRecyclers);
        }

        // Raiders always bring cargo ships
        if (in_array($personality, [BotPersonality::RAIDER, BotPersonality::ECONOMIC])) {
            $largeCargo = $availableUnits->getAmountByMachineName('large_cargo');
            if ($largeCargo > 0) {
                $sendCargo = max(1, (int)($largeCargo * 0.5));
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName('large_cargo'), $sendCargo);
            }
        }

        return $fleet;
    }

    /**
     * Build an expedition fleet for the bot.
     */
    public function buildExpeditionFleet(BotService $bot, float $fleetPercentage = 0.3): UnitCollection
    {
        $planet = $bot->getRichestPlanet();
        if ($planet === null) {
            return new UnitCollection();
        }

        $availableUnits = $planet->getShipUnits();
        if ($availableUnits->getAmount() === 0) {
            return new UnitCollection();
        }

        $personality = $bot->getPersonality();
        $fleet = new UnitCollection();

        // Explorers send bigger expedition fleets
        if ($personality === BotPersonality::EXPLORER) {
            $fleetPercentage = min(0.6, $fleetPercentage * 1.5);
        }

        $fleetPercentage = max(0.05, min(0.6, $fleetPercentage));
        $maxPoints = (int) max(1500, 5000 * $fleetPercentage);

        $composition = $this->calculateExpeditionComposition($personality, $maxPoints);

        foreach ($composition as $machineName => $amount) {
            $available = $availableUnits->getAmountByMachineName($machineName);
            $sendAmount = min($amount, $available);
            if ($sendAmount > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $sendAmount);
            }
        }

        // Add espionage probes
        $probes = $availableUnits->getAmountByMachineName('espionage_probe');
        if ($probes > 0) {
            $sendProbes = min(10, $probes);
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), $sendProbes);
        }

        return $fleet;
    }

    /**
     * Get combat priority map adjusted for target defenses.
     */
    private function getCombatPriority(BotPersonality $personality, ?\OGame\Models\BotIntel $targetIntel = null): array
    {
        $base = [
            'deathstar' => 10,
            'bomber' => 9,
            'destroyer' => 8,
            'battlecruiser' => 7,
            'battle_ship' => 6,
            'cruiser' => 5,
            'heavy_fighter' => 4,
            'light_fighter' => 3,
            'small_cargo' => 1,
            'large_cargo' => 1,
            'colony_ship' => 0,
            'recycler' => 0,
            'espionage_probe' => 0,
        ];

        // Adapt based on target defenses from intel
        if ($targetIntel) {
            $defenses = $targetIntel->defenses ?? [];
            $hasPlasmaTurrets = ($defenses['plasma_turret'] ?? 0) > 5;
            $hasGaussCannons = ($defenses['gauss_cannon'] ?? 0) > 10;
            $hasHeavyDefenses = $hasPlasmaTurrets || $hasGaussCannons;

            if ($hasHeavyDefenses) {
                // Prioritize bombers against heavy defenses
                $base['bomber'] = 10;
                $base['destroyer'] = 9;
                $base['light_fighter'] = 2; // Light fighters die fast against heavy defense
            }
        }

        // Personality adjustments
        if ($personality === BotPersonality::RAIDER) {
            $base['large_cargo'] = 3; // Raiders bring cargo
            $base['small_cargo'] = 2;
        }

        return $base;
    }

    private function calculateExpeditionComposition(BotPersonality $personality, int $maxPoints): array
    {
        $baseComposition = match ($personality) {
            BotPersonality::AGGRESSIVE, BotPersonality::RAIDER => [
                'battlecruiser' => 5,
                'battle_ship' => 10,
                'cruiser' => 20,
                'light_fighter' => 50,
            ],
            BotPersonality::DEFENSIVE, BotPersonality::TURTLE => [
                'battle_ship' => 15,
                'cruiser' => 10,
                'heavy_fighter' => 30,
            ],
            BotPersonality::ECONOMIC, BotPersonality::SCIENTIST => [
                'large_cargo' => 20,
                'small_cargo' => 50,
                'cruiser' => 5,
                'light_fighter' => 20,
            ],
            BotPersonality::EXPLORER => [
                'large_cargo' => 30,
                'cruiser' => 15,
                'heavy_fighter' => 20,
                'light_fighter' => 30,
            ],
            BotPersonality::DIPLOMAT => [
                'large_cargo' => 15,
                'cruiser' => 10,
                'light_fighter' => 30,
            ],
            default => [
                'battle_ship' => 8,
                'cruiser' => 15,
                'heavy_fighter' => 25,
                'light_fighter' => 40,
            ],
        };

        $scale = max(0.1, $maxPoints / 5000);
        $scaled = [];
        foreach ($baseComposition as $unit => $amount) {
            $scaledAmount = max(1, (int) round($amount * $scale));
            $scaled[$unit] = $scaledAmount;
        }

        return $scaled;
    }
}
