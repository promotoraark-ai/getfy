<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('api_applications', 'conversion_pixels')) {
                $table->json('conversion_pixels')->nullable()->after('checkout_sidebar_bg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_applications', function (Blueprint $table) {
            $table->dropColumn('conversion_pixels');
        });
    }
};

