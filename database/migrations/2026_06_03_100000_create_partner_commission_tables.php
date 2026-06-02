<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_fee_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('gateway_slug', 64);
            $table->string('method', 32);
            $table->decimal('percent', 8, 4)->default(0);
            $table->unsignedInteger('fixed_cents')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'gateway_slug', 'method'], 'gateway_fee_tenant_slug_method');
        });

        Schema::create('product_affiliate_programs', function (Blueprint $table) {
            $table->id();
            $table->uuid('product_id')->index();
            $table->boolean('enabled')->default(false);
            $table->decimal('default_commission_percent', 8, 4)->default(0);
            $table->boolean('manual_approval')->default(true);
            $table->boolean('share_buyer_data')->default(false);
            $table->string('public_slug', 64)->nullable()->unique();
            $table->string('support_email')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('settlement_days_pix')->default(0);
            $table->unsignedSmallInteger('settlement_days_card')->default(30);
            $table->unsignedSmallInteger('settlement_days_boleto')->default(2);
            $table->timestamps();
        });

        Schema::create('product_coproducers', function (Blueprint $table) {
            $table->id();
            $table->uuid('product_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('invite_token', 64)->nullable()->unique();
            $table->string('status', 32)->default('pending');
            $table->decimal('commission_percent', 8, 4);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('commission_on_producer_sales')->default(true);
            $table->boolean('commission_on_affiliate_sales')->default(true);
            $table->unsignedSmallInteger('settlement_days_pix')->nullable();
            $table->unsignedSmallInteger('settlement_days_card')->nullable();
            $table->unsignedSmallInteger('settlement_days_boleto')->nullable();
            $table->uuid('cajupay_split_id')->nullable();
            $table->string('payout_method', 32)->default('internal');
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });

        Schema::create('product_affiliates', function (Blueprint $table) {
            $table->id();
            $table->uuid('product_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('affiliate_code', 32);
            $table->decimal('commission_percent', 8, 4)->nullable();
            $table->string('status', 32)->default('pending');
            $table->json('affiliate_pixels')->nullable();
            $table->unsignedSmallInteger('settlement_days_pix')->nullable();
            $table->unsignedSmallInteger('settlement_days_card')->nullable();
            $table->unsignedSmallInteger('settlement_days_boleto')->nullable();
            $table->uuid('cajupay_split_id')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'affiliate_code']);
            $table->unique(['product_id', 'user_id']);
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('beneficiary_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 32);
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('gateway_fee_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->decimal('commission_percent', 8, 4)->default(0);
            $table->decimal('commission_amount', 12, 2);
            $table->string('status', 32)->default('pending');
            $table->string('payment_method', 32)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['beneficiary_user_id', 'status']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type', 16);
            $table->string('source', 32);
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->foreignId('commission_entry_id')->nullable()->constrained('commission_entries')->nullOnDelete();
            $table->string('cajupay_reference')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('pix_key', 255)->nullable()->after('username');
            $table->string('pix_key_type', 32)->nullable()->after('pix_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pix_key', 'pix_key_type']);
        });
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('product_affiliates');
        Schema::dropIfExists('product_coproducers');
        Schema::dropIfExists('product_affiliate_programs');
        Schema::dropIfExists('gateway_fee_settings');
    }
};
