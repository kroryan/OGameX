<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Add new configuration columns if they don't exist
            if (!Schema::hasColumn('bots', 'activity_schedule')) {
                $table->json('activity_schedule')->nullable();
            }
            if (!Schema::hasColumn('bots', 'action_probabilities')) {
                $table->json('action_probabilities')->nullable();
            }
            if (!Schema::hasColumn('bots', 'economy_settings')) {
                $table->json('economy_settings')->nullable();
            }
            if (!Schema::hasColumn('bots', 'fleet_settings')) {
                $table->json('fleet_settings')->nullable();
            }
            if (!Schema::hasColumn('bots', 'behavior_flags')) {
                $table->json('behavior_flags')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Rollback is not supported for this migration due to data loss
            // To rollback, manually drop columns:
            // $table->dropColumn('activity_schedule');
            // $table->dropColumn('action_probabilities');
            // etc.
        });
    }
};
