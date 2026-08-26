<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §C.7. The shared evidence repository records who UPLOADED a file
 * but not who COLLECTED it or where it came from — a distinction that is
 * decorative for a test workpaper and load-bearing for an investigative
 * exhibit ("CCTV, Branch 042, taken by the branch control officer on the
 * 14th" is the answer a disciplinary panel asks for).
 *
 * Additive, and it benefits every module, which is why CR-04 extends the
 * shared table rather than porting a second evidence table of its own.
 * `checksum` already gives the source module's file_hash and
 * evidence_access_logs already gives a far better answer than its
 * download_count, so nothing else is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence', function (Blueprint $table) {
            $table->foreignId('collected_by')->nullable()->after('uploaded_at')
                ->constrained('users', indexName: 'fk_evidence_collector')->nullOnDelete();
            $table->date('collected_on')->nullable()->after('collected_by');
            $table->string('collection_source')->nullable()->after('collected_on');
            $table->text('description')->nullable()->after('collection_source');
        });
    }

    /**
     * The foreign key is dropped BY NAME. dropConstrainedForeignId() would
     * derive `evidence_collected_by_foreign`, which does not exist: up()
     * named the constraint `fk_evidence_collector` in house style, and
     * MySQL refuses to drop a key it has never heard of. SQLite rebuilds
     * the table and never notices, which is precisely how this survives a
     * green test suite and dies on the deployment.
     */
    public function down(): void
    {
        Schema::table('evidence', function (Blueprint $table) {
            $table->dropForeign('fk_evidence_collector');
        });

        Schema::table('evidence', function (Blueprint $table) {
            $table->dropColumn(['collected_by', 'collected_on', 'collection_source', 'description']);
        });
    }
};
