<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>DistriVale - Google Drive</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fonts/inter/inter.css') }}">
    <style>
        body {
            margin: 0; height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #05070c; font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; color: #fff;
        }
        .box { text-align: center; max-width: 420px; padding: 2rem; }
        .icon { font-size: 2.6rem; color: {{ $success ? '#57d9a5' : '#ff8fa3' }}; margin-bottom: 1rem; }
        p { color: #aab4c6; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon"><i class="bi {{ $success ? 'bi-check-circle' : 'bi-exclamation-triangle' }}"></i></div>
        <p>{{ $message }}</p>
    </div>
</body>
</html>
