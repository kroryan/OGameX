<?php

namespace OGame\Services;

/**
 * GameStateAnalyzer - Analyzes the current game state for strategic decision-making.
 */
class GameStateAnalyzer
{
    /**
     * Game phases based on player points.
     */
    private const EARLY_GAME_THRESHOLD = 100000;
    private const MID_GAME_THRESHOLD = 1000000;

    /**
     * In-memory cache of state per bot id within the same request/tick.
     * @var array<int, array>
     */
    private static array $stateCache = [];

    /**
     * Clear the per-tick cache (call at the start of each bot processing cycle).
     */
    public static function clearCache(): void
    {
        self::$stateCache = [];
    }

    /**
     * Analyze the current state of the bot.
     * Results are cached per bot id within the same request to avoid redundant queries.
     */
    public function analyzeCurrentState(BotService $botService): array
    {
        $botId = $botService->getBot()->id;
        if (isset(self::$stateCache[$botId])) {
            return self::$stateCache[$botId];
        }

        $state = $this->doAnalyzeCurrentState($botService);
        self::$stateCache[$botId] = $state;
        return $state;
    }

    /**
     * Perform the actual state analysis.
     */
    private function doAnalyzeCurrentState(BotService $botService): array
    {
        $player = $botService->getPlayer();
        $planets = $player->planets->all();
        $economy = $botService->getBot()->getEconomySettings();
        $minResources = (int) ($economy['min_resources_for_actions'] ?? 500);

        // Calculate total points
        $totalPoints = 0;
        $buildingPoints = 0;
        $fleetPoints = 0;
        $researchPoints = 0;
        $defensePoints = 0;

        $totalProduction = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        $totalResources = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        $storageUsageMax = 0.0;

        // Queue status tracking
        $planetsWithBuildingQueueSpace = 0;
        $planetsWithResearchQueueSpace = 0;
        $totalBuildingQueueSlots = 0;
        $totalResearchQueueSlots = 0;

        foreach ($planets as $planet) {
            $resources = $planet->getResources();
            $totalResources['metal'] += $resources->metal->get();
            $totalResources['crystal'] += $resources->crystal->get();
            $totalResources['deuterium'] += $resources->deuterium->get();
            $storageUsageMax = max($storageUsageMax, $botService->getStorageUsagePercent($planet));

            // Production (approximate from mine levels)
            $totalProduction['metal'] += $this->estimateProduction($planet, 'metal_mine');
            $totalProduction['crystal'] += $this->estimateProduction($planet, 'crystal_mine');
            $totalProduction['deuterium'] += $this->estimateProduction($planet, 'deuterium_synthesizer');

            // Points would need to be calculated from buildings/fleet/research
            $buildingPoints += $this->calculateBuildingPoints($planet);
            $fleetPoints += $this->calculateFleetPoints($planet);
            $defensePoints += $this->calculateDefensePoints($planet);

            // Check queue status
            try {
                $bqService = app(\OGame\Services\BuildingQueueService::class);
                $buildingQueue = $bqService->retrieveQueue($planet);
                $queueCount = count($buildingQueue->queue);
                $totalBuildingQueueSlots += $queueCount;
                if (!$buildingQueue->isQueueFull()) {
                    $planetsWithBuildingQueueSpace++;
                }
            } catch (\Exception $e) {
                // Ignore queue errors
            }

            try {
                $rqService = app(\OGame\Services\ResearchQueueService::class);
                $researchQueue = $rqService->retrieveQueue($planet);
                $queueCount = count($researchQueue->queue);
                $totalResearchQueueSlots += $queueCount;
                if (!$researchQueue->isQueueFull()) {
                    $planetsWithResearchQueueSpace++;
                }
            } catch (\Exception $e) {
                // Ignore queue errors
            }
        }

        $researchPoints = $this->calculateResearchPoints($player);
        $totalPoints = $buildingPoints + $fleetPoints + $researchPoints + $defensePoints;
        $totalResourceSum = $totalResources['metal'] + $totalResources['crystal'] + $totalResources['deuterium'];
        $canAffordBuilding = $botService->canAffordAnyBuilding();
        $canAffordResearch = $botService->canAffordAnyResearch();
        $canAffordUnit = $botService->canAffordAnyUnit();
        $planetCount = count($planets);
        $maxPlanets = $player->getMaxPlanetAmount();
        $resourceImbalance = $this->calculateResourceImbalance($totalResources);
        $fleetSlotsMax = $player->getFleetSlotsMax();
        $fleetSlotsUsed = $player->getFleetSlotsInUse();
        $fleetSlotUsage = $fleetSlotsMax > 0 ? $fleetSlotsUsed / $fleetSlotsMax : 0.0;

        return [
            'total_points' => $totalPoints,
            'building_points' => $buildingPoints,
            'fleet_points' => $fleetPoints,
            'research_points' => $researchPoints,
            'defense_points' => $defensePoints,
            'total_resources' => $totalResources,
            'total_resources_sum' => $totalResourceSum,
            'total_production' => $totalProduction,
            'planet_count' => $planetCount,
            'max_planets' => $maxPlanets,
            'game_phase' => $this->determineGamePhase($totalPoints),
            'min_resources_for_actions' => $minResources,
            'can_afford_build' => $canAffordBuilding,
            'can_afford_fleet' => $canAffordUnit && $totalResourceSum >= $minResources,
            'can_afford_research' => $canAffordResearch,
            'has_significant_fleet' => $fleetPoints > 50000,
            'is_under_threat' => $botService->isUnderThreat(),
            'fleet_slots_available' => $botService->hasFleetSlotsAvailable(),
            'fleet_slots_used' => $fleetSlotsUsed,
            'fleet_slots_max' => $fleetSlotsMax,
            'fleet_slot_usage' => $fleetSlotUsage,
            'is_storage_pressure_high' => $botService->isStoragePressureHigh(),
            'storage_usage_max' => $storageUsageMax,
            'resource_imbalance' => $resourceImbalance,
            'can_colonize' => $botService->shouldColonize(),
            // Queue status
            'planets_with_building_space' => $planetsWithBuildingQueueSpace,
            'planets_with_research_space' => $planetsWithResearchQueueSpace,
            'all_building_queues_full' => $planetsWithBuildingQueueSpace === 0,
            'all_research_queues_full' => $planetsWithResearchQueueSpace === 0,
            'total_building_queue_usage' => $totalBuildingQueueSlots,
            'total_research_queue_usage' => $totalResearchQueueSlots,
        ];
    }

