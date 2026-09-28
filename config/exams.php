<?php

return [

    /*
    | API keys in the form "actor:token,actor2:token2". The actor name is
    | recorded on every write (uploaded_by, updated_by, triggered_by).
    | When empty, authentication is disabled (local development / tests).
    */
    'api_keys' => env('API_KEYS', ''),

    'imports' => [
        'disk' => env('IMPORTS_DISK', 'local'),
        'max_file_kb' => (int) env('IMPORT_MAX_FILE_KB', 102400),    // 100 MB
        'max_rows' => (int) env('IMPORT_MAX_ROWS', 2_000_000),
        'chunk_size' => (int) env('IMPORT_CHUNK_SIZE', 1000),
        'queue' => env('IMPORT_QUEUE', 'default'),
    ],

    'results' => [
        'students_per_chunk' => (int) env('RESULTS_STUDENTS_PER_CHUNK', 500),
        'queue' => env('RESULTS_QUEUE', 'default'),
    ],

    'idempotency' => [
        'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
    ],

    /*
    | Grade scale on the weighted course percentage, evaluated top-down.
    | A course that fails a pass rule is always graded F regardless of score.
    */
    'grade_scale' => [
        ['min' => 90, 'grade' => 'O',  'point' => 10],
        ['min' => 80, 'grade' => 'A+', 'point' => 9],
        ['min' => 70, 'grade' => 'A',  'point' => 8],
        ['min' => 60, 'grade' => 'B+', 'point' => 7],
        ['min' => 50, 'grade' => 'B',  'point' => 6],
        ['min' => 45, 'grade' => 'C',  'point' => 5],
        ['min' => 40, 'grade' => 'P',  'point' => 4],
        ['min' => 0,  'grade' => 'F',  'point' => 0],
    ],
];
