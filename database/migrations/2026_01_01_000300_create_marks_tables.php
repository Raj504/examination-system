<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks and the bulk-import pipeline that feeds them.
 *
 * `marks` is the largest table in the system (students x courses x components
 * per examination). The (enrollment_id, assessment_component_id) unique key
 * is what makes every write path - API upsert, CSV chunk retry - idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mark_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('file_path');
            $table->char('file_hash', 64);
            $table->json('column_map')->nullable(); // header name => column index
            // pending -> splitting -> processing -> completed | completed_with_errors | failed
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('batch_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Re-uploading the same file for the same examination is detected
            // and answered with the existing import (content-level idempotency).
            $table->unique(['examination_id', 'file_hash']);
            $table->index(['examination_id', 'status']);
        });

        // The chunk plan written by the splitter, one row per chunk. A chunk job
        // only carries (import id, chunk index); it reads its byte range from
        // here. Totals are derived from these rows, so a retried chunk
        // overwrites its own stats instead of double-counting.
        Schema::create('mark_import_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('mark_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('status', 16)->default('pending'); // pending | completed | failed
            $table->unsignedBigInteger('byte_offset');
            $table->unsignedInteger('record_count');       // physical CSV records to read
            $table->unsignedInteger('first_row_number');   // CSV row number of first record (header = 1)
            $table->json('skip_rows')->nullable();         // rows rejected by the splitter (duplicates)
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['mark_import_id', 'chunk_index']);
        });

        Schema::create('mark_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('mark_import_id')->constrained()->cascadeOnDelete();
            // -1 = errors found by the splitter pass (e.g. duplicate keys).
            $table->integer('chunk_index');
            $table->unsignedInteger('row_number');
            $table->text('raw');
            $table->json('errors');

            $table->index(['mark_import_id', 'row_number']);
            $table->index(['mark_import_id', 'chunk_index']);
        });

        Schema::create('marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('marks_obtained', 6, 2)->nullable(); // null when absent
            $table->boolean('is_absent')->default(false);
            // Optimistic-concurrency token for interactive edits.
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 8); // api | csv
            $table->uuid('mark_import_id')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'assessment_component_id']);
            $table->index('examination_id');
        });

        // Append-only history of interactive (API) mark changes.
        Schema::create('mark_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mark_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_marks', 6, 2)->nullable();
            $table->boolean('old_is_absent')->nullable();
            $table->decimal('new_marks', 6, 2)->nullable();
            $table->boolean('new_is_absent');
            $table->unsignedInteger('new_version');
            $table->string('changed_by')->nullable();
            $table->timestamp('created_at');

            $table->index('mark_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_audits');
        Schema::dropIfExists('marks');
        Schema::dropIfExists('mark_import_errors');
        Schema::dropIfExists('mark_import_chunks');
        Schema::dropIfExists('mark_imports');
    }
};
