@extends('layout')
@section('title', 'Rejected rows')

@section('content')
    <h1>Rejected rows — {{ $import->original_filename }}</h1>
    <p><a href="{{ route('exams.show', $exam) }}">← Back to {{ $exam->code }}</a></p>
    <div class="box">
        <table>
            <tr><th>Row</th><th>Problem</th><th>Line in the file</th></tr>
            @foreach ($rejectedRows as $row)
                <tr>
                    <td>{{ $row->row_number }}</td>
                    <td>{{ implode('; ', $row->errors) }}</td>
                    <td><code>{{ $row->raw }}</code></td>
                </tr>
            @endforeach
        </table>
        {{ $rejectedRows->links() }}
    </div>
@endsection
