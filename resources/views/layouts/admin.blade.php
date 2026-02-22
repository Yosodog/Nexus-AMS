<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name').' - Admin')</title>
    <link rel="icon" href="{{ $faviconUrl }}">
    <x-theme-script />

    <!-- Fonts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css"
        crossorigin="anonymous">

    <!-- Third Party Plugins -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.2.2/css/dataTables.dataTables.css" />

    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-beta3/dist/css/adminlte.min.css"
        crossorigin="anonymous">

    <style>
        .nav-link.disabled {
            color: rgba(255, 255, 255, 0.4) !important;
            pointer-events: none;
        }

        .app-sidebar .sidebar-wrapper {
            max-height: none;
            overflow: visible;
        }

        .app-content .container-fluid {
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }

        @media (max-width: 767.98px) {
            .app-content .container-fluid {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }

            .app-sidebar {
                max-width: min(280px, 85vw);
                height: 100dvh;
            }

            .app-sidebar .sidebar-wrapper {
                max-height: calc(100dvh - 57px);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            .navbar-nav .dropdown-menu {
                max-width: calc(100vw - 1rem);
            }

            .navbar-nav .user-menu .dropdown-menu {
                min-width: 0;
                width: calc(100vw - 1rem);
            }

            .navbar-nav .user-menu .user-header {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }

            .card-header.d-flex {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .table-responsive,
            .dataTables_wrapper,
            .card-body {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            table.table,
            table.dataTable {
                width: 100%;
            }

            table.table th,
            table.table td,
            table.dataTable th,
            table.dataTable td {
                white-space: nowrap;
            }

            .modal {
                padding: 0 !important;
            }

            .modal-open .modal {
                overflow-y: auto;
            }

            .modal-dialog-scrollable {
                height: 100%;
                margin: 0;
            }

            .modal-dialog-scrollable .modal-content {
                height: 100%;
                border-radius: 0;
            }

            .modal-dialog-scrollable .modal-body {
                -webkit-overflow-scrolling: touch;
                touch-action: pan-y;
            }

            .modal-fullscreen-sm-down .modal-dialog {
                margin: 0;
            }

            .modal-fullscreen-sm-down .modal-content {
                height: 100dvh;
            }

            .modal-fullscreen-sm-down .modal-body {
                max-height: calc(100dvh - 9rem);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            .modal-fullscreen-sm-down .modal-footer {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter {
                float: none;
                text-align: left;
                width: 100%;
            }

            .dataTables_wrapper .dataTables_filter input,
            .dataTables_wrapper .dataTables_length select {
                width: 100%;
                margin-left: 0;
                margin-top: 0.5rem;
            }

            .dataTables_wrapper .dataTables_paginate {
                float: none;
                text-align: center;
                margin-top: 0.75rem;
            }
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
                        <x-admin.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}" />
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>
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
