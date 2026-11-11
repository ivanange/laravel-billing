<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks may name a payment by the provider's own id (external_id), so it
 * must identify one payment per gateway instance. NULLs (offline payments)
 * stay allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->unique(['gateway_instance_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropUnique(['gateway_instance_id', 'external_id']);
        });
    }
};
