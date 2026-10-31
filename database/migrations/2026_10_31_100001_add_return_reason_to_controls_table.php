<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why the checker last sent a control back to draft, kept on the control
     * so the maker sees it where they fix it. Cleared on resubmission; the
     * audit trail keeps the full history.
     */
    public function up(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->text('return_reason')->nullable()->after('approved_at');
            $table->foreignId('returned_by')->nullable()->after('return_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
        });
    }

    public function down(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['return_reason', 'returned_at']);
        });
    }
};
