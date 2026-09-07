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
            line-height: 1.2;
            color: #111827;
            background: #ffffff;
            white-space: pre-wrap;
            tab-size: 8;
        }
        h1 { font-size: 24pt; line-height: 1.2; margin: 0.5em 0 0.3em; }
        h2 { font-size: 18pt; line-height: 1.22; margin: 0.45em 0 0.25em; }
        h3 { font-size: 14pt; line-height: 1.25; margin: 0.4em 0 0.2em; }
        p, li { margin: 0; }
        img { max-width: 100%; height: auto; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; table-layout: fixed; }
        td, th { border: 1px solid #94a3b8; padding: 0.45rem; vertical-align: top; }
        th { background: #f1f5f9; font-weight: 700; }
        ul, ol { padding-left: 1.5rem; margin: 0; }
        ul[data-type='taskList'] { list-style: none; padding-left: 0.25rem; }
        ul[data-type='taskList'] li { display: flex; align-items: flex-start; gap: 0.5rem; }
        ul[data-type='taskList'] li > div { flex: 1; }
        blockquote { margin: 0.6em 0; border-left: 3px solid #cbd5e1; padding-left: 1rem; color: #475569; }
        code { border-radius: 0.25rem; background: #f1f5f9; padding: 0.1em 0.35em; font-family: ui-monospace, Menlo, monospace; font-size: 0.85em; }
        pre { margin: 0.75em 0; overflow-x: auto; border-radius: 0.4rem; background: #0f172a; padding: 0.85rem 1rem; white-space: pre-wrap; color: #e2e8f0; }
        pre code { background: transparent; padding: 0; color: inherit; }
        ol[type='a'] { list-style-type: lower-alpha; }
        ol[type='A'] { list-style-type: upper-alpha; }
        ol[type='i'] { list-style-type: lower-roman; }
        ol[type='I'] { list-style-type: upper-roman; }
        .word-tab { display: inline-block; min-width: 2.5em; }
        .word-page-break { page-break-before: always; break-before: page; }
    </style>
</head>
<body>
    {!! $content !!}
</body>
</html>
