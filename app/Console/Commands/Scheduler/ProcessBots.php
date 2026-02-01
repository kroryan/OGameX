<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use OGame\Enums\BotActionType;
use OGame\Models\Bot;
use OGame\Services\BotDecisionService;
use OGame\Services\BotIntelligenceService;
use OGame\Services\BotService;
use OGame\Services\BotStrategicPlannerService;
use OGame\Services\GameStateAnalyzer;

/**
 * ProcessBots - Enhanced scheduler that processes active playerbots.
 *
 * Now supports: multi-action per tick, state machine, espionage,
 * proactive defense, diplomacy, intelligence gathering, and
 * strategic planning.
 */
class ProcessBots extends Command
{
    protected $signature = 'ogamex:scheduler:process-bots';
    protected $description = 'Process active playerbots and execute their actions';

    public function handle(): int
    {
        if (!config('bots.scheduler_enabled', true)) {
            $this->info('Bot scheduler is disabled.');
            return 0;
        }

        $botFactory = app(\OGame\Factories\BotServiceFactory::class);
        $batchSize = (int) config('bots.scheduler_batch_size', 50);
        $batchSize = max(1, min(500, $batchSize));

        $allActiveQuery = Bot::where('is_active', true)
            ->orderByRaw('last_action_at is null desc')
            ->orderBy('last_action_at');
        $totalActive = (clone $allActiveQuery)->count();

        if ($totalActive === 0) {
            $this->info('No active bots to process.');
            return 0;
        }

        $offset = (int) cache()->get('bots.scheduler_offset', 0);
        if ($offset >= $totalActive) {
            $offset = 0;
        }
        if ($batchSize >= $totalActive) {
            $offset = 0;
        }

        $batch = $allActiveQuery->skip($offset)->take($batchSize)->get();
        if ($batch->isEmpty()) {
            $offset = 0;
            $batch = $allActiveQuery->skip($offset)->take($batchSize)->get();
        }

        $bots = [];
        foreach ($batch as $bot) {
            if ($bot->isActive()) {
                $bots[] = $botFactory->makeFromBotModel($bot);
            }
        }

        if (empty($bots)) {
            $this->info('No bots in active schedule window to process.');
            return 0;
        }

        $this->info("Processing " . count($bots) . " active bot(s) (batch {$batchSize}, offset {$offset}, total {$totalActive})...");

        $successCount = 0;
        $failCount = 0;

        foreach ($bots as $botService) {
            try {
                GameStateAnalyzer::clearCache();
                $this->processBot($botService);
                $successCount++;
            } catch (\Exception $e) {
                $this->error("Error processing bot {$botService->getBot()->name}: {$e->getMessage()}");
                $failCount++;
            }
        }

        $this->info("Bot processing complete: {$successCount} success, {$failCount} failed.");

        $newOffset = $offset + count($bots);
        if ($newOffset >= $totalActive) {
            $newOffset = 0;
        }
        cache()->put('bots.scheduler_offset', $newOffset, now()->addHours(6));

        return 0;
    }

