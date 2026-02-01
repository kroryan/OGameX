<?php

namespace OGame\Http\Controllers\Admin;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Schema;
use OGame\Facades\AppUtil;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\Http\Controllers\OGameController;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\DarkMatterService;
use OGame\Services\DebrisFieldService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

class DeveloperShortcutsController extends OGameController
{
    /**
     * Shows the developer shortcuts page.
     *
     * @return View
     */
    public function index(Request $request, PlayerService $playerService, SettingsService $settingsService): View
    {
        // Get all unit objects
        $units = ObjectService::getUnitObjects();
        $locateResults = null;
        $locateQuery = trim((string)$request->input('locate_username', ''));
        $locateType = (string)$request->input('locate_type', 'all');
        $locateLimit = (int)$request->input('locate_limit', 200);
        $locateLimit = max(1, min(2000, $locateLimit));
        $locateShowAll = $request->boolean('locate_show_all');

        if ($request->has('locate_submit')) {
            $planetsQuery = Planet::query()
                ->join('users', 'users.id', '=', 'planets.user_id')
                ->leftJoin('bots', 'bots.user_id', '=', 'users.id')
                ->where('planets.destroyed', 0);

            if ($locateType === 'bots') {
                $planetsQuery->whereNotNull('bots.id');
            } elseif ($locateType === 'players') {
                $planetsQuery->whereNull('bots.id');
            }

            if (!$locateShowAll && $locateQuery !== '') {
                $planetsQuery->where(function ($query) use ($locateQuery) {
                    $query->where('users.username', 'like', '%' . $locateQuery . '%')
                        ->orWhere('bots.name', 'like', '%' . $locateQuery . '%');
                });
            }

            $locateResults = $planetsQuery
                ->select([
                    'planets.id',
                    'planets.name as planet_name',
                    'planets.galaxy',
                    'planets.system',
                    'planets.planet',
                    'planets.planet_type',
                    'users.username as username',
                    'bots.name as bot_name',
                ])
                ->orderBy('users.username')
                ->orderBy('planets.galaxy')
                ->orderBy('planets.system')
                ->orderBy('planets.planet')
                ->limit($locateLimit)
                ->get();
        }

        return view('ingame.admin.developershortcuts')->with([
            'units' => $units,
            'buildings' => [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()],
            'research' => ObjectService::getResearchObjects(),
            'currentPlanet' => $playerService->planets->current(),
            'settings' => $settingsService,
            'locateResults' => $locateResults,
            'locateQuery' => $locateQuery,
            'locateType' => $locateType,
            'locateLimit' => $locateLimit,
            'locateShowAll' => $locateShowAll,
        ]);
    }

