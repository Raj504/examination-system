@extends('layout')
@section('title', 'Examinations')

@section('content')
    <h1>Examinations</h1>

    <div class="box">
        <h2>New examination</h2>
        <form method="POST" action="{{ route('exams.store') }}" class="row">
            @csrf
            <label>Code <input name="code" value="{{ old('code') }}" placeholder="SEM1-2026" required></label>
            <label>Name <input name="name" value="{{ old('name') }}" placeholder="Semester 1" required></label>
            <label>Academic year
                <select name="academic_year">
                    @foreach ($academicYears as $year)
                        <option value="{{ $year }}" @selected(old('academic_year', $currentAcademicYear) === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </label>
            <button>Create</button>
        </form>
    </div>

    <div class="box">
        <table>
            <tr><th>Code</th><th>Name</th><th>Year</th><th>Status</th></tr>
            @forelse ($exams as $exam)
                <tr>
                    <td><a href="{{ route('exams.show', $exam) }}">{{ $exam->code }}</a></td>
                    <td>{{ $exam->name }}</td>
                    <td>{{ $exam->academic_year }}</td>
                    <td><span class="status">{{ str_replace('_', ' ', $exam->status) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No examinations yet.</td></tr>
            @endforelse
        </table>
    </div>
@endsection
