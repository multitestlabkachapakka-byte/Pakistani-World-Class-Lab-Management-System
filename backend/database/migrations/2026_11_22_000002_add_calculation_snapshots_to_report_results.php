<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A report keeps the short name and the formula of every parameter as they were when it was created,
// so calculated results (e.g. LDL) stay reproducible even if the catalog changes later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_results', function (Blueprint $table) {
            $table->string('short_name_snapshot', 50)->nullable();
            $table->string('calculation_formula_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_results', function (Blueprint $table) {
            $table->dropColumn(['short_name_snapshot', 'calculation_formula_snapshot']);
        });
    }
};
