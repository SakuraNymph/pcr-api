<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedback', function (Blueprint $table) {
            $table->id();

            // users.id 实际为有符号 INT，这里必须保持完全一致的列类型
            $table->integer('user_id')
                ->nullable()
                ->comment('关联 users.id，用户删除后置空');
            $table->unsignedInteger('public_uid')
                ->comment('提交时的公开 UID 快照');
            $table->string('nickname', 255)
                ->nullable()
                ->comment('提交时的昵称快照');

            $table->unsignedTinyInteger('category')
                ->comment('1=使用问题 2=功能建议 3=改进建议 4=其他');
            $table->string('title', 30);
            $table->unsignedTinyInteger('status')
                ->default(0)
                ->comment('0=待处理 1=处理中 2=已回复 3=已关闭');

            $table->string('client_type', 20)->nullable();
            $table->string('client_version', 50)->nullable();
            $table->timestamp('last_message_at')->useCurrent();

            $table->timestamps();

            $table->index(['user_id', 'id'], 'user_feedback_user_id_id_index');
            $table->index(['status', 'last_message_at'], 'user_feedback_status_last_message_index');
            $table->index('public_uid', 'user_feedback_public_uid_index');
            $table->index('created_at', 'user_feedback_created_at_index');
            $table->index(
                ['category', 'status', 'last_message_at'],
                'user_feedback_category_status_last_message_index'
            );

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback');
    }
};
