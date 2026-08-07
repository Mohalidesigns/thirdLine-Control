<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            // NULL = platform reference rate shared by all tenants; a
            // tenant-scoped row overrides it (same pattern as rating matrix).
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_exchange_rates_tenant')->nullOnDelete();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->date('effective_date');
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(
                ['tenant_id', 'base_currency', 'quote_currency', 'effective_date'],
                'uq_exchange_rates_pair_date'
            );
            $table->index(['base_currency', 'quote_currency', 'effective_date'], 'ix_exchange_rates_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
