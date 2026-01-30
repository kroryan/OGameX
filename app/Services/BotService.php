<?php

namespace OGame\Services;

use Exception;
use OGame\Enums\BotActionType;
use OGame\Enums\BotPersonality;
use OGame\Enums\BotTargetType;
use OGame\Models\Bot;
use OGame\Models\BotLog;
use OGame\Models\Planet;
use OGame\Models\Resources;

/**
 * BotService - Handles playerbot actions and decisions.
 *
 * @property Bot $bot
 * @property PlayerService $player
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
     * Check if bot is active.
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
     * Check if bot can afford a build cost.
     */
    public function canAffordBuild(int $metal, int $crystal, int $deuterium): bool
    {
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
     * Build a random structure on a random planet.
     */
    public function buildRandomStructure(): bool
    {
        try {
            $planet = $this->getRichestPlanet();
            if ($planet === null) {
                $this->logAction(BotActionType::BUILD, 'No planets available', [], 'failed');
                return false;
            }

            // Get buildable buildings
            $buildings = ObjectService::getBuildingObjects();
            $affordableBuildings = [];

            foreach ($buildings as $building) {
                if ($planet->getObjectLevel($building->machine_name) >= 30) {
                    continue; // Skip high levels
                }

                $price = ObjectService::getObjectPrice($building->machine_name, $planet);
                if ($this->canAffordBuild((int)$price->metal->get(), (int)$price->crystal->get(), (int)$price->deuterium->get())) {
                    $affordableBuildings[] = $building;
                }
            }

            if (empty($affordableBuildings)) {
                $this->logAction(BotActionType::BUILD, 'No affordable buildings', [], 'failed');
                return false;
            }

            // Pick random building
            $building = $affordableBuildings[array_rand($affordableBuildings)];

            // Build it
            $queueService = app(BuildingQueueService::class);
            $queueService->add($planet, $building->id);

            $price = ObjectService::getObjectPrice($building->machine_name, $planet);
            $this->logAction(BotActionType::BUILD, "Built {$building->machine_name} on planet {$planet->getPlanetName()}", [
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
     * Build random units on a random planet.
     */
    public function buildRandomUnit(): bool
    {
        try {
            $planet = $this->getRichestPlanet();
            if ($planet === null) {
                $this->logAction(BotActionType::FLEET, 'No planets available', [], 'failed');
                return false;
            }

            // Get buildable units
            $units = ObjectService::getUnitObjects();
            $affordableUnits = [];

            foreach ($units as $unit) {
                // Check requirements
                if (!ObjectService::objectRequirementsMet($unit->machine_name, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($unit->machine_name, $planet);
                if ($this->canAffordBuild((int)$price->metal->get(), (int)$price->crystal->get(), (int)$price->deuterium->get())) {
                    $affordableUnits[] = $unit;
                }
            }

            if (empty($affordableUnits)) {
                $this->logAction(BotActionType::FLEET, 'No affordable units', [], 'failed');
                return false;
            }

            // Pick random unit
            $unit = $affordableUnits[array_rand($affordableUnits)];

            // Calculate affordable amount
            $price = ObjectService::getObjectPrice($unit->machine_name, $planet);
            $resources = $planet->getResources();

            // Check if price is valid (not zero)
            $metalCost = $price->metal->get();
            $crystalCost = $price->crystal->get();
            $deuteriumCost = $price->deuterium->get();

            if ($metalCost == 0 && $crystalCost == 0 && $deuteriumCost == 0) {
                // Free unit, set to 1
                $maxAmount = 1;
            } else {
                $maxAmount = min(
                    $metalCost > 0 ? (int)($resources->metal->get() / $metalCost) : 999,
                    $crystalCost > 0 ? (int)($resources->crystal->get() / $crystalCost) : 999,
                    $deuteriumCost > 0 ? (int)($resources->deuterium->get() / $deuteriumCost) : 999,
                    100 // Max 100 at once
                );
            }

            if ($maxAmount < 1) {
                $this->logAction(BotActionType::FLEET, 'Cannot afford any units', [], 'failed');
                return false;
            }

            // Build units
            $queueService = app(UnitQueueService::class);
            $queueService->add($planet, $unit->id, $maxAmount);

            $totalPrice = $price->multiply($maxAmount);
            $this->logAction(BotActionType::FLEET, "Built {$maxAmount}x {$unit->machine_name} on planet {$planet->getPlanetName()}", [
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
     * Research a random technology.
     */
    public function researchRandomTech(): bool
    {
        try {
            // Check if player has a research lab
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

            // Get researchable technologies
            $research = ObjectService::getResearchObjects();
            $affordableResearch = [];

            foreach ($research as $tech) {
                $currentLevel = $this->player->getResearchLevel($tech->machine_name);
                if ($currentLevel >= 10) {
                    continue; // Skip high levels
                }

                if (!ObjectService::objectRequirementsMet($tech->machine_name, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($tech->machine_name, $planet);
                if ($this->canAffordBuild((int)$price->metal->get(), (int)$price->crystal->get(), (int)$price->deuterium->get())) {
                    $affordableResearch[] = $tech;
                }
            }

            if (empty($affordableResearch)) {
                $this->logAction(BotActionType::RESEARCH, 'No affordable research', [], 'failed');
                return false;
            }

            // Pick random research
            $tech = $affordableResearch[array_rand($affordableResearch)];

            // Research it
            $queueService = app(ResearchQueueService::class);
            $queueService->add($planet, $tech->id);

            $price = ObjectService::getObjectPrice($tech->machine_name, $planet);
            $this->logAction(BotActionType::RESEARCH, "Researched {$tech->machine_name}", [
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
     * Find a target planet to attack.
     */
    public function findTarget(): ?PlanetService
    {
        $targetFinder = app(BotTargetFinderService::class);
        return $targetFinder->findTarget($this, $this->bot->getTargetTypeEnum());
    }

    /**
     * Send an attack fleet to a target.
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

            if ($target === null) {
                $target = $this->findTarget();
            }

            if ($target === null) {
                $this->logAction(BotActionType::ATTACK, 'No target found', [], 'failed');
                return false;
            }

            // Build attack fleet
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

            if ($source->getResources()->deuterium < $consumption) {
                $this->logAction(BotActionType::ATTACK, 'Not enough deuterium for attack', [], 'failed');
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
                100, // 100% speed
                0
            );

            $this->logAction(BotActionType::ATTACK, "Sent attack fleet to {$targetCoords->asString()}", [
                'units' => $fleet->count(),
                'consumption' => $consumption,
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

            // Build expedition fleet
            $fleetBuilder = app(BotFleetBuilderService::class);
            $fleet = $fleetBuilder->buildExpeditionFleet($this);

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

            if ($source->getResources()->deuterium < $consumption) {
                $this->logAction(BotActionType::FLEET, 'Not enough deuterium for expedition', [], 'failed');
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
}
