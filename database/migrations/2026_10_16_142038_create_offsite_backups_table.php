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
        Schema::create('offsite_backups', function (Blueprint $table) {
            $table->id();
            // slug in plaats van site_id: back-ups horen bij de map van wpopen en overleven het ontkoppelen van een site
            $table->string('site_slug');
            $table->string('site_name');
            $table->string('backup_id');
            $table->string('kind')->nullable();
            $table->timestamp('backup_created_at')->nullable();
            $table->string('object_key');
            $table->unsignedBigInteger('size')->nullable();
            // vingerafdruk van het versleutelwachtwoord: zo is te zien welke back-ups een ouder wachtwoord hebben
            $table->string('key_id', 32)->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['site_slug', 'backup_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offsite_backups');
    }
};
