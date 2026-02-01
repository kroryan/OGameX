<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OGame\Models\BattleReport;
use OGame\Models\Bot;
use OGame\Models\BotBattleHistory;
use OGame\Models\PlayerProgressSnapshot;
use OGame\Models\User;

class SnapshotPlayerProgress extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ogamex:scheduler:snapshot-player-progress {--minutes=30}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Snapshots player progress for charts';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $bucketMinutes = max(1, (int) $this->option('minutes'));
        $sampledAt = $this->getBucketTime($bucketMinutes);

        $this->info('Snapshotting player progress...');

        $botIdByUserId = Bot::query()->pluck('id', 'user_id')->toArray();

        $botBattleCounts = BotBattleHistory::query()
            ->select('bot_id', DB::raw('COUNT(*) as count'))
            ->groupBy('bot_id')
            ->pluck('count', 'bot_id')
            ->toArray();

        $battleReportCounts = BattleReport::query()
            ->select('planet_user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('planet_user_id')
            ->pluck('count', 'planet_user_id')
            ->toArray();

        $now = now();

        User::query()
            ->whereHas('tech')
            ->leftJoin('highscores', 'highscores.player_id', '=', 'users.id')
            ->select([
                'users.id as user_id',
                'highscores.general',
                'highscores.economy',
                'highscores.research',
                'highscores.military',
            ])
            ->chunk(500, function ($users) use ($botIdByUserId, $botBattleCounts, $battleReportCounts, $sampledAt, $now) {
                $rows = [];

                foreach ($users as $user) {
                    $userId = (int) $user->user_id;
                    $botId = $botIdByUserId[$userId] ?? null;
                    $isBot = $botId !== null;

                    $warsCount = $isBot
                        ? (int) ($botBattleCounts[$botId] ?? 0)
                        : (int) ($battleReportCounts[$userId] ?? 0);

                    $rows[] = [
                        'user_id' => $userId,
                        'is_bot' => $isBot,
                        'general' => (int) ($user->general ?? 0),
                        'economy' => (int) ($user->economy ?? 0),
                        'research' => (int) ($user->research ?? 0),
                        'military' => (int) ($user->military ?? 0),
                        'wars' => $warsCount,
                        'sampled_at' => $sampledAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (empty($rows)) {
                    return;
                }

                PlayerProgressSnapshot::upsert(
                    $rows,
                    ['user_id', 'sampled_at'],
                    ['is_bot', 'general', 'economy', 'research', 'military', 'wars', 'updated_at']
                );
            });

        $this->info('Player progress snapshot complete.');
    }

    private function getBucketTime(int $bucketMinutes): \Illuminate\Support\Carbon
    {
        $now = now();
        $minute = (int) $now->format('i');
        $bucket = (int) floor($minute / $bucketMinutes) * $bucketMinutes;

        return $now->copy()->minute($bucket)->second(0);
    }
}
