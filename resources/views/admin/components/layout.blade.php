<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - @yield('title', 'Nexus AMS')</title>
    <link rel="stylesheet" href="/build/app.css">
</head>
<body>
    @include('admin.components.navbar')
    <div class="container-fluid mt-4">
        @yield('content')
    </div>
    @include('admin.components.footer')
    <script src="/build/app.js"></script>
</body>
</html>
