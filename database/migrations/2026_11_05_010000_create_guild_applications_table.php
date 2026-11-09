<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guild_applications', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('applicant_uid');
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('expires_at', 6);
            $table->timestamp('handled_at', 6)->nullable();
            $table->unsignedBigInteger('handler_uid')->nullable();
            $table->timestamps(6);

            // 仅待处理申请参与唯一约束，已处理记录永久保留。
            $table->unsignedBigInteger('pending_applicant')
                ->nullable()
                ->storedAs('CASE WHEN status = 0 THEN applicant_uid ELSE NULL END');

            $table->unique(
                ['guild_id', 'pending_applicant'],
                'guild_applications_guild_pending_unique'
            );
            $table->index(
                ['applicant_uid', 'status', 'expires_at'],
                'guild_applications_applicant_status_expires_index'
            );
            $table->index(
                ['guild_id', 'status', 'expires_at'],
                'guild_applications_guild_status_expires_index'
            );
            $table->index(
                ['handler_uid', 'handled_at'],
                'guild_applications_handler_handled_index'
            );

            $table->foreign('guild_id')
                ->references('id')
                ->on('guilds')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE guild_applications ADD CONSTRAINT guild_applications_status_check CHECK (status BETWEEN 0 AND 5)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_applications');
    }
};
