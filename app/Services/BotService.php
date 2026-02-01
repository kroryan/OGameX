<?php

namespace OGame\Services;

use Exception;
use OGame\Enums\BotActionType;
use OGame\Enums\CharacterClass;
use OGame\Enums\BotPersonality;
use OGame\Enums\BotTargetType;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Bot;
use OGame\Models\BotLog;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\FleetMission;
use OGame\Models\EspionageReport;
use OGame\Models\Alliance;
use OGame\Services\ObjectService;
use OGame\Services\ObjectServiceFactory;
use OGame\Factories\PlanetServiceFactory;
use Illuminate\Support\Str;

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
        if ($this->bot->shouldSkipAction($actionType)) {
            return true;
        }

        $behavior = $this->bot->behavior_flags ?? [];
        if (isset($behavior['min_resources_for_actions'])) {
            $min = (int) $behavior['min_resources_for_actions'];
            $planet = $this->getRichestPlanet();
            if ($planet !== null) {
                $resources = $planet->getResources();
                $total = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
                if ($total < $min) {
                    return true;
                }
            }
        }

        return false;
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
     * Get the best planet for fleet operations (highest fleet power).
     */
    public function getFleetPlanet(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return null;
        }

        $best = null;
        $bestPower = 0;

        foreach ($planets as $planet) {
            $ships = $planet->getShipUnits();
            $power = 0;
            if ($ships && !empty($ships->units)) {
                foreach ($ships->units as $unitObj) {
                    $power += $this->getUnitPoints($unitObj->unitObject->machine_name) * $unitObj->amount;
                }
            }
            if ($power > $bestPower) {
                $bestPower = $power;
                $best = $planet;
            }
        }

        return $best ?? $this->getRichestPlanet();
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

            $fieldFull = !$this->hasPlanetFieldSpace($planet);

            if ($fieldFull) {
                if ($this->canUpgradeTerraformer($planet, $ignoreReserve)) {
                    return true;
                }
            }

            $budget = $this->getSpendableBudget($planet, $ignoreReserve);
            if ($budget <= 0) {
                continue;
            }

            $buildings = [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()];
            foreach ($buildings as $building) {
                // Skip buildings that consume fields if planet is full
                if ($fieldFull && ($building->consumesPlanetField ?? true)) {
                    continue;
                }

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
        $maxStorageBeforeSpending = (float) ($economy['max_storage_before_spending'] ?? 0.7);
        $usagePercent = $this->getStorageUsagePercent($planet);
        if ($total < 10000) {
            // Early game: spend almost everything to avoid stalling.
            $reserve = min($reserve, 0.05);
            $roles = $this->getPlanetRoles();
            if (($roles[$planet->getPlanetId()] ?? null) === 'colony') {
                $reserve = 0.0;
            }
        } elseif ($total < 50000) {
            // Mid-early: low reserve to keep growing
            $reserve = min($reserve, 0.10);
        } elseif ($total < 100000) {
            // Mid game: moderate reserve
            $reserve = min($reserve, 0.20);
        } elseif ($usagePercent >= $maxStorageBeforeSpending) {
            // Storage pressured: spend more to avoid waste
            $reserve = min($reserve, 0.15);
        }
        // Otherwise use the configured reserve (default 30%), no longer inflating to 60-90%

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

        $personality = $this->getPersonality();
        $skipList = match ($personality) {
            BotPersonality::AGGRESSIVE, BotPersonality::RAIDER => ['colony_ship', 'recycler', 'solar_satellite', 'crawler'],
            BotPersonality::ECONOMIC => ['colony_ship', 'recycler'],
            BotPersonality::TURTLE => ['colony_ship', 'recycler', 'espionage_probe'], // Turtles don't need probes
            BotPersonality::SCIENTIST => ['colony_ship'], // Scientists keep recyclers for resources
            default => [],
        };

        return !in_array($machineName, $skipList);
    }

    public function shouldColonize(): bool
    {
        $maxPlanets = $this->player->getMaxPlanetAmount();
        $behavior = $this->bot->behavior_flags ?? [];
        if (!empty($behavior['max_planets_to_colonize'])) {
            $maxPlanets = min($maxPlanets, (int) $behavior['max_planets_to_colonize']);
        }

        if ($this->isUnderThreat()) {
            return false;
        }

        $currentPlanets = count($this->player->planets->all());
        if ($currentPlanets >= $maxPlanets) {
            return false;
        }

        // Personality influence on colonization timing
        $personality = $this->getPersonality();
        if (in_array($personality, [BotPersonality::AGGRESSIVE, BotPersonality::RAIDER])) {
            $state = (new GameStateAnalyzer())->analyzeCurrentState($this);
            if (($state['fleet_points'] ?? 0) < 80000) {
                return false;
            }
        }
        // Turtles delay colonization until they have solid defense
        if ($personality === BotPersonality::TURTLE) {
            $state = $state ?? (new GameStateAnalyzer())->analyzeCurrentState($this);
            if (($state['defense_points'] ?? 0) < 5000) {
                return false;
            }
        }

        return true;
    }

    public function isUnderThreat(): bool
    {
        $planetIds = $this->player->planets->allIds();
        if (empty($planetIds)) {
            return false;
        }

        $incoming = FleetMission::whereIn('planet_id_to', $planetIds)
            ->where('canceled', 0)
            ->where('processed', 0)
            ->whereIn('mission_type', [1, 6, 9, 10])
            ->where('time_arrival', '>', now()->timestamp)
            ->count();

        return $incoming > 0;
    }

    public function ensureCharacterClass(): void
    {
        try {
            $user = $this->player->getUser();
            if ($user->character_class !== null) {
                return;
            }

            $class = match ($this->getPersonality()) {
                BotPersonality::AGGRESSIVE, BotPersonality::RAIDER => CharacterClass::GENERAL,
                BotPersonality::ECONOMIC, BotPersonality::SCIENTIST => CharacterClass::COLLECTOR,
                BotPersonality::DEFENSIVE, BotPersonality::TURTLE => CharacterClass::GENERAL,
                BotPersonality::EXPLORER => CharacterClass::DISCOVERER,
                BotPersonality::DIPLOMAT => CharacterClass::COLLECTOR,
                default => CharacterClass::DISCOVERER,
            };

            $service = app(CharacterClassService::class);
            $service->selectClass($user, $class);
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: ensureCharacterClass failed: {$e->getMessage()}");
        }
    }

    public function recallRiskyMissions(): int
    {
        try {
            $missions = FleetMission::where('user_id', $this->player->getId())
                ->where('processed', 0)
                ->where('canceled', 0)
                ->where('time_arrival', '>', now()->timestamp)
                ->whereIn('mission_type', [1, 3, 8, 15])
                ->get();

            if ($missions->isEmpty()) {
                return 0;
            }

            $fleetMissionService = app(FleetMissionService::class);
            $count = 0;
            foreach ($missions as $mission) {
                $fleetMissionService->cancelMission($mission);
                $count++;
            }

            if ($count > 0) {
                $this->logAction(BotActionType::FLEET, "Recalled {$count} missions due to threat", []);
            }

            return $count;
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: recallRiskyMissions failed: {$e->getMessage()}");
            return 0;
        }
    }

    public function shouldFleetSaveBySchedule(): bool
    {
        if ($this->isUnderThreat()) {
            return false;
        }
        if (!$this->hasFleetSlotsAvailable()) {
            return false;
        }
        if ($this->getFleetSlotUsage() < 0.4) {
            return false;
        }

        $cacheKey = 'bot_fleet_window_' . $this->bot->id;
        $window = cache()->get($cacheKey);
        if (!is_array($window)) {
            $startHour = rand(0, 23);
            $duration = rand(6, 10);
            $window = ['start' => $startHour, 'duration' => $duration];
            cache()->put($cacheKey, $window, now()->addHours(24));
        }

        $hour = (int) now()->format('H');
        $end = ($window['start'] + $window['duration']) % 24;
        $inWindow = $window['start'] < $end
            ? ($hour >= $window['start'] && $hour < $end)
            : ($hour >= $window['start'] || $hour < $end);

        return !$inWindow;
    }

    public function tryRecycleNearbyDebris(): bool
    {
        if (!$this->hasFleetSlotsAvailable()) {
            return false;
        }

        $source = $this->getPlanetByRole('fleet') ?? $this->getRichestPlanet();
        if ($source === null) {
            return false;
        }

        $recyclers = $source->getObjectAmount('recycler');
        if ($recyclers < 1) {
            return false;
        }

        $coords = $source->getPlanetCoordinates();
        $field = \OGame\Models\DebrisField::where('galaxy', $coords->galaxy)
            ->whereBetween('system', [max(1, $coords->system - 3), $coords->system + 3])
            ->orderByRaw('(metal + crystal + deuterium) DESC')
            ->first();

        if (!$field || ($field->metal + $field->crystal + $field->deuterium) < 5000) {
            return false;
        }

        $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName('recycler'), min(20, $recyclers));

        $targetCoords = new \OGame\Models\Planet\Coordinate($field->galaxy, $field->system, $field->planet);
        $fleetMissionService = app(FleetMissionService::class);
        $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 0, 100);
        if ($source->getResources()->deuterium->get() < $consumption) {
            return false;
        }

        $fleetMissionService->createNewFromPlanet(
            $source,
            $targetCoords,
            \OGame\Models\Enums\PlanetType::DebrisField,
            8,
            $fleet,
            new Resources(0, 0, 0, 0),
            rand(80, 100),
            0
        );

        $this->logAction(BotActionType::FLEET, "Sent recyclers to debris {$targetCoords->asString()}", [
            'recyclers' => $fleet->getAmount(),
        ]);

        return true;
    }

    public function performFleetSave(bool $allowHolding = false): bool
    {
        try {
            if (!$this->hasFleetSlotsAvailable()) {
                return false;
            }

            $source = $this->getRichestPlanet();
            if ($source === null) {
                return false;
            }

            $target = $this->findSafePlanetForSave($source);
            if ($target === null) {
                return false;
            }

            $units = $source->getShipUnits();
            if ($units->getAmount() === 0) {
                return false;
            }

            $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
            foreach ($units->units as $unitObj) {
                $sendAmount = max(0, $unitObj->amount - 1);
                if ($sendAmount > 0) {
                    $fleet->addUnit($unitObj->unitObject, $sendAmount);
                }
            }

            if ($fleet->getAmount() === 0) {
                return false;
            }

            $fleetMissionService = app(FleetMissionService::class);
            $targetCoords = $target->getPlanetCoordinates();
            $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 4, 100);
            if ($source->getResources()->deuterium->get() < $consumption) {
                return false;
            }

            $holdingHours = 0;
            if ($allowHolding && !$this->isUnderThreat()) {
                $holdingHours = rand(1, 3);
            }

            $fleetMissionService->createNewFromPlanet(
                $source,
                $targetCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                4,
                $fleet,
                new Resources(0, 0, 0, 0),
                100,
                $holdingHours
            );

            $this->logAction(BotActionType::FLEET, "Fleet save to {$targetCoords->asString()}", [
                'consumption' => $consumption,
                'holding_hours' => $holdingHours,
            ]);

            return true;
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: performFleetSave failed: {$e->getMessage()}");
            return false;
        }
    }

    public function tryJumpGateEvacuation(): bool
    {
        try {
            $moons = $this->player->planets->allMoons();
            if (count($moons) < 2) {
                return false;
            }

            $jumpGateService = app(JumpGateService::class);
            $eligibleSources = [];
            foreach ($moons as $moon) {
                if ($moon->getObjectLevel('jump_gate') < 1) {
                    continue;
                }
                if ($jumpGateService->isOnCooldown($moon)) {
                    continue;
                }
                $eligibleSources[] = $moon;
            }

            if (count($eligibleSources) < 2) {
                return false;
            }

            $source = $eligibleSources[array_rand($eligibleSources)];
            $targets = array_values(array_filter($eligibleSources, fn ($m) => $m->getPlanetId() !== $source->getPlanetId()));
            if (empty($targets)) {
                return false;
            }
            $target = $targets[array_rand($targets)];

            $ships = [];
            foreach ($jumpGateService->getTransferableShips() as $shipName) {
                $available = $source->getObjectAmount($shipName);
                if ($available > 0) {
                    $ships[$shipName] = (int) floor($available * 0.7);
                }
            }

            if (empty($ships)) {
                return false;
            }

            if (!$jumpGateService->transferShips($source, $target, $ships)) {
                return false;
            }
            $jumpGateService->setCooldown($source, $target);

            $this->logAction(BotActionType::FLEET, "JumpGate transfer to {$target->getPlanetName()}", [
                'ships' => $ships,
            ]);

            return true;
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: tryJumpGateEvacuation failed: {$e->getMessage()}");
            return false;
        }
    }

    private function findSafePlanetForSave(PlanetService $source): ?PlanetService
    {
        $planets = $this->player->planets->all();
        if (empty($planets)) {
            return null;
        }

        foreach ($planets as $planet) {
            if ($planet->getPlanetId() !== $source->getPlanetId() && $planet->hasMoon()) {
                return $planet->moon();
            }
        }

        foreach ($planets as $planet) {
            if ($planet->getPlanetId() !== $source->getPlanetId()) {
                return $planet;
            }
        }

        return null;
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
            rand(70, 100),
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
        $preferredPositions = [4, 5, 6, 7, 8, 9, 10, 11, 12];
        while ($attempts < 40) {
            $systemOffset = rand(-30, 30);
            $system = $coords->system + $systemOffset;
            if ($system < 1 || $system > $maxSystems) {
                $system = rand(1, $maxSystems);
            }

            $position = $preferredPositions[array_rand($preferredPositions)];
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
        $roles = $this->getPlanetRoles();
        foreach ($planets as $planet) {
            if (($roles[$planet->getPlanetId()] ?? null) === 'research' && !$this->isResearchQueueFull($planet)) {
                return $planet;
            }
        }
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
            $planets = $this->player->planets->all();
            if (empty($planets)) {
                $this->logAction(BotActionType::BUILD, 'No planets available', [], 'failed');
                return false;
            }

            $roles = $this->getPlanetRoles();
            $buildings = [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()];
            $affordableBuildings = [];

            foreach ($planets as $planet) {
                if ($this->isBuildingQueueFull($planet)) {
                    continue;
                }

                $fieldFull = !$this->hasPlanetFieldSpace($planet);

                if ($fieldFull) {
                    if ($this->canUpgradeTerraformer($planet)) {
                        return $this->buildTerraformer($planet);
                    }
                    // Don't skip entirely - we can still build non-field buildings
                }

                $role = $roles[$planet->getPlanetId()] ?? 'colony';
                $mineSum = $planet->getObjectLevel('metal_mine')
                    + $planet->getObjectLevel('crystal_mine')
                    + $planet->getObjectLevel('deuterium_synthesizer');

                foreach ($buildings as $building) {
                    $currentLevel = $planet->getObjectLevel($building->machine_name);

                    // Skip very high levels
                    if ($currentLevel >= config('bots.max_building_level', 30)) {
                        continue;
                    }

                    if (!ObjectService::objectRequirementsMet($building->machine_name, $planet)) {
                        continue;
                    }

                    // Skip buildings that consume fields if planet is full
                    if ($fieldFull && ($building->consumesPlanetField ?? true)) {
                        continue;
                    }

                    $price = ObjectService::getObjectPrice($building->machine_name, $planet);
                    $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();

                    $economy = $this->bot->getEconomySettings();
                    $resources = $planet->getResources();
                    $maxToSpend = ($resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get())
                                 * (1 - $economy['save_for_upgrade_percent']);

                    if ($cost > $maxToSpend) {
                        continue;
                    }

                    $priority = $this->getBuildingPriority($building->machine_name, $planet, $currentLevel);
                    $roleBonus = $this->getRoleBonusForBuilding($role, $building->machine_name);
                    $colonyBoost = 0;
                    if ($role === 'colony' && $mineSum < 6) {
                        $colonyBoost = 30;
                    }
                    $score = ($priority + $roleBonus + $colonyBoost) * 1000 - $cost;

                    $affordableBuildings[] = [
                        'building' => $building,
                        'planet' => $planet,
                        'score' => $score,
                        'cost' => $cost,
                    ];
                }
            }

            if (empty($affordableBuildings)) {
                $this->logAction(BotActionType::BUILD, 'No affordable buildings', [], 'failed');
                return false;
            }

            // Sort by score (highest priority/lowest cost)
            usort($affordableBuildings, fn($a, $b) => $b['score'] <=> $a['score']);

            // Pick best building (80% best, 20% second-best for variety)
            $topBuildings = array_slice($affordableBuildings, 0, min(2, count($affordableBuildings)));
            $choice = (count($topBuildings) >= 2 && mt_rand(1, 100) <= 20) ? $topBuildings[1] : $topBuildings[0];
            $building = $choice['building'];
            $planet = $choice['planet'];

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
            'fusion_plant' => 120,

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
        if (in_array($personality, [BotPersonality::ECONOMIC, BotPersonality::SCIENTIST])) {
            if (in_array($machineName, ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'])) {
                $base += 30;
            }
            if (in_array($machineName, ['nano_factory', 'robot_factory', 'fusion_plant'])) {
                $base += 20;
            }
            if ($personality === BotPersonality::SCIENTIST && $machineName === 'research_lab') {
                $base += 40;
            }
        } elseif (in_array($personality, [BotPersonality::AGGRESSIVE, BotPersonality::RAIDER])) {
            if (in_array($machineName, ['robot_factory', 'shipyard', 'nano_factory'])) {
                $base += 30;
            }
        } elseif (in_array($personality, [BotPersonality::DEFENSIVE, BotPersonality::TURTLE])) {
            if (in_array($machineName, ['metal_store', 'crystal_store', 'deuterium_store'])) {
                $base += 30;
            }
            if (in_array($machineName, ['missile_silo'])) {
                $base += 35;
            }
            if ($personality === BotPersonality::TURTLE) {
                // Turtles love all storage and defense infrastructure
                if (in_array($machineName, ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'])) {
                    $base += 15;
                }
            }
        } elseif ($personality === BotPersonality::EXPLORER) {
            if (in_array($machineName, ['shipyard', 'research_lab'])) {
                $base += 20;
            }
        } elseif ($personality === BotPersonality::DIPLOMAT) {
            if (in_array($machineName, ['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'research_lab'])) {
                $base += 15;
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

        // Energy deficit: prioritize energy buildings
        try {
            if ($planet->energy()->get() < 0) {
                if (in_array($machineName, ['solar_plant', 'fusion_plant'])) {
                    $base += 45;
                }
            }
        } catch (Exception) {
            // Ignore energy errors
        }

        // Storage urgency: if storage is nearly full, prioritize spending
        if (in_array($machineName, ['metal_store', 'crystal_store', 'deuterium_store'])) {
            $resources = $planet->getResources();
            $metalMax = $planet->metalStorage()->get();
            if ($metalMax > 0 && $resources->metal->get() / $metalMax > 0.9) {
                $base += 50; // Urgent!
            }
        }

        if ($machineName === 'terraformer' && $this->isPlanetFieldFull($planet)) {
            $base += 80;
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

    private function isPlanetFieldFull(PlanetService $planet): bool
    {
        try {
            return $planet->getBuildingCount() >= $planet->getPlanetFieldMax();
        } catch (Exception) {
            return false;
        }
    }

    private function hasPlanetFieldSpace(PlanetService $planet): bool
    {
        return !$this->isPlanetFieldFull($planet);
    }

    private function canUpgradeTerraformer(PlanetService $planet, bool $ignoreReserve = false): bool
    {
        try {
            if ($this->isBuildingQueueFull($planet)) {
                return false;
            }
            if (!ObjectService::objectRequirementsMet('terraformer', $planet)) {
                return false;
            }
            // If planet is full of fields, terraformer should be HIGHER priority
            // not blocked. This is the main way to get more fields.
            // Removed the incorrect check that prevented building terraformer on full planets.

            $price = ObjectService::getObjectPrice('terraformer', $planet);
            $budget = $this->getSpendableBudget($planet, $ignoreReserve);
            $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();

            // Allow spending more reserve when fields are full since terraformer is critical
            if ($this->isPlanetFieldFull($planet) && !$ignoreReserve) {
                $budget *= 1.5; // Allow using 50% more reserve when critically full
            }

            return $cost <= $budget;
        } catch (Exception $e) {
            return false;
        }
    }

    private function buildTerraformer(PlanetService $planet): bool
    {
        try {
            $queueService = app(BuildingQueueService::class);
            $queueService->add($planet, ObjectService::getObjectByMachineName('terraformer')->id);
            $price = ObjectService::getObjectPrice('terraformer', $planet);
            $this->logAction(BotActionType::BUILD, "Built terraformer on {$planet->getPlanetName()}", [
                'metal' => $price->metal->get(),
                'crystal' => $price->crystal->get(),
                'deuterium' => $price->deuterium->get(),
            ]);
            $this->bot->updateLastAction();
            return true;
        } catch (Exception $e) {
            $this->logAction(BotActionType::BUILD, "Failed to build terraformer: {$e->getMessage()}", [], 'failed');
            return false;
        }
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
            $preferDefense = $this->isUnderThreat()
                || ($this->getPersonality() === BotPersonality::DEFENSIVE && mt_rand(1, 100) <= 35);
            $units = $preferDefense ? ObjectService::getDefenseObjects() : ObjectService::getUnitObjects();
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

                $unitScore = $this->getUnitScore($unit->machine_name, $maxAmount, $planet);
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

            // Sort by score and pick best option (80% best, 20% second-best)
            usort($affordableUnits, fn($a, $b) => $b['score'] <=> $a['score']);
            $topUnits = array_slice($affordableUnits, 0, min(3, count($affordableUnits)));
            $selected = (count($topUnits) >= 2 && mt_rand(1, 100) <= 20) ? $topUnits[1] : $topUnits[0];

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

        $role = $this->isUnderThreat() ? 'defense' : 'fleet';
        $preferred = $this->getPlanetByRole($role);
        if ($preferred && !$this->isUnitQueueFull($preferred)) {
            $budget = $this->getSpendableBudget($preferred);
            if ($budget > 0 && $this->hasAffordableUnitOnPlanet($preferred, $budget)) {
                return $preferred;
            }
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

    private function getPlanetByRole(string $role): ?PlanetService
    {
        $roles = $this->getPlanetRoles();
        foreach ($this->player->planets->all() as $planet) {
            if (($roles[$planet->getPlanetId()] ?? null) === $role) {
                return $planet;
            }
        }
        return null;
    }

    private function getPlanetRoles(): array
    {
        $cacheKey = 'bot_planet_roles_' . $this->bot->id;
        $cached = cache()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $planets = $this->player->planets->all();
        $roles = [];
        if (count($planets) <= 1) {
            foreach ($planets as $planet) {
                $roles[$planet->getPlanetId()] = 'primary';
            }
            cache()->put($cacheKey, $roles, now()->addMinutes(30));
            return $roles;
        }

        $production = [];
        $shipyardScore = [];
        $defenseScore = [];
        $researchScore = [];
        foreach ($planets as $planet) {
            $production[$planet->getPlanetId()] = $planet->getResources()->metal->get()
                + $planet->getResources()->crystal->get()
                + $planet->getResources()->deuterium->get();
            $shipyardScore[$planet->getPlanetId()] = $planet->getObjectLevel('shipyard')
                + $planet->getObjectLevel('robot_factory')
                + $planet->getObjectLevel('nano_factory');
            $defenseScore[$planet->getPlanetId()] = $planet->getObjectAmount('rocket_launcher')
                + $planet->getObjectAmount('light_laser')
                + $planet->getObjectAmount('heavy_laser')
                + $planet->getObjectAmount('gauss_cannon')
                + $planet->getObjectAmount('ion_cannon')
                + $planet->getObjectAmount('plasma_turret');
            $researchScore[$planet->getPlanetId()] = $planet->getObjectLevel('research_lab');
        }

        $economyId = array_key_first(collect($production)->sortDesc()->toArray());
        $fleetId = array_key_first(collect($shipyardScore)->sortDesc()->toArray());
        $defenseId = array_key_first(collect($defenseScore)->sortDesc()->toArray());
        $researchId = array_key_first(collect($researchScore)->sortDesc()->toArray());

        foreach ($planets as $planet) {
            $id = $planet->getPlanetId();
            $roles[$id] = 'colony';
        }
        if ($economyId) {
            $roles[$economyId] = 'economy';
        }
        if ($fleetId) {
            $roles[$fleetId] = 'fleet';
        }
        if ($defenseId) {
            $roles[$defenseId] = 'defense';
        }
        if ($researchId) {
            $roles[$researchId] = 'research';
        }

        cache()->put($cacheKey, $roles, now()->addMinutes(30));
        return $roles;
    }

    private function getRoleBonusForBuilding(string $role, string $machineName): int
    {
        $economyBuildings = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'solar_plant', 'fusion_plant', 'metal_store', 'crystal_store', 'deuterium_store', 'terraformer'];
        $fleetBuildings = ['shipyard', 'robot_factory', 'nano_factory', 'space_dock'];
        $defenseBuildings = ['missile_silo', 'space_dock'];
        $researchBuildings = ['research_lab', 'intergalactic_research_network'];
        $colonyBuildings = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'solar_plant', 'metal_store', 'crystal_store', 'deuterium_store'];

        return match ($role) {
            'economy' => in_array($machineName, $economyBuildings) ? 25 : 0,
            'fleet' => in_array($machineName, $fleetBuildings) ? 25 : 0,
            'defense' => in_array($machineName, $defenseBuildings) ? 25 : 0,
            'research' => in_array($machineName, $researchBuildings) ? 25 : 0,
            'colony' => in_array($machineName, $colonyBuildings) ? 25 : 0,
            default => 0,
        };
    }

    private function hasAffordableUnitOnPlanet(PlanetService $planet, float $budget): bool
    {
        $units = ObjectService::getUnitObjects();
        $resources = $planet->getResources();

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
    private function getUnitScore(string $machineName, int $amount, ?PlanetService $planet = null): int
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

        // CRITICAL: If bot needs to colonize and has no colony ship, make it TOP priority
        if ($machineName === 'colony_ship' && $this->shouldColonize()) {
            // Check if we already have colony ships available
            $hasColonyShip = false;
            if ($planet) {
                $hasColonyShip = $planet->getObjectAmount('colony_ship') > 0;
            } else {
                foreach ($this->player->planets->all() as $p) {
                    if ($p->getObjectAmount('colony_ship') > 0) {
                        $hasColonyShip = true;
                        break;
                    }
                }
            }
            // If no colony ships available, make it highest priority
            if (!$hasColonyShip) {
                $base = 150; // Higher than any other unit
            }
        }

        // Personality adjustments
        if (in_array($personality, [BotPersonality::AGGRESSIVE, BotPersonality::RAIDER])) {
            if (in_array($machineName, ['battle_ship', 'battlecruiser', 'destroyer', 'bomber'])) {
                $base += 15;
            }
            if (in_array($machineName, $defenseUnits)) {
                $base -= 10;
            }
            if ($personality === BotPersonality::RAIDER) {
                // Raiders love cargo ships for looting
                if (in_array($machineName, ['small_cargo', 'large_cargo'])) {
                    $base += 20;
                }
                if ($machineName === 'espionage_probe') {
                    $base += 15;
                }
            }
        } elseif (in_array($personality, [BotPersonality::ECONOMIC, BotPersonality::SCIENTIST])) {
            if (in_array($machineName, ['small_cargo', 'large_cargo'])) {
                $base += 15;
            }
            if (in_array($machineName, $defenseUnits)) {
                $base -= 5;
            }
        } elseif (in_array($personality, [BotPersonality::DEFENSIVE, BotPersonality::TURTLE])) {
            if (in_array($machineName, $defenseUnits)) {
                $base += 20;
            }
            if ($personality === BotPersonality::TURTLE) {
                // Turtles heavily prioritize defenses over ships
                if (!in_array($machineName, $defenseUnits)) {
                    $base -= 15;
                } else {
                    $base += 15;
                }
            }
        } elseif ($personality === BotPersonality::EXPLORER) {
            if (in_array($machineName, ['espionage_probe', 'large_cargo', 'colony_ship'])) {
                $base += 15;
            }
        } elseif ($personality === BotPersonality::DIPLOMAT) {
            if (in_array($machineName, ['small_cargo', 'large_cargo', 'recycler'])) {
                $base += 10;
            }
        }

        if ($this->isUnderThreat() && in_array($machineName, $defenseUnits)) {
            $base += 30;
        }

        if ($machineName === 'colony_ship' && $this->shouldColonize()) {
            $base += 40;
        }

        if ($planet && $machineName === 'solar_satellite') {
            try {
                if ($planet->energy()->get() < 0 && !$this->isUnderThreat()) {
                    $base += 25;
                }
            } catch (Exception) {
                // Ignore
            }
        }

        if ($planet && in_array($machineName, ['interplanetary_missile', 'anti_ballistic_missile'])) {
            try {
                if ($planet->getObjectLevel('missile_silo') > 0) {
                    $base += 15;
                }
            } catch (Exception) {
                // Ignore
            }
        }

        if ($planet && $machineName === 'colony_ship') {
            $roles = $this->getPlanetRoles();
            $role = $roles[$planet->getPlanetId()] ?? 'colony';
            if ($role === 'colony' || $role === 'economy') {
                $base += 15;
            }
        }

        // Amount bonus (bulk is good)
        $amountBonus = min(20, $amount / 10);

        return $base + $amountBonus;
    }

    public function shouldTradeResources(): bool
    {
        if ($this->isUnderThreat()) {
            return false;
        }
        if (!$this->hasFleetSlotsAvailable()) {
            return false;
        }
        if ($this->getFleetSlotUsage() > 0.8) {
            return false;
        }
        $planets = $this->player->planets->all();
        if (count($planets) < 2) {
            return false;
        }

        $economyPlanet = $this->getPlanetByRole('economy');
        $colonyPlanet = $this->getColonyNeedingResources();
        $fleetPlanet = $this->getPlanetByRole('fleet');
        $richest = $economyPlanet ?? $this->getRichestPlanet();
        $lowest = $colonyPlanet ?? $fleetPlanet ?? $this->getLowestStoragePlanet();
        if ($richest === null || $lowest === null || $richest->getPlanetId() === $lowest->getPlanetId()) {
            return false;
        }

        $richUsage = $this->getStorageUsagePercent($richest);
        $lowUsage = $this->getStorageUsagePercent($lowest);
        $economy = $this->bot->getEconomySettings();
        $maxStorageBeforeSpending = (float) ($economy['max_storage_before_spending'] ?? 0.9);

        if ($colonyPlanet !== null && $lowest->getPlanetId() === $colonyPlanet->getPlanetId()) {
            $resources = $richest->getResources();
            $total = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
            $minForAction = (int) ($economy['min_resources_for_actions'] ?? 500);
            return $total >= ($minForAction * 2) && $lowUsage < 0.7;
        }

        $shouldTrade = $richUsage >= $maxStorageBeforeSpending && $lowUsage < 0.6;

        // Personality influence
        if ($this->getPersonality() === BotPersonality::ECONOMIC) {
            $shouldTrade = $shouldTrade || ($richUsage >= 0.7 && $lowUsage < 0.7);
        } elseif ($this->getPersonality() === BotPersonality::DEFENSIVE) {
            $shouldTrade = $shouldTrade && $richUsage >= 0.85;
        }

        return $shouldTrade;
    }

    private function shouldUseMerchantTrade(): bool
    {
        if ($this->isUnderThreat()) {
            return false;
        }

        if ($this->shouldSkipAction('trade')) {
            return false;
        }

        $user = $this->player->getUser();
        if ($user->dark_matter < MerchantService::DARK_MATTER_COST) {
            return false;
        }

        $state = (new GameStateAnalyzer())->analyzeCurrentState($this);
        $imbalance = (float) ($state['resource_imbalance'] ?? 0.0);
        $minImbalance = $this->getMerchantMinImbalance();

        if ($imbalance < $minImbalance && !$this->isStoragePressureHigh()) {
            return false;
        }

        if ($this->getPersonality() === BotPersonality::DEFENSIVE && !$this->isStoragePressureHigh()) {
            return false;
        }

        return true;
    }

    private function tryMerchantTrade(): bool
    {
        try {
            $planet = $this->getRichestPlanet();
            if ($planet === null) {
                return false;
            }

            $resources = $planet->getResources();
            $resourceAmounts = [
                'metal' => $resources->metal->get(),
                'crystal' => $resources->crystal->get(),
                'deuterium' => $resources->deuterium->get(),
            ];

            arsort($resourceAmounts);
            $giveResource = array_key_first($resourceAmounts);

            $lowest = $resourceAmounts;
            asort($lowest);
            $receiveResource = array_key_first($lowest);

            if ($giveResource === $receiveResource) {
                return false;
            }

            $call = MerchantService::callMerchant($this->player, $giveResource);
            if (empty($call['success'])) {
                return false;
            }

            $rates = $call['tradeRates']['receive'] ?? [];
            if (empty($rates[$receiveResource]['rate'])) {
                return false;
            }

            $giveAvailable = (int) $resourceAmounts[$giveResource];
            $minGive = (int) config('bots.merchant_trade_amount_min', 5000);
            $ratio = (float) config('bots.merchant_trade_amount_ratio', 0.2);
            $maxRatio = (float) config('bots.merchant_trade_amount_max_ratio', 0.5);
            $giveAmount = (int) min(max($minGive, $giveAvailable * $ratio), $giveAvailable * $maxRatio, $giveAvailable);
            if ($giveAmount <= 0) {
                return false;
            }

            $result = MerchantService::executeTrade(
                $planet,
                $giveResource,
                $receiveResource,
                $giveAmount,
                (float) $rates[$receiveResource]['rate']
            );

            if (!empty($result['success'])) {
                $this->logAction(BotActionType::TRADE, "Merchant trade {$giveResource}→{$receiveResource}", [
                    'give' => $giveAmount,
                    'receive' => $result['received'] ?? null,
                ]);
                $this->bot->updateLastAction();
                return true;
            }
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: tryMerchantTrade failed: {$e->getMessage()}");
            return false;
        }

        return false;
    }

    private function getFleetSlotUsage(): float
    {
        $max = $this->player->getFleetSlotsMax();
        if ($max <= 0) {
            return 1.0;
        }
        return $this->player->getFleetSlotsInUse() / $max;
    }

    private function getColonyNeedingResources(): ?PlanetService
    {
        $planets = $this->player->planets->all();
        if (count($planets) <= 1) {
            return null;
        }

        $roles = $this->getPlanetRoles();
        $candidate = null;
        $lowestScore = PHP_INT_MAX;

        foreach ($planets as $planet) {
            $role = $roles[$planet->getPlanetId()] ?? 'colony';
            if (!in_array($role, ['colony', 'research', 'defense'], true)) {
                continue;
            }

            $mineScore = $planet->getObjectLevel('metal_mine')
                + $planet->getObjectLevel('crystal_mine')
                + $planet->getObjectLevel('deuterium_synthesizer');

            $resources = $planet->getResources();
            $resourceSum = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();

            $score = ($mineScore * 1000) + $resourceSum;
            if ($score < $lowestScore) {
                $lowestScore = $score;
                $candidate = $planet;
            }
        }

        if ($candidate && $lowestScore < 15000) {
            return $candidate;
        }

        return null;
    }

    public function sendResourceTransport(): bool
    {
        try {
            if ($this->shouldUseMerchantTrade() && $this->tryMerchantTrade()) {
                return true;
            }

            if (!$this->hasFleetSlotsAvailable()) {
                $this->logAction(BotActionType::TRADE, 'No fleet slots available for transport', [], 'failed');
                return false;
            }

            $source = $this->getPlanetByRole('economy') ?? $this->getRichestPlanet();
            $target = $this->getColonyNeedingResources()
                ?? $this->getPlanetByRole('fleet')
                ?? $this->getLowestStoragePlanet();
            if ($source === null || $target === null || $source->getPlanetId() === $target->getPlanetId()) {
                $this->logAction(BotActionType::TRADE, 'No valid transport target', [], 'failed');
                return false;
            }

            $availableLarge = $source->getObjectAmount('large_cargo');
            $availableSmall = $source->getObjectAmount('small_cargo');
            if ($availableLarge + $availableSmall <= 0) {
                if ($this->shouldUseMerchantTrade() && $this->tryMerchantTrade()) {
                    return true;
                }
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
            if ($target === $this->getColonyNeedingResources()) {
                $reserve = min($reserve, 0.15);
            }
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

            // Pick best research (80% best, 20% second-best for variety)
            $topResearch = array_slice($affordableResearch, 0, min(2, count($affordableResearch)));
            $chosen = (count($topResearch) >= 2 && mt_rand(1, 100) <= 20) ? $topResearch[1] : $topResearch[0];
            $tech = $chosen['tech'];

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
        if (in_array($personality, [BotPersonality::AGGRESSIVE, BotPersonality::RAIDER])) {
            if (str_starts_with($machineName, 'weapon') ||
                str_starts_with($machineName, 'shield') ||
                str_starts_with($machineName, 'armor')) {
                $base += 20;
            }
            if (in_array($machineName, ['combustion_drive', 'impulse_drive', 'hyperspace_drive'])) {
                $base += 15;
            }
            if ($personality === BotPersonality::RAIDER) {
                if ($machineName === 'espionage_technology') {
                    $base += 25; // Raiders need espionage
                }
            }
        } elseif (in_array($personality, [BotPersonality::DEFENSIVE, BotPersonality::TURTLE])) {
            if (in_array($machineName, ['shielding_technology', 'armor_technology', 'ion_technology', 'plasma_technology'])) {
                $base += 20;
            }
            if ($personality === BotPersonality::TURTLE) {
                if (in_array($machineName, ['laser_technology', 'ion_technology'])) {
                    $base += 15; // Turtle loves defense tech
                }
            }
        } elseif (in_array($personality, [BotPersonality::ECONOMIC, BotPersonality::SCIENTIST])) {
            if (in_array($machineName, ['energy_technology', 'plasma_technology', 'computer_technology', 'espionage_technology'])) {
                $base += 20;
            }
            if ($personality === BotPersonality::SCIENTIST) {
                // Scientists boost ALL research uniformly
                $base += 15;
            }
        } elseif ($personality === BotPersonality::EXPLORER) {
            if (in_array($machineName, ['astrophysics', 'hyperspace_drive', 'impulse_drive', 'computer_technology'])) {
                $base += 20;
            }
        } elseif ($personality === BotPersonality::DIPLOMAT) {
            if (in_array($machineName, ['computer_technology', 'espionage_technology', 'hyperspace_technology'])) {
                $base += 15;
            }
        } elseif ($personality === BotPersonality::BALANCED) {
            if (in_array($machineName, ['hyperspace_technology', 'computer_technology', 'espionage_technology'])) {
                $base += 15;
            }
        }

        return max(10, min(200, $base));
    }

    private function getAttackProfitRatio(): float
    {
        $base = (float) config('bots.attack_expected_loss_min_profit_ratio', 0.3);
        $actionMetrics = app(BotActionMetricsService::class)->getAttackMetrics();
        if (!empty($actionMetrics['sampled']) && isset($actionMetrics['success_rate'])) {
            if ($actionMetrics['success_rate'] < 0.2) {
                $base += 0.05;
            } elseif ($actionMetrics['success_rate'] > 0.5) {
                $base -= 0.05;
            }
        }

        $metrics = new BotStrategicMetrics();
        $state = (new GameStateAnalyzer())->analyzeCurrentState($this);
        $growth = $metrics->getGrowthRate($state, $this->bot->id);
        $efficiency = $metrics->getResourceEfficiency($state, $this->bot->id);

        if ($growth > 20 && $efficiency > 0.9) {
            return max(0.2, $base - 0.05);
        }
        if ($growth < 5 || $efficiency < 0.6) {
            return min(0.5, $base + 0.1);
        }

        return $base;
    }

    private function getAttackMinLootRatio(): float
    {
        $base = (float) config('bots.attack_min_loot_ratio_capacity', 0.2);
        $metrics = app(BotActionMetricsService::class)->getAttackMetrics();
        if (!empty($metrics['sampled']) && isset($metrics['success_rate'])) {
            if ($metrics['success_rate'] < 0.2) {
                $base += 0.05;
            } elseif ($metrics['success_rate'] > 0.5) {
                $base -= 0.05;
            }
        }

        return max(0.1, min(0.35, $base));
    }

    private function getMerchantMinImbalance(): float
    {
        $base = (float) config('bots.merchant_trade_min_imbalance', 0.35);
        $metrics = app(BotActionMetricsService::class)->getTradeMetrics();
        if (!empty($metrics['sampled']) && isset($metrics['success_rate'])) {
            if ($metrics['success_rate'] < 0.2) {
                $base += 0.05;
            } elseif ($metrics['success_rate'] > 0.6) {
                $base -= 0.05;
            }
        }

        return max(0.15, min(0.6, $base));
    }

    private function estimateAttackLossRatio(int $attackPower, int $defensePower): float
    {
        $ratio = $attackPower / max(1, $defensePower);

        return match (true) {
            $ratio >= 2.0 => 0.10,
            $ratio >= 1.4 => 0.25,
            $ratio >= 1.1 => 0.40,
            $ratio >= 0.9 => 0.65,
            default => 0.85,
        };
    }

    private function shouldAbortAttackByPhalanx(PlanetService $source, PlanetService $target, \OGame\GameObjects\Models\Units\UnitCollection $fleet, float $speedPercent): bool
    {
        try {
            if (!config('bots.attack_phalanx_scan_enabled', true)) {
                return false;
            }
            $scanChance = (float) config('bots.attack_phalanx_scan_chance', 0.35);
            if ($scanChance <= 0 || mt_rand(1, 100) > ($scanChance * 100)) {
                return false;
            }

            $moons = $this->player->planets->allMoons();
            if (empty($moons)) {
                return false;
            }

            $phalanxService = app(PhalanxService::class);
            $fleetMissionService = app(FleetMissionService::class);
            $targetCoords = $target->getPlanetCoordinates();
            $arrival = now()->timestamp + $fleetMissionService->calculateFleetMissionDuration($source, $targetCoords, $fleet, null, $speedPercent);

            foreach ($moons as $moon) {
                $level = $moon->getObjectLevel('sensor_phalanx');
                if ($level < 1) {
                    continue;
                }

                if (!$phalanxService->canScanTarget($moon->getPlanetCoordinates()->galaxy, $moon->getPlanetCoordinates()->system, $level, $targetCoords, $this->player->getId())) {
                    continue;
                }

                $deut = $moon->getResources()->deuterium->get();
                if (!$phalanxService->hasEnoughDeuterium($deut)) {
                    continue;
                }

                $moon->planet->deuterium = max(0, (int) $moon->planet->deuterium - $phalanxService->getScanCost());
                $moon->planet->save();

                $scan = $phalanxService->scanPlanetFleets($target->getPlanetId(), $this->player->getId());
                $abortWindow = (int) config('bots.attack_phalanx_abort_window_seconds', 300);
                foreach ($scan as $fleetInfo) {
                    if (!empty($fleetInfo['is_incoming']) && ($fleetInfo['time_arrival'] ?? 0) <= ($arrival + $abortWindow)) {
                        $this->logAction(BotActionType::ATTACK, 'Phalanx scan indicates incoming support, aborting attack', [
                            'target' => $targetCoords->asString(),
                        ], 'failed');
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: shouldAbortAttackByPhalanx failed: {$e->getMessage()}");
            return false;
        }

        return false;
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
        if ($this->isUnderThreat()) {
            $this->logAction(BotActionType::ATTACK, 'Under threat, prioritizing defense', [], 'failed');
            return false;
        }

        try {
            // Use fleet planet (best military) instead of richest for attacks
            $source = $this->getFleetPlanet();
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

            if (!$this->hasRecentEspionageReport($target)) {
                if ($this->sendEspionageProbe($target)) {
                    $this->bot->updateLastAction();
                    return true;
                }
                $this->logAction(BotActionType::ATTACK, 'No recent espionage report available', [], 'failed');
                return false;
            }

            $report = $this->getLatestEspionageReport($target);
            $reportPower = 0;
            if ($report) {
                $this->recordTargetIntel($report, $target);
                $reportPower = $this->calculateReportDefensePower($report);
                if ($reportPower > 0 && $reportPower > $this->calculateFleetPower($source) * 1.2) {
                    $this->logAction(BotActionType::ATTACK, 'Espionage report indicates target too strong', [
                        'report_power' => $reportPower,
                    ], 'failed');
                    if ($this->sendMissileAttack($target, $report)) {
                        $this->bot->updateLastAction();
                        return true;
                    }
                    return false;
                }
            }

            // Build attack fleet based on target
            $fleetBuilder = app(BotFleetBuilderService::class);
            $fleet = $fleetBuilder->buildAttackFleet($this, $target);

            if ($fleet->getAmount() === 0) {
                $this->logAction(BotActionType::ATTACK, 'No fleet available', [], 'failed');
                return false;
            }

            $lootEstimate = 0;
            if ($report) {
                $lootEstimate = (int) (($report->resources['metal'] ?? 0)
                    + ($report->resources['crystal'] ?? 0)
                    + ($report->resources['deuterium'] ?? 0));
            }
            $cargoCapacity = $fleet->getTotalCargoCapacity($this->player);
            $minLootRatio = $this->getAttackMinLootRatio();
            if ($cargoCapacity <= 0 || $lootEstimate < ($cargoCapacity * $minLootRatio)) {
                $this->logAction(BotActionType::ATTACK, 'Target loot too low for fleet capacity', [
                    'loot' => $lootEstimate,
                    'capacity' => $cargoCapacity,
                ], 'failed');
                $this->addAvoidTargetUserId($target->getPlayer()->getId());
                return false;
            }

            // Calculate consumption
            $fleetMissionService = app(FleetMissionService::class);
            $targetCoords = $target->getPlanetCoordinates();
            $speedPercent = $this->pickAttackSpeedPercent($target);
            $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 0, $speedPercent);

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
                $this->addAvoidTargetUserId($target->getPlayer()->getId());
                return false;
            }

            $lossRatio = $this->estimateAttackLossRatio($attackPower, max($targetPower, $reportPower ?? 0));
            $lossMultiplier = (int) config('bots.attack_expected_loss_cost_multiplier', 1000);
            $expectedLossCost = (int) ($attackPower * $lossRatio * $lossMultiplier);
            $profitRatio = $this->getAttackProfitRatio();
            $consumptionMultiplier = (float) config('bots.attack_min_profit_consumption_multiplier', 1.0);
            $minProfit = (int) (($consumption * $consumptionMultiplier) + ($expectedLossCost * $profitRatio));
            if ($lootEstimate < $minProfit) {
                $this->logAction(BotActionType::ATTACK, 'Attack not profitable after expected losses', [
                    'loot' => $lootEstimate,
                    'consumption' => $consumption,
                    'expected_loss_cost' => $expectedLossCost,
                ], 'failed');
                $this->addAvoidTargetUserId($target->getPlayer()->getId());
                return false;
            }

            if ($this->shouldAbortAttackByPhalanx($source, $target, $fleet, $speedPercent)) {
                $this->addAvoidTargetUserId($target->getPlayer()->getId());
                return false;
            }

            // Send the fleet
            $mission = $fleetMissionService->createNewFromPlanet(
                $source,
                $targetCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                1, // Attack mission
                $fleet,
                new Resources(0, 0, 0, 0),
                $speedPercent,
                0
            );

            $this->logAction(BotActionType::ATTACK, "Sent attack to {$targetCoords->asString()} (power: {$attackPower} vs {$targetPower})", [
                'units' => $fleet->getAmount(),
                'consumption' => $consumption,
                'target_player' => $target->getPlayer()->username,
            ]);

            $this->setAllianceTargetCoordinates($targetCoords);
            $this->tryCreateAcsUnion($mission, $target);

            // Record battle history for learning
            if (config('bots.record_battle_history', true)) {
                $this->recordBattleHistory($target, $attackPower, $targetPower, $lootEstimate, $consumption);
            }

            // Schedule debris recycling after attack
            if (config('bots.auto_recycle_after_attack', true)) {
                cache()->put("bot:{$this->bot->id}:recycle_target", [
                    'g' => $targetCoords->galaxy,
                    's' => $targetCoords->system,
                    'p' => $targetCoords->position,
                ], now()->addHours(2));
            }

            // Set cooldown (in minutes)
            $cooldown = (int) config('bots.default_attack_cooldown_minutes', 30);
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

            if ($this->player->getExpeditionSlotsInUse() >= $this->player->getExpeditionSlotsMax()) {
                $this->logAction(BotActionType::FLEET, 'No expedition slots available', [], 'failed');
                return false;
            }

            // Use fleet planet for expeditions (has the most ships)
            $source = $this->getFleetPlanet() ?? $this->getRichestPlanet();
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
            $speedPercent = rand(70, 100);
            $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $expeditionCoords, 1, $speedPercent);

            if ($source->getResources()->deuterium->get() < $consumption) {
                $this->logAction(BotActionType::FLEET, 'Not enough deuterium for expedition', [
                    'required' => $consumption,
                    'available' => $source->getResources()->deuterium->get(),
                ], 'failed');
                return false;
            }

            // Variable holding time for better rewards
            $holdMin = (int) config('bots.expedition_holding_hours_min', 1);
            $holdMax = (int) config('bots.expedition_holding_hours_max', 4);
            $holdingHours = rand($holdMin, $holdMax);

            // Send the expedition
            $fleetMissionService->createNewFromPlanet(
                $source,
                $expeditionCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                15, // Expedition mission
                $fleet,
                new Resources(0, 0, 0, 0),
                $speedPercent,
                $holdingHours
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

        $known = $this->getBestKnownTarget();
        if ($known) {
            return $known;
        }

        $allianceTarget = $this->getAllianceTargetCoordinates();
        if ($allianceTarget !== null) {
            $planet = \OGame\Models\Planet::where('galaxy', $allianceTarget->galaxy)
                ->where('system', $allianceTarget->system)
                ->where('planet', $allianceTarget->position)
                ->first();
            if ($planet && !in_array($planet->user_id, $this->getAvoidTargetUserIds(), true) && $planet->user_id !== $this->player->getId()) {
                return app(\OGame\Factories\PlanetServiceFactory::class)->make($planet->id);
            }
        }

        // Get candidate targets from target finder
        $candidate = $targetFinder->findTarget($this, $targetType);

        if ($candidate === null) {
            return null;
        }

        // Calculate profitability
        $report = $this->getLatestEspionageReport($candidate);
        $maxIntelAge = (int) config('bots.target_intel_max_age_minutes', 30);
        if ($report && $report->created_at && $report->created_at->diffInMinutes(now()) < $maxIntelAge) {
            $loot = (int) (($report->resources['metal'] ?? 0) + ($report->resources['crystal'] ?? 0) + ($report->resources['deuterium'] ?? 0));
            if ($loot < 10000) {
                $this->logAction(BotActionType::ATTACK, 'Target not profitable enough', [
                    'target_loot' => $loot,
                ], 'failed');
                return null;
            }

            $targetPower = $this->calculateReportDefensePower($report);
            $sourcePower = $this->calculateFleetPower($source);
            if ($targetPower > 0 && $targetPower > $sourcePower * 2) {
                $this->logAction(BotActionType::ATTACK, 'Target too strong (espionage)', [
                    'target_power' => $targetPower,
                    'source_power' => $sourcePower,
                ], 'failed');
                return null;
            }
        }

        return $candidate;
    }

    private function recordTargetIntel(EspionageReport $report, PlanetService $target): void
    {
        $coords = $target->getPlanetCoordinates();
        $loot = ($report->resources['metal'] ?? 0) + ($report->resources['crystal'] ?? 0) + ($report->resources['deuterium'] ?? 0);
        $defense = $this->calculateReportDefensePower($report);
        $userId = $target->getPlayer()->getId();

        // Cache-based intel (fast lookup)
        $cacheKey = 'bot_target_intel_' . $this->bot->id;
        $intel = cache()->get($cacheKey, []);
        if (!is_array($intel)) {
            $intel = [];
        }
        $intel[$userId] = [
            'loot' => $loot,
            'defense' => $defense,
            'g' => $coords->galaxy,
            's' => $coords->system,
            'p' => $coords->position,
            'ts' => now()->timestamp,
        ];
        cache()->put($cacheKey, $intel, now()->addHours(12));

        // Persistent DB-based intel
        try {
            $intelService = new BotIntelligenceService();
            $intelService->recordEspionageIntel($this->bot->id, $report, $userId);

            // Update activity pattern for the target
            $targetUser = $target->getPlayer()->getUser();
            $isActive = ($targetUser->time ?? 0) > (now()->timestamp - 900);
            $intelService->updateActivityPattern($this->bot->id, $userId, $isActive);
        } catch (\Exception $e) {
            // Non-critical: persistent intel recording failed
            logger()->debug("Bot {$this->bot->id}: persistent intel recording failed: {$e->getMessage()}");
        }
    }

    private function getBestKnownTarget(): ?PlanetService
    {
        $cacheKey = 'bot_target_intel_' . $this->bot->id;
        $intel = cache()->get($cacheKey, []);
        if (!is_array($intel) || empty($intel)) {
            return null;
        }

        $avoid = $this->getAvoidTargetUserIds();
        $best = null;
        foreach ($intel as $userId => $data) {
            if (in_array($userId, $avoid, true)) {
                continue;
            }
            if (!$best || ($data['loot'] - $data['defense']) > ($best['loot'] - $best['defense'])) {
                $best = $data + ['user_id' => $userId];
            }
        }

        if (!$best) {
            return null;
        }

        $planet = \OGame\Models\Planet::where('user_id', $best['user_id'])->first();
        if (!$planet) {
            return null;
        }

        return app(\OGame\Factories\PlanetServiceFactory::class)->make($planet->id);
    }

    private function hasRecentEspionageReport(PlanetService $target): bool
    {
        $report = $this->getLatestEspionageReport($target);
        if (!$report) {
            return false;
        }

        $maxAge = (int) config('bots.espionage_report_max_age_minutes', 20);
        return $report->created_at && $report->created_at->diffInMinutes(now()) < $maxAge;
    }

    private function getLatestEspionageReport(PlanetService $target): ?EspionageReport
    {
        $coords = $target->getPlanetCoordinates();
        return EspionageReport::where('planet_galaxy', $coords->galaxy)
            ->where('planet_system', $coords->system)
            ->where('planet_position', $coords->position)
            ->where('planet_type', $target->getPlanetType()->value)
            ->latest()
            ->first();
    }

    private function calculateReportDefensePower(EspionageReport $report): int
    {
        $total = 0;
        $ships = $report->ships ?? [];
        $defense = $report->defense ?? [];

        foreach ($ships as $machine => $amount) {
            $total += $this->getUnitPoints($machine) * (int) $amount;
        }
        foreach ($defense as $machine => $amount) {
            $total += $this->getUnitPoints($machine) * (int) $amount;
        }

        return $total;
    }

    public function sendEspionageProbe(PlanetService $target): bool
    {
        try {
            if (!$this->hasFleetSlotsAvailable()) {
                return false;
            }

            // Use planet closest to target or with probes available
            $source = $this->getFleetPlanet() ?? $this->getRichestPlanet();
            if ($source === null) {
                return false;
            }

            $probesAvailable = $source->getObjectAmount('espionage_probe');
            if ($probesAvailable < 1) {
                return false;
            }

            $probeCount = min(5, $probesAvailable);
            $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), $probeCount);

            $fleetMissionService = app(FleetMissionService::class);
            $targetCoords = $target->getPlanetCoordinates();
            $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 6, 100);
            if ($source->getResources()->deuterium->get() < $consumption) {
                return false;
            }

            $fleetMissionService->createNewFromPlanet(
                $source,
                $targetCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                6,
                $fleet,
                new Resources(0, 0, 0, 0),
                100,
                0
            );

            $this->logAction(BotActionType::ATTACK, "Sent espionage probes ({$probeCount}) to {$targetCoords->asString()}", [
                'consumption' => $consumption,
            ]);

            return true;
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: sendEspionageProbe failed: {$e->getMessage()}");
            return false;
        }
    }

    public function sendMissileAttack(PlanetService $target, ?EspionageReport $report = null): bool
    {
        try {
            $source = $this->getRichestPlanet();
            if ($source === null) {
                return false;
            }

            $missiles = $source->getObjectAmount('interplanetary_missile');
            if ($missiles < 1) {
                return false;
            }

            $targetCoords = $target->getPlanetCoordinates();
            $range = $this->player->getMissileRange();
            $distance = abs($source->getPlanetCoordinates()->system - $targetCoords->system);
            if ($source->getPlanetCoordinates()->galaxy !== $targetCoords->galaxy || $distance > $range) {
                return false;
            }

            $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
            $sendAmount = min(10, $missiles);
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName('interplanetary_missile'), $sendAmount);

            $fleetMissionService = app(FleetMissionService::class);
            $fleetMissionService->createNewFromPlanet(
                $source,
                $targetCoords,
                \OGame\Models\Enums\PlanetType::Planet,
                10,
                $fleet,
                new Resources(0, 0, 0, 0),
                100,
                0
            );

            $this->logAction(BotActionType::ATTACK, "Launched missiles to {$targetCoords->asString()}", [
                'missiles' => $sendAmount,
            ]);

            return true;
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: sendMissileAttack failed: {$e->getMessage()}");
            return false;
        }
    }

    public function ensureAlliance(): void
    {
        try {
            $user = $this->player->getUser();
            if ($user->alliance_id) {
                if (config('bots.alliance_auto_accept', true)) {
                    $this->processAllianceApplications($user->alliance_id, $user->id);
                }
                return;
            }

            $cooldownMinutes = (int) config('bots.alliance_action_cooldown_minutes', 360);
            $cacheKey = 'bot_alliance_action_' . $this->bot->id;
            $lastAction = cache()->get($cacheKey);
            if ($lastAction) {
                return;
            }

            $applyChance = (float) config('bots.alliance_apply_chance', 0.05);
            if (mt_rand(1, 100) > ($applyChance * 100)) {
                return;
            }

            $maxMembers = (int) config('bots.alliance_max_members', 30);
            $openAlliance = Alliance::where('is_open', true)
                ->withCount('members')
                ->having('members_count', '<', $maxMembers)
                ->inRandomOrder()
                ->first();
            $allianceService = app(\OGame\Services\AllianceService::class);

            if ($openAlliance) {
                $allianceService->applyToAlliance($user->id, $openAlliance->id, 'Bot auto-application');
                cache()->put($cacheKey, now()->timestamp, now()->addMinutes($cooldownMinutes));
                return;
            }

            $createChance = (float) config('bots.alliance_create_chance', 0.02);
            if (mt_rand(1, 100) > ($createChance * 100)) {
                return;
            }

            $maxAlliances = (int) config('bots.alliance_max_created', 50);
            $currentAlliances = Alliance::count();
            if ($currentAlliances >= $maxAlliances) {
                return;
            }

            $tag = strtoupper(Str::random(4));
            $name = 'Bot Legion ' . strtoupper(Str::random(3));
            $alliance = $allianceService->createAlliance($user->id, $tag, $name);
            $alliance->is_open = true;
            $alliance->application_text = 'Bots and players welcome.';
            $alliance->save();
            cache()->put($cacheKey, now()->timestamp, now()->addMinutes($cooldownMinutes));
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: ensureAlliance failed: {$e->getMessage()}");
        }
    }

    private function processAllianceApplications(int $allianceId, int $userId): void
    {
        try {
            $allianceService = app(\OGame\Services\AllianceService::class);
            $applications = $allianceService->getPendingApplications($allianceId);

            if ($applications->isEmpty()) {
                return;
            }

            foreach ($applications as $application) {
                $allianceService->acceptApplication($application->id, $userId);
            }
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: processAllianceApplications failed: {$e->getMessage()}");
        }
    }

    public function getAvoidTargetUserIds(): array
    {
        $cacheKey = 'bot_avoid_users_' . $this->bot->id;
        $ids = cache()->get($cacheKey, []);
        return is_array($ids) ? $ids : [];
    }

    private function addAvoidTargetUserId(int $userId): void
    {
        $cacheKey = 'bot_avoid_users_' . $this->bot->id;
        $ids = $this->getAvoidTargetUserIds();
        if (!in_array($userId, $ids, true)) {
            $ids[] = $userId;
            cache()->put($cacheKey, $ids, now()->addHours(12));
        }
    }

    private function getAllianceTargetCoordinates(): ?\OGame\Models\Planet\Coordinate
    {
        $user = $this->player->getUser();
        if (!$user->alliance_id) {
            return null;
        }
        $key = 'alliance_target_' . $user->alliance_id;
        $coords = cache()->get($key);
        if (!is_array($coords)) {
            return null;
        }
        return new \OGame\Models\Planet\Coordinate($coords['g'], $coords['s'], $coords['p']);
    }

    private function setAllianceTargetCoordinates(\OGame\Models\Planet\Coordinate $coords): void
    {
        $user = $this->player->getUser();
        if (!$user->alliance_id) {
            return;
        }
        $key = 'alliance_target_' . $user->alliance_id;
        cache()->put($key, ['g' => $coords->galaxy, 's' => $coords->system, 'p' => $coords->position], now()->addMinutes(30));
    }

    private function tryCreateAcsUnion(FleetMission $mission, PlanetService $target): void
    {
        try {
            $user = $this->player->getUser();
            if (!$user->alliance_id || !config('bots.allow_alliances', true)) {
                return;
            }

            if (mt_rand(1, 100) > 20) {
                return;
            }

            $fleetUnionService = app(FleetUnionService::class);
            $union = $fleetUnionService->createUnion($mission, 'Bot ACS');

            $allyBots = Bot::where('is_active', true)
                ->where('id', '!=', $this->bot->id)
                ->whereHas('user', function ($q) use ($user) {
                    $q->where('alliance_id', $user->alliance_id);
                })
                ->limit(3)
                ->get();

            foreach ($allyBots as $allyBot) {
                $allyService = app(\OGame\Factories\BotServiceFactory::class)->makeFromBotModel($allyBot);
                if (!$allyService->hasFleetSlotsAvailable() || $allyService->isUnderThreat()) {
                    continue;
                }

                $source = $allyService->getRichestPlanet();
                if ($source === null) {
                    continue;
                }

                $fleetBuilder = app(BotFleetBuilderService::class);
                $allyFleet = $fleetBuilder->buildAttackFleet($allyService, $target);
                if ($allyFleet->getAmount() === 0) {
                    continue;
                }

                $fleetMissionService = app(FleetMissionService::class);
                $speedPercent = 80;
                $allyMission = $fleetMissionService->createNewFromPlanet(
                    $source,
                    $target->getPlanetCoordinates(),
                    \OGame\Models\Enums\PlanetType::Planet,
                    1,
                    $allyFleet,
                    new Resources(0, 0, 0, 0),
                    $speedPercent,
                    0
                );

                $fleetUnionService->joinUnion($union, $allyMission);
                $allyService->logAction(BotActionType::ATTACK, 'Joined ACS attack', [
                    'union_id' => $union->id,
                ]);
                break;
            }
        } catch (Exception $e) {
            logger()->warning("Bot {$this->bot->id}: tryCreateAcsUnion failed: {$e->getMessage()}");
        }
    }

    private function pickAttackSpeedPercent(PlanetService $target): float
    {
        try {
            $targetUser = $target->getPlayer()->getUser();
            $lastActive = $targetUser->time ?? null;
            $inactiveMinutes = $lastActive ? (now()->timestamp - $lastActive) / 60 : 0;

            if ($inactiveMinutes > 60) {
                return rand(60, 80);
            }
        } catch (Exception) {
            // ignore
        }

        return rand(80, 100);
    }

    /**
     * Calculate fleet power for a planet (ships + defenses).
     */
    private function calculateTargetFleetPower(PlanetService $planet): int
    {
        $totalPower = 0;
        $units = $planet->getShipUnits();

        foreach ($units->units as $unitObj) {
            $points = $this->getUnitPoints($unitObj->unitObject->machine_name);
            $totalPower += $points * $unitObj->amount;
        }

        // Add defense power using the same getUnitPoints() values for consistency.
        $defenseNames = [
            'rocket_launcher', 'light_laser', 'heavy_laser',
            'gauss_cannon', 'ion_cannon', 'plasma_turret',
            'small_shield_dome', 'large_shield_dome',
        ];

        foreach ($defenseNames as $defense) {
            $amount = $planet->getObjectLevel($defense);
            if ($amount > 0) {
                $totalPower += $this->getUnitPoints($defense) * $amount;
            }
        }

        return $totalPower;
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
     * Get unit combat power points for fleet/defense power calculation.
     */
    private function getUnitPoints(string $machineName): int
    {
        $points = [
            // Ships
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
            // Defense
            'rocket_launcher' => 2,
            'light_laser' => 2,
            'heavy_laser' => 4,
            'gauss_cannon' => 10,
            'ion_cannon' => 8,
            'plasma_turret' => 30,
            'small_shield_dome' => 5,
            'large_shield_dome' => 20,
        ];

        return $points[$machineName] ?? 1;
    }

    /**
     * Build moon infrastructure (lunar base, phalanx, jump gate).
     */
    public function buildMoonInfrastructure(): bool
    {
        if (!config('bots.moon_building_enabled', true)) {
            return false;
        }

        try {
            $moons = $this->player->planets->allMoons();
            if (empty($moons)) {
                return false;
            }

            $queueService = app(BuildingQueueService::class);
            $moonBuildings = [
                'lunar_base' => 150,
                'sensor_phalanx' => 120,
                'jump_gate' => 100,
            ];

            foreach ($moons as $moon) {
                if ($this->isBuildingQueueFull($moon)) {
                    continue;
                }

                $bestBuilding = null;
                $bestScore = 0;

                foreach ($moonBuildings as $name => $basePriority) {
                    if (!ObjectService::objectRequirementsMet($name, $moon)) {
                        continue;
                    }

                    $level = $moon->getObjectLevel($name);
                    $price = ObjectService::getObjectPrice($name, $moon);
                    $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();

                    $resources = $moon->getResources();
                    $total = $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();

                    if ($cost > $total * 0.8) {
                        continue;
                    }

                    // Lunar base is critical - needed for other buildings
                    $score = $basePriority;
                    if ($name === 'lunar_base' && $level < 3) {
                        $score += 100;
                    }
                    // Phalanx is very valuable for intelligence
                    if ($name === 'sensor_phalanx' && $level < 5) {
                        $score += 50;
                        if (in_array($this->getPersonality(), [BotPersonality::RAIDER, BotPersonality::AGGRESSIVE])) {
                            $score += 30;
                        }
                    }
                    // Jump gate for fleet mobility
                    if ($name === 'jump_gate' && $level < 1) {
                        $score += 40;
                    }

                    $score -= $level * 10;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestBuilding = $name;
                    }
                }

                if ($bestBuilding) {
                    $building = ObjectService::getObjectByMachineName($bestBuilding);
                    $queueService->add($moon, $building->id);
                    $price = ObjectService::getObjectPrice($bestBuilding, $moon);
                    $this->logAction(BotActionType::BUILD, "Moon build: {$bestBuilding} on {$moon->getPlanetName()}", [
                        'metal' => $price->metal->get(),
                        'crystal' => $price->crystal->get(),
                        'deuterium' => $price->deuterium->get(),
                    ]);
                    $this->bot->updateLastAction();
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: buildMoonInfrastructure failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Proactive phalanx scan of nearby targets before attacking.
     */
    public function proactivePhalanxScan(): bool
    {
        if (!config('bots.proactive_phalanx_enabled', true)) {
            return false;
        }

        try {
            $moons = $this->player->planets->allMoons();
            if (empty($moons)) {
                return false;
            }

            $phalanxService = app(PhalanxService::class);

            foreach ($moons as $moon) {
                $level = $moon->getObjectLevel('sensor_phalanx');
                if ($level < 1) {
                    continue;
                }

                $deut = $moon->getResources()->deuterium->get();
                if (!$phalanxService->hasEnoughDeuterium($deut)) {
                    continue;
                }

                // Find a nearby target to scan
                $moonCoords = $moon->getPlanetCoordinates();
                $intel = new BotIntelligenceService();
                $targets = $intel->getProfitableTargets($this->bot->id, 3);

                foreach ($targets as $target) {
                    $targetCoords = new \OGame\Models\Planet\Coordinate($target->galaxy, $target->system, $target->planet);

                    if (!$phalanxService->canScanTarget($moonCoords->galaxy, $moonCoords->system, $level, $targetCoords, $this->player->getId())) {
                        continue;
                    }

                    // Pay deuterium cost
                    $moon->planet->deuterium = max(0, (int) $moon->planet->deuterium - $phalanxService->getScanCost());
                    $moon->planet->save();

                    $scan = $phalanxService->scanPlanetFleets($target->target_planet_id ?? 0, $this->player->getId());

                    // Log the scan for intelligence
                    $fleetPresent = !empty($scan);
                    $this->logAction(BotActionType::ESPIONAGE, "Phalanx scan: {$targetCoords->asString()}, fleet=" . ($fleetPresent ? 'yes' : 'no'), []);

                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: proactivePhalanxScan failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Record battle history for learning from attack outcomes.
     */
    private function recordBattleHistory(PlanetService $target, int $attackPower, int $defensePower, int $estimatedLoot, int $consumption): void
    {
        try {
            \OGame\Models\BotBattleHistory::create([
                'bot_id' => $this->bot->id,
                'target_user_id' => $target->getPlayer()->getId(),
                'target_planet_id' => $target->getPlanetId(),
                'attack_power' => $attackPower,
                'defense_power' => $defensePower,
                'estimated_loot' => $estimatedLoot,
                'fuel_cost' => $consumption,
                'result' => 'pending', // Updated when mission completes
            ]);
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: recordBattleHistory failed: {$e->getMessage()}");
        }
    }

    /**
     * Try to recycle debris from a previous attack location.
     */
    public function tryRecycleAfterAttack(): bool
    {
        $cached = cache()->get("bot:{$this->bot->id}:recycle_target");
        if (!is_array($cached)) {
            return false;
        }

        cache()->forget("bot:{$this->bot->id}:recycle_target");

        if (!$this->hasFleetSlotsAvailable()) {
            return false;
        }

        $source = $this->getFleetPlanet() ?? $this->getRichestPlanet();
        if ($source === null) {
            return false;
        }

        $recyclers = $source->getObjectAmount('recycler');
        if ($recyclers < 1) {
            return false;
        }

        $targetCoords = new \OGame\Models\Planet\Coordinate($cached['g'], $cached['s'], $cached['p']);

        $field = \OGame\Models\DebrisField::where('galaxy', $cached['g'])
            ->where('system', $cached['s'])
            ->where('planet', $cached['p'])
            ->first();

        if (!$field || ($field->metal + $field->crystal + $field->deuterium) < 1000) {
            return false;
        }

        $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName('recycler'), min(30, $recyclers));

        $fleetMissionService = app(FleetMissionService::class);
        $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 0, 100);
        if ($source->getResources()->deuterium->get() < $consumption) {
            return false;
        }

        $fleetMissionService->createNewFromPlanet(
            $source,
            $targetCoords,
            \OGame\Models\Enums\PlanetType::DebrisField,
            8,
            $fleet,
            new Resources(0, 0, 0, 0),
            100,
            0
        );

        $this->logAction(BotActionType::FLEET, "Post-attack recycle to {$targetCoords->asString()}", [
            'recyclers' => $fleet->getAmount(),
        ]);

        return true;
    }

    /**
     * Find a target planet to attack.
     */
    public function findTarget(): ?PlanetService
    {
        $targetFinder = app(BotTargetFinderService::class);
        return $targetFinder->findTarget($this, $this->bot->getTargetTypeEnum());
    }

    // ========================================================================
    // SYSTEM 1: Character Class-Aware Strategy
    // ========================================================================

    /**
     * Get class-based bonuses for the bot's character class.
     * Returns multipliers and flags that affect decision-making.
     */
    public function getClassBonuses(): array
    {
        $user = $this->player->getUser();
        $class = CharacterClass::tryFrom($user->character_class ?? 0);

        return match ($class) {
            CharacterClass::COLLECTOR => [
                'mine_production' => 1.25,
                'transport_speed' => 2.0,
                'transport_cargo' => 1.25,
                'crawler_bonus' => 1.5,
                'speedup_discount_type' => 'building',
                'prefer_economy' => true,
                'prefer_attacks' => false,
                'prefer_expeditions' => false,
            ],
            CharacterClass::GENERAL => [
                'combat_speed' => 2.0,
                'fuel_reduction' => 0.5,
                'extra_fleet_slots' => 2,
                'extra_combat_research' => 2,
                'reaper_debris' => 0.30,
                'speedup_discount_type' => 'shipyard',
                'prefer_economy' => false,
                'prefer_attacks' => true,
                'prefer_expeditions' => false,
            ],
            CharacterClass::DISCOVERER => [
                'research_time' => 0.75,
                'expedition_resources' => 1.5,
                'planet_size' => 1.10,
                'extra_expeditions' => 2,
                'enemy_chance' => 0.5,
                'phalanx_range' => 1.20,
                'inactive_loot' => 0.75,
                'speedup_discount_type' => 'research',
                'prefer_economy' => false,
                'prefer_attacks' => false,
                'prefer_expeditions' => true,
            ],
            default => [
                'prefer_economy' => false,
                'prefer_attacks' => false,
                'prefer_expeditions' => false,
            ],
        };
    }

    /**
     * Get the bot's optimal class based on personality (for class-aware strategy).
     */
    public function getOptimalClassForPersonality(): CharacterClass
    {
        return match ($this->getPersonality()) {
            BotPersonality::AGGRESSIVE, BotPersonality::RAIDER => CharacterClass::GENERAL,
            BotPersonality::ECONOMIC, BotPersonality::DIPLOMAT => CharacterClass::COLLECTOR,
            BotPersonality::SCIENTIST, BotPersonality::EXPLORER => CharacterClass::DISCOVERER,
            BotPersonality::DEFENSIVE, BotPersonality::TURTLE => CharacterClass::GENERAL,
            default => CharacterClass::DISCOVERER,
        };
    }

    // ========================================================================
    // SYSTEM 2: Dark Matter Halving (DM Speedup)
    // ========================================================================

    /**
     * Try to halve the longest-running queue item using Dark Matter.
     * Prioritizes based on class bonuses and strategic value.
     */
    public function tryHalveQueue(): bool
    {
        try {
            $user = $this->player->getUser();
            $dmBalance = $user->dark_matter;

            // Only halve if we have significant DM reserves
            $minDmForHalving = (int) config('bots.min_dm_for_halving', 5000);
            if ($dmBalance < $minDmForHalving) {
                return false;
            }

            // Find the longest running queue item
            $bestCandidate = null;
            $bestRemainingTime = 0;
            $bestType = null;

            // Check building queues
            foreach ($this->player->planets->all() as $planet) {
                $buildingQueue = \OGame\Models\BuildingQueue::where('planet_id', $planet->getPlanetId())
                    ->where('processed', 0)
                    ->where('canceled', 0)
                    ->where('building', 1)
                    ->first();

                if ($buildingQueue) {
                    $remaining = (int) $buildingQueue->time_end - now()->timestamp;
                    if ($remaining > 300 && $remaining > $bestRemainingTime) { // > 5 min
                        $bestCandidate = $buildingQueue;
                        $bestRemainingTime = $remaining;
                        $bestType = 'building';
                    }
                }
            }

            // Check research queue
            $researchQueue = \OGame\Models\ResearchQueue::query()
                ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
                ->where('planets.user_id', $user->id)
                ->where('research_queues.processed', 0)
                ->where('research_queues.canceled', 0)
                ->where('research_queues.building', 1)
                ->select('research_queues.*')
                ->first();

            if ($researchQueue) {
                $remaining = (int) $researchQueue->time_end - now()->timestamp;
                if ($remaining > 300 && $remaining > $bestRemainingTime) {
                    $bestCandidate = $researchQueue;
                    $bestRemainingTime = $remaining;
                    $bestType = 'research';
                }
            }

            if (!$bestCandidate || !$bestType) {
                return false;
            }

            // Calculate cost
            $halvingService = app(HalvingService::class);
            $cost = $halvingService->calculateHalvingCost($bestRemainingTime, $bestType);

            // Only spend if we can afford it with buffer
            if ($dmBalance < $cost * 1.5) {
                return false;
            }

            // Class discount awareness
            $classBonuses = $this->getClassBonuses();
            $discountType = $classBonuses['speedup_discount_type'] ?? null;
            $isDiscountedType = ($discountType === $bestType);

            // Prioritize halving items that match our class discount
            if (!$isDiscountedType && $bestRemainingTime < 1800) {
                return false; // Don't halve short items without discount
            }

            // Execute halving
            if ($bestType === 'building') {
                $planet = null;
                foreach ($this->player->planets->all() as $p) {
                    if ($p->getPlanetId() === (int) $bestCandidate->planet_id) {
                        $planet = $p;
                        break;
                    }
                }
                if (!$planet) return false;
                $result = $halvingService->halveBuilding($user, $bestCandidate->id, $planet);
            } else {
                $result = $halvingService->halveResearch($user, $bestCandidate->id, $this->player);
            }

            if (!empty($result['success'])) {
                $this->logAction(BotActionType::BUILD, "DM halving ({$bestType}): saved " . (int)($bestRemainingTime / 2) . "s, cost {$result['cost']} DM", [
                    'dm_cost' => $result['cost'],
                    'time_saved' => (int)($bestRemainingTime / 2),
                    'type' => $bestType,
                ]);
                return true;
            }
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: tryHalveQueue failed: {$e->getMessage()}");
        }

        return false;
    }

    // ========================================================================
    // SYSTEM 3: Wreck Field Auto-Repair
    // ========================================================================

    /**
     * Check for and start repairs on wreck fields at bot's planets.
     */
    public function tryRepairWreckFields(): bool
    {
        try {
            foreach ($this->player->planets->all() as $planet) {
                $coords = $planet->getPlanetCoordinates();

                $wreckFields = \OGame\Models\WreckField::where('galaxy', $coords->galaxy)
                    ->where('system', $coords->system)
                    ->where('planet', $coords->position)
                    ->where('owner_player_id', $this->player->getId())
                    ->where('status', 'active')
                    ->get();

                foreach ($wreckFields as $wreckField) {
                    if ($wreckField->isExpired()) {
                        continue;
                    }

                    $shipData = $wreckField->ship_data ?? [];
                    if (empty($shipData)) {
                        continue;
                    }

                    // Check if planet has space dock
                    $spaceDockLevel = $planet->getObjectLevel('space_dock');
                    if ($spaceDockLevel < 1) {
                        continue;
                    }

                    // Start repairs
                    $wreckFieldService = app(WreckFieldService::class);
                    $wreckFieldService->loadOrCreateForCoordinates($coords);
                    $loaded = $wreckFieldService->loadActiveOrBlockedForCoordinates($coords);
                    if (!$loaded) {
                        continue;
                    }

                    $wf = $wreckFieldService->getWreckField();
                    if (!$wf || $wf->status !== 'active') {
                        continue;
                    }

                    $wreckFieldService->startRepairs($spaceDockLevel);

                    $totalShips = 0;
                    foreach ($shipData as $ship) {
                        $totalShips += $ship['quantity'] ?? 0;
                    }

                    $this->logAction(BotActionType::FLEET, "Started wreck field repair at {$coords->asString()}: {$totalShips} ships", [
                        'space_dock_level' => $spaceDockLevel,
                        'ships' => $totalShips,
                    ]);

                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: tryRepairWreckFields failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Complete wreck field repairs that are done.
     */
    public function tryCompleteWreckFieldRepairs(): bool
    {
        try {
            foreach ($this->player->planets->all() as $planet) {
                $coords = $planet->getPlanetCoordinates();

                $repairing = \OGame\Models\WreckField::where('galaxy', $coords->galaxy)
                    ->where('system', $coords->system)
                    ->where('planet', $coords->position)
                    ->where('owner_player_id', $this->player->getId())
                    ->where('status', 'repairing')
                    ->where('repair_completed_at', '<=', now())
                    ->get();

                foreach ($repairing as $wreckField) {
                    $wreckFieldService = app(WreckFieldService::class);
                    $wreckFieldService->loadForCoordinates($coords);
                    $wf = $wreckFieldService->getWreckField();
                    if (!$wf || $wf->id !== $wreckField->id) {
                        continue;
                    }

                    $shipData = $wreckFieldService->completeRepairs();
                    $totalRecovered = 0;
                    foreach ($shipData as $ship) {
                        $totalRecovered += $ship['quantity'] ?? 0;
                    }

                    $this->logAction(BotActionType::FLEET, "Wreck field repair completed at {$coords->asString()}: recovered {$totalRecovered} ships", []);
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: tryCompleteWreckFieldRepairs failed: {$e->getMessage()}");
            return false;
        }
    }

    // ========================================================================
    // SYSTEM 4: Mission Results Analysis (Learn from battle outcomes)
    // ========================================================================

    /**
     * Analyze completed attack missions and learn from outcomes.
     * Updates intel, threat maps, and avoid lists based on results.
     */
    public function analyzeCompletedMissions(): void
    {
        try {
            $userId = $this->player->getId();
            $cacheKey = "bot:{$this->bot->id}:last_mission_analysis";
            $lastAnalysis = cache()->get($cacheKey, 0);

            // Get recently completed attack missions
            $missions = FleetMission::where('user_id', $userId)
                ->where('processed', 1)
                ->where('mission_type', 1) // Attack
                ->where('time_arrival', '>', $lastAnalysis)
                ->orderBy('time_arrival')
                ->limit(10)
                ->get();

            if ($missions->isEmpty()) {
                return;
            }

            $intel = new BotIntelligenceService();

            foreach ($missions as $mission) {
                // Check battle report for this mission
                $targetUserId = null;
                $targetPlanet = Planet::where('galaxy', $mission->galaxy_to)
                    ->where('system', $mission->system_to)
                    ->where('planet', $mission->position_to)
                    ->first();

                if ($targetPlanet) {
                    $targetUserId = $targetPlanet->user_id;
                }

                // Analyze loot vs cost
                $lootMetal = $mission->metal ?? 0;
                $lootCrystal = $mission->crystal ?? 0;
                $lootDeuterium = $mission->deuterium ?? 0;
                $totalLoot = $lootMetal + $lootCrystal + $lootDeuterium;

                $won = $totalLoot > 0;

                // Record threat interaction
                if ($targetUserId) {
                    $intel->recordThreatInteraction($this->bot->id, $targetUserId, 'our_attack', $won);

                    // If we got zero loot and they were online, avoid them
                    if (!$won) {
                        $this->addAvoidTargetUserId($targetUserId);
                    }
                }

                // Learn profitability for future targeting
                if ($won && $targetUserId) {
                    // Good target - refresh intel
                    cache()->put("bot:{$this->bot->id}:good_target:{$targetUserId}", [
                        'loot' => $totalLoot,
                        'ts' => now()->timestamp,
                    ], now()->addHours(24));
                }
            }

            cache()->put($cacheKey, now()->timestamp, now()->addHours(24));
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: analyzeCompletedMissions failed: {$e->getMessage()}");
        }
    }

    /**
     * Analyze completed expedition missions for DM/resource tracking.
     */
    public function analyzeCompletedExpeditions(): void
    {
        try {
            $userId = $this->player->getId();
            $cacheKey = "bot:{$this->bot->id}:last_expedition_analysis";
            $lastAnalysis = cache()->get($cacheKey, 0);

            $missions = FleetMission::where('user_id', $userId)
                ->where('processed', 1)
                ->where('mission_type', 15) // Expedition
                ->where('time_arrival', '>', $lastAnalysis)
                ->orderBy('time_arrival')
                ->limit(10)
                ->get();

            if ($missions->isEmpty()) {
                return;
            }

            $totalLoot = 0;
            $totalFuel = 0;
            $expeditionCount = $missions->count();

            foreach ($missions as $mission) {
                $loot = ($mission->metal ?? 0) + ($mission->crystal ?? 0) + ($mission->deuterium ?? 0);
                $totalLoot += $loot;
                $totalFuel += $mission->fuel_consumption ?? 0;
            }

            // Track expedition ROI for adaptive strategy
            $roi = $totalFuel > 0 ? ($totalLoot / $totalFuel) : 0;
            cache()->put("bot:{$this->bot->id}:expedition_roi", [
                'roi' => $roi,
                'count' => $expeditionCount,
                'total_loot' => $totalLoot,
                'total_fuel' => $totalFuel,
                'ts' => now()->timestamp,
            ], now()->addHours(24));

            cache()->put($cacheKey, now()->timestamp, now()->addHours(24));
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: analyzeCompletedExpeditions failed: {$e->getMessage()}");
        }
    }

    // ========================================================================
    // SYSTEM 7: Systematic Debris Harvesting
    // ========================================================================

    /**
     * Find and collect debris fields systematically across nearby systems.
     */
    public function harvestNearbyDebris(): bool
    {
        if (!$this->hasFleetSlotsAvailable()) {
            return false;
        }

        try {
            $source = $this->getFleetPlanet() ?? $this->getRichestPlanet();
            if ($source === null) {
                return false;
            }

            $recyclers = $source->getObjectAmount('recycler');
            if ($recyclers < 1) {
                return false;
            }

            $coords = $source->getPlanetCoordinates();
            $range = (int) config('bots.debris_harvest_range', 20);

            // Find all debris fields in range, sorted by value
            $fields = \OGame\Models\DebrisField::where('galaxy', $coords->galaxy)
                ->whereBetween('system', [max(1, $coords->system - $range), $coords->system + $range])
                ->whereRaw('(metal + crystal + deuterium) > ?', [3000])
                ->orderByRaw('(metal + crystal + deuterium) DESC')
                ->limit(5)
                ->get();

            if ($fields->isEmpty()) {
                return false;
            }

            foreach ($fields as $field) {
                $totalValue = $field->metal + $field->crystal + $field->deuterium;
                $targetCoords = new \OGame\Models\Planet\Coordinate($field->galaxy, $field->system, $field->planet);

                // Calculate how many recyclers we need
                $recyclerCapacity = 20000; // Standard recycler cargo
                $classBonuses = $this->getClassBonuses();
                if (!empty($classBonuses['transport_cargo'])) {
                    $recyclerCapacity = (int) ($recyclerCapacity * $classBonuses['transport_cargo']);
                }

                $neededRecyclers = max(1, (int) ceil($totalValue / $recyclerCapacity));
                $sendRecyclers = min($neededRecyclers, $recyclers, 30);

                $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName('recycler'), $sendRecyclers);

                $fleetMissionService = app(FleetMissionService::class);
                $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 8, 100);

                // ROI check: debris value must exceed fuel cost
                if ($consumption > $totalValue * 0.5) {
                    continue;
                }

                if ($source->getResources()->deuterium->get() < $consumption) {
                    continue;
                }

                $fleetMissionService->createNewFromPlanet(
                    $source,
                    $targetCoords,
                    \OGame\Models\Enums\PlanetType::DebrisField,
                    8,
                    $fleet,
                    new Resources(0, 0, 0, 0),
                    100,
                    0
                );

                $this->logAction(BotActionType::FLEET, "Debris harvest to {$targetCoords->asString()}: value={$totalValue}", [
                    'recyclers' => $sendRecyclers,
                    'value' => $totalValue,
                ]);

                return true;
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: harvestNearbyDebris failed: {$e->getMessage()}");
            return false;
        }
    }

    // ========================================================================
    // SYSTEM 8: Counter-Espionage Calculation
    // ========================================================================

    /**
     * Calculate our counter-espionage strength and respond to probes.
     * Builds ABM when being heavily scouted.
     */
    public function handleCounterEspionage(): bool
    {
        try {
            $espionageCounter = $this->bot->espionage_counter ?? 0;
            if ($espionageCounter < 3) {
                return false;
            }

            // Build ABM if being scouted heavily
            foreach ($this->player->planets->all() as $planet) {
                $siloLevel = $planet->getObjectLevel('missile_silo');
                if ($siloLevel < 1) {
                    continue;
                }

                $abmCount = $planet->getObjectAmount('anti_ballistic_missile');
                $ipmCount = $planet->getObjectAmount('interplanetary_missile');
                $maxMissiles = $siloLevel * 10;
                $currentMissiles = $abmCount + $ipmCount;

                if ($currentMissiles >= $maxMissiles) {
                    continue;
                }

                // Need more ABM
                $targetAbm = max(0, (int)($maxMissiles * 0.6) - $abmCount);
                if ($targetAbm < 1) {
                    continue;
                }

                $buildAmount = min($targetAbm, 5);
                if (!ObjectService::objectRequirementsMet('anti_ballistic_missile', $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice('anti_ballistic_missile', $planet);
                $resources = $planet->getResources();
                $maxAffordable = min(
                    $price->metal->get() > 0 ? (int)($resources->metal->get() / $price->metal->get()) : 999,
                    $price->crystal->get() > 0 ? (int)($resources->crystal->get() / $price->crystal->get()) : 999,
                    $price->deuterium->get() > 0 ? (int)($resources->deuterium->get() / $price->deuterium->get()) : 999,
                    $buildAmount
                );

                if ($maxAffordable < 1) {
                    continue;
                }

                if ($this->isUnitQueueFull($planet)) {
                    continue;
                }

                $queueService = app(UnitQueueService::class);
                $abm = ObjectService::getObjectByMachineName('anti_ballistic_missile');
                $queueService->add($planet, $abm->id, $maxAffordable);

                $this->logAction(BotActionType::DEFENSE, "Counter-espionage: built {$maxAffordable}x ABM on {$planet->getPlanetName()}", [
                    'espionage_counter' => $espionageCounter,
                ]);

                // Reset counter
                $this->bot->espionage_counter = 0;
                $this->bot->save();
                return true;
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: handleCounterEspionage failed: {$e->getMessage()}");
            return false;
        }
    }

    // ========================================================================
    // SYSTEM 10: Moon Destruction (Deathstar RIP Strategy)
    // ========================================================================

    /**
     * Attempt to destroy enemy moon using Deathstars.
     * Only targets dangerous players who have phalanx on their moons.
     */
    public function tryMoonDestruction(): bool
    {
        try {
            if (!$this->hasFleetSlotsAvailable()) {
                return false;
            }

            $source = $this->getFleetPlanet() ?? $this->getRichestPlanet();
            if ($source === null) {
                return false;
            }

            // Need deathstars
            $deathstars = $source->getObjectAmount('deathstar');
            if ($deathstars < 1) {
                return false;
            }

            // Only target dangerous players (from threat map)
            $intel = new BotIntelligenceService();
            $threatMap = $intel->getThreatMap($this->bot->id);

            foreach ($threatMap as $threat) {
                if ($threat->threat_score < 50) {
                    continue; // Only target serious threats
                }

                // Find their moon
                $enemyPlanets = Planet::where('user_id', $threat->threat_user_id)
                    ->where('destroyed', 0)
                    ->get();

                foreach ($enemyPlanets as $enemyPlanet) {
                    // Check if planet has a moon
                    $moon = Planet::where('galaxy', $enemyPlanet->galaxy)
                        ->where('system', $enemyPlanet->system)
                        ->where('planet', $enemyPlanet->planet)
                        ->where('planet_type', 3) // Moon
                        ->where('destroyed', 0)
                        ->first();

                    if (!$moon) {
                        continue;
                    }

                    $targetCoords = new \OGame\Models\Planet\Coordinate($moon->galaxy, $moon->system, $moon->planet);
                    $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
                    $fleet->addUnit(ObjectService::getUnitObjectByMachineName('deathstar'), min($deathstars, 3));

                    $fleetMissionService = app(FleetMissionService::class);
                    $consumption = $fleetMissionService->calculateConsumption($source, $fleet, $targetCoords, 9, 100);

                    if ($source->getResources()->deuterium->get() < $consumption) {
                        continue;
                    }

                    $fleetMissionService->createNewFromPlanet(
                        $source,
                        $targetCoords,
                        \OGame\Models\Enums\PlanetType::Moon,
                        9, // Moon destruction
                        $fleet,
                        new Resources(0, 0, 0, 0),
                        100,
                        0
                    );

                    $this->logAction(BotActionType::ATTACK, "Moon destruction mission to {$targetCoords->asString()}", [
                        'deathstars' => min($deathstars, 3),
                        'target_user_id' => $threat->threat_user_id,
                    ]);

                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: tryMoonDestruction failed: {$e->getMessage()}");
            return false;
        }
    }

    // ========================================================================
    // SYSTEM 11: Missile Warfare Strategy (Proactive IPM/ABM)
    // ========================================================================

    /**
     * Proactive missile warfare: launch IPMs at heavily defended targets
     * to soften them before attack. Build ABMs on vulnerable planets.
     */
    public function proactiveMissileWarfare(): bool
    {
        try {
            // Part 1: Build ABMs on undefended planets
            $builtAbm = false;
            foreach ($this->player->planets->all() as $planet) {
                $siloLevel = $planet->getObjectLevel('missile_silo');
                if ($siloLevel < 1) continue;

                $abmCount = $planet->getObjectAmount('anti_ballistic_missile');
                $ipmCount = $planet->getObjectAmount('interplanetary_missile');
                $maxMissiles = $siloLevel * 10;
                $currentMissiles = $abmCount + $ipmCount;

                // Ensure at least 40% ABM coverage
                $targetAbm = (int)($maxMissiles * 0.4);
                if ($abmCount >= $targetAbm || $currentMissiles >= $maxMissiles) {
                    continue;
                }

                $buildAmount = min($targetAbm - $abmCount, 3, $maxMissiles - $currentMissiles);
                if ($buildAmount < 1) continue;

                if (!ObjectService::objectRequirementsMet('anti_ballistic_missile', $planet)) continue;
                if ($this->isUnitQueueFull($planet)) continue;

                $price = ObjectService::getObjectPrice('anti_ballistic_missile', $planet);
                $resources = $planet->getResources();
                $affordable = min(
                    $price->metal->get() > 0 ? (int)($resources->metal->get() / $price->metal->get()) : 999,
                    $price->crystal->get() > 0 ? (int)($resources->crystal->get() / $price->crystal->get()) : 999,
                    $price->deuterium->get() > 0 ? (int)($resources->deuterium->get() / $price->deuterium->get()) : 999,
                    $buildAmount
                );

                if ($affordable < 1) continue;

                $queueService = app(UnitQueueService::class);
                $abm = ObjectService::getObjectByMachineName('anti_ballistic_missile');
                $queueService->add($planet, $abm->id, $affordable);
                $this->logAction(BotActionType::DEFENSE, "Proactive ABM: {$affordable}x on {$planet->getPlanetName()}", []);
                $builtAbm = true;
                break;
            }

            if ($builtAbm) return true;

            // Part 2: Launch IPMs at heavily defended targets before attack
            if (!$this->hasFleetSlotsAvailable()) return false;

            $source = $this->getRichestPlanet();
            if (!$source) return false;

            $ipm = $source->getObjectAmount('interplanetary_missile');
            if ($ipm < 3) return false; // Need at least 3 to be effective

            // Find a target with heavy defenses that we plan to attack
            $intel = new BotIntelligenceService();
            $targets = $intel->getProfitableTargets($this->bot->id, 3, $this->getAvoidTargetUserIds());

            foreach ($targets as $target) {
                $defenses = $target->defenses ?? [];
                $heavyDefenses = ($defenses['plasma_turret'] ?? 0) + ($defenses['gauss_cannon'] ?? 0) + ($defenses['ion_cannon'] ?? 0);

                if ($heavyDefenses < 5) continue; // Not worth missile attack

                $targetCoords = new \OGame\Models\Planet\Coordinate($target->galaxy, $target->system, $target->planet);

                // Check range
                $range = $this->player->getMissileRange();
                if ($source->getPlanetCoordinates()->galaxy !== $targetCoords->galaxy) continue;
                $distance = abs($source->getPlanetCoordinates()->system - $targetCoords->system);
                if ($distance > $range) continue;

                $sendAmount = min($ipm, 10);
                $fleet = new \OGame\GameObjects\Models\Units\UnitCollection();
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName('interplanetary_missile'), $sendAmount);

                $fleetMissionService = app(FleetMissionService::class);
                $fleetMissionService->createNewFromPlanet(
                    $source,
                    $targetCoords,
                    \OGame\Models\Enums\PlanetType::Planet,
                    10,
                    $fleet,
                    new Resources(0, 0, 0, 0),
                    100,
                    0
                );

                $this->logAction(BotActionType::ATTACK, "Proactive IPM to {$targetCoords->asString()}: {$sendAmount} missiles", [
                    'heavy_defenses' => $heavyDefenses,
                ]);

                return true;
            }

            return false;
        } catch (Exception $e) {
            logger()->debug("Bot {$this->bot->id}: proactiveMissileWarfare failed: {$e->getMessage()}");
            return false;
        }
    }

    // ========================================================================
    // SYSTEM 12: Highscore Awareness
    // ========================================================================

    /**
     * Get the bot's current highscore rank and use it for targeting decisions.
     */
    public function getHighscoreContext(): array
    {
        try {
            $highscore = $this->player->getUser()->highscore;
            if (!$highscore) {
                return ['rank' => 0, 'score' => 0, 'military_rank' => 0, 'economy_rank' => 0];
            }

            return [
                'rank' => $highscore->general_rank ?? 0,
                'score' => $highscore->general ?? 0,
                'military_rank' => $highscore->military_rank ?? 0,
                'military_score' => $highscore->military ?? 0,
                'economy_rank' => $highscore->economy_rank ?? 0,
                'economy_score' => $highscore->economy ?? 0,
                'research_rank' => $highscore->research_rank ?? 0,
                'research_score' => $highscore->research ?? 0,
            ];
        } catch (Exception $e) {
            return ['rank' => 0, 'score' => 0, 'military_rank' => 0, 'economy_rank' => 0];
        }
    }

    /**
     * Adjust strategy based on highscore position.
     * Top players should be more defensive, lower-ranked more aggressive for growth.
     */
    public function getHighscoreStrategyModifiers(): array
    {
        $context = $this->getHighscoreContext();
        $rank = $context['rank'] ?? 0;
        $totalPlayers = \OGame\Models\User::count();

        if ($rank <= 0 || $totalPlayers <= 0) {
            return ['attack_modifier' => 1.0, 'defense_modifier' => 1.0, 'economy_modifier' => 1.0];
        }

        $percentile = ($rank / $totalPlayers) * 100; // Lower = better rank

        if ($percentile <= 10) {
            // Top 10%: be more defensive, protect rank
            return ['attack_modifier' => 0.7, 'defense_modifier' => 1.5, 'economy_modifier' => 1.2];
        } elseif ($percentile <= 30) {
            // Top 30%: balanced
            return ['attack_modifier' => 1.0, 'defense_modifier' => 1.2, 'economy_modifier' => 1.0];
        } elseif ($percentile <= 60) {
            // Mid range: aggressive growth
            return ['attack_modifier' => 1.3, 'defense_modifier' => 0.8, 'economy_modifier' => 1.3];
        } else {
            // Bottom: very aggressive, need to catch up
            return ['attack_modifier' => 1.5, 'defense_modifier' => 0.6, 'economy_modifier' => 1.5];
        }
    }
}
