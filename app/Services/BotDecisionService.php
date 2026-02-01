<?php

namespace OGame\Services;

use OGame\Enums\BotActionType;
use OGame\Enums\BotObjective;
use OGame\Enums\BotPersonality;

/**
 * BotDecisionService - Enhanced strategic decision making for playerbots.
 *
 * Now integrates: strategic planning, intelligence data, threat maps,
 * activity patterns, multi-action support, state machine, and
 * personality-driven traits for human-like behavior.
 */
class BotDecisionService
{
    private BotService $botService;
    private GameStateAnalyzer $stateAnalyzer;
    private BotObjectiveService $objectiveService;
    private AdaptiveStrategyService $adaptiveStrategy;
    private BotLongTermStrategyService $longTermStrategy;
    private BotStrategicPlannerService $planner;
    private BotIntelligenceService $intelligence;

    public function __construct(BotService $botService)
    {
        $this->botService = $botService;
        $this->stateAnalyzer = new GameStateAnalyzer();
        $this->objectiveService = new BotObjectiveService();
        $this->adaptiveStrategy = new AdaptiveStrategyService();
        $this->longTermStrategy = new BotLongTermStrategyService();
        $this->planner = new BotStrategicPlannerService();
        $this->intelligence = new BotIntelligenceService();
    }

    /**
     * Decide multiple actions for this tick (like a real player).
     * Returns an array of actions to execute, up to $maxActions.
     */
    public function decideActions(int $maxActions = 3): array
    {
        $state = $this->stateAnalyzer->analyzeCurrentState($this->botService);
        $bot = $this->botService->getBot();
        $botId = $bot->id;

        // Ensure bot has strategic plans
        try {
            $this->planner->ensurePlans($this->botService);
        } catch (\Exception $e) {
            logger()->warning("Bot {$botId}: ensurePlans failed: {$e->getMessage()}");
        }

        // Check for intelligence data
        $state['has_attack_target'] = cache()->remember(
            "bot:{$botId}:has_attack_target",
            now()->addMinutes(5),
            function () {
                try {
                    return $this->botService->findTarget() !== null;
                } catch (\Exception) {
                    return false;
                }
            }
        );

        // Adaptive tuning
        $this->adaptiveStrategy->adaptIfNeeded($this->botService, $state);

        $strategy = $this->longTermStrategy->getStrategy($this->botService, $state);
        $state['long_term_strategy'] = $strategy['strategy'] ?? 'balanced';
        $state['strategy_weights'] = $strategy['weights'] ?? [];

        // Determine objective
        $objective = $this->objectiveService->determineObjective($bot, $state);

        // State machine: update bot state based on situation
        $this->updateBotState($bot, $state);

        // Collect actions
        $actions = [];
        $usedTypes = [];

        for ($i = 0; $i < $maxActions; $i++) {
            $action = $this->decideSingleAction($state, $objective, $usedTypes);
            if ($action === null) {
                break;
            }
            $actions[] = $action;
            $usedTypes[] = $action->value;

            // Some actions are exclusive (don't do attack + defend in same tick)
            if (in_array($action, [BotActionType::ATTACK, BotActionType::DEFENSE])) {
                break;
            }
        }

        return $actions;
    }

    /**
     * Legacy single action decision (backward compatible).
     */
    public function decideNextAction(): ?BotActionType
    {
        $actions = $this->decideActions(1);
        return $actions[0] ?? null;
    }

    /**
     * Decide a single action, optionally excluding already-chosen types.
     */
    private function decideSingleAction(array $state, BotObjective $objective, array $excludeTypes = []): ?BotActionType
    {
        // First: check if strategic plan has an actionable step
        $planned = $this->getPlannedActionType($state, $excludeTypes);
        if ($planned !== null) {
            return $planned;
        }

        $availableActions = $this->getAvailableActions($state, $excludeTypes);

        if (empty($availableActions)) {
            // Relax reserve
            if (!in_array('build', $excludeTypes) && $this->botService->canAffordAnyBuilding(true)) {
                $availableActions[] = BotActionType::BUILD;
            } elseif (!in_array('research', $excludeTypes) && $this->botService->canAffordAnyResearch(true)) {
                $availableActions[] = BotActionType::RESEARCH;
            } elseif (!in_array('fleet', $excludeTypes) && $this->botService->canAffordAnyUnit(true)) {
                $availableActions[] = BotActionType::FLEET;
            }
        }

        // Ultimate fallback: diplomacy is always possible (alliance management, etc.)
        if (empty($availableActions)) {
            if (!in_array('diplomacy', $excludeTypes)) {
                $availableActions[] = BotActionType::DIPLOMACY;
            }
        }

        if (empty($availableActions)) {
            return null;
        }

        // Score each action
        $scoredActions = [];
        foreach ($availableActions as $action) {
            $scoredActions[$action->value] = $this->scoreAction($action, $objective, $state);
        }

        $bestAction = $this->selectBestAction($scoredActions);
        $this->logDecision($objective, $scoredActions, $bestAction);

        return $bestAction;
    }

