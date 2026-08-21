<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR — the immutable Metadata Access Log. One row per Tier 2 view (and
     * per request/approval/denial event), append-only by construction: the
     * model has no UPDATED_AT and the application exposes no route that
     * mutates a row. Readable by the Head of Control and audit, and by
     * ThirdLine over the integration API.
     */
    public function up(): void
    {
        Schema::create('speak_up_metadata_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('reveal_request_id')->nullable()->constrained('speak_up_reveal_requests')->nullOnDelete();
            $table->string('action', 30);
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('reason_code', 60)->nullable();
            $table->text('justification')->nullable();
            $table->json('fields_revealed')->nullable();
            // useCurrent() for MySQL NO_ZERO_DATE; always set explicitly.
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index('report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speak_up_metadata_access_logs');
    }
};
