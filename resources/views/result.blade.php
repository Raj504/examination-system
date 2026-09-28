@extends('layout')
@section('title', 'Result')

@section('content')
    @if (!empty($backUrl))
        <p><a href="{{ $backUrl }}">← Back</a></p>
    @endif
    <div class="box">
        <h1>{{ $result['student']['name'] }} <span class="muted">({{ $result['student']['registration_no'] }})</span></h1>
        <p>{{ $result['examination']['name'] }} ({{ $result['examination']['code'] }})</p>
        <p>
            Result: <strong>{{ strtoupper($result['status']) }}</strong> ·
            SGPA: <strong>{{ $result['sgpa'] ?? '—' }}</strong> ·
            Credits: {{ $result['credits_earned'] }} / {{ $result['credits_attempted'] }}
        </p>
        <table>
            <tr><th>Course</th><th>Credits</th><th>Percentage</th><th>Grade</th><th>Status</th><th>Notes</th></tr>
            @foreach ($result['courses'] as $course)
                <tr>
                    <td>{{ $course['code'] }} — {{ $course['title'] }}</td>
                    <td>{{ $course['credits'] }}</td>
                    <td>{{ $course['percentage'] ?? '—' }}</td>
                    <td>{{ $course['grade'] ?? '—' }}</td>
                    <td>{{ $course['status'] }}</td>
                    <td class="muted">{{ implode(', ', $course['remarks']) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endsection
