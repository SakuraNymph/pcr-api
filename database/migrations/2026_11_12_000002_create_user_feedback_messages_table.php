<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedback_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feedback_id')
                ->constrained('user_feedback')
                ->restrictOnDelete();

            $table->unsignedTinyInteger('sender_type')
                ->comment('1=用户 2=管理员');
            $table->unsignedBigInteger('sender_id')
                ->comment('用户为 users.id，管理员为 admins.id');

            $table->text('content')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feedback_id', 'id'], 'user_feedback_messages_feedback_id_id_index');
            $table->index(
                ['feedback_id', 'sender_type', 'read_at'],
                'user_feedback_messages_feedback_sender_read_index'
            );
            $table->index(['sender_type', 'sender_id'], 'user_feedback_messages_sender_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback_messages');
    }
};
