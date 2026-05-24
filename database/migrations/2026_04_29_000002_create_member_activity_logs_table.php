<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_activity_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->char('product_id', 36)->nullable()->index();

            $table->string('event', 80)->index();
            $table->json('metadata')->nullable();

            $table->string('ip', 45)->nullable()->index();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'product_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_activity_logs');
    }
};

