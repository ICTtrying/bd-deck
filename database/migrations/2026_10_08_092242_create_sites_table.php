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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            // naam is de sleutel in de sitelijst van wpopen, de bron van waarheid voor verbindingsgegevens
            $table->string('name')->unique();
            $table->boolean('is_favorite')->default(false);
            $table->text('notes')->nullable();
            $table->string('live_login_user')->nullable();
            $table->json('snapshot')->nullable();
            $table->json('health')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->json('updates')->nullable();
            $table->timestamp('updates_checked_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
