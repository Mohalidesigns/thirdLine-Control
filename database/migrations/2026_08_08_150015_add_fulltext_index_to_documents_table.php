<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** MySQL-only FULLTEXT for global search (7.7); other drivers use LIKE. */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->fullText(['reference', 'title', 'description'], 'ft_documents_search');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropFullText('ft_documents_search');
        });
    }
};
