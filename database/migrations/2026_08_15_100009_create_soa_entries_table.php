<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statement of Applicability decisions (16.4): per certifiable-framework
     * requirement, whether it applies to this tenant, why, and how far it is
     * implemented. The generated SoA document joins these decisions to the
     * live control mappings, so the document is always derived, never typed.
     */
    public function up(): void
    {
        Schema::create('soa_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('framework_id')->constrained('frameworks')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('framework_requirements')->cascadeOnDelete();
            $table->boolean('is_applicable')->default(true);
            $table->text('justification')->nullable();
            $table->enum('implementation_status', [
                'implemented', 'partially_implemented', 'planned', 'not_implemented',
            ])->default('not_implemented');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'framework_id', 'requirement_id']);
            $table->index('tenant_id');
            $table->index('framework_id');
            $table->index('requirement_id');
            $table->index('decided_by');
            $table->index('implementation_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soa_entries');
    }
};