    /**
     * Process a single bot with multi-action support.
     */
    private function processBot(BotService $bot): void
    {
        $botModel = $bot->getBot();
        $this->line("Processing bot: {$botModel->name} [state:{$botModel->getState()}, personality:{$botModel->personality}]");

        if (!$bot->isActive()) {
            $this->line('  - Skipped: inactive schedule');
            return;
        }

        $bot->ensureCharacterClass();

        // Initialize traits if not set
        $this->ensureTraits($botModel);

        // Fleet save schedule check
        if ($bot->shouldFleetSaveBySchedule() && $bot->performFleetSave(true)) {
            $this->line('  - Scheduled fleet save executed');
            $botModel->updateLastAction();
            return;
        }

        // Timing check
        $lastAction = $botModel->last_action_at;
        $interval = config('bots.scheduler_interval_minutes', 5);
        if ($lastAction && $lastAction->diffInMinutes(now()) < $interval) {
            $this->line("  - Skipped: last action {$lastAction->diffInMinutes(now())}m ago (min: {$interval})");
            return;
        }

        // Refresh queues and resources
        $this->refreshBotQueues($bot);

        // Alliance management
        if (config('bots.allow_alliances', true)) {
            $bot->ensureAlliance();
        }

        // Counter-espionage detection
        try {
            $intel = new BotIntelligenceService();
            if ($intel->detectEspionage($bot)) {
                $this->line('  - Detected incoming espionage!');
            }
        } catch (\Exception $e) {
            // Non-critical
        }

        // Moon infrastructure building (low frequency)
        if (mt_rand(1, 10) === 1) {
            try {
                if ($bot->buildMoonInfrastructure()) {
                    $this->line('  - Built moon infrastructure');
                }
            } catch (\Exception $e) {
                // Non-critical
            }
        }

        // Proactive phalanx scanning (low frequency)
        if (mt_rand(1, 15) === 1) {
            try {
                if ($bot->proactivePhalanxScan()) {
                    $this->line('  - Proactive phalanx scan executed');
                }
            } catch (\Exception $e) {
                // Non-critical
            }
        }

        // Threat response (event-driven)
        if ($bot->isUnderThreat()) {
            $this->handleThreatResponse($bot);
            return;
        }

        // Multi-action decision: up to 3 actions per tick
        $maxActions = config('bots.max_actions_per_tick', 3);
        $decisionService = new BotDecisionService($bot);
        $actions = $decisionService->decideActions($maxActions);

        if (empty($actions)) {
            $this->line('  - Skipped: no viable actions available');
            $bot->logAction(BotActionType::BUILD, 'No viable actions available', [], 'failed');
            $botModel->updateLastAction();
            return;
        }

        $this->line("  - Actions planned: " . implode(', ', array_map(fn($a) => $a->value, $actions)));

        // Execute each action
        $anySuccess = false;
        foreach ($actions as $action) {
            $success = $this->executeAction($bot, $action, $decisionService);
            if ($success) {
                $anySuccess = true;
                $this->line("  - {$action->value}: Success");
            } else {
                $this->line("  - {$action->value}: Failed");
            }
        }

        $botModel->updateLastAction();

        if ($anySuccess) {
            // Reset espionage counter after successful actions
            if ($botModel->espionage_counter > 0) {
                $botModel->espionage_counter = max(0, $botModel->espionage_counter - 1);
                $botModel->save();
            }
        }
    }

    /**
     * Execute a single action.
     */
    private function executeAction(BotService $bot, BotActionType $action, BotDecisionService $decisionService): bool
    {
        return match ($action) {
            BotActionType::BUILD => $this->handleBuildAction($bot),
            BotActionType::RESEARCH => $bot->researchRandomTech(),
            BotActionType::FLEET => $this->handleFleetAction($bot, $decisionService),
            BotActionType::ATTACK => $this->handleAttackAction($bot),
            BotActionType::TRADE => $bot->sendResourceTransport(),
            BotActionType::ESPIONAGE => $this->handleEspionageAction($bot),
            BotActionType::DEFENSE => $this->handleDefenseAction($bot),
            BotActionType::DIPLOMACY => $this->handleDiplomacyAction($bot),
        };
    }

    /**
     * Handle build action - checks for strategic plan first.
     */
    private function handleBuildAction(BotService $bot): bool
    {
        // Check if strategic planner has a specific building step
        $botId = $bot->getBot()->id;
        $planned = cache()->get("bot:{$botId}:planned_step");

        if ($planned && ($planned['step']['type'] ?? '') === 'building') {
            $step = $planned['step'];
            try {
                $planet = $bot->getRichestPlanet();
                if ($planet && \OGame\Services\ObjectService::objectRequirementsMet($step['name'], $planet)) {
                    $building = \OGame\Services\ObjectService::getObjectByMachineName($step['name']);
                    if ($building) {
                        $queueService = app(\OGame\Services\BuildingQueueService::class);
                        if (!$bot->isBuildingQueueFull($planet)) {
                            $queueService->add($planet, $building->id);
                            $bot->logAction(BotActionType::BUILD, "Planned build: {$step['name']} on {$planet->getPlanetName()}", []);
                            // Advance the plan
                            if (isset($planned['plan_id'])) {
                                (new BotStrategicPlannerService())->completeStep($planned['plan_id']);
                            }
                            cache()->forget("bot:{$botId}:planned_step");
                            return true;
                        }
                    }
                }
            } catch (\Exception $e) {
                logger()->warning("Bot {$botId}: planned build failed: {$e->getMessage()}");
            }
            cache()->forget("bot:{$botId}:planned_step");
        }

        return $bot->buildRandomStructure();
    }

