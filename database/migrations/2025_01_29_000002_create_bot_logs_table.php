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
        Schema::create('bot_logs', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement()->primary();
            $table->unsignedInteger('bot_id');
            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');
            $table->enum('action_type', ['build', 'research', 'fleet', 'attack', 'trade']);
            $table->text('action_description');
            $table->json('resources_spended')->nullable();
            $table->enum('result', ['success', 'failed', 'partial'])->default('success');
            $table->timestamps();

            $table->index('bot_id');
            $table->index('action_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_logs');
    }
};
