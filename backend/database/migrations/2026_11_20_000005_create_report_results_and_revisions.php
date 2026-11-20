<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Link reports to individual tests (a report can have multiple tests)
        Schema::create('report_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_id')->index();
            $table->uuid('test_definition_id')->index();
            $table->string('test_name_snapshot'); // frozen name at time of report
            $table->string('department_snapshot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('pending'); // pending, entered, verified
            $table->timestamps();

            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
            // test_definition_id not hard FK so catalog changes never break old reports
        });

        // Individual parameter results
        Schema::create('report_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_id')->index();
            $table->uuid('report_test_id')->index();
            $table->uuid('test_parameter_id')->nullable(); // null if parameter later deleted
            $table->string('parameter_name_snapshot');
            $table->string('unit_snapshot')->nullable();
            $table->string('result_value')->nullable();
            $table->string('result_flag')->nullable(); // H, L, HH, LL, Positive, Negative, etc.
            $table->string('reference_range_text')->nullable(); // frozen display text
            $table->decimal('ref_low', 12, 4)->nullable();
            $table->decimal('ref_high', 12, 4)->nullable();
            $table->boolean('is_abnormal')->default(false);
            $table->boolean('is_critical')->default(false);
            $table->boolean('print_selected')->default(true);
            $table->boolean('was_auto_filled')->default(false);
            $table->boolean('was_manually_edited')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('interpretation')->nullable();
            $table->timestamps();

            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
            $table->foreign('report_test_id')->references('id')->on('report_tests')->cascadeOnDelete();
        });

        // Amendment history – never lose a finalized version
        Schema::create('report_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_id')->index();
            $table->unsignedInteger('revision_number');
            $table->string('reason')->nullable();
            $table->json('full_snapshot'); // complete report + results at that moment
            $table->string('created_by_device')->nullable();
            $table->string('created_by_user')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
            $table->unique(['report_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_revisions');
        Schema::dropIfExists('report_results');
        Schema::dropIfExists('report_tests');
    }
};
