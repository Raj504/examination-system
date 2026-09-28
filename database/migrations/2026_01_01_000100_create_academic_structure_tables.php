<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data: programmes, students and the course catalogue.
 *
 * These tables are "slow-changing" reference data. Natural keys
 * (programme code, registration number, course code) carry unique indexes
 * because every external interface (CSV, API) addresses rows by them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained()->restrictOnDelete();
            $table->string('registration_no', 32)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->index('programme_id');
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('title');
            $table->unsignedTinyInteger('credits');
            $table->timestamps();

            $table->index('programme_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
        Schema::dropIfExists('students');
        Schema::dropIfExists('programmes');
    }
};
