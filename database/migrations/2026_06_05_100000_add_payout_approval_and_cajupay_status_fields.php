<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payout_requests')) {
            return;
        }

        Schema::table('payout_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('payout_requests', 'cajupay_status')) {
                $table->string('cajupay_status', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('payout_requests', 'approved_by_user_id')) {
                $table->foreignId('approved_by_user_id')->nullable()->after('completed_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payout_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            }
            if (! Schema::hasColumn('payout_requests', 'rejected_by_user_id')) {
                $table->foreignId('rejected_by_user_id')->nullable()->after('approved_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payout_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by_user_id');
            }
            if (! Schema::hasColumn('payout_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payout_requests')) {
            return;
        }

        Schema::table('payout_requests', function (Blueprint $table) {
            foreach (['rejection_reason', 'rejected_at', 'rejected_by_user_id', 'approved_at', 'approved_by_user_id', 'cajupay_status'] as $col) {
                if (Schema::hasColumn('payout_requests', $col)) {
                    if (str_ends_with($col, '_user_id')) {
                        $table->dropForeign([$col]);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
