<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 22mm 20mm;
            font-family: Arial, sans-serif;
            font-size: 12pt;
            line-height: 1.55;
            color: #111827;
            background: #ffffff;
        }
        h1 { font-size: 24pt; line-height: 1.2; margin: 0.7em 0 0.35em; }
        h2 { font-size: 18pt; line-height: 1.25; margin: 0.65em 0 0.35em; }
        h3 { font-size: 14pt; line-height: 1.3; margin: 0.6em 0 0.3em; }
        p { margin: 0 0 0.7em; }
        img { max-width: 100%; height: auto; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; table-layout: fixed; }
        td, th { border: 1px solid #94a3b8; padding: 0.45rem; vertical-align: top; }
        th { background: #f1f5f9; font-weight: 700; }
        ul, ol { padding-left: 1.5rem; margin: 0.6em 0; }
        .word-tab { display: inline-block; min-width: 2.5em; }
        .word-page-break { page-break-before: always; break-before: page; }
    </style>
</head>
<body>
    {!! $content !!}
</body>
</html>
