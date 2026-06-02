<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'cajupay_split_payout_enabled')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('cajupay_split_payout_enabled')->default(false)->after('is_active');
            });
        }

        if (Schema::hasTable('product_coproducers') && ! Schema::hasColumn('product_coproducers', 'payout_method')) {
            Schema::table('product_coproducers', function (Blueprint $table) {
                $table->string('payout_method', 32)->default('internal')->after('cajupay_split_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'cajupay_split_payout_enabled')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('cajupay_split_payout_enabled');
            });
        }

        if (Schema::hasColumn('product_coproducers', 'payout_method')) {
            Schema::table('product_coproducers', function (Blueprint $table) {
                $table->dropColumn('payout_method');
            });
        }
    }
};
