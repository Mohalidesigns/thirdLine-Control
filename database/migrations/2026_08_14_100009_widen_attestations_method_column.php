<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 15 adds two attestation channels — `offline` (queued on a
     * device, replayed by the sync engine) and `ussd`. The column was an
     * enum, i.e. a hard-coded channel list at the schema layer; a plain
     * string with the application validating against Attestation::METHODS
     * survives the next channel without another migration (R8: additive).
     */
    public function up(): void
    {
        Schema::table('attestations', function (Blueprint $table) {
            $table->string('method', 20)->default('web')->change();
        });
    }

    public function down(): void
    {
        Schema::table('attestations', function (Blueprint $table) {
            $table->enum('method', ['web', 'mobile', 'whatsapp', 'email'])->default('web')->change();
        });
    }
};
