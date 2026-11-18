<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * refund_started_at: when the payment last entered "refunding", so a stuck
 * refund is never settled by hand while the provider call may still run.
 * refund_resolved_*: who settled a stuck refund by hand, when, and what they
 * said the provider showed ("refunded" or "not_refunded").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->timestamp('refund_started_at')->nullable()->after('refunded_by');
            $table->timestamp('refund_resolved_at')->nullable()->after('refund_started_at');
            $table->unsignedBigInteger('refund_resolved_by')->nullable()->after('refund_resolved_at');
            $table->string('refund_resolution', 16)->nullable()->after('refund_resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropColumn(['refund_started_at', 'refund_resolved_at', 'refund_resolved_by', 'refund_resolution']);
        });
    }
};
