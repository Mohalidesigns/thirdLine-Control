<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The spend ceiling (Part C §C.1.5). One row per tenant per period.
     *
     * Money is integer minor units plus an ISO-4217 code, never a float
     * (R7) — model pricing is configured in USD, so a tenant reporting in
     * naira sees the converted figure through the existing exchange-rate
     * table rather than a rounded number stored twice.
     *
     * hard_stop true means BudgetService refuses the call before any request
     * leaves the building. A budget that only warns is not a budget.
     */
    public function up(): void
    {
        Schema::create('ai_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period_label', 20);           // e.g. 2026-08
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('token_budget')->default(0);
            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->unsignedBigInteger('cost_budget_minor')->default(0);
            $table->unsignedBigInteger('cost_used_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->boolean('hard_stop')->default(true);
            $table->json('alert_thresholds')->nullable();
            $table->json('alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period_label']);
            $table->index('tenant_id');
            $table->index('period_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_budgets');
    }
};
