<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract and SLA register (17.2). SLA commitments are rows in a JSON
     * list rather than columns, because every contract measures something
     * different and a schema that fixed them would be wrong by the second
     * contract. Each commitment may name the obligation it discharges, so
     * a contractual SLA and a regulatory one line up.
     */
    public function up(): void
    {
        Schema::create('vendor_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('reference', 30);
            $table->string('title');
            $table->string('contract_type', 100)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('renewal_notice_days')->default(90);
            $table->bigInteger('value_minor')->nullable();
            $table->string('currency', 3)->default('NGN');
            // [{label, target, measure, obligation_id}] — the SLA commitments.
            $table->json('sla_commitments')->nullable();
            $table->foreignId('obligation_id')->nullable()
                ->constrained('regulatory_obligations')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('renewal_alerted_at')->nullable();
            $table->enum('status', ['Draft', 'Active', 'Expiring', 'Expired', 'Terminated'])->default('Draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('vendor_id');
            $table->index('entity_id');
            $table->index('obligation_id');
            $table->index('document_id');
            $table->index('owner_id');
            $table->index('status');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_contracts');
    }
};
