<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Enums\BotActionType;
use OGame\Enums\BotPersonality;
use OGame\Enums\BotTargetType;
use OGame\Factories\BotServiceFactory;
use OGame\Http\Controllers\OGameController;
use OGame\Models\Bot;
use OGame\Models\BotLog;
use OGame\Models\User;
use OGame\Services\BotDecisionService;
use OGame\Services\BotService;
use OGame\Services\PlayerService;

/**
 * BotManagementController - Admin panel for managing playerbots.
 */
class BotManagementController extends OGameController
{
    public function __construct(
        private BotServiceFactory $botFactory
    ) {}

    /**
     * Display list of all bots.
     */
    public function index(): View
    {
        $bots = Bot::with('user', 'logs')->latest()->get();

        // Get statistics
        $stats = [
            'total' => $bots->count(),
            'active' => $bots->where('is_active', true)->count(),
            'inactive' => $bots->where('is_active', false)->count(),
            'total_actions' => BotLog::count(),
        ];

        return view('ingame.admin.bots', [
            'bots' => $bots,
            'stats' => $stats,
            'personalities' => BotPersonality::cases(),
            'targetTypes' => BotTargetType::cases(),
        ]);
    }

    /**
     * Show form to create a new bot.
     */
    public function create(): View
    {
        // Get available users that are not bots
        $botUserIds = Bot::pluck('user_id')->toArray();
        $availableUsers = User::whereNotIn('id', $botUserIds)
            ->where('username', '!=', 'Legor')
            ->orderBy('username')
            ->get();

        return view('ingame.admin.bots-create', [
            'personalities' => BotPersonality::cases(),
            'targetTypes' => BotTargetType::cases(),
            'availableUsers' => $availableUsers,
        ]);
    }

    /**
     * Store a new bot.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:bots,user_id',
            'name' => 'required|string|max:255',
            'personality' => 'required|in:aggressive,defensive,economic,balanced',
            'priority_target_type' => 'required|in:random,weak,rich,similar',
            'max_fleets_sent' => 'nullable|integer|min:1|max:10',
        ]);

        $bot = Bot::create([
            'user_id' => $validated['user_id'],
            'name' => $validated['name'],
            'personality' => $validated['personality'],
            'priority_target_type' => $validated['priority_target_type'],
            'max_fleets_sent' => $validated['max_fleets_sent'] ?? 3,
            'is_active' => true,
        ]);

        return redirect()
            ->route('admin.bots.index')
            ->with('success', "Bot '{$bot->name}' created successfully!");
    }

    /**
     * Show form to edit a bot.
     */
    public function edit(int $botId): View
    {
        $bot = Bot::findOrFail($botId);

        return view('ingame.admin.bots-edit', [
            'bot' => $bot,
            'personalities' => BotPersonality::cases(),
            'targetTypes' => BotTargetType::cases(),
        ]);
    }

    /**
     * Update a bot.
     */
    public function update(Request $request, int $botId): RedirectResponse
    {
        $bot = Bot::findOrFail($botId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'personality' => 'required|in:aggressive,defensive,economic,balanced',
            'priority_target_type' => 'required|in:random,weak,rich,similar',
            'max_fleets_sent' => 'nullable|integer|min:1|max:10',
            'activity_schedule' => 'nullable|json',
            'action_probabilities' => 'nullable|json',
            'economy_settings' => 'nullable|json',
            'fleet_settings' => 'nullable|json',
            'behavior_flags' => 'nullable|json',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'personality' => $validated['personality'],
            'priority_target_type' => $validated['priority_target_type'],
            'max_fleets_sent' => $validated['max_fleets_sent'] ?? 3,
        ];

        // Add JSON fields if provided and not empty
        if (!empty($validated['activity_schedule'])) {
            $updateData['activity_schedule'] = json_decode($validated['activity_schedule'], true);
        }
        if (!empty($validated['action_probabilities'])) {
            $updateData['action_probabilities'] = json_decode($validated['action_probabilities'], true);
        }
        if (!empty($validated['economy_settings'])) {
            $updateData['economy_settings'] = json_decode($validated['economy_settings'], true);
        }
        if (!empty($validated['fleet_settings'])) {
            $updateData['fleet_settings'] = json_decode($validated['fleet_settings'], true);
        }
        if (!empty($validated['behavior_flags'])) {
            $updateData['behavior_flags'] = json_decode($validated['behavior_flags'], true);
        }

        $bot->update($updateData);

        return redirect()
            ->route('admin.bots.index')
            ->with('success', "Bot '{$bot->name}' updated successfully!");
    }

