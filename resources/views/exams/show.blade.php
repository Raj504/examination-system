@extends('layout')
@section('title', $exam->code)

@section('content')
    {{-- Header: status, what to do next, and the buttons for the next step --}}
    <div class="box">
        <h1>{{ $exam->code }} <span class="muted">· {{ $exam->name }} · {{ $exam->academic_year }}</span></h1>
        <p>Status: <span class="status">{{ str_replace('_', ' ', $exam->status) }}</span></p>
        <p><strong>What to do next:</strong> {{ $nextStep }}</p>
        @foreach ($actions as $action => $label)
            <form method="POST" action="{{ route('exams.status', $exam) }}" class="inline"
                  @if ($action === 'publish') onsubmit="return confirm('Publish results? Students will see them and they become final.')" @endif>
                @csrf
                <input type="hidden" name="action" value="{{ $action }}">
                <button>{{ $label }}</button>
            </form>
        @endforeach
    </div>

    {{-- 1. Courses --}}
    <div class="box">
        <h2>1. Courses</h2>
        <table>
            <tr><th>Course</th><th>Credits</th><th>Pass %</th><th>Components</th><th>Students</th></tr>
            @forelse ($courses as $course)
                <tr>
                    <td>{{ $course['code'] }}</td>
                    <td>{{ $course['credits'] }}</td>
                    <td>{{ $course['pass_percentage'] }}</td>
                    <td>
                        @foreach ($course['components'] as $component)
                            <div>{{ $component['code'] }} — out of {{ $component['max'] / 100 }}, worth {{ $component['weight'] }}%</div>
                        @endforeach
                    </td>
                    <td>{{ $enrolledCounts[$course['id']] ?? 0 }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No courses yet.</td></tr>
            @endforelse
        </table>

        @if ($exam->isStructureEditable())
            <h2 style="margin-top:20px">Add a course</h2>
            @if ($catalogCourses->isEmpty())
                <p class="muted">No courses exist yet. Create one on the <a href="{{ route('setup') }}">Programmes, courses &amp; students</a> page first.</p>
            @else
                <form method="POST" action="{{ route('exams.courses', $exam) }}">
                    @csrf
                    <div class="row">
                        <label>Course
                            <select name="course_code">
                                @foreach ($catalogCourses as $c)
                                    <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->title }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Pass mark (%) <input name="pass_percentage" type="number" step="0.01" min="0" max="100" value="{{ old('pass_percentage', 40) }}" required></label>
                    </div>

                    <p class="muted">Components (for example a mid-term and an end-term exam). Fill in as many rows as you need; empty rows are ignored. The weights must add up to 100.</p>
                    <table style="max-width:600px">
                        <tr><th>Code</th><th>Name (optional)</th><th>Marked out of</th><th>Weight %</th></tr>
                        @php $defaults = [['MID', 'Mid term', 30, 30], ['END', 'End term', 70, 70], ['', '', '', ''], ['', '', '', '']]; @endphp
                        @foreach ($defaults as $i => [$code, $name, $max, $weight])
                            <tr>
                                <td><input name="components[{{ $i }}][code]" value="{{ old("components.$i.code", $code) }}" size="8" placeholder="LAB"></td>
                                <td><input name="components[{{ $i }}][name]" value="{{ old("components.$i.name", $name) }}"></td>
                                <td><input name="components[{{ $i }}][max_marks]" value="{{ old("components.$i.max_marks", $max) }}" type="number" step="0.01" min="0" style="width:90px"></td>
                                <td><input name="components[{{ $i }}][weight]" value="{{ old("components.$i.weight", $weight) }}" type="number" step="0.01" min="0" max="100" style="width:80px"></td>
                            </tr>
                        @endforeach
                    </table>
                    <p><button>Save course</button></p>
                </form>
            @endif
        @endif
    </div>

    {{-- 2. Students --}}
    <div class="box">
        <h2>2. Enroll students</h2>
        @if (! $exam->acceptsEnrollments())
            <p class="muted">Enrollment is closed once marks are locked.</p>
        @elseif (! $courses)
            <p class="muted">Add a course first (section 1).</p>
        @else
            <form method="POST" action="{{ route('exams.enroll', $exam) }}">
                @csrf
                <div class="row">
                    <label>Course
                        <select name="course_code">
                            @foreach ($courses as $course)
                                <option>{{ $course['code'] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <p class="muted">Type registration numbers separated by spaces or new lines. The students must already exist
                    (add them on the <a href="{{ route('setup') }}">Programmes, courses &amp; students</a> page).</p>
                <textarea name="registration_nos" placeholder="e.g. S001 S002 S003"></textarea>
                <p><button>Enroll</button></p>
            </form>
        @endif
    </div>

    {{-- 3. Marks --}}
    @if ($exam->status !== \App\Models\Examination::DRAFT)
        <div class="box">
            <h2>3. Marks</h2>

            @if ($exam->acceptsMarks())
                <h2 style="font-size:14px">Enter one mark</h2>
                <form method="POST" action="{{ route('exams.marks.store', $exam) }}" class="row">
                    @csrf
                    <label>Registration no <input name="registration_no" value="{{ old('registration_no') }}" required></label>
                    <label>Course and component
                        <select name="component">
                            @foreach ($courses as $course)
                                @foreach ($course['components'] as $component)
                                    <option value="{{ $course['code'] }}|{{ $component['code'] }}">{{ $course['code'] }} — {{ $component['code'] }} (out of {{ $component['max'] / 100 }})</option>
                                @endforeach
                            @endforeach
                        </select>
                    </label>
                    <label>Marks (type AB if absent) <input name="marks" size="10" required></label>
                    <button>Save mark</button>
                </form>
                <p class="muted">To change a mark that is already saved, click <strong>Edit</strong> in the table below.</p>

                <h2 style="font-size:14px;margin-top:20px">Or upload many marks from a CSV file</h2>
                <form method="POST" action="{{ route('exams.imports.store', $exam) }}" enctype="multipart/form-data" class="row">
                    @csrf
                    <input type="file" name="file" accept=".csv,text/csv" required>
                    <button>Upload</button>
                </form>
                <p class="muted">The first line must be <code>registration_no,course_code,component_code,marks</code>, e.g. <code>S001,CS101,MID,24.5</code>. Use <code>AB</code> for absent.</p>
            @else
                <p class="muted">Marks can only be changed while the status is "marks entry".</p>
            @endif

            @if ($imports->isNotEmpty())
                <h2 style="font-size:14px;margin-top:20px">Uploaded files</h2>
                <table>
                    <tr><th>File</th><th>Status</th><th>Rows</th><th>Saved</th><th>Rejected</th><th></th></tr>
                    @foreach ($imports as $import)
                        <tr>
                            <td>{{ $import->original_filename }}</td>
                            <td><span class="status">{{ str_replace('_', ' ', $import->status) }}</span>
                                @if (in_array($import->status, \App\Models\MarkImport::ACTIVE_STATUSES, true) && $import->created_at->lt(now()->subMinute()))
                                    <div class="muted">Not moving? Start the background worker: <code>php artisan queue:work</code></div>
                                @endif
                                @if ($import->failure_reason)<div class="muted">{{ $import->failure_reason }}</div>@endif</td>
                            <td>{{ number_format($import->total_rows) }}</td>
                            <td>{{ number_format($import->success_rows) }}</td>
                            <td>{{ number_format($import->failed_rows) }}</td>
                            <td>@if ($import->failed_rows)<a href="{{ route('exams.imports.errors', [$exam, $import]) }}">See rejected rows</a>@endif</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            <h2 style="font-size:14px;margin-top:20px">Saved marks</h2>
            <form method="GET" action="{{ route('exams.show', $exam) }}" class="row">
                <label>Find a student <input name="student" value="{{ request('student') }}" placeholder="Registration no"></label>
                <button class="secondary">Search</button>
                @if (request('student'))<a href="{{ route('exams.show', $exam) }}">Show all</a>@endif
            </form>
            <table>
                <tr><th>Student</th><th>Course</th><th>Component</th><th>Marks</th><th></th></tr>
                @forelse ($marks as $mark)
                    <tr>
                        <td>{{ $mark->registration_no }}</td>
                        <td>{{ $mark->course_code }}</td>
                        <td>{{ $mark->component_code }}</td>
                        <td>{{ $mark->is_absent ? 'AB (absent)' : $mark->marks_obtained }}</td>
                        <td>@if ($exam->acceptsMarks())<a href="{{ route('exams.marks.edit', [$exam, $mark->id]) }}">Edit</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No marks yet.</td></tr>
                @endforelse
            </table>
            {{ $marks->links() }}
        </div>
    @endif

    {{-- 4. Results --}}
    @if ($latestRun)
        <div class="box">
            <h2>4. Results</h2>
            @if ($latestRun->status === 'processing')
                <p>Calculating results… this page refreshes by itself.</p>
                <p class="muted">Taking more than a minute? The background worker is probably not running.
                    Start it in a terminal with <code>php artisan queue:work</code>.</p>
            @elseif ($latestRun->status === 'failed')
                <p class="error">The last calculation failed. Click "Process results" to try again.</p>
            @elseif ($latestRun->summary)
                <p>
                    Passed: <strong>{{ $latestRun->summary['students']['pass'] ?? 0 }}</strong> ·
                    Failed: <strong>{{ $latestRun->summary['students']['fail'] ?? 0 }}</strong> ·
                    Withheld (marks missing): <strong>{{ $latestRun->summary['students']['withheld'] ?? 0 }}</strong>
                </p>
            @endif

            @if ($results)
                <table>
                    <tr><th>Student</th><th>Name</th><th>SGPA</th><th>Credits earned</th><th>Result</th></tr>
                    @foreach ($results as $result)
                        <tr>
                            <td><a href="{{ route('exams.result', [$exam, $result->registration_no]) }}">{{ $result->registration_no }}</a></td>
                            <td>{{ $result->name }}</td>
                            <td>{{ $result->sgpa ?? '—' }}</td>
                            <td>{{ $result->credits_earned }} / {{ $result->credits_attempted }}</td>
                            <td>{{ $result->status }}</td>
                        </tr>
                    @endforeach
                </table>
                {{ $results->links() }}
                <p class="muted">Click a registration number to see that student's marks per course.</p>
            @endif
        </div>
    @endif
@endsection
