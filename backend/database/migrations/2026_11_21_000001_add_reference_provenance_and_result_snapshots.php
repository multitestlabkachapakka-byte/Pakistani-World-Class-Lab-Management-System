<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical-safety provenance: every reference interval records its unit, population,
 * methodology, source and who (if anyone) verified it. Report results keep a frozen
 * copy of the range that was applied, so a finalized report stays explainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_ranges', function (Blueprint $table) {
            $table->string('unit', 50)->nullable();
            $table->string('population')->nullable();      // e.g. "Adult, non-pregnant"
            $table->string('methodology')->nullable();     // e.g. "Enzymatic" / "Jaffe"
            $table->string('verified_by')->nullable();     // pathologist name; null = not verified
            $table->timestamp('verified_at')->nullable();
            $table->string('updated_by_device')->nullable();
            $table->unsignedBigInteger('rev')->default(1);
            $table->softDeletes();
        });

        Schema::table('test_definitions', function (Blueprint $table) {
            $table->string('verified_by')->nullable();
        });

        Schema::table('report_results', function (Blueprint $table) {
            $table->uuid('reference_range_id')->nullable();
            $table->decimal('critical_low', 12, 4)->nullable();
            $table->decimal('critical_high', 12, 4)->nullable();
            $table->string('reference_source')->nullable();
            $table->string('reference_verification_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_results', function (Blueprint $table) {
            $table->dropColumn([
                'reference_range_id', 'critical_low', 'critical_high',
                'reference_source', 'reference_verification_status',
            ]);
        });

        Schema::table('test_definitions', function (Blueprint $table) {
            $table->dropColumn('verified_by');
        });

        Schema::table('reference_ranges', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'unit', 'population', 'methodology', 'verified_by',
                'verified_at', 'updated_by_device', 'rev',
            ]);
        });
    }
};