    /**
     * Check if strategic plans suggest a specific action type.
     */
    private function getPlannedActionType(array $state, array $excludeTypes): ?BotActionType
    {
        try {
            $planned = $this->planner->getNextPlannedAction($this->botService);
            if (!$planned) {
                return null;
            }

            $step = $planned['step'];
            $type = match ($step['type'] ?? '') {
                'building' => BotActionType::BUILD,
                'research' => BotActionType::RESEARCH,
                'unit' => BotActionType::FLEET,
                default => null,
            };

            if ($type && !in_array($type->value, $excludeTypes)) {
                // Store the planned step in cache for execution
                $botId = $this->botService->getBot()->id;
                cache()->put("bot:{$botId}:planned_step", $planned, now()->addMinutes(10));
                return $type;
            }
        } catch (\Exception $e) {
            logger()->warning("Bot: getPlannedActionType failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Update bot state machine based on current situation.
     */
    private function updateBotState(\OGame\Models\Bot $bot, array $state): void
    {
        $currentState = $bot->getState();

        if (!empty($state['is_under_threat'])) {
            if ($currentState !== 'defending') {
                $bot->setState('defending');
            }
            return;
        }

        // Transition logic
        $newState = match (true) {
            $state['game_phase'] === 'early' && ($state['total_points'] ?? 0) < 5000 => 'building',
            ($state['can_colonize'] ?? false) && count($this->botService->getPlayer()->planets->all()) < 3 => 'colonizing',
            ($state['has_significant_fleet'] ?? false) && ($state['has_attack_target'] ?? false) => 'raiding',
            ($state['fleet_slot_usage'] ?? 0) > 0.6 => 'saving',
            default => 'exploring',
        };

        if ($newState !== $currentState) {
            $bot->setState($newState);
        }
    }

    /**
     * Get actions that are currently available to the bot.
     */
    private function getAvailableActions(array $state, array $excludeTypes = []): array
    {
        $actions = [];

        // BUILD
        if (!in_array('build', $excludeTypes)
            && !$this->botService->shouldSkipAction('build')
            && ($state['can_afford_build'] ?? false)
            && !$state['all_building_queues_full']) {
            $actions[] = BotActionType::BUILD;
        }

        // RESEARCH
        if (!in_array('research', $excludeTypes)
            && !$this->botService->shouldSkipAction('research')
            && ($state['can_afford_research'] ?? false)
            && !$state['all_research_queues_full']) {
            $actions[] = BotActionType::RESEARCH;
        }

        // FLEET
        if (!in_array('fleet', $excludeTypes)
            && !$this->botService->shouldSkipAction('fleet')
            && ($state['can_afford_fleet'] ?? false)) {
            $actions[] = BotActionType::FLEET;
        }

        // ATTACK
        $slotUsage = (float) ($state['fleet_slot_usage'] ?? 0.0);
        if (!in_array('attack', $excludeTypes)
            && !$this->botService->shouldSkipAction('attack')
            && $this->botService->canAttack()
            && $this->botService->hasFleetSlotsAvailable()
            && ($state['has_significant_fleet'] ?? false)
            && !empty($state['has_attack_target'])
            && $slotUsage < 0.9) {
            $actions[] = BotActionType::ATTACK;
        }

        // TRADE
        if (!in_array('trade', $excludeTypes)
            && !$this->botService->shouldSkipAction('trade')
            && $this->botService->shouldTradeResources()) {
            $actions[] = BotActionType::TRADE;
        }

        // ESPIONAGE (new) - proactive espionage
        if (!in_array('espionage', $excludeTypes)
            && $this->botService->hasFleetSlotsAvailable()
            && $slotUsage < 0.7
            && $this->shouldDoProactiveEspionage($state)) {
            $actions[] = BotActionType::ESPIONAGE;
        }

        // DEFENSE (new) - proactive defense building
        if (!in_array('defense', $excludeTypes)
            && !$this->botService->shouldSkipAction('build')
            && ($state['can_afford_fleet'] ?? false)
            && $this->shouldBuildDefense($state)) {
            $actions[] = BotActionType::DEFENSE;
        }

        // DIPLOMACY (new) - alliance and social actions
        if (!in_array('diplomacy', $excludeTypes)
            && mt_rand(1, 20) === 1) { // 5% chance per tick
            $actions[] = BotActionType::DIPLOMACY;
        }

        return $actions;
    }

    /**
     * Should the bot proactively spy on neighbors?
     */
    private function shouldDoProactiveEspionage(array $state): bool
    {
        $bot = $this->botService->getBot();
        $personality = $bot->getPersonalityEnum();

        // Scientists and Raiders love espionage
        $chance = match ($personality) {
            BotPersonality::RAIDER, BotPersonality::AGGRESSIVE => 30,
            BotPersonality::SCIENTIST, BotPersonality::BALANCED => 20,
            BotPersonality::DIPLOMAT, BotPersonality::EXPLORER => 15,
            default => 10,
        };

        // Boost if we have espionage probes
        $planet = $this->botService->getRichestPlanet();
        if ($planet && $planet->getObjectAmount('espionage_probe') < 2) {
            return false;
        }

        return mt_rand(1, 100) <= $chance;
    }

    /**
     * Should the bot proactively build defenses?
     */
    private function shouldBuildDefense(array $state): bool
    {
        $bot = $this->botService->getBot();
        $personality = $bot->getPersonalityEnum();
        $defensePoints = $state['defense_points'] ?? 0;
        $buildingPoints = $state['building_points'] ?? 0;

        // Turtles and defensive bots always want defenses
        if (in_array($personality, [BotPersonality::TURTLE, BotPersonality::DEFENSIVE])) {
            return mt_rand(1, 100) <= 40;
        }

        // All bots should build some defense if ratio is too low
        if ($buildingPoints > 5000 && $defensePoints < $buildingPoints * 0.1) {
            return mt_rand(1, 100) <= 25;
        }

        // Counter-espionage triggered: someone is scouting us
        if (($bot->espionage_counter ?? 0) > 2) {
            return mt_rand(1, 100) <= 35;
        }

        return false;
    }

    /**
     * Score an action based on how well it advances the objective.
     */
    private function scoreAction(BotActionType $action, BotObjective $objective, array $state): float
    {
        $score = 0.0;
        $bot = $this->botService->getBot();

        // Base score from objective weights
        $weights = $objective->getActionWeights();
        $baseScore = $weights[$action->value] ?? 10;
        $score += $baseScore;

        // Strategy weights
        $strategyWeights = $state['strategy_weights'] ?? [];
        if (!empty($strategyWeights[$action->value])) {
            $score *= (float) $strategyWeights[$action->value];
        }

        // Bot-specific action probabilities
        $probWeights = $bot->getActionProbabilities();
        $probModifier = ($probWeights[$action->value] ?? 100) / 100;
        $score *= $probModifier;

        // Phase modifier
        $phaseModifier = $this->getPhaseModifier($action, $state['game_phase']);
        $score *= $phaseModifier;

        // State modifier
        $stateModifier = $this->getStateModifier($action, $state);
        $score *= $stateModifier;

        // Strategic bonus
        $strategicBonus = $this->getStrategicBonus($action, $objective, $state);
        $score += $strategicBonus;

        // Trait bonuses
        $traitBonus = $this->getTraitBonus($action, $bot, $state);
        $score += $traitBonus;

        // Risk tolerance modifier
        $riskMod = $this->getRiskModifier($action, $bot->getRiskTolerance());
        $score *= $riskMod;

        // ROI/risk adjustments
        $score += $this->getRoiBonus($action, $state);
        $score -= $this->getRiskPenalty($action, $state);

        return max(1.0, $score);
    }

    /**
     * Get trait-based bonus for an action.
     */
    private function getTraitBonus(BotActionType $action, \OGame\Models\Bot $bot, array $state): float
    {
        $bonus = 0.0;
        $traits = $bot->getTraits();

        if (in_array('vengeful', $traits) && $action === BotActionType::ATTACK) {
            // Vengeful bots attack more after being attacked
            if (($bot->espionage_counter ?? 0) > 0 || !empty($state['is_under_threat'])) {
                $bonus += 20;
            }
        }

        if (in_array('opportunistic', $traits)) {
            if ($action === BotActionType::ATTACK && !empty($state['has_attack_target'])) {
                $bonus += 15;
            }
            if ($action === BotActionType::TRADE && !empty($state['is_storage_pressure_high'])) {
                $bonus += 10;
            }
        }

        if (in_array('cautious', $traits)) {
            if ($action === BotActionType::DEFENSE) {
                $bonus += 15;
            }
            if ($action === BotActionType::ATTACK) {
                $bonus -= 10;
            }
        }

        if (in_array('impatient', $traits)) {
            if ($action === BotActionType::ATTACK || $action === BotActionType::FLEET) {
                $bonus += 10;
            }
            if ($action === BotActionType::RESEARCH) {
                $bonus -= 5;
            }
        }

        if (in_array('patient', $traits)) {
            if ($action === BotActionType::RESEARCH || $action === BotActionType::BUILD) {
                $bonus += 10;
            }
        }

        if (in_array('social', $traits) && $action === BotActionType::DIPLOMACY) {
            $bonus += 20;
        }

        if (in_array('adventurous', $traits) && $action === BotActionType::FLEET) {
            $bonus += 10; // Loves expeditions
        }

        if (in_array('methodical', $traits)) {
            if ($action === BotActionType::RESEARCH) {
                $bonus += 15;
            }
            if ($action === BotActionType::ESPIONAGE) {
                $bonus += 10;
            }
        }

        return $bonus;
    }

    /**
     * Modify score based on risk tolerance.
     */
    private function getRiskModifier(BotActionType $action, int $riskTolerance): float
    {
        $riskFactor = $riskTolerance / 50.0; // 0.0-2.0 range

        return match ($action) {
            BotActionType::ATTACK => 0.5 + ($riskFactor * 0.5), // 0.5-1.5
            BotActionType::DEFENSE => 1.5 - ($riskFactor * 0.3), // 0.9-1.5
            BotActionType::ESPIONAGE => 0.8 + ($riskFactor * 0.2), // 0.8-1.2
            default => 1.0,
        };
    }

    /**
     * Get phase modifier for an action.
     */
    private function getPhaseModifier(BotActionType $action, string $phase): float
    {
        $modifiers = [
            'early' => [
                BotActionType::BUILD->value => 1.5,
                BotActionType::RESEARCH->value => 1.3,
                BotActionType::FLEET->value => 0.5,
                BotActionType::ATTACK->value => 0.2,
                BotActionType::TRADE->value => 0.6,
                BotActionType::ESPIONAGE->value => 0.3,
                BotActionType::DEFENSE->value => 0.4,
                BotActionType::DIPLOMACY->value => 0.5,
            ],
            'mid' => [
                BotActionType::BUILD->value => 1.0,
                BotActionType::RESEARCH->value => 1.0,
                BotActionType::FLEET->value => 1.2,
                BotActionType::ATTACK->value => 1.0,
                BotActionType::TRADE->value => 1.0,
                BotActionType::ESPIONAGE->value => 1.2,
                BotActionType::DEFENSE->value => 1.0,
                BotActionType::DIPLOMACY->value => 1.0,
            ],
            'late' => [
                BotActionType::BUILD->value => 0.7,
                BotActionType::RESEARCH->value => 0.8,
                BotActionType::FLEET->value => 1.3,
                BotActionType::ATTACK->value => 1.4,
                BotActionType::TRADE->value => 1.1,
                BotActionType::ESPIONAGE->value => 1.3,
                BotActionType::DEFENSE->value => 0.8,
                BotActionType::DIPLOMACY->value => 1.2,
            ],
        ];

        return $modifiers[$phase][$action->value] ?? 1.0;
    }

    /**
     * Get state modifier for an action.
     */
    private function getStateModifier(BotActionType $action, array $state): float
    {
        $modifier = 1.0;

        switch ($action->value) {
            case 'build':
                $richestPlanet = $this->botService->getRichestPlanet();
                if ($richestPlanet) {
                    $resources = $richestPlanet->getResources();
                    $metalMax = $richestPlanet->metalStorage()->get();
                    $usagePercent = $metalMax > 0 ? $resources->metal->get() / $metalMax : 0;
                    if ($usagePercent > 0.9) {
                        $modifier = 1.5;
                    }
                }
                if (!empty($state['is_under_threat'])) {
                    $modifier *= 1.2;
                }
                break;

            case 'fleet':
                if (($state['fleet_points'] ?? 0) > 200000) {
                    $modifier = 0.7;
                }
                if (!empty($state['is_under_threat'])) {
                    $modifier *= 1.4;
                }
                if (($state['fleet_slot_usage'] ?? 0.0) > 0.8) {
                    $modifier *= 0.6;
                }
                break;

            case 'attack':
                if ($state['has_significant_fleet'] ?? false) {
                    $modifier = 1.3;
                }
                if (($state['total_resources_sum'] ?? 0) < 100000) {
                    $modifier = 0.5;
                }
                if (!empty($state['is_under_threat'])) {
                    $modifier *= 0.6;
                }
                if (($state['fleet_slot_usage'] ?? 0.0) > 0.85) {
                    $modifier *= 0.5;
                }
                break;

            case 'research':
                if (($state['research_points'] ?? 0) < ($state['building_points'] ?? 0) * 0.5) {
                    $modifier = 1.4;
                }
                if (!empty($state['is_under_threat'])) {
                    $modifier *= 0.8;
                }
                break;

            case 'trade':
                $modifier = ($this->botService->isStoragePressureHigh()) ? 1.5 : 1.1;
                if (($state['resource_imbalance'] ?? 0.0) > 0.4) {
                    $modifier *= 1.3;
                }
                break;

            case 'espionage':
                $modifier = 1.0;
                // Boost espionage if we're about to attack
                if (($state['has_significant_fleet'] ?? false) && ($state['fleet_slot_usage'] ?? 0.0) < 0.5) {
                    $modifier = 1.5;
                }
                break;

            case 'defense':
                if (!empty($state['is_under_threat'])) {
                    $modifier = 2.0;
                }
                $defenseRatio = ($state['building_points'] ?? 0) > 0
                    ? ($state['defense_points'] ?? 0) / ($state['building_points'] ?? 1)
                    : 0;
                if ($defenseRatio < 0.1) {
                    $modifier *= 1.5;
                }
                break;

            case 'diplomacy':
                $modifier = 0.8; // Low base modifier, boosted by traits
                break;
        }

        return $modifier;
    }

    private function getRoiBonus(BotActionType $action, array $state): float
    {
        $bonus = 0.0;
        $production = $state['total_production'] ?? ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        $prodSum = (int) ($production['metal'] + $production['crystal'] + $production['deuterium']);
        $resourceSum = (int) ($state['total_resources_sum'] ?? 0);

        switch ($action) {
            case BotActionType::BUILD:
                if ($prodSum > 0) {
                    $bonus += min(25, ($prodSum / 1000) * 5);
                }
                if (!empty($state['is_storage_pressure_high'])) {
                    $bonus += 10;
                }
                break;
            case BotActionType::RESEARCH:
                if (($state['research_points'] ?? 0) < ($state['building_points'] ?? 0) * 0.6) {
                    $bonus += 15;
                }
                break;
            case BotActionType::FLEET:
                if (($state['fleet_points'] ?? 0) < ($state['building_points'] ?? 0) * 0.3) {
                    $bonus += 10;
                }
                break;
            case BotActionType::ATTACK:
                if (!empty($state['has_attack_target'])) {
                    $bonus += 15;
                }
                break;
            case BotActionType::TRADE:
                $imbalance = (float) ($state['resource_imbalance'] ?? 0.0);
                $bonus += min(20, $imbalance * 30);
                break;
            case BotActionType::DEFENSE:
                if (($state['defense_points'] ?? 0) < ($state['building_points'] ?? 0) * 0.15) {
                    $bonus += 20;
                }
                break;
            default:
                break;
        }

        return $bonus;
    }

    private function getRiskPenalty(BotActionType $action, array $state): float
    {
        $penalty = 0.0;
        $resourceSum = (int) ($state['total_resources_sum'] ?? 0);

        if (!empty($state['is_under_threat'])) {
            if ($action === BotActionType::ATTACK) {
                $penalty += 30;
            } elseif ($action === BotActionType::FLEET) {
                $penalty += 10;
            }
        }

        if ($resourceSum < 3000 && $action === BotActionType::ATTACK) {
            $penalty += 20;
        }

        return $penalty;
    }

    /**
     * Get strategic bonus for action-objective combinations.
     */
    private function getStrategicBonus(BotActionType $action, BotObjective $objective, array $state): float
    {
        $bonus = 0.0;

        switch ($objective) {
            case BotObjective::ECONOMIC_GROWTH:
                if ($action === BotActionType::BUILD) $bonus += 30;
                if ($action === BotActionType::RESEARCH) $bonus += 15;
                if ($action === BotActionType::TRADE) $bonus += 20;
                break;

            case BotObjective::FLEET_ACCUMULATION:
                if ($action === BotActionType::FLEET) $bonus += 40;
                if ($action === BotActionType::ATTACK && ($state['has_significant_fleet'] ?? false)) $bonus += 20;
                break;

            case BotObjective::DEFENSIVE_FORTIFICATION:
                if ($action === BotActionType::BUILD) $bonus += 25;
                if ($action === BotActionType::DEFENSE) $bonus += 40;
                if ($action === BotActionType::RESEARCH) $bonus += 15;
                break;

            case BotObjective::TERRITORIAL_EXPANSION:
                if ($action === BotActionType::RESEARCH) $bonus += 35;
                if ($action === BotActionType::FLEET) {
                    $bonus += 25;
                    if ($this->botService->shouldColonize()) $bonus += 25;
                }
                break;

            case BotObjective::RAIDING_AND_PROFIT:
                if ($action === BotActionType::ATTACK) $bonus += 50;
                if ($action === BotActionType::FLEET) $bonus += 30;
                if ($action === BotActionType::ESPIONAGE) $bonus += 25;
                break;

            case BotObjective::TECH_RUSH:
                if ($action === BotActionType::RESEARCH) $bonus += 50;
                if ($action === BotActionType::BUILD) $bonus += 15;
                break;

            case BotObjective::INTELLIGENCE_GATHERING:
                if ($action === BotActionType::ESPIONAGE) $bonus += 45;
                if ($action === BotActionType::ATTACK) $bonus += 15;
                break;

            case BotObjective::ALLIANCE_WARFARE:
                if ($action === BotActionType::ATTACK) $bonus += 35;
                if ($action === BotActionType::FLEET) $bonus += 25;
                if ($action === BotActionType::DIPLOMACY) $bonus += 20;
                break;
        }

        return $bonus;
    }

    /**
     * Select the best action from scored options.
     */
    private function selectBestAction(array $scoredActions): BotActionType
    {
        arsort($scoredActions);
        $topActions = array_slice($scoredActions, 0, 3, true);

        $intWeights = [];
        foreach ($topActions as $action => $score) {
            $intWeights[$action] = max(1, (int) round($score * 100));
        }

        $totalWeight = array_sum($intWeights);
        $rand = mt_rand(1, $totalWeight);
        $counter = 0;

        foreach ($intWeights as $action => $weight) {
            $counter += $weight;
            if ($rand <= $counter) {
                return BotActionType::from($action);
            }
        }

        return BotActionType::from(array_key_first($scoredActions));
    }

    /**
     * Log decision for debugging.
     */
    private function logDecision(BotObjective $objective, array $scoredActions, BotActionType $chosen): void
    {
        if (mt_rand(1, 5) !== 1) {
            return;
        }

        $actionScores = [];
        foreach ($scoredActions as $action => $score) {
            $actionScores[] = "{$action}:" . round($score, 1);
        }

        $bot = $this->botService->getBot();
        $this->botService->logAction(
            BotActionType::BUILD,
            "Decision [state:{$bot->getState()}] Obj:{$objective->value}, Pick:{$chosen->value}, Scores:" . implode(',', $actionScores),
            []
        );
    }

    /**
     * Check if bot should perform expedition.
     */
    public function shouldDoExpedition(): bool
    {
        $chance = config('bots.expedition_chance', 0.15);
        $bot = $this->botService->getBot();
        $personality = $bot->getPersonalityEnum();

        // Explorers love expeditions
        if ($personality === BotPersonality::EXPLORER) {
            $chance *= 2.5;
        }

        $state = $this->stateAnalyzer->analyzeCurrentState($this->botService);
        if ($state['game_phase'] === 'early') {
            $chance *= 0.5;
        } elseif ($state['game_phase'] === 'late') {
            $chance *= 1.5;
        }

        return mt_rand(1, 100) <= ($chance * 100);
    }
}
