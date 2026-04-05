@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <h3 class="mb-1">Distribution History — {{ $nation->nation_name }}</h3>
                    <p class="text-secondary mb-0">Last 30 distribution cycles.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <a href="{{ route('admin.growth-circles.index') }}" class="btn btn-outline-secondary mb-3">← Back</a>

            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Cities</th>
                                <th>Food Before</th>
                                <th>Food Sent</th>
                                <th>Uranium Before</th>
                                <th>Uranium Sent</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($distributions as $dist)
                                <tr>
                                    <td>{{ $dist->created_at->diffForHumans() }}</td>
                                    <td>{{ $dist->city_count }}</td>
                                    <td>{{ number_format($dist->food_level_before) }}</td>
                                    <td>{{ number_format($dist->food_sent) }}</td>
                                    <td>{{ number_format($dist->uranium_level_before, 1) }}</td>
                                    <td>{{ number_format($dist->uranium_sent, 1) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No distribution records.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
