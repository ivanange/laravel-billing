<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_gateway_instances', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 64)->index();
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->json('enabled_methods')->nullable();
            $table->json('currencies')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('billing_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('billing_calendar_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('billing_calendars')->cascadeOnDelete();
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->string('billable_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('billing_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('billing_plans')->cascadeOnDelete();
            $table->string('label');
            $table->string('duration_unit', 16)->nullable();
            $table->unsignedSmallInteger('duration_count')->nullable();
            $table->foreignId('calendar_id')->nullable()->constrained('billing_calendars')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->morphs('billable');
            $table->nullableMorphs('payer');
            $table->foreignId('gateway_instance_id')->nullable()->constrained('billing_gateway_instances')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->date('paid_until')->nullable()->index();
            $table->date('expiry_notified_for')->nullable();
            $table->date('lapse_notified_for')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'billable_type', 'billable_id']);
        });

        Schema::create('billing_coverage_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('billing_subscriptions')->restrictOnDelete();
            $table->morphs('billable');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('grace_ends_on');
            $table->string('label')->nullable();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16)->index();
            $table->timestamps();
            $table->index(['subscription_id', 'status']);
            $table->index(['status', 'starts_on', 'grace_ends_on']);
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('payer');
            $table->foreignId('gateway_instance_id')->constrained('billing_gateway_instances')->restrictOnDelete();
            $table->string('method', 64);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16)->index();
            $table->date('paid_on');
            $table->string('reference')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('proof_path')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('billing_payments')->restrictOnDelete();
            $table->foreignId('coverage_period_id')->constrained('billing_coverage_periods')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_allocations');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_coverage_periods');
        Schema::dropIfExists('billing_subscriptions');
        Schema::dropIfExists('billing_plan_prices');
        Schema::dropIfExists('billing_plans');
        Schema::dropIfExists('billing_calendar_periods');
        Schema::dropIfExists('billing_calendars');
        Schema::dropIfExists('billing_gateway_instances');
    }
};
