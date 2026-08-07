<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->string('department')->nullable()->after('unit_id');
            $table->string('coso_component')->nullable()->after('category_id');
            $table->string('test_frequency')->nullable()->after('frequency');
            $table->text('control_documentation')->nullable()->after('framework_refs');
            $table->text('notes')->nullable()->after('control_documentation');
            $table->string('design_effectiveness')->default('Not Assessed')->after('notes');
            $table->string('operating_effectiveness')->default('Not Tested')->after('design_effectiveness');
            $table->string('overall_rating')->nullable()->after('operating_effectiveness');
            $table->timestamp('last_assessed_at')->nullable()->after('overall_rating');

            $table->index(['tenant_id', 'design_effectiveness']);
            $table->index(['tenant_id', 'operating_effectiveness']);
        });
    }

    public function down(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'design_effectiveness']);
            $table->dropIndex(['tenant_id', 'operating_effectiveness']);
            $table->dropColumn([
                'department', 'coso_component', 'test_frequency', 'control_documentation',
                'notes', 'design_effectiveness', 'operating_effectiveness', 'overall_rating',
                'last_assessed_at',
            ]);
        });
    }
};
