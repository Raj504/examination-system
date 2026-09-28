@extends('layout')
@section('title', 'Edit mark')

@section('content')
    <h1>Edit mark</h1>
    <div class="box">
        <p>{{ $mark->registration_no }} · {{ $mark->course_code }} · {{ $mark->component_code }}</p>
        <form method="POST" action="{{ route('exams.marks.update', [$exam, $mark->id]) }}" class="row">
            @csrf
            @method('PUT')
            {{-- The version we showed you. If someone else saves first, the version
                 no longer matches and your change is refused instead of overwriting theirs. --}}
            <input type="hidden" name="version" value="{{ $mark->version }}">
            <label>Marks (type AB if absent)
                <input name="marks" value="{{ old('marks', $mark->is_absent ? 'AB' : $mark->marks_obtained) }}" required autofocus>
            </label>
            <button>Save</button>
            <a href="{{ route('exams.show', $exam) }}">Cancel</a>
        </form>
    </div>
@endsection
