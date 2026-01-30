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
     * Build a random structure on a smart planet selection.
     */
    public function buildRandomStructure(): bool
    {
        try {
            // Smart planet selection: use planet with lowest storage
            $planet = $this->getLowestStoragePlanet();
            if ($planet === null) {
                $this->logAction(BotActionType::BUILD, 'No planets available', [], 'failed');
                return false;
            }

            // Check minimum resources
            if (!$this->hasMinimumResources()) {
                $this->logAction(BotActionType::BUILD, 'Insufficient resources for building', [], 'failed');
                return false;
            }

            // Get buildable buildings with smart prioritization
            $buildings = ObjectService::getBuildingObjects();
            $affordableBuildings = [];

            foreach ($buildings as $building) {
                $currentLevel = $planet->getObjectLevel($building->machine_name);

                // Skip very high levels
                if ($currentLevel >= 30) {
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
     */
    private function getBuildingPriority(string $machineName, PlanetService $planet, int $currentLevel): int
    {
        $economy = $this->bot->getEconomySettings();
        $personality = $this->bot->getPersonality();

        // Base priorities for economic decisions
        $priorities = [
            // Production buildings (most important for economy)
            'metal_mine' => 120,
            'crystal_mine' => 120,
            'deuterium_synthesizer' => 110,
            // Storage (essential for growth)
            'metal_store' => 100,
            'crystal_store' => 100,
            'deuterium_store' => 100,
            // Production facilities
            'robot_factory' => 80,
            'shipyard' => 70,
            'nanite_factory' => 60,
            // Research
            'research_lab' => 75,
            // Defense
            'missile_silo' => 30,
            'ion_cannon' => 25,
            'plasma_cannon' => 20,
            'gauss_cannon' => 15,
            // Energy
            'solar_plant' => 90,
            'fusion_reactor' => 85,
            // Special
            'sensor_phalanx' => 40,
            'jump_gate' => 50,
            // Terraforming
            'lunar_base' => 50,
            'metal_den' => 60,
            'crystal_den' => 60,
            'deuterium_den' => 60,
        ];

        $base = $priorities[$machineName] ?? 50;

        // Personality-based modifiers
        if ($personality === BotPersonality::ECONOMIC) {
            if (in_array($machineName, ['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'metal_den', 'crystal_den', 'deuterium_den'])) {
                $base += 20;
            }
        } elseif ($personality === BotPersonality::AGGRESSIVE) {
            if (in_array($machineName, ['robot_factory', 'shipyard', 'nanite_factory'])) {
                $base += 20;
            }
        } elseif ($personality === BotPersonality::DEFENSIVE) {
            if (in_array($machineName, ['missile_silo', 'ion_cannon', 'plasma_cannon', 'gauss_cannon', 'shield_domed'])) {
                $base += 25;
            }
        }

        // Level curve: prioritize lower levels for faster growth
        $levelModifier = max(0, 25 - $currentLevel);

        // Balance: don't over-specialize too much
        return $base + $levelModifier;
    }

    /**
     * Build random units with smart composition.
     */
    public function buildRandomUnit(): bool
    {
        try {
            $planet = $this->getLowestStoragePlanet();
            if ($planet === null) {
                $this->logAction(BotActionType::FLEET, 'No planets available', [], 'failed');
                return false;
            }

            if (!$this->hasMinimumResources()) {
                $this->logAction(BotActionType::FLEET, 'Insufficient resources', [], 'failed');
                return false;
            }

            // Get fleet settings
            $fleetSettings = $this->bot->getFleetSettings();

            // Get buildable units with smart selection
            $units = ObjectService::getUnitObjects();
            $affordableUnits = [];

            foreach ($units as $unit) {
                // Skip units based on personality
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

        // Personality adjustments
        if ($personality === BotPersonality::AGGRESSIVE) {
            if (in_array($machineName, ['battle_ship', 'battlecruiser', 'destroyer', 'bomber'])) {
                $base += 15;
            }
        } elseif ($personality === BotPersonality::ECONOMIC) {
            if (in_array($machineName, ['small_cargo', 'large_cargo'])) {
                $base += 15;
            }
        }

        // Amount bonus (bulk is good)
        $amountBonus = min(20, $amount / 10);

        return $base + $amountBonus;
    }

    /**
     * Research a random technology with smart prioritization.
     */
    public function researchRandomTech(): bool
    {
        try {
            $planet = $this->getRichestPlanet();
            if ($planet === null) {
                $this->logAction(BotActionType::RESEARCH, 'No planets available', [], 'failed');
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
                if ($currentLevel >= 15) {
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
     */
    private function getTechPriority(string $machineName, BotPersonality $personality): int
    {
        $basePriorities = [
            // Combat technologies (highest for aggressive)
            'weapon_technology' => 100,
            'shielding_technology' => 100,
            'armor_technology' => 100,
            // Drive technologies
            'combustion_drive' => 80,
            'impulse_drive' => 85,
            'hyperspace_drive' => 90,
            // Special
            'espionage_technology' => 70,
            'computer_technology' => 75,
            'astrophysics_technology' => 60,
            'hyperspace_technology' => 50,
            // Production
            'energy_technology' => 90,
            'plasma_technology' => 85,
            // Other
            'laser_technology' => 70,
            'ion_technology' => 65,
            'hyperspace_drive' => 60,
            // Colony
            'cold_drive' => 40,
            'intergalactic_drive' => 50,
        ];

        $base = $basePriorities[$machineName] ?? 50;

        // Personality modifiers
        if ($personality === BotPersonality::AGGRESSIVE) {
            if (str_starts_with($machineName, 'weapon') ||
                str_starts_with($machineName, 'shield') ||
                str_starts_with($machineName, 'armor')) {
                $base += 15;
            }
        } elseif ($personality === BotPersonality::ECONOMIC) {
            if (in_array($machineName, ['energy_technology', 'plasma_technology', 'cold_drive', 'intergalactic_drive'])) {
                $base += 15;
            }
        }

        return $base;
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

            if ($fleet->count() === 0) {
                $this->logAction(BotActionType::ATTACK, 'No fleet available', [], 'failed');
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
                'units' => $fleet->count(),
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

            if ($fleet->count() === 0) {
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
                'units' => $fleet->count(),
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
            'plasma_cannon' => 15,
            'small_shield' => 2,
            'large_shield' => 6,
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
