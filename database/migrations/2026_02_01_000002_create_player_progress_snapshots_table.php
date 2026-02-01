<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('player_progress_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->boolean('is_bot')->default(false);
            $table->bigInteger('general')->default(0);
            $table->bigInteger('economy')->default(0);
            $table->bigInteger('research')->default(0);
            $table->bigInteger('military')->default(0);
            $table->bigInteger('wars')->default(0);
            $table->timestamp('sampled_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'sampled_at']);
            $table->index(['sampled_at']);
            $table->index(['is_bot']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_progress_snapshots');
    }
};
