<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', env("APP_NAME") . ' - Admin')</title>

    <!-- Fonts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css"
          crossorigin="anonymous">

    <!-- Third Party Plugins -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.2.2/css/dataTables.dataTables.css"/>

    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-beta3/dist/css/adminlte.min.css"
          crossorigin="anonymous">

    <style>
        .nav-link.disabled {
            color: rgba(255, 255, 255, 0.4) !important;
            pointer-events: none;
        }
    </style>

    @stack('styles')
</head>
<body class="sidebar-expand-lg bg-body-tertiary">

<div class="app-wrapper">
    @include('admin.components.navbar')
    @include('admin.components.sidebar')

    <main class="app-main">
        <div class="app-content">
            <div class="container-fluid">
                @if (session('alert-message') || $errors->any())
                    <x-admin.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}"/>
                @endif
                @yield('content')
            </div>
        </div>
    </main>

    @include('admin.components.footer')
</div>

<!-- Required Scripts -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-beta3/dist/js/adminlte.min.js"
        crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.slim.min.js"
        integrity="sha256-kmHvs0B+OpCW5GVHUNjv9rOmY0IvSIRcf7zGUDTDQM8=" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/2.2.2/js/dataTables.js"></script>

@stack('modals')
@stack('scripts')
@yield("scripts")
</body>
</html>
