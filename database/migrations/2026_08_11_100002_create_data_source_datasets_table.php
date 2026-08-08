<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One extractable table/endpoint/file on a source (12.1). The
     * pii_classification is declared here, at the point of ingestion, so
     * SnapshotService can hash before anything is written (12.7, R5).
     *
     * Retaining sensitive personal data in clear requires an explicit DPO
     * authorisation, recorded on the dataset as an approval — absent that,
     * the declared pii_fields are hashed and there is no way back.
     */
    public function up(): void
    {
        Schema::create('data_source_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('extraction_config')->nullable();
            $table->json('schema_definition')->nullable();
            $table->json('primary_key_fields')->nullable();
            $table->string('incremental_field')->nullable();
            $table->unsignedInteger('retention_days')->default(365);
            $table->unsignedBigInteger('row_count_last')->nullable();
            $table->enum('pii_classification', ['none', 'internal', 'personal', 'sensitive_personal'])->default('none');
            $table->json('pii_fields')->nullable();
            $table->foreignId('dpo_authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dpo_authorised_at')->nullable();
            $table->text('dpo_authorisation_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['data_source_id', 'name']);
            $table->index('tenant_id');
            $table->index('data_source_id');
            $table->index('dpo_authorised_by');
            $table->index('is_active');
            $table->index('pii_classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_datasets');
    }
};
