<?php

namespace OGame\Services;

use OGame\Enums\BotPersonality;
use OGame\Models\BotStrategicPlan;
use OGame\Models\BotPlanetPlan;

/**
 * BotStrategicPlannerService - Long-term planning and goal management.
 *
 * Handles: #1 multi-week planning, #2 tech dependency trees, #3 build orders,
 * #4 future simulation, #5 planet prioritization, #6 economy optimization.
 */
class BotStrategicPlannerService
{
    /**
     * Technology dependency tree - what tech requires what.
     */
    private const TECH_DEPENDENCIES = [
        'espionage_technology' => [],
        'computer_technology' => [],
        'energy_technology' => [],
        'laser_technology' => [['energy_technology', 2]],
        'ion_technology' => [['laser_technology', 5], ['energy_technology', 4]],
        'plasma_technology' => [['laser_technology', 10], ['ion_technology', 5], ['energy_technology', 8]],
        'hyperspace_technology' => [['energy_technology', 5], ['shielding_technology', 5]],
        'combustion_drive' => [['energy_technology', 1]],
        'impulse_drive' => [['energy_technology', 1]],
        'hyperspace_drive' => [['hyperspace_technology', 3]],
        'weapon_technology' => [],
        'shielding_technology' => [['energy_technology', 3]],
        'armor_technology' => [],
        'astrophysics' => [['espionage_technology', 4], ['impulse_drive', 3]],
        'intergalactic_research_network' => [['computer_technology', 8], ['hyperspace_technology', 8]],
        'graviton_technology' => [['energy_technology', 12]],
    ];

