<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('void_reason');
            $table->string('failure_reason', 64)->nullable()->after('failed_at');
            $table->timestamp('refunded_at')->nullable()->after('failure_reason');
            $table->unsignedBigInteger('refunded_by')->nullable()->after('refunded_at');
            // Webhooks and returns look payments up by (instance, reference).
            $table->index(['gateway_instance_id', 'reference']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropIndex(['gateway_instance_id', 'reference']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['failed_at', 'failure_reason', 'refunded_at', 'refunded_by']);
        });
    }
};