    /**
     * Updates the planet objects and units.
     *
     * @param Request $request
     * @param PlayerService $playerService
     * @param SettingsService $settingsService
     * @return RedirectResponse
     * @throws Exception
     */
    public function update(Request $request, PlayerService $playerService, SettingsService $settingsService): RedirectResponse
    {
        if ($request->has('enable_free_class_changes')) {
            // Enable free class changes
            $settingsService->set('dev_free_class_changes', 1);
            return redirect()->back()->with('success', 'Free class changes enabled! You can now change classes without Dark Matter cost.');
        } elseif ($request->has('disable_free_class_changes')) {
            // Disable free class changes
            $settingsService->set('dev_free_class_changes', 0);
            return redirect()->back()->with('success', 'Free class changes disabled. Normal costs apply.');
        } elseif ($request->has('reset_character_class')) {
            // Reset character class
            $user = $playerService->getUser();
            $user->character_class = null;
            $user->character_class_free_used = false;
            $user->character_class_changed_at = null;
            $user->save();
            return redirect()->back()->with('success', 'Character class has been reset. You can now select a class for free.');
        } elseif ($request->has('set_mines')) {
            // Handle "Set all mines to level 30"
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('metal_mine')->id, 30);
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('crystal_mine')->id, 30);
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('deuterium_synthesizer')->id, 30);
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30);
        } elseif ($request->has('set_storages')) {
            // Handle "Set all storages to level 30"
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('metal_store')->id, 15);
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('crystal_store')->id, 15);
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('deuterium_store')->id, 15);
        } elseif ($request->has('set_shipyard')) {
            // Handle "Set all shipyard facilities to level 12"
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 12);
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('robot_factory')->id, 12);
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('nano_factory')->id, 12);
        } elseif ($request->has('set_research')) {
            // Handle "Set all research to level 10"
            $playerService->planets->current()->setObjectLevel(ObjectService::getObjectByMachineName('research_lab')->id, 12);
            foreach (ObjectService::getResearchObjects() as $research) {
                $playerService->setResearchLevel($research->machine_name, 10);
            }
        } elseif ($request->has('reset_buildings')) {
            // Handle "Reset all buildings"
            foreach (ObjectService::getBuildingObjects() as $building) {
                $playerService->planets->current()->setObjectLevel($building->id, 0);
            }
            foreach (ObjectService::getStationObjects() as $building) {
                $playerService->planets->current()->setObjectLevel($building->id, 0);
            }
        } elseif ($request->has('reset_research')) {
            // Handle "Reset all research"
            foreach (ObjectService::getResearchObjects() as $research) {
                $playerService->setResearchLevel($research->machine_name, 0);
            }
        } elseif ($request->has('reset_units')) {
            // Handle "Reset all units"
            foreach (ObjectService::getUnitObjects() as $unit) {
                $playerService->planets->current()->removeUnit($unit->machine_name, $playerService->planets->current()->getObjectAmount($unit->machine_name));
            }
        } elseif ($request->has('reset_resources')) {
            // Set all resources to 0 by deducting the current amount.
            $playerService->planets->current()->deductResources($playerService->planets->current()->getResources());

            return redirect()->back()->with('success', 'All resources have been set to 0');
        } elseif ($request->has('update_current_resources')) {
            $metal = AppUtil::parseResourceValue($request->input('current_metal', 0));
            $crystal = AppUtil::parseResourceValue($request->input('current_crystal', 0));
            $deuterium = AppUtil::parseResourceValue($request->input('current_deuterium', 0));

            $resourcesToAdd = new Resources(
                metal: max(0, $metal),
                crystal: max(0, $crystal),
                deuterium: max(0, $deuterium),
                energy: 0
            );

            $resourcesToDeduct = new Resources(
                metal: abs(min(0, $metal)),
                crystal: abs(min(0, $crystal)),
                deuterium: abs(min(0, $deuterium)),
                energy: 0
            );

            $planet = $playerService->planets->current();
            if ($resourcesToDeduct->sum() > 0) {
                $planet->deductResources($resourcesToDeduct);
            }
            if ($resourcesToAdd->sum() > 0) {
                $planet->addResources($resourcesToAdd);
            }

            return redirect()->back()->with('success', 'Current planet resources updated.');
        } elseif ($request->has('update_all_planets_resources')) {
            $metal = AppUtil::parseResourceValue($request->input('all_metal', 0));
            $crystal = AppUtil::parseResourceValue($request->input('all_crystal', 0));
            $deuterium = AppUtil::parseResourceValue($request->input('all_deuterium', 0));

            $resourcesToAdd = new Resources(
                metal: max(0, $metal),
                crystal: max(0, $crystal),
                deuterium: max(0, $deuterium),
                energy: 0
            );

            $resourcesToDeduct = new Resources(
                metal: abs(min(0, $metal)),
                crystal: abs(min(0, $crystal)),
                deuterium: abs(min(0, $deuterium)),
                energy: 0
            );

            foreach ($playerService->planets->all() as $planet) {
                if ($resourcesToDeduct->sum() > 0) {
                    $planet->deductResources($resourcesToDeduct);
                }
                if ($resourcesToAdd->sum() > 0) {
                    $planet->addResources($resourcesToAdd);
                }
            }

            return redirect()->back()->with('success', 'All planets resources updated.');
        } elseif ($request->has('finish_current_queues')) {
            $planet = $playerService->planets->current();

            $buildingQueue = BuildingQueue::where('planet_id', $planet->getPlanetId())
                ->where('processed', 0)
                ->where('canceled', 0)
                ->orderBy('id')
                ->get();
            foreach ($buildingQueue as $item) {
                $planet->setObjectLevel($item->object_id, $item->object_level_target, false);
                $item->processed = 1;
                $item->save();
            }
            if ($buildingQueue->isNotEmpty()) {
                $planet->updateResourceProductionStats(false);
                $planet->updateResourceStorageStats(false);
                $planet->save();
            }

            $unitQueueQuery = UnitQueue::where('planet_id', $planet->getPlanetId())
                ->where('processed', 0);
            if (Schema::hasColumn('unit_queues', 'canceled')) {
                $unitQueueQuery->where('canceled', 0);
            }
            $unitQueue = $unitQueueQuery->orderBy('id')->get();
            foreach ($unitQueue as $item) {
                $remaining = max(0, $item->object_amount - $item->object_amount_progress);
                if ($remaining > 0) {
                    $object = ObjectService::getUnitObjectById($item->object_id);
                    $planet->addUnit($object->machine_name, $remaining, false);
                }
                $item->object_amount_progress = $item->object_amount;
                $item->time_progress = $item->time_end;
                $item->processed = 1;
                $item->save();
            }
            if ($unitQueue->isNotEmpty()) {
                $planet->save();
            }

            $planetIds = $playerService->planets->allIds();
            $researchQueue = ResearchQueue::whereIn('planet_id', $planetIds)
                ->where('processed', 0)
                ->where('canceled', 0)
                ->orderBy('id')
                ->get();
            foreach ($researchQueue as $item) {
                $object = ObjectService::getResearchObjectById($item->object_id);
                $playerService->setResearchLevel($object->machine_name, $item->object_level_target);
                $item->processed = 1;
                $item->save();
            }

            return redirect()->back()->with('success', 'Queues finished instantly for current planet and player research.');
        } elseif ($request->has('clear_current_queues')) {
            $planet = $playerService->planets->current();
            BuildingQueue::where('planet_id', $planet->getPlanetId())
                ->where('processed', 0)
                ->where('canceled', 0)
                ->delete();
            $unitQueueDelete = UnitQueue::where('planet_id', $planet->getPlanetId())
                ->where('processed', 0);
            if (Schema::hasColumn('unit_queues', 'canceled')) {
                $unitQueueDelete->where('canceled', 0);
            }
            $unitQueueDelete->delete();

            $planetIds = $playerService->planets->allIds();
            ResearchQueue::whereIn('planet_id', $planetIds)
                ->where('processed', 0)
                ->where('canceled', 0)
                ->delete();

            return redirect()->back()->with('success', 'Queues cleared for current planet and player research.');
        } elseif ($request->has('reset_account_full')) {
            // Handle "Reset account to initial state"
            // This completely resets the account to creation state
            $user = $playerService->getUser();
            $planets = $playerService->planets->all();
            $homeworld = null;

            // Clear all queues first
            foreach ($planets as $planet) {
                BuildingQueue::where('planet_id', $planet->getPlanetId())->delete();
                UnitQueue::where('planet_id', $planet->getPlanetId())->delete();
            }
            ResearchQueue::whereIn('planet_id', $playerService->planets->allIds())->delete();

            // Keep only the homeworld (planet_current), delete all others
            // Only attempt to abandon planets if there's more than one
            $planetsToDelete = [];
            foreach ($planets as $planet) {
                if ($planet->getPlanetId() === $user->planet_current) {
                    $homeworld = $planet;
                } else {
                    $planetsToDelete[] = $planet->getPlanetId();
                }
            }

            // Only delete planets if we have more than one (can't abandon the last planet)
            if (count($planets) > 1 && !empty($planetsToDelete)) {
                foreach ($planetsToDelete as $planetId) {
                    $planetModel = \OGame\Models\Planet::find($planetId);
                    if ($planetModel) {
                        // Mark as destroyed instead of using abandonPlanet()
                        // abandonPlanet() has protection against deleting the last planet
                        $planetModel->destroyed = 1;
                        $planetModel->destroyed_moon_id = null;
                        $planetModel->save();

                        // Clear queues for this planet
                        BuildingQueue::where('planet_id', $planetId)->delete();
                        UnitQueue::where('planet_id', $planetId)->delete();
                        ResearchQueue::where('planet_id', $planetId)->delete();
                    }
                }
            }

            // Reset the homeworld (or only planet)
            if ($homeworld) {
                // Reset all buildings on homeworld
                foreach (ObjectService::getBuildingObjects() as $building) {
                    $homeworld->setObjectLevel($building->id, 0);
                }
                foreach (ObjectService::getStationObjects() as $building) {
                    $homeworld->setObjectLevel($building->id, 0);
                }

                // Reset all units on homeworld
                foreach (ObjectService::getUnitObjects() as $unit) {
                    $currentAmount = $homeworld->getObjectAmount($unit->machine_name);
                    if ($currentAmount > 0) {
                        $homeworld->removeUnit($unit->machine_name, $currentAmount);
                    }
                }

                // Reset resources to starting values
                $homeworld->setResources(new Resources(
                    metal: 500,
                    crystal: 500,
                    deuterium: 0,
                    energy: 0
                ));

                // Reset planet name to Homeworld
                $homeworld->setName('Homeworld');
                $homeworld->save();
            }

            // Reset all research
            foreach (ObjectService::getResearchObjects() as $research) {
                $playerService->setResearchLevel($research->machine_name, 0);
            }

            // Reset character class
            $user->character_class = null;
            $user->character_class_free_used = false;
            $user->character_class_changed_at = null;
            $user->save();

            return redirect()->back()->with('success', 'Account has been reset to initial state. Only homeworld remains with starting resources.');
        }

        // Handle unit submission
        $amountOfUnits = max(1, $request->input('amount_of_units', 1));
        foreach (ObjectService::getUnitObjects() as $unit) {
            if ($request->has('unit_' . $unit->id)) {
                // Handle adding the specific unit
                $playerService->planets->current()->addUnit($unit->machine_name, AppUtil::parseResourceValue($amountOfUnits));
            }
        }

        // Handle building level setting
        foreach ($request->all() as $key => $value) {
            if (str_starts_with($key, 'building_')) {
                $buildingId = (int)substr($key, 9); // Remove 'building_' prefix
                $level = (int)$request->input('building_level', 1);

                // Find the building object
                $building = null;
                foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $obj) {
                    if ($obj->id === $buildingId) {
                        $building = $obj;
                        break;
                    }
                }

                if ($building) {
                    $playerService->planets->current()->setObjectLevel($building->id, $level);
                }
            }
        }

        // Handle research level setting
        foreach ($request->all() as $key => $value) {
            if (str_starts_with($key, 'research_')) {
                $researchId = (int)substr($key, 9); // Remove 'research_' prefix
                $level = (int)$request->input('research_level', 1);

                // Find the research object
                $research = null;
                foreach (ObjectService::getResearchObjects() as $obj) {
                    if ($obj->id === $researchId) {
                        $research = $obj;
                        break;
                    }
                }

                if ($research) {
                    $playerService->setResearchLevel($research->machine_name, $level);
                }
            }
        }

        return redirect()->route('admin.developershortcuts.index')->with('success', __('Changes saved!'));
    }

    /**
     * Updates the resources of the specified planet.
     *
     * @param Request $request
     * @param PlayerService $playerService
     * @return RedirectResponse
     */
    public function updateResources(Request $request, PlayerService $playerService, SettingsService $settingsService): RedirectResponse
    {
        // Validate coordinates
        $validated = $request->validate([
            'galaxy' => 'required|integer|min:1|max:' . $settingsService->numberOfGalaxies(),
            'system' => 'required|integer|min:1|max:' . UniverseConstants::MAX_SYSTEM_COUNT,
            'position' => 'required|integer|min:1|max:' . UniverseConstants::MAX_PLANET_POSITION,
        ]);

        $coordinate = new Coordinate(
            $validated['galaxy'],
            $validated['system'],
            $validated['position']
        );

        $planetFactory = app(PlanetServiceFactory::class);
        if ($request->has('update_resources_planet')) {
            $planet = $planetFactory->makePlanetForCoordinate($coordinate);
        } elseif ($request->has('update_resources_moon')) {
            $planet = $planetFactory->makeMoonForCoordinate($coordinate);
        } else {
            return redirect()->back()->with('error', 'Invalid action specified');
        }

        // Parse each resource value, handling k/m/b suffixes and negative values
        $metal = AppUtil::parseResourceValue($request->input('metal', 0));
        $crystal = AppUtil::parseResourceValue($request->input('crystal', 0));
        $deuterium = AppUtil::parseResourceValue($request->input('deuterium', 0));

        // Split resources into positive and negative values
        $resourcesToAdd = new Resources(
            metal: max(0, $metal),
            crystal: max(0, $crystal),
            deuterium: max(0, $deuterium),
            energy: 0
        );

        $resourcesToDeduct = new Resources(
            metal: abs(min(0, $metal)),
            crystal: abs(min(0, $crystal)),
            deuterium: abs(min(0, $deuterium)),
            energy: 0
        );

        // First deduct negative values, then add positive values
        if ($resourcesToDeduct->sum() > 0) {
            $planet->deductResources($resourcesToDeduct);
        }

        if ($resourcesToAdd->sum() > 0) {
            $planet->addResources($resourcesToAdd);
        }

        return redirect()->back()->with('success', 'Resources updated successfully');
    }

    /**
     * Creates a planet or moon at the specified coordinates.
     *
     * @param Request $request
     * @param PlanetServiceFactory $planetServiceFactory
     * @param PlayerService $player
     * @return RedirectResponse
     */
    public function createAtCoords(Request $request, PlanetServiceFactory $planetServiceFactory, PlayerService $player, SettingsService $settingsService): RedirectResponse
    {
        // Validate coordinates
        $validated = $request->validate([
            'galaxy' => 'required|integer|min:1|max:' . $settingsService->numberOfGalaxies(),
            'system' => 'required|integer|min:1|max:' . UniverseConstants::MAX_SYSTEM_COUNT,
            'position' => 'required|integer|min:1|max:' . UniverseConstants::MAX_PLANET_POSITION,
        ]);

        $coordinate = new Coordinate(
            $validated['galaxy'],
            $validated['system'],
            $validated['position']
        );

        try {
            if ($request->has('delete_moon')) {
                // Check if there's a moon at these coordinates.
                $moon = $planetServiceFactory->makeMoonForCoordinate($coordinate);

                if (!$moon) {
                    return redirect()->back()->with('error', 'No moon exists at ' . $coordinate->asString());
                }

                // Delete the moon.
                $moon->abandonPlanet();
                return redirect()->back()->with('success', 'Moon deleted successfully at ' . $coordinate->asString());
            }

            if ($request->has('delete_planet')) {
                // Check if there's a moon at these coordinates.
                $planet = $planetServiceFactory->makePlanetForCoordinate($coordinate);

                if (!$planet) {
                    return redirect()->back()->with('error', 'No planet exists at ' . $coordinate->asString());
                }

                // Delete the planet.
                $planet->abandonPlanet();
                return redirect()->back()->with('success', 'Planet deleted successfully at ' . $coordinate->asString());
            }

            if ($request->has('create_planet')) {
                // Create planet for current admin user
                $planetServiceFactory->createAdditionalPlanetForPlayer($player, $coordinate);
                return redirect()->back()->with('success', 'Planet created successfully at ' . $coordinate->asString());
            }

            if ($request->has('create_moon')) {
                // First check if there's a planet at these coordinates.
                $existingPlanet = $planetServiceFactory->makeForCoordinate($coordinate);
                if (!$existingPlanet) {
                    return redirect()->back()->with('error', 'Cannot create moon - no planet exists at ' . $coordinate->asString());
                }

                // Get moon parameters from request
                $debrisAmount = (int)AppUtil::parseResourceValue($request->input('moon_debris', '2000000'));
                $xFactor = $request->filled('moon_factor') ? (int)$request->input('moon_factor') : null;

                // Validate inputs
                if ($debrisAmount < 0) {
                    return redirect()->back()->with('error', 'Debris amount must be positive');
                }
                if ($xFactor !== null && ($xFactor < 10 || $xFactor > 20)) {
                    return redirect()->back()->with('error', 'X factor must be between 10 and 20');
                }

                // Create moon with specified parameters
                // Moon chance is set to 20% (maximum) since we're guaranteed to create the moon anyway
                $moon = $planetServiceFactory->createMoonForPlanet($existingPlanet, $debrisAmount, 20, $xFactor);
                $moonSize = $moon->getPlanetDiameter();

                $xFactorText = $xFactor !== null ? " (x={$xFactor})" : " (x=random)";
                return redirect()->back()->with('success', "Moon created at {$coordinate->asString()} with diameter {$moonSize} km{$xFactorText}");
            }
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Failed to create planet/moon: ' . $e->getMessage());
        }

        return redirect()->back()->with('error', 'Invalid action specified');
    }

    /**
     * Creates a debris field at the specified coordinates.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function updateDarkMatter(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'dark_matter' => 'required|string',
        ]);

        // Find the user by username
        $user = \OGame\Models\User::where('username', $validated['username'])->first();
        if (!$user) {
            return redirect()->back()->with('error', "User '{$validated['username']}' not found.");
        }

        // Parse the dark matter amount, handling k/m/b suffixes
        $amount = (int)AppUtil::parseResourceValue($validated['dark_matter']);

        if ($amount <= 0) {
            return redirect()->back()->with('error', 'Dark Matter amount must be positive.');
        }

        // Add dark matter to the user
        $darkMatterService = app(DarkMatterService::class);
        $darkMatterService->credit(
            $user,
            $amount,
            \OGame\Enums\DarkMatterTransactionType::ADMIN_ADJUSTMENT->value,
            'Added via Developer Shortcuts by admin'
        );

        return redirect()->back()->with('success', "Successfully added {$amount} Dark Matter to user '{$validated['username']}'.");
    }

    /**
     * Creates a debris field at the specified coordinates.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function createDebris(Request $request)
    {
        $coordinate = new Coordinate(
            galaxy: (int)$request->input('galaxy'),
            system: (int)$request->input('system'),
            position: (int)$request->input('position')
        );

        $debrisField = app(DebrisFieldService::class);

        if ($request->has('delete_debris')) {
            // Load and delete if exists
            if ($debrisField->loadForCoordinates($coordinate)) {
                $debrisField->delete();
                return redirect()->back()->with('success', 'Debris field deleted successfully at ' . $coordinate->asString());
            }
            return redirect()->back()->with('error', 'No debris field exists at ' . $coordinate->asString());
        }

        // Create/append debris field
        $debrisField->loadOrCreateForCoordinates($coordinate);

        // Add the resources
        $resources = new Resources(
            metal: (int)AppUtil::parseResourceValue($request->input('metal', 0)),
            crystal: (int)AppUtil::parseResourceValue($request->input('crystal', 0)),
            deuterium: (int)AppUtil::parseResourceValue($request->input('deuterium', 0)),
            energy: 0,
        );

        $debrisField->appendResources($resources);
        $debrisField->save();

        return redirect()->back()->with('success', 'Debris field created/updated successfully at ' . $coordinate->asString());
    }
}
