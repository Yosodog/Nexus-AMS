@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <h3 class="mb-1">Growth Circles</h3>
                    <p class="text-secondary mb-0">Enrolled members, distribution status, and abuse flags.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            @if (session('alert-message'))
                <div class="alert alert-{{ session('alert-type') === 'success' ? 'success' : 'danger' }} alert-dismissible">
                    {{ session('alert-message') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="card shadow-sm">
                <div class="card-header">Enrolled Nations</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nation</th>
                                <th>Cities</th>
                                <th>Enrolled</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($enrollments as $enrollment)
                                <tr class="{{ $enrollment->suspended ? 'table-warning' : '' }}">
                                    <td>{{ $enrollment->nation?->nation_name ?? '—' }}</td>
                                    <td>{{ $enrollment->nation?->num_cities ?? '—' }}</td>
                                    <td>{{ $enrollment->enrolled_at->diffForHumans() }}</td>
                                    <td>
                                        @if ($enrollment->suspended)
                                            <span class="badge text-bg-warning">Suspended</span>
                                            <small class="text-muted d-block">{{ $enrollment->suspended_reason }}</small>
                                        @else
                                            <span class="badge text-bg-success">Active</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="{{ route('admin.growth-circles.distributions', $enrollment->nation) }}"
                                               class="btn btn-sm btn-outline-secondary">History</a>

                                            @can('manage-growth-circles')
                                                @if ($enrollment->suspended)
                                                    <form method="POST"
                                                          action="{{ route('admin.growth-circles.clear-suspension', $enrollment) }}">
                                                        @csrf
                                                        <button class="btn btn-sm btn-outline-success"
                                                                onclick="return confirm('Clear suspension for this nation?')">
                                                            Clear Suspension
                                                        </button>
                                                    </form>
                                                @endif

                                                <form method="POST"
                                                      action="{{ route('admin.growth-circles.remove', $enrollment->nation) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Remove this nation from Growth Circles? Their previous tax bracket will be restored.')">
                                                        Remove
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No nations enrolled.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($enrollments->hasPages())
                    <div class="card-footer">{{ $enrollments->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