    /**
     * Build orders for each personality in early game.
     */
    private const BUILD_ORDERS = [
        'aggressive' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 4],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 4],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 3],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 6],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 6],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 3],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 2],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 2],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 1],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 1],
            ['type' => 'research', 'name' => 'combustion_drive', 'level' => 2],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 4],
            ['type' => 'research', 'name' => 'weapon_technology', 'level' => 3],
            ['type' => 'unit', 'name' => 'light_fighter', 'amount' => 30],
            ['type' => 'research', 'name' => 'impulse_drive', 'level' => 2],
            ['type' => 'unit', 'name' => 'cruiser', 'amount' => 10],
        ],
        'economic' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 5],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 7],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 8],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 7],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 10],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 3],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 3],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 3],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 12],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 10],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 8],
            ['type' => 'research', 'name' => 'plasma_technology', 'level' => 1],
        ],
        'defensive' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 5],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 4],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 7],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 6],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 4],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 2],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 2],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 2],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 2],
            ['type' => 'research', 'name' => 'laser_technology', 'level' => 3],
            ['type' => 'research', 'name' => 'shielding_technology', 'level' => 2],
            ['type' => 'unit', 'name' => 'rocket_launcher', 'amount' => 50],
            ['type' => 'unit', 'name' => 'light_laser', 'amount' => 30],
            ['type' => 'building', 'name' => 'missile_silo', 'level' => 2],
        ],
        'balanced' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 5],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 4],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 7],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 7],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 6],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 4],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 2],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 2],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 2],
            ['type' => 'research', 'name' => 'espionage_technology', 'level' => 2],
            ['type' => 'research', 'name' => 'computer_technology', 'level' => 2],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 2],
            ['type' => 'unit', 'name' => 'light_fighter', 'amount' => 15],
            ['type' => 'unit', 'name' => 'small_cargo', 'amount' => 10],
        ],
        'raider' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 4],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 4],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 3],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 6],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 3],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 2],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 3],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 1],
            ['type' => 'research', 'name' => 'espionage_technology', 'level' => 4],
            ['type' => 'research', 'name' => 'combustion_drive', 'level' => 3],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 1],
            ['type' => 'unit', 'name' => 'small_cargo', 'amount' => 30],
            ['type' => 'unit', 'name' => 'light_fighter', 'amount' => 20],
            ['type' => 'unit', 'name' => 'espionage_probe', 'amount' => 20],
            ['type' => 'research', 'name' => 'impulse_drive', 'level' => 2],
            ['type' => 'unit', 'name' => 'cruiser', 'amount' => 10],
        ],
        'turtle' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 5],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 8],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 7],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 5],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 3],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 2],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 3],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 2],
            ['type' => 'research', 'name' => 'laser_technology', 'level' => 6],
            ['type' => 'research', 'name' => 'shielding_technology', 'level' => 3],
            ['type' => 'research', 'name' => 'armor_technology', 'level' => 3],
            ['type' => 'unit', 'name' => 'rocket_launcher', 'amount' => 100],
            ['type' => 'unit', 'name' => 'light_laser', 'amount' => 50],
            ['type' => 'unit', 'name' => 'heavy_laser', 'amount' => 20],
            ['type' => 'building', 'name' => 'missile_silo', 'level' => 4],
        ],
        'scientist' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 5],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 5],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 4],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 6],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 4],
            ['type' => 'research', 'name' => 'espionage_technology', 'level' => 4],
            ['type' => 'research', 'name' => 'computer_technology', 'level' => 4],
            ['type' => 'research', 'name' => 'laser_technology', 'level' => 6],
            ['type' => 'research', 'name' => 'ion_technology', 'level' => 3],
            ['type' => 'research', 'name' => 'plasma_technology', 'level' => 1],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 3],
            ['type' => 'research', 'name' => 'astrophysics', 'level' => 3],
            ['type' => 'unit', 'name' => 'espionage_probe', 'amount' => 50],
        ],
        'diplomat' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 5],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 4],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 7],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 6],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 4],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 2],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 2],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 2],
            ['type' => 'building', 'name' => 'alliance_depot', 'level' => 2],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 2],
            ['type' => 'research', 'name' => 'computer_technology', 'level' => 3],
            ['type' => 'unit', 'name' => 'large_cargo', 'amount' => 15],
            ['type' => 'unit', 'name' => 'light_fighter', 'amount' => 10],
            ['type' => 'unit', 'name' => 'recycler', 'amount' => 5],
        ],
        'explorer' => [
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 5],
            ['type' => 'building', 'name' => 'solar_plant', 'level' => 5],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 4],
            ['type' => 'building', 'name' => 'metal_mine', 'level' => 7],
            ['type' => 'building', 'name' => 'crystal_mine', 'level' => 6],
            ['type' => 'building', 'name' => 'deuterium_synthesizer', 'level' => 5],
            ['type' => 'building', 'name' => 'robot_factory', 'level' => 2],
            ['type' => 'building', 'name' => 'shipyard', 'level' => 3],
            ['type' => 'building', 'name' => 'research_lab', 'level' => 3],
            ['type' => 'research', 'name' => 'energy_technology', 'level' => 2],
            ['type' => 'research', 'name' => 'combustion_drive', 'level' => 3],
            ['type' => 'research', 'name' => 'astrophysics', 'level' => 4],
            ['type' => 'research', 'name' => 'espionage_technology', 'level' => 3],
            ['type' => 'unit', 'name' => 'large_cargo', 'amount' => 20],
            ['type' => 'unit', 'name' => 'light_fighter', 'amount' => 20],
            ['type' => 'unit', 'name' => 'espionage_probe', 'amount' => 15],
        ],
    ];

    /**
     * Planet specialization templates.
     */
    private const PLANET_SPECIALIZATIONS = [
        'economy' => [
            'metal_mine' => 20, 'crystal_mine' => 18, 'deuterium_synthesizer' => 15,
            'solar_plant' => 20, 'metal_store' => 8, 'crystal_store' => 8,
            'deuterium_store' => 8, 'robot_factory' => 8, 'nano_factory' => 5,
        ],
        'fleet' => [
            'shipyard' => 12, 'robot_factory' => 10, 'nano_factory' => 8,
            'metal_mine' => 15, 'crystal_mine' => 13, 'deuterium_synthesizer' => 12,
            'solar_plant' => 15,
        ],
        'defense' => [
            'metal_mine' => 15, 'crystal_mine' => 13, 'deuterium_synthesizer' => 10,
            'solar_plant' => 15, 'missile_silo' => 6, 'shipyard' => 6,
        ],
        'research' => [
            'research_lab' => 12, 'metal_mine' => 15, 'crystal_mine' => 13,
            'deuterium_synthesizer' => 12, 'solar_plant' => 15,
            'robot_factory' => 8,
        ],
        'deuterium' => [
            'deuterium_synthesizer' => 20, 'solar_plant' => 20,
            'fusion_plant' => 10, 'metal_mine' => 10, 'crystal_mine' => 8,
        ],
    ];

    /**
     * Create or update the strategic plan for a bot.
     * Ensures the bot always has active plans to follow.
     */
    public function ensurePlans(BotService $botService): void
    {
        $bot = $botService->getBot();
        $botId = $bot->id;

        // Clean up stale plans first
        $this->cleanupStalePlans($botId);

        // Check if we already have active plans (don't spam new ones)
        $activePlans = BotStrategicPlan::where('bot_id', $botId)
            ->where('status', 'active')
            ->count();

        if ($activePlans >= 3) {
            return;
        }

        $personality = $bot->getPersonalityEnum();
        $state = (new GameStateAnalyzer())->analyzeCurrentState($botService);
        $phase = $state['game_phase'];

        // Create build order plan if in early game
        if ($phase === 'early' && !$this->hasPlanOfType($botId, 'build_order')) {
            $this->createBuildOrderPlan($botId, $personality);
        }

        // Create tech chain plan if missing
        if (!$this->hasPlanOfType($botId, 'tech_chain')) {
            $this->createTechChainPlan($botId, $botService, $personality, $phase);
        }

        // Create fleet goal plan
        if ($phase !== 'early' && !$this->hasPlanOfType($botId, 'fleet_goal')) {
            $this->createFleetGoalPlan($botId, $personality, $state);
        }

        // Ensure planet specializations
        $this->ensurePlanetPlans($botService);
    }

    /**
     * Get the next recommended action from active plans.
     * Returns null if no plan has an actionable step right now.
     */
    public function getNextPlannedAction(BotService $botService): ?array
    {
        $botId = $botService->getBot()->id;
        $plans = BotStrategicPlan::where('bot_id', $botId)
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->get();

        foreach ($plans as $plan) {
            $step = $plan->getCurrentStep();
            if (!$step) {
                $plan->status = 'completed';
                $plan->completed_at = now();
                $plan->save();
                continue;
            }

            // Check if this step can be executed
            if ($this->canExecuteStep($botService, $step)) {
                return [
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->plan_type,
                    'step' => $step,
                ];
            }

            // Check if step is already completed (level already reached)
            if ($this->isStepCompleted($botService, $step)) {
                $plan->advanceStep();
                // Try the next step
                $nextStep = $plan->getCurrentStep();
                if ($nextStep && $this->canExecuteStep($botService, $nextStep)) {
                    return [
                        'plan_id' => $plan->id,
                        'plan_type' => $plan->plan_type,
                        'step' => $nextStep,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Mark a plan step as completed and advance.
     */
    public function completeStep(int $planId): void
    {
        $plan = BotStrategicPlan::find($planId);
        if ($plan) {
            $plan->advanceStep();
        }
    }

    /**
     * Get the planet specialization for a specific planet.
     */
    public function getPlanetSpecialization(int $botId, int $planetId): ?string
    {
        $plan = BotPlanetPlan::where('bot_id', $botId)
            ->where('planet_id', $planetId)
            ->first();

        return $plan ? $plan->specialization : null;
    }

    /**
     * Calculate ROI for building a mine upgrade.
     * Returns hours until the upgrade pays for itself.
     */
    public function calculateMineROI(string $machineName, PlanetService $planet, int $currentLevel): float
    {
        $price = ObjectService::getObjectPrice($machineName, $planet);
        $cost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();

        if ($cost <= 0) {
            return 0;
        }

        $currentProduction = $this->estimateHourlyProduction($machineName, $currentLevel);
        $nextProduction = $this->estimateHourlyProduction($machineName, $currentLevel + 1);
        $gainPerHour = $nextProduction - $currentProduction;

        if ($gainPerHour <= 0) {
            return PHP_INT_MAX;
        }

        return $cost / $gainPerHour;
    }

    /**
     * Find the optimal next mine upgrade across all planets.
     * Returns the building + planet combination with best ROI.
     */
    public function findBestMineUpgrade(BotService $botService): ?array
    {
        $planets = $botService->getPlayer()->planets->all();
        $best = null;
        $bestROI = PHP_INT_MAX;

        $mines = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'];

        foreach ($planets as $planet) {
            foreach ($mines as $mine) {
                $level = $planet->getObjectLevel($mine);
                if ($level >= 25) {
                    continue;
                }

                if (!ObjectService::objectRequirementsMet($mine, $planet)) {
                    continue;
                }

                $roi = $this->calculateMineROI($mine, $planet, $level);
                if ($roi < $bestROI) {
                    $bestROI = $roi;
                    $best = [
                        'building' => $mine,
                        'planet' => $planet,
                        'level' => $level + 1,
                        'roi_hours' => $roi,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Simulate future resource state.
     * Predicts resources after X hours based on current production.
     */
    public function simulateFuture(BotService $botService, int $hours): array
    {
        $planets = $botService->getPlayer()->planets->all();
        $totalMetal = 0;
        $totalCrystal = 0;
        $totalDeut = 0;

        foreach ($planets as $planet) {
            $resources = $planet->getResources();
            $metalProd = $this->estimateHourlyProduction('metal_mine', $planet->getObjectLevel('metal_mine'));
            $crystalProd = $this->estimateHourlyProduction('crystal_mine', $planet->getObjectLevel('crystal_mine'));
            $deutProd = $this->estimateHourlyProduction('deuterium_synthesizer', $planet->getObjectLevel('deuterium_synthesizer'));

            $totalMetal += $resources->metal->get() + ($metalProd * $hours);
            $totalCrystal += $resources->crystal->get() + ($crystalProd * $hours);
            $totalDeut += $resources->deuterium->get() + ($deutProd * $hours);
        }

        return [
            'metal' => (int) $totalMetal,
            'crystal' => (int) $totalCrystal,
            'deuterium' => (int) $totalDeut,
            'total' => (int) ($totalMetal + $totalCrystal + $totalDeut),
            'hours' => $hours,
        ];
    }

    /**
     * Get the full tech dependency chain needed to reach a target tech+level.
     */
    public function getTechChain(string $targetTech, int $targetLevel, PlayerService $player): array
    {
        $chain = [];
        $this->buildTechChain($targetTech, $targetLevel, $player, $chain, []);
        return $chain;
    }

    // --- Private Methods ---

    private function buildTechChain(string $tech, int $targetLevel, PlayerService $player, array &$chain, array $visited): void
    {
        if (in_array($tech, $visited)) {
            return;
        }
        $visited[] = $tech;

        $deps = self::TECH_DEPENDENCIES[$tech] ?? [];
        foreach ($deps as [$depTech, $depLevel]) {
            $currentDepLevel = 0;
            try {
                $currentDepLevel = $player->getResearchLevel($depTech);
            } catch (\Exception $e) {
                // tech not found
            }

            if ($currentDepLevel < $depLevel) {
                $this->buildTechChain($depTech, $depLevel, $player, $chain, $visited);
                $chain[] = ['type' => 'research', 'name' => $depTech, 'level' => $depLevel];
            }
        }

        $currentLevel = 0;
        try {
            $currentLevel = $player->getResearchLevel($tech);
        } catch (\Exception $e) {
            // tech not found
        }

        for ($l = $currentLevel + 1; $l <= $targetLevel; $l++) {
            $chain[] = ['type' => 'research', 'name' => $tech, 'level' => $l];
        }
    }

    private function createBuildOrderPlan(int $botId, BotPersonality $personality): void
    {
        $order = self::BUILD_ORDERS[$personality->value] ?? self::BUILD_ORDERS['balanced'];

        BotStrategicPlan::create([
            'bot_id' => $botId,
            'plan_type' => 'build_order',
            'goal_description' => "Early game build order for {$personality->value} personality",
            'steps' => $order,
            'current_step' => 0,
            'status' => 'active',
            'priority' => 80,
            'target_completion_at' => now()->addDays(3),
        ]);
    }

    private function createTechChainPlan(int $botId, BotService $botService, BotPersonality $personality, string $phase): void
    {
        $player = $botService->getPlayer();
        $targetTech = match ($personality) {
            BotPersonality::AGGRESSIVE => 'hyperspace_technology',
            BotPersonality::DEFENSIVE => 'plasma_technology',
            BotPersonality::ECONOMIC => 'plasma_technology',
            BotPersonality::BALANCED => 'hyperspace_technology',
        };
        $targetLevel = match ($phase) {
            'early' => 3,
            'mid' => 6,
            default => 8,
        };

        $chain = $this->getTechChain($targetTech, $targetLevel, $player);
        if (empty($chain)) {
            return;
        }

        // Also add astrophysics for colonization
        $astroChain = $this->getTechChain('astrophysics', 4, $player);
        $allSteps = array_merge($astroChain, $chain);

        // Remove duplicates
        $seen = [];
        $uniqueSteps = [];
        foreach ($allSteps as $step) {
            $key = $step['name'] . ':' . $step['level'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueSteps[] = $step;
            }
        }

        if (empty($uniqueSteps)) {
            return;
        }

        BotStrategicPlan::create([
            'bot_id' => $botId,
            'plan_type' => 'tech_chain',
            'goal_description' => "Research chain: {$targetTech} level {$targetLevel} + astrophysics 4",
            'steps' => $uniqueSteps,
            'current_step' => 0,
            'status' => 'active',
            'priority' => 70,
            'target_completion_at' => now()->addDays(14),
        ]);
    }

    private function createFleetGoalPlan(int $botId, BotPersonality $personality, array $state): void
    {
        $steps = match ($personality) {
            BotPersonality::AGGRESSIVE => [
                ['type' => 'unit', 'name' => 'light_fighter', 'amount' => 100],
                ['type' => 'unit', 'name' => 'cruiser', 'amount' => 30],
                ['type' => 'unit', 'name' => 'battle_ship', 'amount' => 20],
                ['type' => 'unit', 'name' => 'bomber', 'amount' => 10],
                ['type' => 'unit', 'name' => 'battlecruiser', 'amount' => 15],
                ['type' => 'unit', 'name' => 'destroyer', 'amount' => 5],
            ],
            BotPersonality::DEFENSIVE => [
                ['type' => 'unit', 'name' => 'rocket_launcher', 'amount' => 200],
                ['type' => 'unit', 'name' => 'light_laser', 'amount' => 100],
                ['type' => 'unit', 'name' => 'heavy_laser', 'amount' => 50],
                ['type' => 'unit', 'name' => 'gauss_cannon', 'amount' => 20],
                ['type' => 'unit', 'name' => 'plasma_turret', 'amount' => 10],
                ['type' => 'unit', 'name' => 'small_shield_dome', 'amount' => 1],
                ['type' => 'unit', 'name' => 'large_shield_dome', 'amount' => 1],
            ],
            BotPersonality::ECONOMIC => [
                ['type' => 'unit', 'name' => 'small_cargo', 'amount' => 50],
                ['type' => 'unit', 'name' => 'large_cargo', 'amount' => 30],
                ['type' => 'unit', 'name' => 'recycler', 'amount' => 20],
                ['type' => 'unit', 'name' => 'cruiser', 'amount' => 15],
                ['type' => 'unit', 'name' => 'espionage_probe', 'amount' => 20],
            ],
            default => [
                ['type' => 'unit', 'name' => 'light_fighter', 'amount' => 50],
                ['type' => 'unit', 'name' => 'cruiser', 'amount' => 20],
                ['type' => 'unit', 'name' => 'battle_ship', 'amount' => 10],
                ['type' => 'unit', 'name' => 'large_cargo', 'amount' => 20],
                ['type' => 'unit', 'name' => 'recycler', 'amount' => 10],
            ],
        };

        BotStrategicPlan::create([
            'bot_id' => $botId,
            'plan_type' => 'fleet_goal',
            'goal_description' => "Fleet buildup for {$personality->value} strategy",
            'steps' => $steps,
            'current_step' => 0,
            'status' => 'active',
            'priority' => 60,
            'target_completion_at' => now()->addDays(14),
        ]);
    }

    private function ensurePlanetPlans(BotService $botService): void
    {
        $bot = $botService->getBot();
        $planets = $botService->getPlayer()->planets->all();
        $personality = $bot->getPersonalityEnum();

        $existingPlans = BotPlanetPlan::where('bot_id', $bot->id)->pluck('planet_id')->toArray();

        $i = 0;
        foreach ($planets as $planet) {
            if (in_array($planet->getPlanetId(), $existingPlans)) {
                continue;
            }

            // Assign specialization based on position and personality
            $specialization = $this->determinePlanetSpecialization($planet, $personality, $i, count($planets));

            BotPlanetPlan::create([
                'bot_id' => $bot->id,
                'planet_id' => $planet->getPlanetId(),
                'specialization' => $specialization,
                'target_levels' => self::PLANET_SPECIALIZATIONS[$specialization] ?? [],
                'priority' => $i === 0 ? 90 : 50,
            ]);
            $i++;
        }
    }

    private function determinePlanetSpecialization(PlanetService $planet, BotPersonality $personality, int $index, int $totalPlanets): string
    {
        // First planet is always balanced/economy
        if ($index === 0) {
            return 'economy';
        }

        // Assign based on personality and planet position
        $coords = $planet->getPlanetCoordinates();
        $position = $coords->position;

        // High positions (12-15) are good for deuterium (colder)
        if ($position >= 12) {
            return 'deuterium';
        }

        return match ($personality) {
            BotPersonality::AGGRESSIVE => $index === 1 ? 'fleet' : ($index === 2 ? 'economy' : 'fleet'),
            BotPersonality::DEFENSIVE => $index === 1 ? 'defense' : ($index === 2 ? 'economy' : 'defense'),
            BotPersonality::ECONOMIC => $index === 1 ? 'economy' : ($index === 2 ? 'research' : 'economy'),
            default => match ($index % 3) {
                0 => 'economy',
                1 => 'fleet',
                2 => 'research',
            },
        };
    }

    private function canExecuteStep(BotService $botService, array $step): bool
    {
        $player = $botService->getPlayer();
        $type = $step['type'] ?? '';

        if ($type === 'building') {
            $planet = $botService->getRichestPlanet();
            if (!$planet) {
                return false;
            }
            $currentLevel = $planet->getObjectLevel($step['name']);
            if ($currentLevel >= ($step['level'] ?? 1)) {
                return false; // Already at target
            }
            if (!ObjectService::objectRequirementsMet($step['name'], $planet)) {
                return false;
            }
            $price = ObjectService::getObjectPrice($step['name'], $planet);
            $resources = $planet->getResources();
            return $resources->metal->get() >= $price->metal->get()
                && $resources->crystal->get() >= $price->crystal->get()
                && $resources->deuterium->get() >= $price->deuterium->get();
        }

        if ($type === 'research') {
            $currentLevel = 0;
            try {
                $currentLevel = $player->getResearchLevel($step['name']);
            } catch (\Exception $e) {
                return false;
            }
            if ($currentLevel >= ($step['level'] ?? 1)) {
                return false; // Already at target
            }
            $planet = $botService->findPlanetWithResearchQueueSpace();
            if (!$planet) {
                return false;
            }
            if (!ObjectService::objectRequirementsMet($step['name'], $planet)) {
                return false;
            }
            $price = ObjectService::getObjectPrice($step['name'], $planet);
            $resources = $planet->getResources();
            return $resources->metal->get() >= $price->metal->get()
                && $resources->crystal->get() >= $price->crystal->get()
                && $resources->deuterium->get() >= $price->deuterium->get();
        }

        if ($type === 'unit') {
            $planet = $botService->getRichestPlanet();
            if (!$planet) {
                return false;
            }
            if (!ObjectService::objectRequirementsMet($step['name'], $planet)) {
                return false;
            }
            $price = ObjectService::getObjectPrice($step['name'], $planet);
            $resources = $planet->getResources();
            return $resources->metal->get() >= $price->metal->get()
                && $resources->crystal->get() >= $price->crystal->get()
                && $resources->deuterium->get() >= $price->deuterium->get();
        }

        return false;
    }

    private function isStepCompleted(BotService $botService, array $step): bool
    {
        $player = $botService->getPlayer();
        $type = $step['type'] ?? '';

        if ($type === 'building') {
            // Check all planets - any planet reaching the level counts
            foreach ($player->planets->all() as $planet) {
                if ($planet->getObjectLevel($step['name']) >= ($step['level'] ?? 1)) {
                    return true;
                }
            }
            return false;
        }

        if ($type === 'research') {
            try {
                return $player->getResearchLevel($step['name']) >= ($step['level'] ?? 1);
            } catch (\Exception $e) {
                return false;
            }
        }

        if ($type === 'unit') {
            // Check total units across all planets
            $totalAmount = 0;
            foreach ($player->planets->all() as $planet) {
                try {
                    $totalAmount += $planet->getObjectAmount($step['name']);
                } catch (\Exception $e) {
                    // ignore
                }
            }
            return $totalAmount >= ($step['amount'] ?? 1);
        }

        return false;
    }

    private function hasPlanOfType(int $botId, string $type): bool
    {
        return BotStrategicPlan::where('bot_id', $botId)
            ->where('plan_type', $type)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Clean up stale plans that have made no progress in 12 hours.
     */
    public function cleanupStalePlans(int $botId): int
    {
        $stalePlans = BotStrategicPlan::where('bot_id', $botId)
            ->where('status', 'active')
            ->where('updated_at', '<', now()->subHours(12))
            ->get();

        $cleaned = 0;
        foreach ($stalePlans as $plan) {
            $plan->status = 'abandoned';
            $plan->save();
            $cleaned++;
        }

        return $cleaned;
    }

    /**
     * Get the resource cost of the next planned step.
     * Returns null if no active plan or step cannot be costed.
     *
     * @return array{metal: int, crystal: int, deuterium: int, total: int}|null
     */
    public function getNextPlannedCost(BotService $botService): ?array
    {
        $botId = $botService->getBot()->id;
        $plans = BotStrategicPlan::where('bot_id', $botId)
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->get();

        foreach ($plans as $plan) {
            $step = $plan->getCurrentStep();
            if (!$step) {
                continue;
            }

            // Skip completed steps
            if ($this->isStepCompleted($botService, $step)) {
                continue;
            }

            $cost = $this->getStepCost($botService, $step);
            if ($cost !== null) {
                return $cost;
            }
        }

        return null;
    }

    /**
     * Get the resource cost of a specific plan step.
     */
    private function getStepCost(BotService $botService, array $step): ?array
    {
        $type = $step['type'] ?? '';

        try {
            if ($type === 'building') {
                $planet = $botService->getRichestPlanet();
                if (!$planet) {
                    return null;
                }
                $price = ObjectService::getObjectPrice($step['name'], $planet);
                return [
                    'metal' => $price->metal->get(),
                    'crystal' => $price->crystal->get(),
                    'deuterium' => $price->deuterium->get(),
                    'total' => $price->metal->get() + $price->crystal->get() + $price->deuterium->get(),
                ];
            }

            if ($type === 'research') {
                $planet = $botService->getRichestPlanet();
                if (!$planet) {
                    return null;
                }
                $price = ObjectService::getObjectPrice($step['name'], $planet);
                return [
                    'metal' => $price->metal->get(),
                    'crystal' => $price->crystal->get(),
                    'deuterium' => $price->deuterium->get(),
                    'total' => $price->metal->get() + $price->crystal->get() + $price->deuterium->get(),
                ];
            }

            if ($type === 'unit') {
                $planet = $botService->getRichestPlanet();
                if (!$planet) {
                    return null;
                }
                $amount = $step['amount'] ?? 1;
                $price = ObjectService::getObjectPrice($step['name'], $planet);
                return [
                    'metal' => $price->metal->get() * $amount,
                    'crystal' => $price->crystal->get() * $amount,
                    'deuterium' => $price->deuterium->get() * $amount,
                    'total' => ($price->metal->get() + $price->crystal->get() + $price->deuterium->get()) * $amount,
                ];
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    private function estimateHourlyProduction(string $machineName, int $level): int
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
}
