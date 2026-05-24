<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('url_token', 80)->unique();
            $table->string('product_id');
            $table->unsignedBigInteger('product_offer_id')->nullable();
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->json('field_map')->nullable();
            $table->text('signing_secret')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_webhook_endpoints');
    }
};
