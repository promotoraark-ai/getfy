<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('push_fail_count')->default(0)->after('user_agent');
            $table->timestamp('last_push_failed_at')->nullable()->after('push_fail_count');
        });
    }

    public function down(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['push_fail_count', 'last_push_failed_at']);
        });
    }
};
