<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Examinations') · Exam Results</title>
    @if (!empty($autoRefresh))
        {{-- Something is running in the background: reload every 3 seconds to show progress. --}}
        <meta http-equiv="refresh" content="3">
    @endif
    <style>
        body { font-family: system-ui, "Segoe UI", Roboto, sans-serif; font-size: 14px; margin: 0; background: #f5f6f8; color: #1d2330; }
        nav { background: #fff; border-bottom: 1px solid #ddd; padding: 12px 24px; display: flex; gap: 20px; align-items: center; }
        nav strong { margin-right: 12px; }
        a { color: #2f5fd0; }
        main { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
        .box { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
        th { color: #666; font-size: 12px; }
        input, select, textarea, button { font: inherit; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { width: 100%; box-sizing: border-box; min-height: 80px; font-family: monospace; }
        button { background: #2f5fd0; color: #fff; border-color: #2f5fd0; cursor: pointer; }
        button.secondary { background: #fff; color: #2f5fd0; }
        form.inline { display: inline; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; margin-bottom: 8px; }
        .row label { display: flex; flex-direction: column; font-size: 12px; color: #666; gap: 2px; }
        .muted { color: #777; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #e8eefc; color: #2f5fd0; font-size: 12px; font-weight: 600; }
        .success { background: #e4f5ea; color: #1f6f3f; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px; }
        .error { background: #fbe6e3; color: #9b2c1f; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px; }
        code { background: #f0f0f0; padding: 1px 4px; border-radius: 3px; }
    </style>
</head>
<body>
<nav>
    <strong>🎓 Exam Results</strong>
    <a href="{{ route('exams.index') }}">Examinations</a>
    <a href="{{ route('setup') }}">Programmes, courses &amp; students</a>
    <a href="{{ route('portal') }}">Student result lookup</a>
    <a href="{{ route('docs') }}">API docs</a>
</nav>
<main>
    @if (session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