    /**
     * Determine the game phase based on points.
     */
    public function determineGamePhase(int $points): string
    {
        if ($points < self::EARLY_GAME_THRESHOLD) {
            return 'early';
        } elseif ($points < self::MID_GAME_THRESHOLD) {
            return 'mid';
        } else {
            return 'late';
        }
    }

    private function calculateResourceImbalance(array $resources): float
    {
        $total = $resources['metal'] + $resources['crystal'] + $resources['deuterium'];
        if ($total <= 0) {
            return 0.0;
        }
        $avg = $total / 3;
        $maxDiff = max(
            abs($resources['metal'] - $avg),
            abs($resources['crystal'] - $avg),
            abs($resources['deuterium'] - $avg)
        );
        return $maxDiff / $avg;
    }

    /**
     * Estimate production from a mine.
     */
    private function estimateProduction(PlanetService $planet, string $machineName): int
    {
        $level = $planet->getObjectLevel($machineName);
        if ($level <= 0) {
            return 0;
        }

        // Simplified production formula
        return match ($machineName) {
            'metal_mine' => (int)(30 * $level * pow(1.1, $level)),
            'crystal_mine' => (int)(20 * $level * pow(1.1, $level)),
            'deuterium_synthesizer' => (int)(10 * $level * pow(1.1, $level) * 0.7), // Average energy factor
            default => 0,
        };
    }

    /**
     * Calculate building points for a planet.
     */
    private function calculateBuildingPoints(PlanetService $planet): int
    {
        // Simplified: sum of building levels
        $buildings = [
            'metal_mine', 'crystal_mine', 'deuterium_synthesizer',
            'metal_store', 'crystal_store', 'deuterium_store',
            'robot_factory', 'shipyard', 'research_lab',
            'solar_plant', 'fusion_plant',
        ];

        $points = 0;
        foreach ($buildings as $building) {
            try {
                $level = $planet->getObjectLevel($building);
                $points += $level * $level; // Each level costs ~level^2 resources
            } catch (\Exception $e) {
                // Building doesn't exist, skip it
                continue;
            }
        }

        return $points;
    }

    /**
     * Calculate fleet points for a planet.
     */
    private function calculateFleetPoints(PlanetService $planet): int
    {
        try {
            $points = 0;

            // Get ship units
            $ships = $planet->getShipUnits();
            if ($ships && !empty($ships->units)) {
                foreach ($ships->units as $unitObj) {
                    $unitPoints = $this->getUnitPoints($unitObj->unitObject->machine_name);
                    $points += $unitPoints * $unitObj->amount;
                }
            }

            return $points;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get points value for a unit.
     */
    private function getUnitPoints(string $machineName): int
    {
        return match ($machineName) {
            // Ships
            'light_fighter' => 4,
            'heavy_fighter' => 10,
            'cruiser' => 29,
            'battle_ship' => 60,
            'battlecruiser' => 70,
            'bomber' => 90,
            'destroyer' => 125,
            'deathstar' => 10000,
            'small_cargo' => 4,
            'large_cargo' => 12,
            'colony_ship' => 40,
            'recycler' => 18,
            'espionage_probe' => 1,
            'solar_satellite' => 1,
            // Defense
            'rocket_launcher' => 2,
            'light_laser' => 2,
            'heavy_laser' => 8,
            'gauss_cannon' => 37,
            'ion_cannon' => 8,
            'plasma_turret' => 130,
            'small_shield_dome' => 20,
            'large_shield_dome' => 100,
            default => 1,
        };
    }

    /**
     * Calculate defense points for a planet.
     */
    private function calculateDefensePoints(PlanetService $planet): int
    {
        $defenses = [
            'rocket_launcher', 'light_laser', 'heavy_laser',
            'gauss_cannon', 'ion_cannon', 'plasma_turret',
            'small_shield_dome', 'large_shield_dome',
        ];

        $points = 0;
        foreach ($defenses as $defense) {
            $level = $planet->getObjectLevel($defense);
            $unitPoints = $this->getUnitPoints($defense);
            $points += $unitPoints * $level;
        }

        return $points;
    }

    /**
     * Calculate research points for a player.
     */
    private function calculateResearchPoints(PlayerService $player): int
    {
        $techs = [
            'espionage_technology', 'computer_technology', 'weapon_technology',
            'shielding_technology', 'armor_technology', 'energy_technology',
            'hyperspace_technology', 'combustion_drive', 'impulse_drive',
            'hyperspace_drive', 'laser_technology', 'ion_technology',
            'plasma_technology', 'intergalactic_research_network',
            'astrophysics', 'graviton_technology',
        ];

        $points = 0;
        foreach ($techs as $tech) {
            try {
                $level = $player->getResearchLevel($tech);
                $points += $level * $level;
            } catch (\Exception $e) {
                // Technology doesn't exist, skip it
                continue;
            }
        }

        return $points;
    }
}