    /**
     * Handle fleet action (build units, expedition, colonization, recycling).
     */
    private function handleFleetAction(BotService $bot, BotDecisionService $decisionService): bool
    {
        // First priority: recycle debris from previous attacks
        if ($bot->tryRecycleAfterAttack()) {
            return true;
        }

        if ($bot->tryRecycleNearbyDebris()) {
            return true;
        }

        if ($bot->shouldColonize() && $bot->sendColonization()) {
            return true;
        }

        if ($decisionService->shouldDoExpedition() && $bot->sendExpedition()) {
            return true;
        }

        // Check for planned unit step
        $botId = $bot->getBot()->id;
        $planned = cache()->get("bot:{$botId}:planned_step");
        if ($planned && ($planned['step']['type'] ?? '') === 'unit') {
            cache()->forget("bot:{$botId}:planned_step");
            // Build the planned unit
            $step = $planned['step'];
            try {
                $planet = $bot->getRichestPlanet();
                if ($planet && \OGame\Services\ObjectService::objectRequirementsMet($step['name'], $planet)) {
                    $unit = \OGame\Services\ObjectService::getObjectByMachineName($step['name']);
                    if ($unit) {
                        $amount = min($step['amount'] ?? 1, 100);
                        $queueService = app(\OGame\Services\UnitQueueService::class);
                        $queueService->add($planet, $unit->id, $amount);
                        $bot->logAction(BotActionType::FLEET, "Planned build: {$amount}x {$step['name']}", []);
                        if (isset($planned['plan_id'])) {
                            (new BotStrategicPlannerService())->completeStep($planned['plan_id']);
                        }
                        return true;
                    }
                }
            } catch (\Exception $e) {
                logger()->warning("Bot {$botId}: planned unit build failed: {$e->getMessage()}");
            }
        }

        return $bot->buildRandomUnit();
    }

    /**
     * Handle attack action with intelligence-based timing.
     */
    private function handleAttackAction(BotService $bot): bool
    {
        $botId = $bot->getBot()->id;
        $intel = new BotIntelligenceService();

        // Check if target is likely offline (best attack timing)
        try {
            $target = $bot->findTarget();
            if ($target) {
                $targetUserId = $target->getPlayer()->getId();
                if (!$intel->isGoodTimeToAttack($botId, $targetUserId)) {
                    $bot->logAction(BotActionType::ATTACK, 'Delaying attack: target likely online', [], 'failed');
                    return false;
                }
            }
        } catch (\Exception $e) {
            // Non-critical, proceed with attack
        }

        return $bot->sendAttackFleet();
    }

