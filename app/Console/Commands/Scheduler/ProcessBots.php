<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use OGame\Enums\BotActionType;
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
        $allActiveBots = $botFactory->getActiveBots();
        $bots = $botFactory->getActiveBotServices();

        if (empty($bots)) {
            $this->info('No bots in active schedule window to process.');
            $this->info('Total active bots: ' . $allActiveBots->count());
            return 0;
        }

        $this->info("Processing " . count($bots) . " active bot(s)...");

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

        // Check if bot has been recently processed
        $lastAction = $botModel->last_action_at;
        $interval = config('bots.scheduler_interval_minutes', 5);

        if ($lastAction && $lastAction->diffInMinutes(now()) < $interval) {
            $this->line("  - Skipped: last action was {$lastAction->diffInMinutes(now())} minutes ago (min: {$interval})");
            return;
        }

        // Decide next action
        $decisionService = new BotDecisionService($bot);
        $action = $decisionService->decideNextAction();
        if ($action === null) {
            $this->line('  - Skipped: no viable actions available');
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

        if ($success) {
            $this->line("  - Result: Success");
        } else {
            $this->line("  - Result: Failed");
        }
    }

    /**
     * Handle fleet action (build units or send expedition).
     */
    private function handleFleetAction(BotService $bot): bool
    {
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
        // For now, just return false as trade is not implemented
        // Could be expanded to transport resources between bot's planets
        return false;
    }
}
