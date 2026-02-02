<?php

namespace OGame\Services;

/**
 * BotBattleSimulator - Lightweight battle outcome prediction for bot decision-making.
 *
 * Uses a simplified power-ratio model with rapid-fire mechanics to estimate
 * battle outcomes without running the full battle engine. Designed for <1ms execution.
 */
class BotBattleSimulator
{
    /**
     * Unit stats: [attack, hull, shield, value (resource cost / 1000)]
     */
    private const UNIT_STATS = [
        // Ships
        'light_fighter'   => ['attack' => 50,    'hull' => 400,    'shield' => 10,    'value' => 4],
        'heavy_fighter'   => ['attack' => 150,   'hull' => 1000,   'shield' => 25,    'value' => 10],
        'cruiser'         => ['attack' => 400,   'hull' => 2700,   'shield' => 50,    'value' => 29],
        'battle_ship'     => ['attack' => 1000,  'hull' => 6000,   'shield' => 200,   'value' => 60],
        'battlecruiser'   => ['attack' => 700,   'hull' => 7000,   'shield' => 400,   'value' => 70],
        'bomber'          => ['attack' => 1000,  'hull' => 7500,   'shield' => 500,   'value' => 90],
        'destroyer'       => ['attack' => 2000,  'hull' => 11000,  'shield' => 500,   'value' => 125],
        'deathstar'       => ['attack' => 200000,'hull' => 900000, 'shield' => 50000, 'value' => 10000],
        'small_cargo'     => ['attack' => 5,     'hull' => 400,    'shield' => 10,    'value' => 4],
        'large_cargo'     => ['attack' => 5,     'hull' => 1200,   'shield' => 25,    'value' => 12],
        'colony_ship'     => ['attack' => 50,    'hull' => 3000,   'shield' => 100,   'value' => 40],
        'recycler'        => ['attack' => 1,     'hull' => 1600,   'shield' => 10,    'value' => 18],
        'espionage_probe' => ['attack' => 0,     'hull' => 100,    'shield' => 0,     'value' => 1],
        'solar_satellite' => ['attack' => 1,     'hull' => 200,    'shield' => 1,     'value' => 1],
        // Defenses
        'rocket_launcher'    => ['attack' => 80,    'hull' => 200,    'shield' => 20,    'value' => 2],
        'light_laser'        => ['attack' => 100,   'hull' => 200,    'shield' => 25,    'value' => 2],
        'heavy_laser'        => ['attack' => 250,   'hull' => 800,    'shield' => 100,   'value' => 8],
        'gauss_cannon'       => ['attack' => 1100,  'hull' => 3500,   'shield' => 200,   'value' => 37],
        'ion_cannon'         => ['attack' => 150,   'hull' => 800,    'shield' => 500,   'value' => 8],
        'plasma_turret'      => ['attack' => 3000,  'hull' => 10000,  'shield' => 300,   'value' => 130],
        'small_shield_dome'  => ['attack' => 1,     'hull' => 2000,   'shield' => 2000,  'value' => 20],
        'large_shield_dome'  => ['attack' => 1,     'hull' => 10000,  'shield' => 10000, 'value' => 100],
    ];

