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
        Schema::create('command_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // bewaard zodat het logboek leesbaar blijft als de site later verwijderd is
            $table->string('site_name')->nullable();
            $table->string('action');
            $table->string('label');
            $table->json('arguments');
            $table->string('status')->default('queued')->index();
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('command_runs');
    }
};
