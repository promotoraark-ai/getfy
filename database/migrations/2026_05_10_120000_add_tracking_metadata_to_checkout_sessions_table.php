<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }
        if (! Schema::hasColumn('checkout_sessions', 'tracking_metadata')) {
            Schema::table('checkout_sessions', function (Blueprint $table) {
                $table->json('tracking_metadata')->nullable()->after('utm_campaign');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('checkout_sessions') && Schema::hasColumn('checkout_sessions', 'tracking_metadata')) {
            Schema::table('checkout_sessions', function (Blueprint $table) {
                $table->dropColumn('tracking_metadata');
            });
        }
    }
};
