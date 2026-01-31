<?php

namespace OGame\Services;

use Exception;
use OGame\Enums\BotActionType;
use OGame\Enums\BotPersonality;
use OGame\Enums\BotTargetType;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Bot;
use OGame\Models\BotLog;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\ObjectServiceFactory;
use OGame\Factories\PlanetServiceFactory;

/**
 * BotService - Handles playerbot actions and decisions with enhanced logic.
 */
class BotService
{
    private Bot $bot;
    private PlayerService $player;

    public function __construct(Bot $bot, PlayerService $player)
    {
        $this->bot = $bot;
        $this->player = $player;
    }

    /**
     * Get the bot model.
     */
    public function getBot(): Bot
    {
        return $this->bot;
    }

    /**
     * Get the player service.
     */
    public function getPlayer(): PlayerService
    {
        return $this->player;
    }

    /**
     * Get the bot personality.
     */
    public function getPersonality(): BotPersonality
    {
        return $this->bot->getPersonalityEnum();
    }

    /**
     * Get the bot target type.
     */
    public function getTargetType(): BotTargetType
    {
        return $this->bot->getTargetTypeEnum();
    }

    /**
     * Check if bot is currently active (considering schedule).
     */
    public function isActive(): bool
    {
        return $this->bot->isActive();
    }

    /**
     * Check if bot can attack (not in cooldown).
     */
    public function canAttack(): bool
    {
        return $this->bot->canAttack();
    }

    /**
     * Check if bot can send another fleet mission (bot cap + player slot cap).
     */
    public function hasFleetSlotsAvailable(): bool
    {
        $botCap = $this->bot->max_fleets_sent ?? config('bots.max_fleets_per_bot', 3);
        $playerCap = $this->player->getFleetSlotsMax();
        $limit = min($botCap, $playerCap);

        return $this->player->getFleetSlotsInUse() < $limit;
    }

    /**
     * Check if bot should skip action based on behavior flags.
     */
    public function shouldSkipAction(string $actionType): bool
    {
        return $this->bot->shouldSkipAction($actionType);
    }

    /**
     * Get a random planet from the bot's planets.
     */
    public function getRandomPlanet(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return null;
        }

