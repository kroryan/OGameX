<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use OGame\Models\BotLog;

/**
 * PruneBotLogs - Removes old bot log entries to prevent unbounded table growth.
 */
class PruneBotLogs extends Command
{
    protected $signature = 'ogamex:scheduler:prune-bot-logs';

    protected $description = 'Prune old bot log entries beyond the configured retention period';

    public function handle(): int
    {
        $retentionDays = (int) config('bots.bot_logs_retention_days', 14);
        if ($retentionDays <= 0) {
            $this->info('Bot log pruning is disabled (retention_days <= 0).');
            return 0;
        }

        $cutoff = now()->subDays($retentionDays);
        $deleted = BotLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} bot log entries older than {$retentionDays} days.");

        return 0;
    }
}
