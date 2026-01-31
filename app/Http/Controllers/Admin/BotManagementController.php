<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use OGame\Enums\BotActionType;
use OGame\Enums\BotPersonality;
use OGame\Enums\BotTargetType;
use OGame\Factories\BotServiceFactory;
use OGame\Http\Controllers\OGameController;
use OGame\Jobs\BulkCreateBots;
use OGame\Models\Bot;
use OGame\Models\BotLog;
use OGame\Models\User;

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
     * Bulk create bot accounts and assign them to new bots.
     */
    public function bulkStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'count' => 'required|integer|min:1|max:300',
            'password' => ['nullable', 'string', Password::default()],
            'email_prefix' => 'nullable|string|max:50',
            'email_domain' => 'required|string|max:100',
            'bot_name_prefix' => 'nullable|string|max:50',
            'personality' => 'required|in:aggressive,defensive,economic,balanced,random',
            'priority_target_type' => 'required|in:random,weak,rich,similar,random_choice',
            'max_fleets_sent' => 'nullable|integer|min:1|max:10',
            'is_active' => 'nullable|boolean',
        ]);

        $count = (int) $validated['count'];
        $emailPrefix = $this->sanitizeEmailLocalPart($validated['email_prefix'] ?? 'bot');
        $emailDomain = strtolower(trim($validated['email_domain']));
        $botNamePrefix = trim($validated['bot_name_prefix'] ?? 'Bot');
        $maxFleets = $validated['max_fleets_sent'] ?? 3;
        $isActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;

        if ($emailPrefix === '') {
            $emailPrefix = 'bot';
        }

        $batchToken = now()->format('ymdHis') . Str::lower(Str::random(4));

        BulkCreateBots::dispatch([
            'count' => $count,
            'password' => $validated['password'],
            'email_prefix' => $emailPrefix,
            'email_domain' => $emailDomain,
            'bot_name_prefix' => $botNamePrefix,
            'personality' => $validated['personality'],
            'priority_target_type' => $validated['priority_target_type'],
            'max_fleets_sent' => $maxFleets,
            'is_active' => $isActive,
            'batch_token' => $batchToken,
        ]);

        return redirect()
            ->route('admin.bots.index')
            ->with('success', "Bulk creation queued: {$count} bot account(s).");
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
            // Activity schedule
            'active_hours' => 'nullable|array',
            'active_hours.*' => 'integer|min:0|max:23',
            'inactive_days' => 'nullable|array',
            'inactive_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            // Action probabilities
            'prob_build' => 'nullable|integer|min:0|max:100',
            'prob_fleet' => 'nullable|integer|min:0|max:100',
            'prob_attack' => 'nullable|integer|min:0|max:100',
            'prob_research' => 'nullable|integer|min:0|max:100',
            // Economy settings
            'economy_save_percent' => 'nullable|numeric|min:0|max:1',
            'economy_min_resources' => 'nullable|integer|min:0',
            'economy_max_storage' => 'nullable|numeric|min:0|max:1',
            'economy_prioritize' => 'nullable|string|in:balanced,metal,crystal,deuterium',
            // Fleet settings
            'fleet_attack_percent' => 'nullable|numeric|min:0|max:1',
            'fleet_expedition_percent' => 'nullable|numeric|min:0|max:1',
            'fleet_min_size' => 'nullable|integer|min:0',
            'fleet_prefer_fast' => 'nullable|boolean',
            'fleet_recyclers' => 'nullable|boolean',
            // Behavior flags
            'disabled_actions' => 'nullable|array',
            'disabled_actions.*' => 'string|in:trade,expedition',
            'avoid_stronger' => 'nullable|boolean',
            'max_planets' => 'nullable|integer|min:1|max:15',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'personality' => $validated['personality'],
            'priority_target_type' => $validated['priority_target_type'],
            'max_fleets_sent' => $validated['max_fleets_sent'] ?? 3,
        ];

        // Build activity schedule
        $activitySchedule = [];
        if (isset($validated['active_hours']) && !empty($validated['active_hours'])) {
            $activitySchedule['active_hours'] = array_map('intval', $validated['active_hours']);
        }
        if (isset($validated['inactive_days']) && !empty($validated['inactive_days'])) {
            $activitySchedule['inactive_days'] = $validated['inactive_days'];
        }
        if (!empty($activitySchedule)) {
            $updateData['activity_schedule'] = $activitySchedule;
        }

        // Build action probabilities
        $actionProbs = [];
        if (isset($validated['prob_build']) && $validated['prob_build'] > 0) {
            $actionProbs['build'] = (int)$validated['prob_build'];
        }
        if (isset($validated['prob_fleet']) && $validated['prob_fleet'] > 0) {
            $actionProbs['fleet'] = (int)$validated['prob_fleet'];
        }
        if (isset($validated['prob_attack']) && $validated['prob_attack'] > 0) {
            $actionProbs['attack'] = (int)$validated['prob_attack'];
        }
        if (isset($validated['prob_research']) && $validated['prob_research'] > 0) {
            $actionProbs['research'] = (int)$validated['prob_research'];
        }
        if (!empty($actionProbs)) {
            $updateData['action_probabilities'] = $actionProbs;
        }

        // Build economy settings
        $economySettings = [];
        if (isset($validated['economy_save_percent']) && $validated['economy_save_percent'] !== '') {
            $economySettings['save_for_upgrade_percent'] = (float)$validated['economy_save_percent'];
        }
        if (isset($validated['economy_min_resources']) && $validated['economy_min_resources'] !== '') {
            $economySettings['min_resources_for_actions'] = (int)$validated['economy_min_resources'];
        }
        if (isset($validated['economy_max_storage']) && $validated['economy_max_storage'] !== '') {
            $economySettings['max_storage_before_spending'] = (float)$validated['economy_max_storage'];
        }
        if (isset($validated['economy_prioritize']) && $validated['economy_prioritize'] !== '') {
            $economySettings['prioritize_production'] = $validated['economy_prioritize'];
        }
        if (!empty($economySettings)) {
            $updateData['economy_settings'] = $economySettings;
        }

        // Build fleet settings
        $fleetSettings = [];
        if (isset($validated['fleet_attack_percent']) && $validated['fleet_attack_percent'] !== '') {
            $fleetSettings['attack_fleet_percentage'] = (float)$validated['fleet_attack_percent'];
        }
        if (isset($validated['fleet_expedition_percent']) && $validated['fleet_expedition_percent'] !== '') {
            $fleetSettings['expedition_fleet_percentage'] = (float)$validated['fleet_expedition_percent'];
        }
        if (isset($validated['fleet_min_size']) && $validated['fleet_min_size'] !== '') {
            $fleetSettings['min_fleet_size_for_attack'] = (int)$validated['fleet_min_size'];
        }
        if (isset($validated['fleet_prefer_fast']) && $validated['fleet_prefer_fast'] !== null) {
            $fleetSettings['prefer_fast_ships'] = true;
        }
        if (isset($validated['fleet_recyclers']) && $validated['fleet_recyclers'] !== null) {
            $fleetSettings['always_include_recyclers'] = true;
        }
        if (!empty($fleetSettings)) {
            $updateData['fleet_settings'] = $fleetSettings;
        }

        // Build behavior flags
        $behaviorFlags = [];
        if (isset($validated['disabled_actions']) && !empty($validated['disabled_actions'])) {
            $behaviorFlags['disabled_actions'] = $validated['disabled_actions'];
        }
        if (isset($validated['avoid_stronger']) && $validated['avoid_stronger'] !== null) {
            $behaviorFlags['avoid_stronger_players'] = true;
        }
        if (isset($validated['max_planets']) && $validated['max_planets'] !== '') {
            $behaviorFlags['max_planets_to_colonize'] = (int)$validated['max_planets'];
        }
        if (!empty($behaviorFlags)) {
            $updateData['behavior_flags'] = $behaviorFlags;
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
     * Show logs for all bots.
     */
    public function logsAll(Request $request): View
    {
        $query = BotLog::with('bot')->latest();

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->input('action_type'));
        }

        if ($request->filled('bot_id')) {
            $query->where('bot_id', (int) $request->input('bot_id'));
        }

        $logs = $query->paginate(100);
        $bots = Bot::orderBy('name')->get();

        return view('ingame.admin.bots-logs-all', [
            'logs' => $logs,
            'bots' => $bots,
            'actionTypes' => BotActionType::cases(),
            'currentFilter' => $request->input('action_type'),
            'currentBot' => $request->input('bot_id'),
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

    private function sanitizeEmailLocalPart(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9._+-]+/', '', $value) ?? '';
    }
}
