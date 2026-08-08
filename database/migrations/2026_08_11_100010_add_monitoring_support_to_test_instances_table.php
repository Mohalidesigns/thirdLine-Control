<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Continuous results must land in the *existing* testing machinery
     * (12.4), so a monitoring rule linked to a control produces a real
     * TestInstance rather than a shadow record. Two additive columns mark
     * where the instance came from; everything downstream — review,
     * rating, residual risk — is unchanged (R8).
     *
     * An automated instance is still only a draft result: a human reviews
     * and rates it, and the rating still needs a second person (R2/R4).
     */
    public function up(): void
    {
        Schema::table('test_instances', function (Blueprint $table) {
            $table->enum('source', ['manual', 'automated'])->default('manual')->after('is_ad_hoc');
            $table->foreignId('monitoring_rule_id')->nullable()->after('source')
                ->constrained('monitoring_rules')->nullOnDelete();

            $table->index('monitoring_rule_id');
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('test_instances', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropConstrainedForeignId('monitoring_rule_id');
            $table->dropColumn('source');
        });
    }
};
