<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Due dates roll off weekends and public holidays. Holidays are data
        // (R1): seeded per jurisdiction, overridable per tenant.
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_public_holidays_tenant')->cascadeOnDelete();
            $table->string('jurisdiction', 8)->default('NG');
            $table->date('holiday_date');
            $table->string('name');
            // Fixed-date holidays repeat every year; lunar ones are dated rows.
            $table->boolean('is_recurring')->default(false);
            $table->string('source', 500)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('jurisdiction');
            $table->index('holiday_date');
            $table->unique(['tenant_id', 'jurisdiction', 'holiday_date', 'name'], 'uq_public_holidays');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
