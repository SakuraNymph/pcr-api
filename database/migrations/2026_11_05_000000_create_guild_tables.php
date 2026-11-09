<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guilds', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('name', 32);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('owner_uid');
            $table->unsignedTinyInteger('status')->default(1);

            // status=1 时生成 owner_uid，利用唯一索引保证一个用户最多拥有一个正常运营公会。
            $table->unsignedBigInteger('active_owner_uid')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 THEN owner_uid ELSE NULL END');

            $table->timestamps();

            $table->unique('active_owner_uid', 'guilds_active_owner_uid_unique');
            $table->index(['status', 'created_at'], 'guilds_status_created_at_index');
            $table->index('name', 'guilds_name_index');
        });

        Schema::create('guild_members', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('uid');
            $table->unsignedTinyInteger('role')->default(3);
            $table->unsignedTinyInteger('member_slot');
            $table->unsignedTinyInteger('vice_slot')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('joined_at', 6);
            $table->timestamp('left_at', 6)->nullable();
            $table->timestamps(6);

            $table->unsignedBigInteger('active_uid')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 THEN uid ELSE NULL END');
            $table->unsignedTinyInteger('active_member_slot')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 THEN member_slot ELSE NULL END');
            $table->unsignedTinyInteger('active_owner_slot')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 AND role = 1 THEN 1 ELSE NULL END');
            $table->unsignedTinyInteger('active_vice_slot')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 AND role = 2 THEN vice_slot ELSE NULL END');

            $table->unique('active_uid', 'guild_members_active_uid_unique');
            $table->unique(
                ['guild_id', 'active_member_slot'],
                'guild_members_guild_active_slot_unique'
            );
            $table->unique(
                ['guild_id', 'active_owner_slot'],
                'guild_members_guild_active_owner_unique'
            );
            $table->unique(
                ['guild_id', 'active_vice_slot'],
                'guild_members_guild_active_vice_unique'
            );
            $table->index(['guild_id', 'status', 'role'], 'guild_members_guild_status_role_index');
            $table->index(['uid', 'created_at'], 'guild_members_uid_created_at_index');

            $table->foreign('guild_id')
                ->references('id')
                ->on('guilds')
                ->restrictOnDelete();
        });

        Schema::create('guild_invitations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('inviter_uid');
            $table->unsignedBigInteger('invitee_uid');
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('expires_at', 6);
            $table->timestamp('handled_at', 6)->nullable();
            $table->timestamps(6);

            // 仅待处理邀请参与唯一约束，已处理记录仍永久保留。
            $table->unsignedBigInteger('pending_uid')
                ->nullable()
                ->storedAs('CASE WHEN status = 0 THEN invitee_uid ELSE NULL END');

            $table->unique(
                ['guild_id', 'pending_uid'],
                'guild_invitations_guild_pending_unique'
            );
            $table->index(
                ['invitee_uid', 'status', 'expires_at'],
                'guild_invitations_invitee_status_expires_index'
            );
            $table->index(
                ['inviter_uid', 'status', 'expires_at'],
                'guild_invitations_inviter_status_expires_index'
            );
            $table->index(
                ['guild_id', 'status', 'expires_at'],
                'guild_invitations_guild_status_expires_index'
            );

            $table->foreign('guild_id')
                ->references('id')
                ->on('guilds')
                ->restrictOnDelete();
        });

        Schema::create('guild_months', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->char('year_month', 7);
            $table->timestamps();

            $table->unique(['guild_id', 'year_month'], 'guild_months_guild_month_unique');
            $table->index(['guild_id', 'year_month'], 'guild_months_guild_month_index');

            $table->foreign('guild_id')
                ->references('id')
                ->on('guilds')
                ->restrictOnDelete();
        });

        Schema::create('guild_month_members', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('month_id');
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('uid');
            $table->string('nickname', 32)->nullable();
            $table->unsignedTinyInteger('role')->default(3);
            $table->unsignedTinyInteger('member_slot');
            $table->unsignedBigInteger('team_1')->nullable();
            $table->unsignedBigInteger('team_1_return')->nullable();
            $table->unsignedBigInteger('team_2')->nullable();
            $table->unsignedBigInteger('team_2_return')->nullable();
            $table->unsignedBigInteger('team_3')->nullable();
            $table->unsignedBigInteger('team_3_return')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(
                ['month_id', 'uid'],
                'guild_month_members_month_uid_unique'
            );
            $table->index(
                ['guild_id', 'uid'],
                'guild_month_members_guild_uid_index'
            );
            $table->index(
                ['month_id', 'member_slot'],
                'guild_month_members_month_slot_index'
            );

            $table->foreign('month_id')
                ->references('id')
                ->on('guild_months')
                ->restrictOnDelete();
            $table->foreign('guild_id')
                ->references('id')
                ->on('guilds')
                ->restrictOnDelete();
        });

        Schema::create('guild_logs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('actor_uid');
            $table->unsignedBigInteger('target_uid')->nullable();
            $table->string('action', 40);
            $table->json('context')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['guild_id', 'created_at'], 'guild_logs_guild_created_at_index');
            $table->index(['actor_uid', 'created_at'], 'guild_logs_actor_created_at_index');
            $table->index(['action', 'created_at'], 'guild_logs_action_created_at_index');

            $table->foreign('guild_id')
                ->references('id')
                ->on('guilds')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE guilds ADD CONSTRAINT guilds_status_check CHECK (status IN (0, 1))');
            DB::statement('ALTER TABLE guild_members ADD CONSTRAINT guild_members_role_check CHECK (role IN (1, 2, 3))');
            DB::statement('ALTER TABLE guild_members ADD CONSTRAINT guild_members_status_check CHECK (status IN (0, 1))');
            DB::statement('ALTER TABLE guild_members ADD CONSTRAINT guild_members_slot_check CHECK (member_slot BETWEEN 1 AND 30)');
            DB::statement('ALTER TABLE guild_members ADD CONSTRAINT guild_members_vice_slot_check CHECK (status = 0 OR (role = 2 AND vice_slot IS NOT NULL AND vice_slot BETWEEN 1 AND 2) OR (role <> 2 AND vice_slot IS NULL))');
            DB::statement('ALTER TABLE guild_invitations ADD CONSTRAINT guild_invitations_status_check CHECK (status BETWEEN 0 AND 5)');
            DB::statement('ALTER TABLE guild_month_members ADD CONSTRAINT guild_month_members_role_check CHECK (role IN (1, 2, 3))');
            DB::statement('ALTER TABLE guild_month_members ADD CONSTRAINT guild_month_members_slot_check CHECK (member_slot BETWEEN 1 AND 30)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_logs');
        Schema::dropIfExists('guild_month_members');
        Schema::dropIfExists('guild_months');
        Schema::dropIfExists('guild_invitations');
        Schema::dropIfExists('guild_members');
        Schema::dropIfExists('guilds');
    }
};
