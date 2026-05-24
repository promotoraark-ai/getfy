<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_documents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->char('product_id', 36)->nullable()->index();

            $table->string('public_code', 32)->unique();
            $table->string('public_hash', 64);

            $table->json('payload_snapshot')->nullable();

            $table->unsignedBigInteger('generated_by_user_id')->nullable()->index();
            $table->timestamp('generated_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('generated_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_documents');
    }
};

