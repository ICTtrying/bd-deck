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
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('uptime_enabled')->default(true);
            // up of down; leeg zolang er nog niets gemeten is
            $table->string('uptime_status')->nullable();
            // een enkele misser is nog geen storing: pas bij meerdere op rij gaat de status op down
            $table->unsignedSmallInteger('uptime_failures')->default(0);
            $table->timestamp('uptime_status_since')->nullable();
            $table->timestamp('uptime_checked_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->boolean('is_up');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('checked_at');

            $table->index(['site_id', 'checked_at']);
        });

        Schema::create('uptime_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['site_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uptime_incidents');
        Schema::dropIfExists('uptime_checks');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'uptime_enabled', 'uptime_status', 'uptime_failures', 'uptime_status_since',
                'uptime_checked_at', 'ssl_expires_at', 'ssl_checked_at',
            ]);
        });
    }
};
