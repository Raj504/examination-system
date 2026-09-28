@extends('layout')
@section('title', 'Setup')

@section('content')
    <h1>Programmes, courses &amp; students</h1>

    <div class="box">
        <h2>Programmes</h2>
        <form method="POST" action="{{ route('setup.programme') }}" class="row">
            @csrf
            <label>Code <input name="code" value="{{ old('code') }}" placeholder="BTECH" required></label>
            <label>Name <input name="name" placeholder="Bachelor of Technology" required></label>
            <button>Save programme</button>
        </form>
        <table>
            <tr><th>Code</th><th>Name</th></tr>
            @forelse ($programmes as $programme)
                <tr><td>{{ $programme->code }}</td><td>{{ $programme->name }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">No programmes yet.</td></tr>
            @endforelse
        </table>
    </div>

    <div class="box">
        <h2>Courses</h2>
        @if ($programmes->isEmpty())
            <p class="muted">Add a programme first.</p>
        @else
            <form method="POST" action="{{ route('setup.course') }}" class="row">
                @csrf
                <label>Programme
                    <select name="programme_code">
                        @foreach ($programmes as $programme)
                            <option value="{{ $programme->code }}">{{ $programme->code }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Code <input name="code" placeholder="CS101" required></label>
                <label>Title <input name="title" placeholder="Programming" required></label>
                <label>Credits <input name="credits" type="number" min="0" max="40" value="4" required></label>
                <button>Save course</button>
            </form>
        @endif
        <table>
            <tr><th>Code</th><th>Title</th><th>Credits</th><th>Programme</th></tr>
            @forelse ($courses as $course)
                <tr><td>{{ $course->code }}</td><td>{{ $course->title }}</td><td>{{ $course->credits }}</td><td>{{ $course->programme->code }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No courses yet.</td></tr>
            @endforelse
        </table>
    </div>

    <div class="box">
        <h2>Students ({{ number_format($studentCount) }} registered)</h2>
        @if ($programmes->isEmpty())
            <p class="muted">Add a programme first.</p>
        @else
            <form method="POST" action="{{ route('setup.students') }}">
                @csrf
                <p class="muted">One student per line: <code>registration_no, name, programme_code</code></p>
                <textarea name="students" placeholder="S001, Asha Rao, BTECH">{{ old('students') }}</textarea>
                <p><button>Save students</button></p>
            </form>
        @endif
    </div>
@endsection
