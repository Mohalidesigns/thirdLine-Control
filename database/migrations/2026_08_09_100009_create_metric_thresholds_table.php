<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Threshold bands are data (R1): the operator, the bounds, the colour,
     * the action and the escalation target are all configured, never coded.
     */
    public function up(): void
    {
        Schema::create('metric_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('metric_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['Green', 'Amber', 'Red', 'Critical']);
            $table->enum('operator', ['gt', 'gte', 'lt', 'lte', 'between', 'outside']);
            $table->decimal('value_from', 20, 6)->nullable();
            $table->decimal('value_to', 20, 6)->nullable();
            $table->char('colour_hex', 7)->default('#0ca30c');
            $table->text('action_required')->nullable();
            $table->string('escalate_to_role', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('metric_id');
            $table->index(['metric_id', 'sort_order'], 'metric_threshold_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_thresholds');
    }
};
