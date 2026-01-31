<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use OGame\Enums\BotActionType;
use OGame\Models\Bot;
use OGame\Services\BotDecisionService;
use OGame\Services\BotService;

/**
 * ProcessBots - Scheduler command that processes active playerbots.
 */
class ProcessBots extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ogamex:scheduler:process-bots';

    /**
     * The console command description.
     */
    protected $description = 'Process active playerbots and execute their actions';

    /**
     * Execute the console command.
     */
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
            $this->info('Total active bots: ' . $totalActive);
            return 0;
        }

        $this->info("Processing " . count($bots) . " active bot(s) (batch size {$batchSize}, offset {$offset}, total {$totalActive})...");

        $successCount = 0;
        $failCount = 0;

        foreach ($bots as $botService) {
            try {
                $this->processBot($botService);
                $successCount++;
            } catch (\Exception $e) {
                $this->error("Error processing bot {$botService->getBot()->name}: {$e->getMessage()}");
                $this->error("Stack trace: " . $e->getTraceAsString());
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
     * Process a single bot.
     */
    private function processBot(BotService $bot): void
    {
        $botModel = $bot->getBot();
        $this->line("Processing bot: {$botModel->name}");

        if (!$bot->isActive()) {
            $this->line('  - Skipped: inactive schedule');
            return;
        }

        if ($bot->shouldFleetSaveBySchedule() && $bot->performFleetSave()) {
            $this->line('  - Scheduled fleet save executed');
            $bot->getBot()->updateLastAction();
            return;
        }

        // Check if bot has been recently processed
        $lastAction = $botModel->last_action_at;
        $interval = config('bots.scheduler_interval_minutes', 5);

        if ($lastAction && $lastAction->diffInMinutes(now()) < $interval) {
            $this->line("  - Skipped: last action was {$lastAction->diffInMinutes(now())} minutes ago (min: {$interval})");
            return;
        }

        $this->refreshBotQueues($bot);

        if (config('bots.allow_alliances', true)) {
            $bot->ensureAlliance();
        }

        if ($bot->isUnderThreat()) {
            if ($bot->performFleetSave()) {
                $this->line('  - Defensive action: fleet save initiated');
                $bot->getBot()->updateLastAction();
                return;
            }
        }

        // Decide next action
        $decisionService = new BotDecisionService($bot);
        $action = $decisionService->decideNextAction();
        if ($action === null) {
            $this->line('  - Skipped: no viable actions available');
            $bot->logAction(BotActionType::BUILD, 'No viable actions available', [], 'failed');
            $bot->getBot()->updateLastAction();
            return;
        }

        $this->line("  - Action: {$action->value}");

        // Execute the action
        $success = match ($action) {
            BotActionType::BUILD => $bot->buildRandomStructure(),
            BotActionType::RESEARCH => $bot->researchRandomTech(),
            BotActionType::FLEET => $this->handleFleetAction($bot),
            BotActionType::ATTACK => $bot->sendAttackFleet(),
            BotActionType::TRADE => $this->handleTradeAction($bot),
        };

        $botModel->updateLastAction();

        if ($success) {
            $this->line("  - Result: Success");
        } else {
            $this->line("  - Result: Failed");
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

    /**
     * Handle fleet action (build units or send expedition).
     */
    private function handleFleetAction(BotService $bot): bool
    {
        if ($bot->tryRecycleNearbyDebris()) {
            return true;
        }

        if ($bot->shouldColonize()) {
            if ($bot->sendColonization()) {
                return true;
            }
        }

        // 15% chance to send expedition instead of building fleet
        if (config('bots.expedition_chance', 0.15) * 100 >= mt_rand(1, 100)) {
            return $bot->sendExpedition();
        }

        return $bot->buildRandomUnit();
    }

    /**
     * Handle trade action (transport resources between planets).
     */
    private function handleTradeAction(BotService $bot): bool
    {
        return $bot->sendResourceTransport();
    }
}
