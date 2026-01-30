<?php

namespace OGame\Services;

use OGame\Enums\BotActionType;
use OGame\Enums\BotPersonality;

/**
 * BotDecisionService - Handles decision making for playerbots.
 */
class BotDecisionService
{
    private BotService $botService;

    public function __construct(BotService $botService)
    {
        $this->botService = $botService;
    }

    /**
     * Decide the next action for the bot based on personality.
     */
    public function decideNextAction(): BotActionType
    {
        $personality = $this->botService->getPersonality();
        $weights = $personality->getActionWeights();

        // Weights: [build, fleet, attack, research]
        // If bot can't attack, redistribute attack weight to other actions
        if (!$this->botService->canAttack()) {
            $weights = $this->redistributeAttackWeight($weights);
        }

        // Weighted random selection
        $rand = mt_rand(1, array_sum($weights));
        $counter = 0;

        // build (0 -> weights[0])
        $counter += $weights[0];
        if ($rand <= $counter) {
            return BotActionType::BUILD;
        }

        // fleet (weights[0] -> weights[0] + weights[1])
        $counter += $weights[1];
        if ($rand <= $counter) {
            // Small chance to do expedition instead of building fleet
            if (mt_rand(1, 100) <= 15) { // 15% chance
                return BotActionType::FLEET; // Will trigger expedition
            }
            return BotActionType::FLEET;
        }

        // attack (weights[0] + weights[1] -> weights[0] + weights[1] + weights[2])
        $counter += $weights[2];
        if ($rand <= $counter) {
            return BotActionType::ATTACK;
        }

        // research (remaining)
        return BotActionType::RESEARCH;
    }

    /**
     * Redistribute attack weight to other actions when attack is on cooldown.
     */
    private function redistributeAttackWeight(array $weights): array
    {
        // Attack is index 2
        $attackWeight = $weights[2];
        $remainingWeight = 100 - $attackWeight;

        // Redistribute attack weight proportionally to other actions
        $newWeights = [
            (int)($weights[0] + ($weights[0] / $remainingWeight) * $attackWeight),
            (int)($weights[1] + ($weights[1] / $remainingWeight) * $attackWeight),
            0, // Attack weight becomes 0
            (int)($weights[3] + ($weights[3] / $remainingWeight) * $attackWeight),
        ];

        // Normalize to ensure sum is 100
        $sum = array_sum($newWeights);
        return [
            (int)($newWeights[0] * 100 / $sum),
            (int)($newWeights[1] * 100 / $sum),
            0,
            (int)($newWeights[3] * 100 / $sum),
        ];
    }

    /**
     * Check if bot should perform expedition based on chance.
     */
    public function shouldDoExpedition(): bool
    {
        $chance = config('bots.expedition_chance', 0.15);
        return mt_rand(1, 100) <= ($chance * 100);
    }
}
