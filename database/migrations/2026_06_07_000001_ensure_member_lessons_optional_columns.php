<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garante colunas opcionais de member_lessons quando migrations anteriores falharam
 * (ex.: after('content_files') com content_files ainda inexistente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_lessons')) {
            return;
        }

        Schema::table('member_lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('member_lessons', 'link_title')) {
                $table->string('link_title')->nullable();
            }
            if (! Schema::hasColumn('member_lessons', 'watermark_enabled')) {
                $table->boolean('watermark_enabled')->default(false);
            }
            if (! Schema::hasColumn('member_lessons', 'content_files')) {
                $table->json('content_files')->nullable();
            }
            if (! Schema::hasColumn('member_lessons', 'support_files')) {
                $table->json('support_files')->nullable();
            }
            if (! Schema::hasColumn('member_lessons', 'useful_links')) {
                $table->json('useful_links')->nullable();
            }
            if (! Schema::hasColumn('member_lessons', 'release_after_days')) {
                $table->unsignedInteger('release_after_days')->nullable();
            }
            if (! Schema::hasColumn('member_lessons', 'release_at_date')) {
                $table->date('release_at_date')->nullable();
            }
            if (! Schema::hasColumn('member_lessons', 'likes_count')) {
                $table->unsignedInteger('likes_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        // Reparo idempotente; não remove colunas em down.
    }
};
