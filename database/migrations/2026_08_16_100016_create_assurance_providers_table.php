<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The assurance provider register (17.4) — the King IV/V and NCCG
     * combined-assurance concept. `line` places a provider in the three
     * lines model plus the external providers that sit outside it; whether
     * a provider is independent of the activity it assures is recorded
     * rather than inferred from its line, because a "second line" function
     * assuring its own process is exactly the case a combined assurance map
     * exists to expose.
     */
    public function up(): void
    {
        Schema::create('assurance_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->enum('line', [
                'management', 'second_line', 'internal_audit',
                'external_audit', 'regulator', 'external_assurance',
            ])->default('second_line');
            $table->text('description')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('is_independent')->default(false);
            // Set when this provider's coverage arrives over the integration
            // layer rather than being maintained here (17.5).
            $table->foreignId('integration_config_id')->nullable()
                ->constrained('integration_configs')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index('integration_config_id');
            $table->index('line');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assurance_providers');
    }
};
