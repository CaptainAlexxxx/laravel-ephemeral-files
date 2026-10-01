<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Laravel'))</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="bg-body-tertiary">
    <nav class="navbar navbar-expand-sm navbar-dark bg-dark border-bottom">
        <div class="container">
            <a class="navbar-brand" href="{{ route('files.create') }}">{{ config('app.name', 'Laravel') }}</a>
            <div class="navbar-nav">
                <a class="nav-link {{ request()->routeIs('files.create') ? 'active' : '' }}" href="{{ route('files.create') }}">Upload</a>
                <a class="nav-link {{ request()->routeIs('files.index') ? 'active' : '' }}" href="{{ route('files.index') }}">Files</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="col-lg-8 mx-auto">
            @yield('content')
        </div>
    </main>

    <script
        src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"
        integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs"
        crossorigin="anonymous"></script>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <script src="{{ asset('js/app.js') }}"></script>
    @stack('scripts')
</body>
</html>