        $randomKey = array_rand($planets);
        return $planets[$randomKey];
    }

    /**
     * Get the planet with most resources.
     */
    public function getRichestPlanet(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return null;
        }

        $richest = null;
        $maxResources = 0;

        foreach ($planets as $planet) {
            $resources = $planet->getResources();
            $total = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
            if ($total > $maxResources) {
                $maxResources = $total;
                $richest = $planet;
            }
        }

        return $richest;
    }

    /**
     * Get the planet with lowest storage (for spending resources).
     */
    public function getLowestStoragePlanet(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return $this->getRichestPlanet();
        }

        $lowest = null;
        $minStorage = PHP_INT_MAX;

        foreach ($planets as $planet) {
            $resources = $planet->getResources();
            $metalMax = $planet->metalStorage()->get();
            $crystalMax = $planet->crystalStorage()->get();
            $deuteriumMax = $planet->deuteriumStorage()->get();
            $maxStorage = $metalMax + $crystalMax + $deuteriumMax;

            if ($maxStorage <= 0) {
                continue; // Skip invalid storage
            }

            $currentTotal = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
            $usagePercent = $currentTotal / $maxStorage;

            if ($usagePercent < $minStorage && $usagePercent < 0.95) { // Not too full
                $minStorage = $usagePercent;
                $lowest = $planet;
            }
        }

        return $lowest ?? $this->getRichestPlanet();
    }

    /**
     * Check if bot can afford a build cost.
     */
    public function canAffordBuild(int $metal, int $crystal, int $deuterium): bool
    {
        $economy = $this->bot->getEconomySettings();
        $planet = $this->getRichestPlanet();
        if ($planet === null) {
            return false;
        }

        $resources = $planet->getResources();
        return $resources->metal->get() >= $metal &&
               $resources->crystal->get() >= $crystal &&
               $resources->deuterium->get() >= $deuterium;
    }

    /**
     * Check if bot has enough resources to perform actions.
     */
    public function hasMinimumResources(): bool
    {
        $economy = $this->bot->getEconomySettings();
        $planet = $this->getRichestPlanet();
        if ($planet === null) {
            return false;
        }

        $resources = $planet->getResources();
        $total = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
        return $total >= $economy['min_resources_for_actions'];
    }

    /**
     * Check if bot can afford at least one building.
     */
    public function canAffordAnyBuilding(bool $ignoreReserve = false): bool
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return false;
        }

        foreach ($planets as $planet) {
            if ($this->isBuildingQueueFull($planet)) {
                continue;
            }

            $budget = $this->getSpendableBudget($planet, $ignoreReserve);
            if ($budget <= 0) {
                continue;
            }

            $buildings = ObjectService::getBuildingObjects();
            foreach ($buildings as $building) {
                $currentLevel = $planet->getObjectLevel($building->machine_name);
                if ($currentLevel >= config('bots.max_building_level', 30)) {
                    continue;
                }

                if (!ObjectService::objectRequirementsMet($building->machine_name, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($building->machine_name, $planet);
                $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
                if ($cost <= $budget) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if bot can afford at least one research.
     */
    public function canAffordAnyResearch(bool $ignoreReserve = false): bool
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return false;
        }

        foreach ($planets as $planet) {
            if ($this->isResearchQueueFull($planet)) {
                continue;
            }

            if ($planet->getObjectLevel('research_lab') < 1) {
                continue;
            }

            $budget = $this->getSpendableBudget($planet, $ignoreReserve);
            if ($budget <= 0) {
                continue;
            }

            $research = ObjectService::getResearchObjects();
            foreach ($research as $tech) {
                $currentLevel = $this->player->getResearchLevel($tech->machine_name);
                if ($currentLevel >= config('bots.max_research_level', 10)) {
                    continue;
                }

                if (!ObjectService::objectRequirementsMet($tech->machine_name, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($tech->machine_name, $planet);
                $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
                if ($cost <= $budget) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if bot can afford at least one unit.
     */
    public function canAffordAnyUnit(bool $ignoreReserve = false): bool
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return false;
        }

        foreach ($planets as $planet) {
            if ($this->isUnitQueueFull($planet)) {
                continue;
            }

            $budget = $this->getSpendableBudget($planet, $ignoreReserve);
            if ($budget <= 0) {
                continue;
            }

            $units = ObjectService::getUnitObjects();
            foreach ($units as $unit) {
                if (!$this->shouldConsiderUnitForPersonality($unit->machine_name)) {
                    continue;
                }

                if (!ObjectService::objectRequirementsMet($unit->machine_name, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($unit->machine_name, $planet);
                $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
                if ($cost <= $budget) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getSpendableBudget(PlanetService $planet, bool $ignoreReserve = false): float
    {
        $economy = $this->bot->getEconomySettings();
        $resources = $planet->getResources();
        $total = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
        if ($ignoreReserve) {
            return $total;
        }
        $reserve = (float) ($economy['save_for_upgrade_percent'] ?? 0.3);
        $maxStorageBeforeSpending = (float) ($economy['max_storage_before_spending'] ?? 0.9);
        $usagePercent = $this->getStorageUsagePercent($planet);
        if ($usagePercent < $maxStorageBeforeSpending) {
            // Keep more resources when storage isn't pressured
            $reserve = min(0.9, max($reserve, 0.6));
        }

        return $total * max(0, 1 - $reserve);
    }

    public function getStorageUsagePercent(PlanetService $planet): float
    {
        $resources = $planet->getResources();
        $metalMax = $planet->metalStorage()->get();
        $crystalMax = $planet->crystalStorage()->get();
        $deuteriumMax = $planet->deuteriumStorage()->get();
        $maxStorage = $metalMax + $crystalMax + $deuteriumMax;
        if ($maxStorage <= 0) {
            return 0.0;
        }
        $currentTotal = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
        return $currentTotal / $maxStorage;
    }

    public function isStoragePressureHigh(): bool
    {
        $planet = $this->getRichestPlanet();
        if ($planet === null) {
            return false;
        }
        $economy = $this->bot->getEconomySettings();
        $maxStorageBeforeSpending = (float) ($economy['max_storage_before_spending'] ?? 0.9);
        return $this->getStorageUsagePercent($planet) >= $maxStorageBeforeSpending;
    }

    private function shouldConsiderUnitForPersonality(string $machineName): bool
    {
        if ($machineName === 'colony_ship' && $this->shouldColonize()) {
            return true;
        }

        if ($this->getPersonality() === BotPersonality::AGGRESSIVE) {
            if (in_array($machineName, ['colony_ship', 'recycler', 'solar_satellite', 'crawler'])) {
                return false;
            }
        } elseif ($this->getPersonality() === BotPersonality::ECONOMIC) {
            if (in_array($machineName, ['colony_ship', 'recycler'])) {
                return false;
            }
        }

        return true;
    }

    public function shouldColonize(): bool
    {
        $maxPlanets = $this->player->getMaxPlanetAmount();
        $behavior = $this->bot->behavior_flags ?? [];
        if (!empty($behavior['max_planets_to_colonize'])) {
            $maxPlanets = min($maxPlanets, (int) $behavior['max_planets_to_colonize']);
        }

        return count($this->player->planets->all()) < $maxPlanets;
    }

    public function sendColonization(): bool
    {
        if (!$this->shouldColonize()) {
            return false;
        }
        if (!$this->hasFleetSlotsAvailable()) {
            $this->logAction(BotActionType::FLEET, 'No fleet slots available for colonization', [], 'failed');
            return false;
        }

        $source = $this->getRichestPlanet();
        if ($source === null) {
            $this->logAction(BotActionType::FLEET, 'No source planet available for colonization', [], 'failed');
            return false;
        }

        $colonyShips = $source->getObjectAmount('colony_ship');
        if ($colonyShips < 1) {
            $this->logAction(BotActionType::FLEET, 'No colony ship available', [], 'failed');
            return false;
        }

        $targetCoords = $this->findColonizationTarget($source);
        if ($targetCoords === null) {
            $this->logAction(BotActionType::FLEET, 'No colonization target found', [], 'failed');
            return false;
        }

        $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName('colony_ship'), 1);

        $availableSmall = $source->getObjectAmount('small_cargo');
        $availableLarge = $source->getObjectAmount('large_cargo');
        if ($availableLarge > 0) {
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('large_cargo'), min(5, $availableLarge));
        } elseif ($availableSmall > 0) {
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), min(10, $availableSmall));
        }

        $fleetMissionService = app(FleetMissionService::class);
        $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 7, 100);
        if ($source->getResources()->deuterium->get() < $consumption) {
            $this->logAction(BotActionType::FLEET, 'Not enough deuterium for colonization', [
                'required' => $consumption,
                'available' => $source->getResources()->deuterium->get(),
            ], 'failed');
            return false;
        }

        $resourcesToSend = new Resources(0, 0, 0, 0);
        $cargoCapacity = $fleet->getTotalCargoCapacity($this->player);
        if ($cargoCapacity > 0) {
            $available = $source->getResources();
            $sendTotal = min((int)($cargoCapacity * 0.7), (int)($available->metal->get() + $available->crystal->get() + $available->deuterium->get()));
            if ($sendTotal > 0) {
                $split = (int)($sendTotal / 3);
                $resourcesToSend = new Resources($split, $split, $sendTotal - ($split * 2), 0);
            }
        }

        $fleetMissionService->createNewFromPlanet(
            $source,
            $targetCoords,
            \OGame\Models\Enums\PlanetType::Planet,
            7,
            $fleet,
            $resourcesToSend,
            100,
            0
        );

        $this->logAction(BotActionType::FLEET, "Sent colonization to {$targetCoords->asString()}", [
            'consumption' => $consumption,
            'resources' => [
                'metal' => $resourcesToSend->metal->get(),
                'crystal' => $resourcesToSend->crystal->get(),
                'deuterium' => $resourcesToSend->deuterium->get(),
            ],
        ]);

        $this->bot->updateLastAction();
        return true;
    }

    private function findColonizationTarget(PlanetService $source): ?\OGame\Models\Planet\Coordinate
    {
        $coords = $source->getPlanetCoordinates();
        $galaxy = $coords->galaxy;
        $maxSystems = \OGame\GameConstants\UniverseConstants::MAX_SYSTEM_COUNT;

        $attempts = 0;
        while ($attempts < 40) {
            $systemOffset = rand(-50, 50);
            $system = $coords->system + $systemOffset;
            if ($system < 1 || $system > $maxSystems) {
                $system = rand(1, $maxSystems);
            }

            $position = rand(4, 12);
            if (!$this->player->canColonizePosition($position)) {
                $attempts++;
                continue;
            }

            $exists = Planet::where('galaxy', $galaxy)
                ->where('system', $system)
                ->where('planet', $position)
                ->where('destroyed', 0)
                ->exists();

            if (!$exists) {
                return new \OGame\Models\Planet\Coordinate($galaxy, $system, $position);
            }

            $attempts++;
        }

        return null;
    }

    /**
     * Log an action for this bot.
     */
    public function logAction(BotActionType $action, string $description, array $resourcesSpent = [], string $result = 'success'): void
    {
        BotLog::create([
            'bot_id' => $this->bot->id,
            'action_type' => $action->value,
            'action_description' => $description,
            'resources_spended' => !empty($resourcesSpent) ? $resourcesSpent : null,
            'result' => $result,
        ]);
    }

    /**
     * Check if building queue is full for a planet.
     */
    public function isBuildingQueueFull(PlanetService $planet): bool
    {
        $queueService = app(BuildingQueueService::class);
        $queue = $queueService->retrieveQueue($planet);
        return $queue->isQueueFull();
    }

    /**
     * Check if research queue is full for a planet.
     */
    public function isResearchQueueFull(PlanetService $planet): bool
    {
        $queueService = app(ResearchQueueService::class);
        $queue = $queueService->retrieveQueue($planet);
        return $queue->isQueueFull();
    }

    /**
     * Check if unit queue is full for a planet.
     */
    public function isUnitQueueFull(PlanetService $planet): bool
    {
        $queueService = app(UnitQueueService::class);
        $queue = $queueService->retrieveQueue($planet);
        return $queue->isQueueFull();
    }

    /**
     * Find a planet with available building queue space.
     */
    public function findPlanetWithBuildingQueueSpace(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        foreach ($planets as $planet) {
            if (!$this->isBuildingQueueFull($planet)) {
                return $planet;
            }
        }
        return null;
    }

    /**
     * Find a planet with available research queue space.
     */
    public function findPlanetWithResearchQueueSpace(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        foreach ($planets as $planet) {
            if (!$this->isResearchQueueFull($planet)) {
                return $planet;
            }
        }
        return null;
    }

    /**
     * Build a random structure on a smart planet selection.
     */
    public function buildRandomStructure(): bool
    {
        try {
            // Smart planet selection: use planet with lowest storage AND available queue space
            $planet = $this->getLowestStoragePlanet();

            // If that planet has full queue, find another with space
            if ($planet !== null && $this->isBuildingQueueFull($planet)) {
                $planet = $this->findPlanetWithBuildingQueueSpace();
            }

            if ($planet === null) {
                $this->logAction(BotActionType::BUILD, 'No planets available or all building queues full', [], 'failed');
                return false;
            }

            // Get buildable buildings with smart prioritization
            $buildings = ObjectService::getBuildingObjects();
            $affordableBuildings = [];

            foreach ($buildings as $building) {
                $currentLevel = $planet->getObjectLevel($building->machine_name);

                // Skip very high levels
                if ($currentLevel >= config('bots.max_building_level', 30)) {
                    continue;
                }

                // Skip if we can't afford it
                $price = ObjectService::getObjectPrice($building->machine_name, $planet);
                $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();

                $economy = $this->bot->getEconomySettings();
                $resources = $planet->getResources();
                $maxToSpend = ($resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get())
                             * (1 - $economy['save_for_upgrade_percent']);

                if ($cost > $maxToSpend) {
                    continue;
                }

                // Prioritize: production buildings > storage > others
                $priority = $this->getBuildingPriority($building->machine_name, $planet, $currentLevel);
                $score = $priority * 1000 - $cost;

                $affordableBuildings[] = [
                    'building' => $building,
                    'score' => $score,
                    'cost' => $cost,
                ];
            }

            if (empty($affordableBuildings)) {
                $this->logAction(BotActionType::BUILD, 'No affordable buildings', [], 'failed');
                return false;
            }

            // Sort by score (highest priority/lowest cost)
            usort($affordableBuildings, fn($a, $b) => $b['score'] <=> $a['score']);

            // Pick from top 3 buildings (some randomness)
            $topBuildings = array_slice($affordableBuildings, 0, min(3, count($affordableBuildings)));
            $building = $topBuildings[array_rand($topBuildings)]['building'];

            // Build it
            $queueService = app(BuildingQueueService::class);
            $queueService->add($planet, $building->id);

            $price = ObjectService::getObjectPrice($building->machine_name, $planet);
            $this->logAction(BotActionType::BUILD, "Built {$building->machine_name} (level {$planet->getObjectLevel($building->machine_name)}) on {$planet->getPlanetName()}", [
                'metal' => $price->metal->get(),
                'crystal' => $price->crystal->get(),
                'deuterium' => $price->deuterium->get(),
            ]);

            $this->bot->updateLastAction();
            return true;

        } catch (Exception $e) {
            $this->logAction(BotActionType::BUILD, "Failed to build: {$e->getMessage()}", [], 'failed');
            return false;
        }
    }

    /**
     * Get building priority score based on bot personality and current state.
     * Enhanced with game phase logic and critical building detection.
     */
    private function getBuildingPriority(string $machineName, PlanetService $planet, int $currentLevel): int
    {
        $economy = $this->bot->getEconomySettings();
        $personality = $this->bot->getPersonality();
        $price = ObjectService::getObjectPrice($machineName, $planet);
        $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
        $prioritizeProduction = $economy['prioritize_production'] ?? 'balanced';

        // Get game phase
        $analyzer = new \OGame\Services\GameStateAnalyzer();
        $state = $analyzer->analyzeCurrentState($this);
        $phase = $state['game_phase'];

        // CRITICAL buildings for progression
        $criticalBuildings = [
            'metal_mine', 'crystal_mine', 'deuterium_synthesizer', // Production
            'solar_plant', 'fusion_plant', // Energy
            'metal_store', 'crystal_store', 'deuterium_store', // Storage
            'robot_factory', 'shipyard', 'research_lab', // Facilities
        ];

        // Base priorities with phase awareness
        $priorities = [
            // TIER 1: Core production (highest priority early game)
            'metal_mine' => 140,
            'crystal_mine' => 135,
            'deuterium_synthesizer' => 130,
            'solar_plant' => 125,
            'fusion_reactor' => 120,

            // TIER 2: Storage and facilities (essential for growth)
            'metal_store' => 110,
            'crystal_store' => 110,
            'deuterium_store' => 110,
            'robot_factory' => 100,
            'shipyard' => 95,
            'research_lab' => 90,

            // TIER 3: Advanced production (mid game)
            'nano_factory' => 85,

            // TIER 4: Specialized buildings
            'missile_silo' => 70,
            'sensor_phalanx' => 65,
            'jump_gate' => 60,
            'lunar_base' => 50,
            'terraformer' => 75,
            'space_dock' => 70,
        ];

        $base = $priorities[$machineName] ?? 50;

        // Phase-specific bonuses
        if ($phase === 'early') {
            // Early game: prioritize mines and energy
            if (in_array($machineName, ['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'solar_plant'])) {
                $base += 30;
            }
            // Storage is crucial early
            if (in_array($machineName, ['metal_store', 'crystal_store', 'deuterium_store']) && $currentLevel < 10) {
                $base += 25;
            }
        } elseif ($phase === 'mid') {
            // Mid game: boost nanite and facilities
            if ($machineName === 'nano_factory') {
                $base += 40;
            }
            if (in_array($machineName, ['robot_factory', 'shipyard', 'research_lab']) && $currentLevel < 10) {
                $base += 20;
            }
        } elseif ($phase === 'late') {
            // Late game: focus on advanced buildings
            if ($machineName === 'space_dock') {
                $base += 25;
            }
        }

        // Personality-based modifiers
        if ($personality === BotPersonality::ECONOMIC) {
            // Economic bots LOVE mines and production
            if (in_array($machineName, ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'])) {
                $base += 30;
            }
            if (in_array($machineName, ['nano_factory', 'robot_factory', 'fusion_plant'])) {
                $base += 20;
            }
        } elseif ($personality === BotPersonality::AGGRESSIVE) {
            // Aggressive bots prioritize fleet production facilities
            if (in_array($machineName, ['robot_factory', 'shipyard', 'nano_factory'])) {
                $base += 30;
            }
        } elseif ($personality === BotPersonality::DEFENSIVE) {
            // Defensive bots prioritize storage and defenses
            if (in_array($machineName, ['metal_store', 'crystal_store', 'deuterium_store'])) {
                $base += 30;
            }
            if (in_array($machineName, ['missile_silo'])) {
                $base += 35;
            }
        }

        if ($prioritizeProduction === 'metal' && $machineName === 'metal_mine') {
            $base += 25;
        } elseif ($prioritizeProduction === 'crystal' && $machineName === 'crystal_mine') {
            $base += 25;
        } elseif ($prioritizeProduction === 'deuterium' && $machineName === 'deuterium_synthesizer') {
            $base += 25;
        }

        // Level curve: prioritize lower levels for faster growth
        if ($currentLevel < 5) {
            $base += (5 - $currentLevel) * 8; // +40 for level 0, +32 for level 1, etc.
        } elseif ($currentLevel >= 20) {
            $base -= ($currentLevel - 20) * 3; // Reduce priority for very high levels
        }

        // Storage urgency: if storage is nearly full, prioritize spending
        if (in_array($machineName, ['metal_store', 'crystal_store', 'deuterium_store'])) {
            $resources = $planet->getResources();
            $metalMax = $planet->metalStorage()->get();
            if ($metalMax > 0 && $resources->metal->get() / $metalMax > 0.9) {
                $base += 50; // Urgent!
            }
        }

        // ROI: prefer upgrades with fast payback
        $productionGain = $this->estimateProductionGain($machineName, $planet, $currentLevel);
        if ($productionGain > 0 && $cost > 0) {
            $dailyGain = $productionGain * 24;
            $roiScore = (int)min(40, ($dailyGain / $cost) * 200);
            $base += $roiScore;
        }

        return max(10, min(200, $base));
    }

    private function estimateProductionGain(string $machineName, PlanetService $planet, int $currentLevel): int
    {
        $levelNow = $currentLevel;
        $levelNext = $currentLevel + 1;

        return match ($machineName) {
            'metal_mine' => $this->estimateMineProduction('metal_mine', $levelNext)
                - $this->estimateMineProduction('metal_mine', $levelNow),
            'crystal_mine' => $this->estimateMineProduction('crystal_mine', $levelNext)
                - $this->estimateMineProduction('crystal_mine', $levelNow),
            'deuterium_synthesizer' => $this->estimateMineProduction('deuterium_synthesizer', $levelNext)
                - $this->estimateMineProduction('deuterium_synthesizer', $levelNow),
            default => 0,
        };
    }

    private function estimateMineProduction(string $machineName, int $level): int
    {
        if ($level <= 0) {
            return 0;
        }

        return match ($machineName) {
            'metal_mine' => (int)(30 * $level * pow(1.1, $level)),
            'crystal_mine' => (int)(20 * $level * pow(1.1, $level)),
            'deuterium_synthesizer' => (int)(10 * $level * pow(1.1, $level) * 0.7),
            default => 0,
        };
    }

    /**
     * Build random units with smart composition.
     */
    public function buildRandomUnit(): bool
    {
        try {
            $planet = $this->findPlanetForUnitBuild();
            if ($planet === null) {
                $this->logAction(BotActionType::FLEET, 'No planets available', [], 'failed');
                return false;
            }

            // Get fleet settings
            $fleetSettings = $this->bot->getFleetSettings();

            // Get buildable units with smart selection
            $units = ObjectService::getUnitObjects();
            $affordableUnits = [];

            foreach ($units as $unit) {
                if (!$this->shouldConsiderUnitForPersonality($unit->machine_name)) {
                    continue;
                }

                if (!ObjectService::objectRequirementsMet($unit->machine_name, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($unit->machine_name, $planet);
                $metalCost = $price->metal->get();
                $crystalCost = $price->crystal->get();
                $deuteriumCost = $price->deuterium->get();

                // Calculate affordable amount
                $resources = $planet->getResources();
                $maxAmount = min(
                    $metalCost > 0 ? (int)($resources->metal->get() / $metalCost) : 999,
                    $crystalCost > 0 ? (int)($resources->crystal->get() / $crystalCost) : 999,
                    $deuteriumCost > 0 ? (int)($resources->deuterium->get() / $deuteriumCost) : 999,
                    100 // Max 100 at once
                );

                if ($maxAmount < 1) {
                    continue;
                }

                $unitScore = $this->getUnitScore($unit->machine_name, $maxAmount);
                $affordableUnits[] = [
                    'unit' => $unit,
                    'amount' => $maxAmount,
                    'score' => $unitScore,
                ];
            }

            if (empty($affordableUnits)) {
                $this->logAction(BotActionType::FLEET, 'No affordable units', [], 'failed');
                return false;
            }

            // Sort by score and pick from top options
            usort($affordableUnits, fn($a, $b) => $b['score'] <=> $a['score']);
            $selected = $affordableUnits[array_rand(array_slice($affordableUnits, 0, min(5, count($affordableUnits))))];

            $unit = $selected['unit'];
            $maxAmount = $selected['amount'];

            // Build units
            $queueService = app(UnitQueueService::class);
            $queueService->add($planet, $unit->id, $maxAmount);

            $totalPrice = ObjectService::getObjectPrice($unit->machine_name, $planet)->multiply($maxAmount);
            $this->logAction(BotActionType::FLEET, "Built {$maxAmount}x {$unit->title} on {$planet->getPlanetName()}", [
                'metal' => $totalPrice->metal->get(),
                'crystal' => $totalPrice->crystal->get(),
                'deuterium' => $totalPrice->deuterium->get(),
            ]);

            $this->bot->updateLastAction();
            return true;

        } catch (Exception $e) {
            $this->logAction(BotActionType::FLEET, "Failed to build units: {$e->getMessage()}", [], 'failed');
            return false;
        }
    }

    private function findPlanetForUnitBuild(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return null;
        }

        $bestPlanet = null;
        $bestBudget = 0.0;
        foreach ($planets as $planet) {
            if ($this->isUnitQueueFull($planet)) {
                continue;
            }

            $budget = $this->getSpendableBudget($planet);
            if ($budget <= 0) {
                continue;
            }

            if (!$this->hasAffordableUnitOnPlanet($planet, $budget)) {
                continue;
            }

            if ($budget > $bestBudget) {
                $bestBudget = $budget;
                $bestPlanet = $planet;
            }
        }

        return $bestPlanet;
    }

    private function hasAffordableUnitOnPlanet(PlanetService $planet, float $budget): bool
    {
        $units = ObjectService::getUnitObjects();
        $resources = $planet->getResources();

        foreach ($units as $unit) {
            if ($this->getPersonality() === BotPersonality::AGGRESSIVE) {
                if (in_array($unit->machine_name, ['colony_ship', 'recycler', 'solar_satellite', 'crawler'])) {
                    continue;
                }
            } elseif ($this->getPersonality() === BotPersonality::ECONOMIC) {
                if (in_array($unit->machine_name, ['colony_ship', 'recycler'])) {
                    continue;
                }
            }

            if (!ObjectService::objectRequirementsMet($unit->machine_name, $planet)) {
                continue;
            }

            $price = ObjectService::getObjectPrice($unit->machine_name, $planet);
            $metalCost = $price->metal->get();
            $crystalCost = $price->crystal->get();
            $deuteriumCost = $price->deuterium->get();
            $cost = $metalCost + $crystalCost + $deuteriumCost;

            if ($cost <= $budget &&
                $resources->metal->get() >= $metalCost &&
                $resources->crystal->get() >= $crystalCost &&
                $resources->deuterium->get() >= $deuteriumCost) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get unit score for bot decision making.
     */
    private function getUnitScore(string $machineName, int $amount): int
    {
        // Unit priorities based on personality
        $personality = $this->getPersonality();
        $fleetSettings = $this->bot->getFleetSettings();

        $basePriorities = [
            'light_fighter' => 80,
            'heavy_fighter' => 85,
            'cruiser' => 90,
            'battle_ship' => 95,
            'battlecruiser' => 100,
            'bomber' => 92,
            'destroyer' => 98,
            'deathstar' => 70,
            'small_cargo' => 60,
            'large_cargo' => 65,
            'colony_ship' => 40,
            'recycler' => 75,
            'espionage_probe' => 50,
        ];

        $base = $basePriorities[$machineName] ?? 50;
        $defenseUnits = [
            'rocket_launcher', 'light_laser', 'heavy_laser',
            'gauss_cannon', 'ion_cannon', 'plasma_turret',
            'small_shield_dome', 'large_shield_dome',
            'anti_ballistic_missile', 'interplanetary_missile',
        ];

        // Personality adjustments
        if ($personality === BotPersonality::AGGRESSIVE) {
            if (in_array($machineName, ['battle_ship', 'battlecruiser', 'destroyer', 'bomber'])) {
                $base += 15;
            }
            if (in_array($machineName, $defenseUnits)) {
                $base -= 10;
            }
        } elseif ($personality === BotPersonality::ECONOMIC) {
            if (in_array($machineName, ['small_cargo', 'large_cargo'])) {
                $base += 15;
            }
            if (in_array($machineName, $defenseUnits)) {
                $base -= 5;
            }
        } elseif ($personality === BotPersonality::DEFENSIVE) {
            if (in_array($machineName, $defenseUnits)) {
                $base += 20;
            }
        }

        if ($machineName === 'colony_ship' && $this->shouldColonize()) {
            $base += 40;
        }

        // Amount bonus (bulk is good)
        $amountBonus = min(20, $amount / 10);

        return $base + $amountBonus;
    }

    public function shouldTradeResources(): bool
    {
        if (!$this->hasFleetSlotsAvailable()) {
            return false;
        }
        $planets = $this->player->planets->all();
        if (count($planets) < 2) {
            return false;
        }

        $richest = $this->getRichestPlanet();
        $lowest = $this->getLowestStoragePlanet();
        if ($richest === null || $lowest === null || $richest->getPlanetId() === $lowest->getPlanetId()) {
            return false;
        }

        $richUsage = $this->getStorageUsagePercent($richest);
        $lowUsage = $this->getStorageUsagePercent($lowest);
        $economy = $this->bot->getEconomySettings();
        $maxStorageBeforeSpending = (float) ($economy['max_storage_before_spending'] ?? 0.9);

        return $richUsage >= $maxStorageBeforeSpending && $lowUsage < 0.6;
    }

    public function sendResourceTransport(): bool
    {
        try {
            if (!$this->hasFleetSlotsAvailable()) {
                $this->logAction(BotActionType::TRADE, 'No fleet slots available for transport', [], 'failed');
                return false;
            }

            $source = $this->getRichestPlanet();
            $target = $this->getLowestStoragePlanet();
            if ($source === null || $target === null || $source->getPlanetId() === $target->getPlanetId()) {
                $this->logAction(BotActionType::TRADE, 'No valid transport target', [], 'failed');
                return false;
            }

            $availableLarge = $source->getObjectAmount('large_cargo');
            $availableSmall = $source->getObjectAmount('small_cargo');
            if ($availableLarge + $availableSmall <= 0) {
                $this->logAction(BotActionType::TRADE, 'No cargo ships available', [], 'failed');
                return false;
            }

            $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
            if ($availableLarge > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName('large_cargo'), min(20, $availableLarge));
            }
            if ($availableSmall > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), min(40, $availableSmall));
            }

            if ($fleet->getAmount() === 0) {
                $this->logAction(BotActionType::TRADE, 'No cargo ships available', [], 'failed');
                return false;
            }

            $fleetMissionService = app(FleetMissionService::class);
            $targetCoords = $target->getPlanetCoordinates();
            $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 3, 100);
            if ($source->getResources()->deuterium->get() < $consumption) {
                $this->logAction(BotActionType::TRADE, 'Not enough deuterium for transport', [
                    'required' => $consumption,
                    'available' => $source->getResources()->deuterium->get(),
                ], 'failed');
                return false;
            }

            $resources = $source->getResources();
            $cargoCapacity = $fleet->getTotalCargoCapacity($this->player);
            if ($cargoCapacity <= 0) {
                $this->logAction(BotActionType::TRADE, 'No cargo capacity', [], 'failed');
                return false;
            }

            $economy = $this->bot->getEconomySettings();
            $reserve = (float) ($economy['save_for_upgrade_percent'] ?? 0.3);
            $sendableMetal = max(0, (int)($resources->metal->get() * (1 - $reserve)));
            $sendableCrystal = max(0, (int)($resources->crystal->get() * (1 - $reserve)));
            $sendableDeut = max(0, (int)($resources->deuterium->get() * (1 - $reserve) - $consumption));

            $sendTotal = min($cargoCapacity, $sendableMetal + $sendableCrystal + $sendableDeut);
            if ($sendTotal <= 0) {
                $this->logAction(BotActionType::TRADE, 'No sendable resources', [], 'failed');
                return false;
            }

            $split = (int)($sendTotal / 3);
            $toSend = new Resources(
                metal: min($sendableMetal, $split),
                crystal: min($sendableCrystal, $split),
                deuterium: min($sendableDeut, $sendTotal - ($split * 2)),
                energy: 0
            );

            $fleetMissionService->createNewFromPlanet(
                $source,
                $targetCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                3,
                $fleet,
                $toSend,
                100,
                0
            );

            $this->logAction(BotActionType::TRADE, "Sent transport to {$targetCoords->asString()}", [
                'metal' => $toSend->metal->get(),
                'crystal' => $toSend->crystal->get(),
                'deuterium' => $toSend->deuterium->get(),
            ]);

            $this->bot->updateLastAction();
            return true;
        } catch (Exception $e) {
            $this->logAction(BotActionType::TRADE, "Failed to transport: {$e->getMessage()}", [], 'failed');
            return false;
        }
    }

    /**
     * Research a random technology with smart prioritization.
     */
    public function researchRandomTech(): bool
    {
        try {
            $planet = $this->getRichestPlanet();

            // If that planet has full queue, find another with space
            if ($planet !== null && $this->isResearchQueueFull($planet)) {
                $planet = $this->findPlanetWithResearchQueueSpace();
            }

            if ($planet === null) {
                $this->logAction(BotActionType::RESEARCH, 'No planets available or all research queues full', [], 'failed');
                return false;
            }

            $researchLabLevel = $planet->getObjectLevel('research_lab');
            if ($researchLabLevel < 1) {
                $this->logAction(BotActionType::RESEARCH, 'No research lab available', [], 'failed');
                return false;
            }

            // Get researchable technologies with smart prioritization
            $research = ObjectService::getResearchObjects();
            $affordableResearch = [];

            foreach ($research as $tech) {
                $currentLevel = $this->player->getResearchLevel($tech->machine_name);
                if ($currentLevel >= config('bots.max_research_level', 10)) {
                    continue;
                }

                if (!ObjectService::objectRequirementsMet($tech->machine_name, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($tech->machine_name, $planet);
                $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();

                $economy = $this->bot->getEconomySettings();
                $resources = $planet->getResources();
                $maxToSpend = ($resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get())
                             * (1 - $economy['save_for_upgrade_percent']);

                if ($cost > $maxToSpend) {
                    continue;
                }

                // Priority: combat tech > production > special
                $priority = $this->getTechPriority($tech->machine_name, $this->getPersonality());
                $score = $priority * 1000 - $cost;

                $affordableResearch[] = [
                    'tech' => $tech,
                    'score' => $score,
                    'cost' => $cost,
                ];
            }

            if (empty($affordableResearch)) {
                $this->logAction(BotActionType::RESEARCH, 'No affordable research', [], 'failed');
                return false;
            }

            // Sort by score
            usort($affordableResearch, fn($a, $b) => $b['score'] <=> $a['score']);

            // Pick from top 3 with slight randomness
            $topResearch = array_slice($affordableResearch, 0, min(3, count($affordableResearch)));
            $tech = $topResearch[array_rand($topResearch)]['tech'];

            // Research it
            $queueService = app(ResearchQueueService::class);
            $queueService->add($this->player, $planet, $tech->id);

            $price = ObjectService::getObjectPrice($tech->machine_name, $planet);
            $this->logAction(BotActionType::RESEARCH, "Researched {$tech->machine_name} (level {$this->player->getResearchLevel($tech->machine_name)}) on {$planet->getPlanetName()}", [
                'metal' => $price->metal->get(),
                'crystal' => $price->crystal->get(),
                'deuterium' => $price->deuterium->get(),
            ]);

            $this->bot->updateLastAction();
            return true;

        } catch (Exception $e) {
            $this->logAction(BotActionType::RESEARCH, "Failed to research: {$e->getMessage()}", [], 'failed');
            return false;
        }
    }

    /**
     * Get technology priority for research decisions.
     * Enhanced with game phase logic and dependencies.
     */
    private function getTechPriority(string $machineName, BotPersonality $personality): int
    {
        // Get current level
        try {
            $currentLevel = $this->player->getResearchLevel($machineName);
        } catch (\Exception $e) {
            $currentLevel = 0;
        }

        // Determine game phase
        $analyzer = new \OGame\Services\GameStateAnalyzer();
        $state = $analyzer->analyzeCurrentState($this);
        $phase = $state['game_phase'];

        // CRITICAL technologies for mid/late game progression
        $criticalTechs = [
            'espionage_technology',  // Needed for spying on targets
            'computer_technology',   // Needed for fleet slots
            'astrophysics', // Needed for colonization
            'hyperspace_technology', // Needed for battleships
        ];

        // High priority base values for critical techs
        $basePriorities = [
            // CRITICAL: Core progression techs
            'espionage_technology' => 150,
            'computer_technology' => 145,
            'astrophysics' => 140,
            'hyperspace_technology' => 135,

            // Combat technologies
            'weapon_technology' => 120,
            'shielding_technology' => 120,
            'armor_technology' => 120,

            // Drive technologies (essential for better ships)
            'combustion_drive' => 100,
            'impulse_drive' => 110,
            'hyperspace_drive' => 115,

            // Energy tech (needed for plasma and other high-end techs)
            'energy_technology' => 105,

            // Defense techs
            'laser_technology' => 90,
            'ion_technology' => 95,
            'plasma_technology' => 100,

            // Special
            'intergalactic_research_network' => 80,
            'graviton_technology' => 70,

        ];

        $base = $basePriorities[$machineName] ?? 70;

        // Early game: boost critical techs even more
        if ($phase === 'early') {
            if (in_array($machineName, $criticalTechs)) {
                $base += 30;
            }
        }

        // Mid game: prioritize astrophysics for colonization
        if ($phase === 'mid') {
            if ($machineName === 'astrophysics') {
                $base += 20;
            }
        }

        // Level curve: prioritize lower levels
        if ($currentLevel < 5) {
            $base += (5 - $currentLevel) * 5; // +25 for level 0, +20 for level 1, etc.
        } elseif ($currentLevel >= 10) {
            $base -= ($currentLevel - 10) * 3; // Reduce priority for high levels
        }

        // Personality modifiers
        if ($personality === BotPersonality::AGGRESSIVE) {
            if (str_starts_with($machineName, 'weapon') ||
                str_starts_with($machineName, 'shield') ||
                str_starts_with($machineName, 'armor')) {
                $base += 20;
            }
            if (in_array($machineName, ['combustion_drive', 'impulse_drive', 'hyperspace_drive'])) {
                $base += 15;
            }
        } elseif ($personality === BotPersonality::DEFENSIVE) {
            if (in_array($machineName, ['shielding_technology', 'armor_technology', 'ion_technology', 'plasma_technology'])) {
                $base += 20;
            }
        } elseif ($personality === BotPersonality::ECONOMIC) {
            if (in_array($machineName, ['energy_technology', 'plasma_technology', 'computer_technology', 'espionage_technology'])) {
                $base += 20;
            }
        } elseif ($personality === BotPersonality::BALANCED) {
            if (in_array($machineName, ['hyperspace_technology', 'computer_technology', 'espionage_technology'])) {
                $base += 15;
            }
        }

        return max(10, min(200, $base));
    }

    /**
     * Send an attack fleet with improved target selection.
     */
    public function sendAttackFleet(?PlanetService $target = null): bool
    {
        if (!$this->canAttack()) {
            $this->logAction(BotActionType::ATTACK, 'Attack on cooldown', [], 'failed');
            return false;
        }
        if (!$this->hasFleetSlotsAvailable()) {
            $this->logAction(BotActionType::ATTACK, 'No fleet slots available', [], 'failed');
            return false;
        }

        try {
            $source = $this->getRichestPlanet();
            if ($source === null) {
                $this->logAction(BotActionType::ATTACK, 'No source planet available', [], 'failed');
                return false;
            }

            $fleetSettings = $this->bot->getFleetSettings();

            // Calculate available fleet points
            $availableUnits = $source->getShipUnits();
            $totalFleetPoints = 0;

            $units = new \OGame\GameObjects\Models\Units\UnitCollection();
            foreach ($availableUnits->units as $unitObj) {
                $unitPoints = $this->getUnitPoints($unitObj->unitObject->machine_name);
                $totalFleetPoints += $unitPoints * $unitObj->amount;
                $units->addUnit($unitObj->unitObject, $unitObj->amount);
            }

            $minFleetSize = $fleetSettings['min_fleet_size_for_attack'] ?? 100;
            if ($totalFleetPoints < $minFleetSize) {
                $this->logAction(BotActionType::ATTACK, 'Fleet too small for attack', [
                    'available_points' => $totalFleetPoints,
                    'required' => $minFleetSize,
                ], 'failed');
                return false;
            }

            // Find target if not provided
            if ($target === null) {
                $target = $this->findProfitableTarget($source);
            }

            if ($target === null) {
                $this->logAction(BotActionType::ATTACK, 'No suitable target found', [], 'failed');
                return false;
            }

            // Build attack fleet based on target
            $fleetBuilder = app(BotFleetBuilderService::class);
            $fleet = $fleetBuilder->buildAttackFleet($this, $target);

            if ($fleet->getAmount() === 0) {
                $this->logAction(BotActionType::ATTACK, 'No fleet available', [], 'failed');
                return false;
            }

            $lootEstimate = $target->getResources()->metal->get()
                + $target->getResources()->crystal->get()
                + $target->getResources()->deuterium->get();
            $cargoCapacity = $fleet->getTotalCargoCapacity($this->player);
            if ($cargoCapacity <= 0 || $lootEstimate < ($cargoCapacity * 0.2)) {
                $this->logAction(BotActionType::ATTACK, 'Target loot too low for fleet capacity', [
                    'loot' => $lootEstimate,
                    'capacity' => $cargoCapacity,
                ], 'failed');
                return false;
            }

            // Calculate consumption
            $fleetMissionService = app(FleetMissionService::class);
            $targetCoords = $target->getPlanetCoordinates();
            $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 0, 100);

            if ($source->getResources()->deuterium->get() < $consumption) {
                $this->logAction(BotActionType::ATTACK, 'Not enough deuterium for attack', [
                    'required' => $consumption,
                    'available' => $source->getResources()->deuterium->get(),
                ], 'failed');
                return false;
            }

            if ($lootEstimate < ($consumption * 2)) {
                $this->logAction(BotActionType::ATTACK, 'Attack not profitable after consumption', [
                    'loot' => $lootEstimate,
                    'consumption' => $consumption,
                ], 'failed');
                return false;
            }

            // Check for defenses on target
            $targetPower = $this->calculateTargetFleetPower($target);
            $attackPower = $this->calculateFleetPower($fleet);

            if ($targetPower > $attackPower * 1.5) {
                $this->logAction(BotActionType::ATTACK, 'Target too strong', [
                    'target_power' => $targetPower,
                    'attack_power' => $attackPower,
                    'target_player' => $target->getPlayer()->username,
                ], 'failed');
                return false;
            }

            // Send the fleet
            $fleetMissionService->createNewFromPlanet(
                $source,
                $targetCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                1, // Attack mission
                $fleet,
                new Resources(0, 0, 0, 0),
                100,
                0
            );

            $this->logAction(BotActionType::ATTACK, "Sent attack to {$targetCoords->asString()} (power: {$attackPower} vs {$targetPower})", [
                'units' => $fleet->getAmount(),
                'consumption' => $consumption,
                'target_player' => $target->getPlayer()->username,
            ]);

            // Set cooldown
            $cooldown = config('bots.default_attack_cooldown_hours', 2);
            $this->bot->setAttackCooldown($cooldown);
            $this->bot->updateLastAction();

            return true;

        } catch (Exception $e) {
            $this->logAction(BotActionType::ATTACK, "Failed to send attack: {$e->getMessage()}", [], 'failed');
            return false;
        }
    }

    /**
     * Send an expedition fleet.
     */
    public function sendExpedition(): bool
    {
        try {
            if (!$this->hasFleetSlotsAvailable()) {
                $this->logAction(BotActionType::FLEET, 'No fleet slots available for expedition', [], 'failed');
                return false;
            }

            $source = $this->getRichestPlanet();
            if ($source === null) {
                $this->logAction(BotActionType::FLEET, 'No planet available for expedition', [], 'failed');
                return false;
            }

            $fleetSettings = $this->bot->getFleetSettings();
            $expeditionPercentage = $fleetSettings['expedition_fleet_percentage'] ?? 0.3;

            // Build expedition fleet
            $fleetBuilder = app(BotFleetBuilderService::class);
            $fleet = $fleetBuilder->buildExpeditionFleet($this, $expeditionPercentage);

            if ($fleet->getAmount() === 0) {
                $this->logAction(BotActionType::FLEET, 'No fleet available for expedition', [], 'failed');
                return false;
            }

            // Get expedition coordinates (position 16 in current system)
            $coords = $source->getPlanetCoordinates();
            $expeditionCoords = new \OGame\Models\Planet\Coordinate($coords->galaxy, $coords->system, 16);

            // Calculate consumption
            $fleetMissionService = app(FleetMissionService::class);
            $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $expeditionCoords, 1, 100);

            if ($source->getResources()->deuterium->get() < $consumption) {
                $this->logAction(BotActionType::FLEET, 'Not enough deuterium for expedition', [
                    'required' => $consumption,
                    'available' => $source->getResources()->deuterium->get(),
                ], 'failed');
                return false;
            }

            // Send the expedition
            $fleetMissionService->createNewFromPlanet(
                $source,
                $expeditionCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                15, // Expedition mission
                $fleet,
                new Resources(0, 0, 0, 0),
                100,
                1 // 1 hour holding time
            );

            $this->logAction(BotActionType::FLEET, "Sent expedition to {$expeditionCoords->asString()}", [
                'units' => $fleet->getAmount(),
                'consumption' => $consumption,
            ]);

            $this->bot->updateLastAction();
            return true;

        } catch (Exception $e) {
            $this->logAction(BotActionType::FLEET, "Failed to send expedition: {$e->getMessage()}", [], 'failed');
            return false;
        }
    }

    /**
     * Find a profitable target for attack.
     */
    private function findProfitableTarget(PlanetService $source): ?PlanetService
    {
        $targetFinder = app(BotTargetFinderService::class);
        $targetType = $this->bot->getTargetTypeEnum();

        // Get candidate targets from target finder
        $candidate = $targetFinder->findTarget($this, $targetType);

        if ($candidate === null) {
            return null;
        }

        // Calculate profitability
        $sourceResources = $source->getResources();
        $targetResources = $candidate->getResources();
        $loot = $targetResources->metal->get() + $targetResources->crystal->get() + $targetResources->deuterium->get();

        if ($loot < 10000) {
            $this->logAction(BotActionType::ATTACK, 'Target not profitable enough', [
                'target_loot' => $loot,
            ], 'failed');
            return null;
        }

        // Check fleet strength comparison
        $targetPower = $this->calculateTargetFleetPower($candidate);
        $sourcePower = $this->calculateFleetPower($source);

        if ($targetPower > $sourcePower * 2) {
            $this->logAction(BotActionType::ATTACK, 'Target too strong', [
                'target_power' => $targetPower,
                'source_power' => $sourcePower,
            ], 'failed');
            return null;
        }

        return $candidate;
    }

    /**
     * Calculate fleet power for a planet (defenses).
     */
    private function calculateTargetFleetPower(PlanetService $planet): int
    {
        $totalPower = 0;
        $units = $planet->getShipUnits();

        foreach ($units->units as $unitObj) {
            $points = $this->getUnitPoints($unitObj->unitObject->machine_name);
            $totalPower += $points * $unitObj->amount;
        }

        // Add defense power
        $defensePoints = 0;
        $defenses = [
            'rocket_launcher' => 2,
            'light_laser' => 2,
            'heavy_laser' => 4,
            'gauss_cannon' => 10,
            'ion_cannon' => 10,
            'plasma_turret' => 15,
            'small_shield_dome' => 2,
            'large_shield_dome' => 6,
        ];

        foreach ($defenses as $defense => $points) {
            $level = $planet->getObjectLevel($defense);
            if ($level > 0) {
                $defensePoints += $points * $level;
            }
        }

        return $totalPower + $defensePoints;
    }

    /**
     * Calculate fleet power for a fleet.
     */
    private function calculateFleetPower($fleet): int
    {
        if ($fleet instanceof PlanetService) {
            $fleet = $fleet->getShipUnits();
        }
        if (!is_object($fleet) || !property_exists($fleet, 'units')) {
            return 0;
        }

        $totalPower = 0;
        foreach ($fleet->units as $unitObj) {
            $points = $this->getUnitPoints($unitObj->unitObject->machine_name);
            $totalPower += $points * $unitObj->amount;
        }
        return $totalPower;
    }

    /**
     * Get unit points for fleet power calculation.
     */
    private function getUnitPoints(string $machineName): int
    {
        $points = [
            'light_fighter' => 3,
            'heavy_fighter' => 6,
            'cruiser' => 10,
            'battle_ship' => 30,
            'battlecruiser' => 40,
            'bomber' => 35,
            'destroyer' => 60,
            'deathstar' => 200,
            'small_cargo' => 5,
            'large_cargo' => 10,
            'colony_ship' => 15,
            'recycler' => 8,
            'espionage_probe' => 1,
            'solar_satellite' => 1,
            'crawler' => 5,
        ];

        return $points[$machineName] ?? 1;
    }

    /**
     * Find a target planet to attack.
     */
    public function findTarget(): ?PlanetService
    {
        $targetFinder = app(BotTargetFinderService::class);
        return $targetFinder->findTarget($this, $this->bot->getTargetTypeEnum());
    }
}
