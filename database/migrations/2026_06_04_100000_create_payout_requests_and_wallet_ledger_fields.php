<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('wallet_bucket', 16);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 32)->default('pending');
            $table->string('pix_key', 255);
            $table->string('pix_key_type', 32);
            $table->string('pix_owner_document', 32)->nullable();
            $table->string('cajupay_payout_id', 64)->nullable();
            $table->json('cajupay_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('payout_request_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_entry_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        if (Schema::hasTable('commission_entries')) {
            Schema::table('commission_entries', function (Blueprint $table) {
                if (! Schema::hasColumn('commission_entries', 'amount_paid')) {
                    $table->decimal('amount_paid', 12, 2)->default(0)->after('commission_amount');
                }
                if (! Schema::hasColumn('commission_entries', 'payout_request_id')) {
                    $table->foreignId('payout_request_id')->nullable()->after('paid_at')
                        ->constrained('payout_requests')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'pix_owner_document')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pix_owner_document', 32)->nullable()->after('pix_key_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('commission_entries')) {
            Schema::table('commission_entries', function (Blueprint $table) {
                if (Schema::hasColumn('commission_entries', 'payout_request_id')) {
                    $table->dropForeign(['payout_request_id']);
                    $table->dropColumn('payout_request_id');
                }
                if (Schema::hasColumn('commission_entries', 'amount_paid')) {
                    $table->dropColumn('amount_paid');
                }
            });
        }

        Schema::dropIfExists('payout_request_allocations');
        Schema::dropIfExists('payout_requests');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'pix_owner_document')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pix_owner_document');
            });
        }
    }
};
