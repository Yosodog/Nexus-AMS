@php use Carbon\Carbon; @endphp
<nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block"><a href="{{ route("home") }}" class="nav-link">Home</a></li>
        </ul>
        <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img src="{{ Auth::user()->nation->flag }}" class="user-image rounded-circle shadow"
                         alt="User Image" style="object-fit: cover;">
                    <span class="d-none d-md-inline">{{ Auth::user()->name }}</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <!--begin::User Image-->
                    <li class="user-header text-bg-primary">
                        <img src="{{ Auth::user()->nation->flag }}" class="rounded-circle shadow" alt="User Image"
                             style="object-fit: cover;">
                        <p>
                            {{ Auth::user()->name }} - Admin
                            <small>Member
                                since {{ Carbon::now()->subDays(Auth::user()->nation->alliance_seniority)->toFormattedDateString() }}</small>
                        </p>
                    </li>
                    <!--end::User Image-->
                    <!--begin::Menu Body-->
                    <li class="user-body">
                        <!--begin::Row-->
                        <div class="row">
                            <div class="col-4 text-center"><a href="#">Followers</a></div>
                            <div class="col-4 text-center"><a href="#">Sales</a></div>
                            <div class="col-4 text-center"><a href="#">Friends</a></div>
                        </div>
                        <!--end::Row-->
                    </li>
                    <!--end::Menu Body-->
                    <!--begin::Menu Footer-->
                    <li class="user-footer">
                        <a href="#" class="btn btn-default btn-flat">Profile</a>
                        <a class="btn btn-default btn-flat float-end"
                           onclick="event.preventDefault(); document.getElementById('admin-logout-form').submit();">Logout</a>
                    </li>
                    <!--end::Menu Footer-->
                </ul>
            </li>
        </ul>
    </div>
</nav>

<form id="admin-logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
    @csrf
</form>
