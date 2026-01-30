<?php

namespace OGame\Factories;

use OGame\Models\Bot;
use OGame\Services\BotService;
use OGame\Services\PlayerService;

/**
 * Factory for creating BotService instances.
 */
class BotServiceFactory
{
    /**
     * Create BotService from bot ID.
     */
    public function makeFromBotId(int $botId): BotService
    {
        $bot = Bot::findOrFail($botId);
        return $this->makeFromBotModel($bot);
    }

    /**
     * Create BotService from user ID.
     */
    public function makeFromUserId(int $userId): BotService
    {
        $bot = Bot::where('user_id', $userId)->firstOrFail();
        return $this->makeFromBotModel($bot);
    }

    /**
     * Create BotService from Bot model.
     */
    public function makeFromBotModel(Bot $bot): BotService
    {
        // Create PlayerServiceFactory instance directly
        $playerServiceFactory = new PlayerServiceFactory();
        $player = $playerServiceFactory->make($bot->user_id);
        return new BotService($bot, $player);
    }

    /**
     * Get all active bots.
     */
    public function getActiveBots(): \Illuminate\Database\Eloquent\Collection
    {
        return Bot::where('is_active', true)->get();
    }

    /**
     * Get all bots as BotService instances.
     */
    public function getAllBotServices(): array
    {
        $bots = Bot::all();
        $services = [];

        foreach ($bots as $bot) {
            $services[] = $this->makeFromBotModel($bot);
        }

        return $services;
    }

    /**
     * Get active bots as BotService instances.
     */
    public function getActiveBotServices(): array
    {
        $bots = $this->getActiveBots();
        $services = [];

        foreach ($bots as $bot) {
            $services[] = $this->makeFromBotModel($bot);
        }

        return $services;
    }
}
