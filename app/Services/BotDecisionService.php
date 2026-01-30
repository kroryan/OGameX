<?php

namespace OGame\Services;

use OGame\Enums\BotActionType;
use OGame\Enums\BotObjective;

/**
 * BotDecisionService - Handles strategic decision making for playerbots.
 *
 * This service implements a strategic decision-making system that:
 * 1. Analyzes the current game state
 * 2. Determines the appropriate objective based on personality and game phase
 * 3. Evaluates available options and scores them strategically
 * 4. Chooses the best action to advance the bot's objective
 */
class BotDecisionService
{
    private BotService $botService;
    private GameStateAnalyzer $stateAnalyzer;

    public function __construct(BotService $botService)
    {
        $this->botService = $botService;
        $this->stateAnalyzer = new GameStateAnalyzer();
    }

    /**
     * Decide the next action for the bot using strategic decision-making.
     */
    public function decideNextAction(): BotActionType
    {
        // Step 1: Analyze current game state
        $state = $this->stateAnalyzer->analyzeCurrentState($this->botService);

        // Step 2: Determine objective based on personality and game phase
        $objective = $this->stateAnalyzer->determineObjective(
            $this->botService->getBot(),
            $state
        );

        // Step 3: Get available options
        $availableActions = $this->getAvailableActions($state);

        // Step 4: Score each option based on objective and state
        $scoredActions = [];
        foreach ($availableActions as $action) {
            $scoredActions[$action->value] = $this->scoreAction(
                $action,
                $objective,
                $state
            );
        }

        // Step 5: Choose action with highest score (with some randomness for variety)
        $bestAction = $this->selectBestAction($scoredActions);

        // Log the decision for debugging
        $this->logDecision($objective, $scoredActions, $bestAction);

        return $bestAction;
    }

    /**
     * Get actions that are currently available to the bot.
     */
    private function getAvailableActions(array $state): array
    {
        $actions = [];

        // Build is only available if we have resources AND building queues are not all full
        if ($state['total_resources']['metal'] > 1000 && !$state['all_building_queues_full']) {
            $actions[] = BotActionType::BUILD;
        }

        // Research is only available if we have resources AND research queues are not all full
        if ($state['can_afford_research'] && !$state['all_research_queues_full']) {
            $actions[] = BotActionType::RESEARCH;
        }

        // Fleet is always available (unit queue is unlimited) if we have resources
        if ($state['can_afford_fleet']) {
            $actions[] = BotActionType::FLEET;
        }

        // Attack is only available if not on cooldown and has fleet
        if ($this->botService->canAttack() && $state['has_significant_fleet']) {
            $actions[] = BotActionType::ATTACK;
        }

        // Ensure we always have at least one option - prefer fleet as fallback (unlimited queue)
        if (empty($actions)) {
            $actions[] = BotActionType::FLEET; // Will try to build fleet
        }

        return $actions;
    }

    /**
     * Score an action based on how well it advances the objective.
     */
    private function scoreAction(BotActionType $action, BotObjective $objective, array $state): float
    {
        $score = 0.0;

        // Base score from objective weights (0-100)
        $weights = $objective->getActionWeights();
        $baseScore = $weights[$action->value] ?? 10;
        $score += $baseScore;

        // Apply modifiers based on game phase
        $phaseModifier = $this->getPhaseModifier($action, $state['game_phase']);
        $score *= $phaseModifier;

        // Apply modifiers based on current state
        $stateModifier = $this->getStateModifier($action, $state);
        $score *= $stateModifier;

        // Add strategic bonus for certain combinations
        $strategicBonus = $this->getStrategicBonus($action, $objective, $state);
        $score += $strategicBonus;

        // Ensure minimum score
        return max(1.0, $score);
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
            ],
            'mid' => [
                BotActionType::BUILD->value => 1.0,
                BotActionType::RESEARCH->value => 1.0,
                BotActionType::FLEET->value => 1.2,
                BotActionType::ATTACK->value => 1.0,
            ],
            'late' => [
                BotActionType::BUILD->value => 0.7,
                BotActionType::RESEARCH->value => 0.8,
                BotActionType::FLEET->value => 1.3,
                BotActionType::ATTACK->value => 1.4,
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

        // Resource availability modifier
        $totalMetal = $state['total_resources']['metal'];
        $totalCrystal = $state['total_resources']['crystal'];
        $totalDeut = $state['total_resources']['deuterium'];
        $totalResources = $totalMetal + $totalCrystal + $totalDeut;

        switch ($action->value) {
            case 'build':
                // If storage is nearly full, prioritize building
                $richestPlanet = $this->botService->getRichestPlanet();
                if ($richestPlanet) {
                    $resources = $richestPlanet->getResources();
                    $metalMax = $richestPlanet->metalStorage()->get();
                    $usagePercent = $metalMax > 0 ? $resources->metal->get() / $metalMax : 0;
                    if ($usagePercent > 0.9) {
                        $modifier = 1.5; // Urgent to spend
                    }
                }
                break;

            case 'fleet':
                // If already have significant fleet, reduce priority
                if ($state['fleet_points'] > 200000) {
                    $modifier = 0.7;
                }
                break;

            case 'attack':
                // Higher modifier if bot has strong fleet
                if ($state['has_significant_fleet']) {
                    $modifier = 1.3;
                }
                // Reduce if resources are low (need to rebuild)
                if ($totalResources < 100000) {
                    $modifier = 0.5;
                }
                break;

            case 'research':
                // Boost if research is low compared to buildings
                if ($state['research_points'] < $state['building_points'] * 0.5) {
                    $modifier = 1.4;
                }
                break;
        }

        return $modifier;
    }

