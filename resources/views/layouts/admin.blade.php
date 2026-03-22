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

        html[data-bs-theme="dark"] .table-light,
        html[data-bs-theme="dark"] .table-light > tr > th,
        html[data-bs-theme="dark"] .table-light > tr > td,
        html[data-bs-theme="dark"] .table-light th,
        html[data-bs-theme="dark"] .table-light td {
            background-color: var(--bs-tertiary-bg);
            color: var(--bs-body-color);
            border-color: var(--bs-border-color);
        }

        html[data-bs-theme="dark"] .card .table-light th {
            background-color: var(--bs-tertiary-bg);
        }

        .dt-container .dt-layout-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem 1rem;
        }

        .dt-container .dt-layout-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .dt-container .dt-length label,
        .dt-container .dt-search label,
        .dataTables_wrapper .dataTables_length label,
        .dataTables_wrapper .dataTables_filter label {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 0;
            font-size: 0.875rem;
            color: var(--bs-secondary-color);
        }

        .dt-container .dt-length select,
        .dataTables_wrapper .dataTables_length select {
            min-width: 5rem;
            margin-right: 0.25rem;
        }

        .dt-container .dt-search input,
        .dt-container .dt-length select,
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid var(--bs-border-color);
            border-radius: 0.5rem;
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);
            padding: 0.375rem 0.75rem;
            line-height: 1.5;
        }

        .dt-container .dt-search input,
        .dataTables_wrapper .dataTables_filter input {
            min-width: min(100%, 18rem);
            margin-left: 0;
        }

        .dt-container .dt-info,
        .dataTables_wrapper .dataTables_info {
            color: var(--bs-secondary-color);
            font-size: 0.875rem;
            padding-top: 0;
        }

        .dt-container .dt-paging .dt-paging-button,
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 0.5rem !important;
            margin: 0 0.125rem;
        }

        .dt-container .dt-paging .dt-paging-button.current,
        .dt-container .dt-paging .dt-paging-button.current:hover,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--bs-primary);
            border-color: var(--bs-primary);
            color: #fff !important;
        }

        .dt-container .dt-paging .dt-paging-button:hover,
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--bs-tertiary-bg);
            border-color: var(--bs-border-color);
            color: var(--bs-body-color) !important;
        }

        .dt-container .dt-layout-row:first-child,
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            padding-bottom: 0.75rem;
        }

        .dt-container .dt-layout-row:last-child,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding-top: 0.75rem;
        }

        .dt-container .dt-layout-row:first-child {
            padding-top: 1rem;
        }

        .dt-container .dt-layout-row:last-child {
            padding-bottom: 1rem;
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
            .dataTables_wrapper .dataTables_filter,
            .dt-container .dt-length,
            .dt-container .dt-search,
            .dt-container .dt-info,
            .dt-container .dt-paging {
                float: none;
                text-align: left;
                width: 100%;
            }

            .dataTables_wrapper .dataTables_filter input,
            .dataTables_wrapper .dataTables_length select,
            .dt-container .dt-search input,
            .dt-container .dt-length select {
                width: 100%;
                margin-left: 0;
                margin-top: 0.5rem;
            }

            .dataTables_wrapper .dataTables_paginate,
            .dt-container .dt-paging {
                float: none;
                text-align: center;
                margin-top: 0.75rem;
            }

            .dt-container .dt-layout-cell {
                padding-left: 0;
                padding-right: 0;
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
    <script>
        window.initAdminDataTable = function (selector, options = {}) {
            const defaults = {
                pageLength: 25,
                scrollX: true,
                autoWidth: false,
                language: {
                    searchPlaceholder: 'Search table...',
                    search: '',
                    lengthMenu: '_MENU_ entries'
                },
                layout: {
                    topStart: 'pageLength',
                    topEnd: 'search',
                    bottomStart: 'info',
                    bottomEnd: 'paging'
                },
                columnDefs: [
                    {targets: '_all', className: 'align-middle'}
                ]
            };

            const mergedOptions = {
                ...defaults,
                ...options,
                language: {
                    ...defaults.language,
                    ...(options.language || {})
                },
                layout: options.layout === undefined ? defaults.layout : options.layout,
                columnDefs: options.columnDefs ?? defaults.columnDefs
            };

            return new DataTable(selector, mergedOptions);
        };
    </script>

    @stack('modals')
    @stack('scripts')
    @yield("scripts")
</body>

</html>
