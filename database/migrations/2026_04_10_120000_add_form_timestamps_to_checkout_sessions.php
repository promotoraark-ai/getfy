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

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_sessions', 'form_started_at')) {
                $table->timestamp('form_started_at')->nullable()->after('step');
            }
            if (! Schema::hasColumn('checkout_sessions', 'form_filled_at')) {
                $table->timestamp('form_filled_at')->nullable()->after('form_started_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_sessions', 'form_filled_at')) {
                $table->dropColumn('form_filled_at');
            }
            if (Schema::hasColumn('checkout_sessions', 'form_started_at')) {
                $table->dropColumn('form_started_at');
            }
        });
    }
};
