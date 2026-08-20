<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One tile on a dashboard. data_source_key names a registered source in
     * App\Dashboards\Sources — the frontend never sends a query, only a key
     * plus a configuration the source itself validates, so a widget can
     * never become an arbitrary read of the database (13.2 business rule).
     *
     * permission_required is a second gate on top of the source's own
     * permission: it lets a tenant restrict a tile further, never widen it.
     */
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->enum('widget_type', [
                'stat', 'line', 'bar', 'stacked_bar', 'horizontal_bar', 'pie', 'donut',
                'heatmap', 'gauge', 'table', 'tree', 'timeline', 'progress', 'sparkline',
                'map', 'scorecard', 'text',
            ])->default('stat');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('data_source_key', 80)->nullable();
            $table->json('query_config')->nullable();
            $table->json('display_config')->nullable();
            $table->json('drill_down_config')->nullable();
            $table->json('position')->nullable();
            $table->unsignedInteger('refresh_seconds')->default(0);
            $table->string('permission_required')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('dashboard_id');
            $table->index('data_source_key');
            $table->index(['dashboard_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
