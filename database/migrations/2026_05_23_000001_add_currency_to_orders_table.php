<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'currency')) {
                $table->string('currency', 3)->default('BRL')->after('amount');
                $table->index('currency');
            }
        });

        if (Schema::hasColumn('orders', 'currency')) {
            DB::table('orders')->whereNull('currency')->update(['currency' => 'BRL']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'currency')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['currency']);
            $table->dropColumn('currency');
        });
    }
};
