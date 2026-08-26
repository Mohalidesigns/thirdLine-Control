<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §C.1: every raw string the workbook uses, mapped to a
     * frequency code. `Quaterly`, `bi-annually`, `twice annually` and
     * `Half yearly` all resolve here rather than in PHP, so revision 2 of
     * the client's workbook is a data change, not a deploy.
     *
     * `normalised` is the lookup key — lower-cased, whitespace-collapsed —
     * because the source spells the same idea with different casing and
     * trailing double spaces.
     */
    public function up(): void
    {
        Schema::create('frequency_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('control_frequency_id')->constrained('control_frequencies')->cascadeOnDelete();
            $table->string('raw');
            $table->string('normalised', 191);
            $table->timestamps();

            $table->unique(['tenant_id', 'normalised']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frequency_aliases');
    }
};