    /**
     * Rapid-fire table: attacker => [target => rapid_fire_value]
     */
    private const RAPID_FIRE = [
        'cruiser'       => ['light_fighter' => 6, 'rocket_launcher' => 10, 'espionage_probe' => 5, 'solar_satellite' => 5],
        'battle_ship'   => ['espionage_probe' => 5, 'solar_satellite' => 5],
        'battlecruiser' => ['small_cargo' => 3, 'large_cargo' => 3, 'light_fighter' => 3, 'heavy_fighter' => 4, 'cruiser' => 4, 'battle_ship' => 7],
        'bomber'        => ['rocket_launcher' => 20, 'light_laser' => 20, 'heavy_laser' => 10, 'gauss_cannon' => 5, 'ion_cannon' => 10, 'plasma_turret' => 5, 'espionage_probe' => 5, 'solar_satellite' => 5],
        'destroyer'     => ['light_laser' => 10, 'battlecruiser' => 2, 'espionage_probe' => 5, 'solar_satellite' => 5],
        'deathstar'     => ['small_cargo' => 250, 'large_cargo' => 250, 'light_fighter' => 200, 'heavy_fighter' => 100, 'cruiser' => 33, 'battle_ship' => 30, 'colony_ship' => 250, 'recycler' => 250, 'espionage_probe' => 1250, 'solar_satellite' => 1250, 'bomber' => 25, 'destroyer' => 5, 'battlecruiser' => 15, 'rocket_launcher' => 200, 'light_laser' => 200, 'heavy_laser' => 100, 'gauss_cannon' => 50, 'ion_cannon' => 100, 'plasma_turret' => 10],
        'light_fighter' => ['espionage_probe' => 5, 'solar_satellite' => 5],
        'heavy_fighter' => ['small_cargo' => 3, 'espionage_probe' => 5, 'solar_satellite' => 5],
    ];

    /**
     * Simulate a battle and return predicted outcome.
     *
     * @param array<string, int> $attackerFleet  machine_name => count
     * @param array{weapons: int, shielding: int, armor: int} $attackerTech
     * @param array<string, int> $defenderFleet  machine_name => count (ships)
     * @param array<string, int> $defenderDefenses  machine_name => count (defense structures)
     * @param array{weapons: int, shielding: int, armor: int} $defenderTech
     * @param int $simCount Number of simulations to average
     * @return array{win_chance: float, estimated_losses_value: int, estimated_loot_ratio: float}
     */
    public function simulate(
        array $attackerFleet,
        array $attackerTech,
        array $defenderFleet,
        array $defenderDefenses,
        array $defenderTech,
        int $simCount = 3
    ): array {
        $wins = 0;
        $totalLossValue = 0;

        for ($i = 0; $i < $simCount; $i++) {
            $result = $this->runSimulation(
                $attackerFleet,
                $attackerTech,
                array_merge($defenderFleet, $defenderDefenses),
                $defenderTech
            );
            if ($result['attacker_wins']) {
                $wins++;
            }
            $totalLossValue += $result['attacker_losses_value'];
        }

        return [
            'win_chance' => $wins / $simCount,
            'estimated_losses_value' => (int)($totalLossValue / $simCount),
        ];
    }

    /**
     * Run a single battle simulation using simplified power-ratio model.
     */
    private function runSimulation(
        array $attackerFleet,
        array $attackerTech,
        array $defenderUnits,
        array $defenderTech
    ): array {
        // Build unit arrays with tech bonuses
        $attackers = $this->buildUnitArray($attackerFleet, $attackerTech);
        $defenders = $this->buildUnitArray($defenderUnits, $defenderTech);

        $initialAttackerValue = $this->calculateFleetValue($attackerFleet);

        // Simulate up to 6 rounds
        for ($round = 0; $round < 6; $round++) {
            if (empty($attackers) || empty($defenders)) {
                break;
            }

            // Apply ±15% random variance to damage
            $varianceFactor = 0.85 + (mt_rand(0, 30) / 100.0);

            // Attackers fire at defenders
            $defenders = $this->applyDamage($attackers, $defenders, $varianceFactor);

            // Defenders fire at attackers
            $varianceFactor = 0.85 + (mt_rand(0, 30) / 100.0);
            $attackers = $this->applyDamage($defenders, $attackers, $varianceFactor);
        }

        $remainingAttackerValue = 0;
        foreach ($attackers as $unit) {
            $remainingAttackerValue += $unit['value'] * $unit['count'];
        }

        $attackerWins = !empty($attackers) && empty($defenders);
        $attackerLossesValue = ($initialAttackerValue - $remainingAttackerValue) * 1000;

        return [
            'attacker_wins' => $attackerWins,
            'attacker_losses_value' => max(0, $attackerLossesValue),
        ];
    }

