<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR — the break-glass request that opens Tier 2 (identifying) reporter
     * metadata. Reason code, written justification, and a second-person
     * approval; nobody self-approves, and an approval expires rather than
     * standing forever.
     */
    public function up(): void
    {
        Schema::create('speak_up_reveal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('reason_code', 60);
            $table->text('justification');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speak_up_reveal_requests');
    }
};
