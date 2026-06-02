<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_affiliate_programs') && ! Schema::hasColumn('product_affiliate_programs', 'share_buyer_data')) {
            Schema::table('product_affiliate_programs', function (Blueprint $table) {
                $table->boolean('share_buyer_data')->default(false)->after('manual_approval');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_affiliate_programs', 'share_buyer_data')) {
            Schema::table('product_affiliate_programs', function (Blueprint $table) {
                $table->dropColumn('share_buyer_data');
            });
        }
    }
};
