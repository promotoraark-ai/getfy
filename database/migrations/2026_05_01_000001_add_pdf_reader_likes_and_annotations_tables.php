<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_lessons', function (Blueprint $table) {
            $table->unsignedInteger('likes_count')->default(0)->after('watermark_enabled');
        });

        Schema::create('member_lesson_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_lesson_id')->constrained('member_lessons')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'member_lesson_id']);
            $table->index('member_lesson_id');
        });

        Schema::create('member_lesson_pdf_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_lesson_id')->constrained('member_lessons')->cascadeOnDelete();
            $table->unsignedInteger('file_index')->default(0);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['user_id', 'member_lesson_id', 'file_index'], 'member_pdf_ann_user_lesson_file_unique');
            $table->index(['member_lesson_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_lesson_pdf_annotations');
        Schema::dropIfExists('member_lesson_likes');

        Schema::table('member_lessons', function (Blueprint $table) {
            $table->dropColumn('likes_count');
        });
    }
};
