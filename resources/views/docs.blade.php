<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>API docs · Exam Results</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
</head>
<body>
<p style="font-family: system-ui, sans-serif; margin: 12px 20px;"><a href="{{ route('exams.index') }}">← Back to the app</a></p>
<div id="swagger"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
<script>
    // Swagger UI: interactive documentation of the JSON API described in public/openapi.yaml.
    SwaggerUIBundle({ url: '/openapi.yaml', dom_id: '#swagger' });
</script>
</body>
</html>
