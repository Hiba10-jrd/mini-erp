<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->string('dedup_key', 64)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['notifiable_type', 'notifiable_id', 'dedup_key'], 'notifications_recipient_dedup_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