    /**
     * Delete a bot.
     */
    public function delete(int $botId): RedirectResponse
    {
        $bot = Bot::findOrFail($botId);
        $name = $bot->name;
        $bot->delete();

        return redirect()
            ->route('admin.bots.index')
            ->with('success', "Bot '{$name}' deleted successfully!");
    }

    /**
     * Toggle bot active status.
     */
    public function toggle(int $botId): RedirectResponse
    {
        $bot = Bot::findOrFail($botId);
        $bot->is_active = !$bot->is_active;
        $bot->save();

        $status = $bot->is_active ? 'activated' : 'deactivated';

        return redirect()
            ->route('admin.bots.index')
            ->with('success', "Bot '{$bot->name}' {$status}!");
    }

    /**
     * Show bot activity logs.
     */
    public function logs(int $botId, Request $request): View
    {
        $bot = Bot::findOrFail($botId);

        $query = $bot->logs()->latest();

        // Filter by action type if specified
        if ($request->has('action_type')) {
            $query->where('action_type', $request->input('action_type'));
        }

        $logs = $query->paginate(50);

        return view('ingame.admin.bots-logs', [
            'bot' => $bot,
            'logs' => $logs,
            'actionTypes' => BotActionType::cases(),
            'currentFilter' => $request->input('action_type'),
        ]);
    }

    /**
     * Force bot to execute a specific action.
     */
    public function forceAction(Request $request, int $botId): RedirectResponse
    {
        $bot = Bot::findOrFail($botId);
        $botService = $this->botFactory->makeFromBotModel($bot);

        $action = $request->input('action');
        $success = false;

        switch ($action) {
            case 'build':
                $success = $botService->buildRandomStructure();
                break;
            case 'fleet':
                $success = $botService->buildRandomUnit();
                break;
            case 'research':
                $success = $botService->researchRandomTech();
                break;
            case 'attack':
                $success = $botService->sendAttackFleet();
                break;
            case 'expedition':
                $success = $botService->sendExpedition();
                break;
        }

        if ($success) {
            return redirect()
                ->back()
                ->with('success', "Bot '{$bot->name}' executed {$action} successfully!");
        }

        return redirect()
            ->back()
            ->with('error', "Bot '{$bot->name}' failed to execute {$action}.");
    }

    /**
     * Show bot statistics.
     */
    public function stats(): View
    {
        $bots = Bot::with('user')->get();

        // Calculate statistics
        $stats = [
            'total' => $bots->count(),
            'active' => $bots->where('is_active', true)->count(),
            'by_personality' => [
                'aggressive' => $bots->where('personality', 'aggressive')->count(),
                'defensive' => $bots->where('personality', 'defensive')->count(),
                'economic' => $bots->where('personality', 'economic')->count(),
                'balanced' => $bots->where('personality', 'balanced')->count(),
            ],
            'by_target_type' => [
                'random' => $bots->where('priority_target_type', 'random')->count(),
                'weak' => $bots->where('priority_target_type', 'weak')->count(),
                'rich' => $bots->where('priority_target_type', 'rich')->count(),
                'similar' => $bots->where('priority_target_type', 'similar')->count(),
            ],
        ];

        // Recent activity
        $recentLogs = BotLog::with('bot')
            ->latest()
            ->limit(100)
            ->get();

        // Action breakdown
        $actionBreakdown = BotLog::select('action_type')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('action_type')
            ->pluck('count', 'action_type')
            ->toArray();

        return view('ingame.admin.bots-stats', [
            'stats' => $stats,
            'recentLogs' => $recentLogs,
            'actionBreakdown' => $actionBreakdown,
            'bots' => $bots,
        ]);
    }
}