    /**
     * Get strategic bonus for action-objective combinations.
     */
    private function getStrategicBonus(BotActionType $action, BotObjective $objective, array $state): float
    {
        $bonus = 0.0;

        // Special bonuses for certain combinations
        switch ($objective->value) {
            case BotObjective::ECONOMIC_GROWTH->value:
                if ($action === BotActionType::BUILD) {
                    // Extra bonus for building when focused on economy
                    $bonus += 30;
                }
                if ($action === BotActionType::RESEARCH) {
                    // Bonus for energy/production techs
                    $bonus += 15;
                }
                break;

            case BotObjective::FLEET_ACCUMULATION->value:
                if ($action === BotActionType::FLEET) {
                    $bonus += 40;
                }
                if ($action === BotActionType::ATTACK && $state['has_significant_fleet']) {
                    // Bonus for using fleet when it's significant
                    $bonus += 20;
                }
                break;

            case BotObjective::DEFENSIVE_FORTIFICATION->value:
                if ($action === BotActionType::BUILD) {
                    // Build defenses
                    $bonus += 35;
                }
                if ($action === BotActionType::RESEARCH) {
                    // Defense techs
                    $bonus += 20;
                }
                break;

            case BotObjective::TERRITORIAL_EXPANSION->value:
                if ($action === BotActionType::RESEARCH) {
                    // Astrophysics is crucial for expansion
                    $bonus += 35;
                }
                if ($action === BotActionType::FLEET) {
                    // Need colony ships
                    $bonus += 25;
                }
                break;

            case BotObjective::RAIDING_AND_PROFIT->value:
                if ($action === BotActionType::ATTACK) {
                    $bonus += 50;
                }
                if ($action === BotActionType::FLEET) {
                    $bonus += 30;
                }
                break;
        }

        return $bonus;
    }

    /**
     * Select the best action from scored options.
     * Uses weighted random selection to add variety while preferring better options.
     */
    private function selectBestAction(array $scoredActions): BotActionType
    {
        // Sort by score (descending)
        arsort($scoredActions);

        // Get top 3 actions
        $topActions = array_slice($scoredActions, 0, 3, true);

        // Calculate total score for weighted selection
        $totalScore = array_sum($topActions);

        // Weighted random selection
        $rand = mt_rand(1, $totalScore * 100) / 100;
        $counter = 0;

        foreach ($topActions as $action => $score) {
            $counter += $score;
            if ($rand <= $counter) {
                return BotActionType::from($action);
            }
        }

        // Fallback to highest score
        return BotActionType::from(array_key_first($scoredActions));
    }

    /**
     * Log decision for debugging purposes.
     */
    private function logDecision(BotObjective $objective, array $scoredActions, BotActionType $chosen): void
    {
        // Only log occasionally to avoid spam
        if (mt_rand(1, 10) !== 1) {
            return;
        }

        $bot = $this->botService->getBot();
        $actionScores = [];
        foreach ($scoredActions as $action => $score) {
            $actionScores[] = "{$action}:{$score}";
        }

        $this->botService->logAction(
            BotActionType::BUILD, // Use BUILD as category for decision logs
            "Strategic Decision - Objective: {$objective->value}, Chosen: {$chosen->value}, Scores: " . implode(', ', $actionScores),
            []
        );
    }

    /**
     * Check if bot should perform expedition based on chance and strategic context.
     */
    public function shouldDoExpedition(): bool
    {
        $chance = config('bots.expedition_chance', 0.15);

        // Reduce expedition chance in early game
        $state = $this->stateAnalyzer->analyzeCurrentState($this->botService);
        if ($state['game_phase'] === 'early') {
            $chance *= 0.5;
        }

        return mt_rand(1, 100) <= ($chance * 100);
    }
}