    /**
     * Handle proactive espionage action.
     */
    private function handleEspionageAction(BotService $bot): bool
    {
        $botId = $bot->getBot()->id;
        $intel = new BotIntelligenceService();

        $source = $bot->getRichestPlanet();
        if (!$source) {
            return false;
        }

        $probes = $source->getObjectAmount('espionage_probe');
        if ($probes < 2) {
            return false;
        }

        $coords = $source->getPlanetCoordinates();

        // Find planets that need scouting
        $targets = $intel->findPlanetsNeedingEspionage($botId, $coords->galaxy, $coords->system, 10);
        if (empty($targets)) {
            return false;
        }

        // Pick a random target to spy on
        $targetData = $targets[array_rand($targets)];
        $targetPlanet = \OGame\Models\Planet::find($targetData['id'] ?? 0);
        if (!$targetPlanet || $targetPlanet->user_id === $bot->getPlayer()->getId()) {
            return false;
        }

        try {
            $targetService = app(\OGame\Factories\PlanetServiceFactory::class)->make($targetPlanet->id);

            // Update activity pattern
            $targetUser = $targetService->getPlayer()->getUser();
            $isActive = ($targetUser->time ?? 0) > (now()->timestamp - 900); // Active in last 15 min
            $intel->updateActivityPattern($botId, $targetPlanet->user_id, $isActive);

            return $bot->sendEspionageProbe($targetService);
        } catch (\Exception $e) {
            logger()->warning("Bot {$botId}: proactive espionage failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Handle proactive defense building.
     */
    private function handleDefenseAction(BotService $bot): bool
    {
        try {
            $buildOnAll = config('bots.defense_all_planets', true);
            $planets = $buildOnAll ? $bot->getPlayer()->planets->all() : [$bot->getRichestPlanet()];
            $planets = array_filter($planets);

            if (empty($planets)) {
                return false;
            }

            $anyBuilt = false;
            $personality = $bot->getPersonality();
            $queueService = app(\OGame\Services\UnitQueueService::class);

            foreach ($planets as $planet) {
                if ($bot->isUnitQueueFull($planet)) {
                    continue;
                }

                $defenses = \OGame\Services\ObjectService::getDefenseObjects();
                $affordable = [];

                foreach ($defenses as $defense) {
                    if (!\OGame\Services\ObjectService::objectRequirementsMet($defense->machine_name, $planet)) {
                        continue;
                    }

                    $price = \OGame\Services\ObjectService::getObjectPrice($defense->machine_name, $planet);
                    $resources = $planet->getResources();

                    $maxAmount = min(
                        $price->metal->get() > 0 ? (int)($resources->metal->get() / $price->metal->get()) : 999,
                        $price->crystal->get() > 0 ? (int)($resources->crystal->get() / $price->crystal->get()) : 999,
                        $price->deuterium->get() > 0 ? (int)($resources->deuterium->get() / $price->deuterium->get()) : 999,
                        50
                    );

                    if ($maxAmount < 1) {
                        continue;
                    }

                    $affordable[] = ['unit' => $defense, 'amount' => $maxAmount];
                }

                if (empty($affordable)) {
                    continue;
                }

                $selected = $affordable[array_rand($affordable)];

                if (in_array($personality, [\OGame\Enums\BotPersonality::TURTLE, \OGame\Enums\BotPersonality::DEFENSIVE])) {
                    usort($affordable, fn($a, $b) => $b['amount'] <=> $a['amount']);
                    $selected = $affordable[0];
                }

                $amount = min($selected['amount'], rand(5, 30));
                $queueService->add($planet, $selected['unit']->id, $amount);

                $bot->logAction(BotActionType::DEFENSE, "Built {$amount}x {$selected['unit']->machine_name} on {$planet->getPlanetName()}", []);
                $anyBuilt = true;

                // Only build on one planet per tick unless turtle/defensive
                if (!in_array($personality, [\OGame\Enums\BotPersonality::TURTLE, \OGame\Enums\BotPersonality::DEFENSIVE])) {
                    break;
                }
            }

            return $anyBuilt;
        } catch (\Exception $e) {
            logger()->warning("Bot {$bot->getBot()->id}: defense build failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Handle diplomacy actions (alliance management, messaging etc).
     */
    private function handleDiplomacyAction(BotService $bot): bool
    {
        try {
            $botModel = $bot->getBot();
            $user = $bot->getPlayer()->getUser();

            // Sync alliance allies to threat map
            if ($user->alliance_id) {
                $intel = new BotIntelligenceService();
                $intel->syncAllianceAllies($botModel->id, $user->alliance_id);
                $bot->logAction(BotActionType::DIPLOMACY, 'Synced alliance allies to threat map', []);
                return true;
            }

            // If not in alliance, try to join one
            $bot->ensureAlliance();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Handle threat response with full defensive protocol.
     */
    private function handleThreatResponse(BotService $bot): void
    {
        $bot->recallRiskyMissions();

        if ($bot->tryJumpGateEvacuation()) {
            $this->line('  - Defensive: jump gate evacuation');
        }

        if ($bot->performFleetSave()) {
            $this->line('  - Defensive: fleet save initiated');
        }

        // Also try to build defenses while under threat
        $this->handleDefenseAction($bot);

        $bot->getBot()->updateLastAction();
        $this->line('  - Defensive: threat response complete');
    }

    /**
     * Ensure bot has traits initialized.
     */
    private function ensureTraits(Bot $botModel): void
    {
        if (empty($botModel->traits)) {
            $personality = $botModel->getPersonalityEnum();
            $botModel->traits = $personality->getDefaultTraits();
            $botModel->risk_tolerance = $personality->getDefaultRiskTolerance();
            $botModel->save();
        }
    }

    /**
     * Update queues/resources so bots don't get stuck with stale state.
     */
    private function refreshBotQueues(BotService $bot): void
    {
        $player = $bot->getPlayer();

        try {
            $player->updateResearchQueue(true);
        } catch (\Exception $e) {
            $this->error("  - Research queue update failed: {$e->getMessage()}");
        }

        try {
            $player->updateFleetMissions();
        } catch (\Exception $e) {
            $this->error("  - Fleet missions update failed: {$e->getMessage()}");
        }

        foreach ($player->planets->all() as $planet) {
            try {
                $planet->updateBuildingQueue(true);
                $planet->updateUnitQueue(true);
                $planet->updateResources(true);
            } catch (\Exception $e) {
                $this->error("  - Planet queue update failed (planet {$planet->getPlanetId()}): {$e->getMessage()}");
            }
        }
    }
}
