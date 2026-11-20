<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mr_number')->unique()->index();
            $table->string('name');
            $table->string('guardian_name')->nullable();
            $table->string('guardian_relation')->nullable(); // S/O, D/O, W/O, F/O
            $table->date('date_of_birth')->nullable();
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->unsignedSmallInteger('age_days')->nullable();
            $table->enum('gender', ['male', 'female', 'other']);
            $table->string('phone', 20)->index(); // normalized +923...
            $table->string('phone_raw')->nullable();
            $table->string('cnic', 15)->nullable()->index(); // 12345-1234567-1
            $table->string('whatsapp', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address');
            $table->string('city')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('updated_by_device')->nullable();
            $table->unsignedBigInteger('rev')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['name', 'phone']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
