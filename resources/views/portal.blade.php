@extends('layout')
@section('title', 'Student result')

@section('content')
    <h1>Student result lookup</h1>
    <div class="box">
        <p class="muted">Results can be seen only after the examination is published.</p>
        <form method="GET" action="{{ route('portal') }}" class="row">
            <label>Examination code <input name="exam" value="{{ request('exam') }}" placeholder="SEM1-2026" required></label>
            <label>Registration no <input name="reg" value="{{ request('reg') }}" placeholder="S001" required></label>
            <button>Show result</button>
        </form>
        @if ($notFound)
            <div class="error">No published result found for these details.</div>
        @endif
    </div>
@endsection
