<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Models\PlayerProgressSnapshot;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

class BotProgressController extends OGameController
{
    public function index(PlayerService $player, SettingsService $settingsService): View
    {
        $this->setBodyId('bots-progress');

        if (!$player->isAdmin() && !$settingsService->botProgressPublicVisible()) {
            abort(403);
        }

        return view('ingame.bots-progress')->with([
            'progress_public_enabled' => $settingsService->botProgressPublicVisible(),
        ]);
    }

    public function data(Request $request, PlayerService $player, SettingsService $settingsService): JsonResponse
    {
        if (!$player->isAdmin() && !$settingsService->botProgressPublicVisible()) {
            abort(403);
        }

        $metric = (string) $request->input('metric', 'general');
        $allowed = ['general', 'economy', 'research', 'military', 'wars'];
        if (!in_array($metric, $allowed, true)) {
            $metric = 'general';
        }

        $range = (string) $request->input('range', '7d');
        $end = now();
        $start = match ($range) {
            '24h' => $end->copy()->subHours(24),
            '30d' => $end->copy()->subDays(30),
            default => $end->copy()->subDays(7),
        };

        $snapshots = PlayerProgressSnapshot::query()
            ->select([
                'player_progress_snapshots.user_id',
                'player_progress_snapshots.is_bot',
                'player_progress_snapshots.sampled_at',
                "player_progress_snapshots.{$metric}",
                'users.username',
                'bots.name as bot_name',
            ])
            ->join('users', 'users.id', '=', 'player_progress_snapshots.user_id')
            ->leftJoin('bots', 'bots.user_id', '=', 'users.id')
            ->whereBetween('player_progress_snapshots.sampled_at', [$start, $end])
            ->orderBy('player_progress_snapshots.sampled_at')
            ->get();

        $labels = $snapshots
            ->pluck('sampled_at')
            ->unique()
            ->values();

        $labelKeys = [];
        foreach ($labels as $index => $label) {
            $labelKeys[$label->timestamp] = $index;
        }

        $datasets = [];
        foreach ($snapshots as $row) {
            $userId = (int) $row->user_id;
            if (!isset($datasets[$userId])) {
                $displayName = $row->bot_name ?: $row->username;
                $datasets[$userId] = [
                    'label' => $displayName,
                    'is_bot' => (bool) $row->is_bot,
                    'data' => array_fill(0, $labels->count(), null),
                ];
            }

            $index = $labelKeys[$row->sampled_at->timestamp] ?? null;
            if ($index !== null) {
                $datasets[$userId]['data'][$index] = (int) $row->{$metric};
            }
        }

        $formattedLabels = $labels->map(fn ($label) => $label->format('m/d H:i'))->toArray();

        return response()->json([
            'labels' => $formattedLabels,
            'datasets' => array_values($datasets),
        ]);
    }
}
