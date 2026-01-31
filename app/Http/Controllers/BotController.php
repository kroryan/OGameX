<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Enums\BotActionType;
use OGame\Models\Bot;
use OGame\Models\BotLog;
use OGame\Services\PlayerService;

class BotController extends OGameController
{
    /**
     * Show bot logs for the current player.
     */
    public function logs(Request $request, PlayerService $player): View
    {
        $bot = Bot::where('user_id', $player->getId())->first();
        $currentFilter = (string)$request->input('action_type', '');

        if (!$bot) {
            return view('ingame.bots.logs', [
                'bot' => null,
                'logs' => collect(),
                'actionTypes' => BotActionType::cases(),
                'currentFilter' => $currentFilter,
            ]);
        }

        $query = BotLog::where('bot_id', $bot->id)->latest();
        if ($currentFilter !== '') {
            $query->where('action_type', $currentFilter);
        }

        $logs = $query->paginate(50);

        return view('ingame.bots.logs', [
            'bot' => $bot,
            'logs' => $logs,
            'actionTypes' => BotActionType::cases(),
            'currentFilter' => $currentFilter,
        ]);
    }
}
