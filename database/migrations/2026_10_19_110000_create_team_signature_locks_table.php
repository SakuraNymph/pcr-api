<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_signature_locks', function (Blueprint $table): void {
            $table->id();
            $table->char('year_month', 7);
            $table->string('team_key', 100);
            $table->unsignedTinyInteger('mode');
            $table->unsignedTinyInteger('stage');
            $table->unsignedTinyInteger('boss');

            $table->unique(
                ['year_month', 'team_key', 'mode', 'stage', 'boss'],
                'uk_team_signature_locks_month_signature'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_signature_locks');
    }
};
