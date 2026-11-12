<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedback_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('user_feedback_messages')
                ->restrictOnDelete();

            $table->string('path', 191)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id', 'user_feedback_images_message_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback_images');
    }
};