    /**
     * Build internal unit array with tech bonuses applied.
     */
    private function buildUnitArray(array $fleet, array $tech): array
    {
        $units = [];
        $weaponBonus = 1 + ($tech['weapons'] ?? 0) * 0.10;
        $shieldBonus = 1 + ($tech['shielding'] ?? 0) * 0.10;
        $armorBonus = 1 + ($tech['armor'] ?? 0) * 0.10;

        foreach ($fleet as $name => $count) {
            if ($count <= 0 || !isset(self::UNIT_STATS[$name])) {
                continue;
            }
            $stats = self::UNIT_STATS[$name];
            $units[] = [
                'name' => $name,
                'count' => $count,
                'attack' => $stats['attack'] * $weaponBonus,
                'hull' => $stats['hull'] * $armorBonus,
                'shield' => $stats['shield'] * $shieldBonus,
                'value' => $stats['value'],
            ];
        }

        return $units;
    }

    /**
     * Apply damage from shooters to targets, accounting for rapid fire.
     * Returns surviving target units.
     */
    private function applyDamage(array $shooters, array $targets, float $variance): array
    {
        // Calculate total effective firepower from shooters
        $totalDamage = 0;
        foreach ($shooters as $shooter) {
            $baseDamage = $shooter['attack'] * $shooter['count'] * $variance;

            // Apply rapid-fire bonus: extra effective shots against target types
            $rapidFireBonus = 1.0;
            $rfTable = self::RAPID_FIRE[$shooter['name']] ?? [];
            if (!empty($rfTable)) {
                // Average rapid-fire bonus weighted by target composition
                $totalTargets = 0;
                $weightedRf = 0;
                foreach ($targets as $target) {
                    $rf = $rfTable[$target['name']] ?? 1;
                    $weightedRf += $target['count'] * $rf;
                    $totalTargets += $target['count'];
                }
                if ($totalTargets > 0) {
                    $rapidFireBonus = $weightedRf / $totalTargets;
                }
            }

            $totalDamage += $baseDamage * $rapidFireBonus;
        }

        // Distribute damage proportionally across targets
        $totalTargetHP = 0;
        foreach ($targets as $target) {
            $totalTargetHP += ($target['hull'] + $target['shield']) * $target['count'];
        }

        if ($totalTargetHP <= 0) {
            return [];
        }

        $surviving = [];
        foreach ($targets as $target) {
            $unitHP = $target['hull'] + $target['shield'];
            $groupHP = $unitHP * $target['count'];
            $damageShare = ($groupHP / $totalTargetHP) * $totalDamage;

            // Units destroyed = damage / HP per unit (with hull explosion at 70% damage)
            $effectiveHP = $unitHP * 0.7; // Hull explosion threshold
            $unitsDestroyed = (int)floor($damageShare / $effectiveHP);
            $remaining = $target['count'] - $unitsDestroyed;

            if ($remaining > 0) {
                $target['count'] = $remaining;
                $surviving[] = $target;
            }
        }

        return $surviving;
    }

    /**
     * Calculate total fleet value in kilo-resources.
     */
    private function calculateFleetValue(array $fleet): int
    {
        $value = 0;
        foreach ($fleet as $name => $count) {
            $value += (self::UNIT_STATS[$name]['value'] ?? 1) * $count;
        }
        return $value;
    }

    /**
     * Get the win threshold for a bot personality.
     */
    public static function getWinThreshold(string $personality): float
    {
        return match ($personality) {
            'aggressive', 'raider' => 0.40,
            'balanced', 'explorer' => 0.55,
            'defensive', 'turtle' => 0.70,
            'economic', 'scientist' => 0.65,
            'diplomat' => 0.60,
            default => 0.55,
        };
    }
}
