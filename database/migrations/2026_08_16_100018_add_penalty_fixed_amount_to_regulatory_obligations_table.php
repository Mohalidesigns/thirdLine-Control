<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Several Nigerian penalties are two-part: a fixed sum on default plus a
     * recurring sum for each day it continues — the NCC's ₦3,000,000 plus
     * ₦300,000 per day, and the SEC's ₦500,000 plus ₦5,000 per day. With a
     * single amount and a single basis, only the recurring element could be
     * modelled and the fixed element survived as prose, so exposure understated
     * the real cost from the first day of default.
     */
    public function up(): void
    {
        Schema::table('regulatory_obligations', function (Blueprint $table) {
            // R7: integer minor units, same currency as penalty_currency.
            $table->bigInteger('penalty_fixed_amount_minor')->nullable()->after('penalty_amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('regulatory_obligations', function (Blueprint $table) {
            $table->dropColumn('penalty_fixed_amount_minor');
        });
    }
};
