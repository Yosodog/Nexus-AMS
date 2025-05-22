@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">Users</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">All Users</div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>Username</th>
                    <th>Discord</th>
                    <th>Nation ID</th>
                    <th>In Alliance</th>
                    <th>Is Admin</th>
                    <th>Last Active</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>
                            {{ $user->name }}
                            @if($user->last_active_at && now()->diffInMinutes($user->last_active_at) <= 5)
                                <span class="badge bg-success ms-1">Online</span>
                            @endif
                        </td>
                        <td>{{ $user->nation->discord ?? '—' }}</td>
                        <td><a href="https://politicsandwar.com/nation/id={{ $user->nation_id }}" target="_blank">{{ $user->nation_id }}</a></td>
                        <td>
                            @if($user->nation && $user->nation->alliance_id === (int) env("PW_ALLIANCE_ID"))
                                <span class="badge bg-primary">Yes</span>
                            @else
                                <span class="badge bg-secondary">No</span>
                            @endif
                        </td>
                        <td>
                            @if($user->is_admin)
                                <i class="bi bi-check-circle-fill text-success"></i>
                            @else
                                <i class="bi bi-x-circle-fill text-danger"></i>
                            @endif
                        </td>
                        <td>
                            {{ $user->last_active_at ? $user->last_active_at->diffForHumans() : 'Never' }}
                        </td>
                        <td>
                            <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-sm btn-outline-primary">
                                Edit
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="mt-3">
                {{ $users->links() }}
            </div>
        </div>
    </div>
@endsection