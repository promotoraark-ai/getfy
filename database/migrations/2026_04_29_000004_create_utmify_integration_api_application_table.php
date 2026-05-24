<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('utmify_integration_api_application')) {
            Schema::create('utmify_integration_api_application', function (Blueprint $table) {
                $table->id();
                $table->foreignId('utmify_integration_id')->constrained('utmify_integrations')->cascadeOnDelete();
                $table->foreignId('api_application_id')->constrained('api_applications')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['utmify_integration_id', 'api_application_id'], 'utmify_int_api_app_unique');
            });
        } else {
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = 'utmify_integration_api_application' AND index_name = 'utmify_int_api_app_unique' LIMIT 1",
                [DB::getDatabaseName()]
            );
            if (! $exists) {
                Schema::table('utmify_integration_api_application', function (Blueprint $table) {
                    $table->unique(['utmify_integration_id', 'api_application_id'], 'utmify_int_api_app_unique');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('utmify_integration_api_application');
    }
};

