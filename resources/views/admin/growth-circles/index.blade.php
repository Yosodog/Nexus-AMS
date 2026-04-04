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

            @can('manage-growth-circles')
                <div class="card shadow-sm mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Program Settings</span>
                        <span class="badge {{ $growthCirclesEnabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                            {{ $growthCirclesEnabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Configure the Growth Circles tax bracket, payout source, and abuse alert destination.
                        </p>
                        <form method="POST" action="{{ route('admin.settings.growth-circles') }}">
                            @csrf
                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="growth_circles_enabled" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="growthCirclesEnabled"
                                       name="growth_circles_enabled" value="1" @checked($growthCirclesEnabled)>
                                <label class="form-check-label" for="growthCirclesEnabled">Enable Growth Circles</label>
                            </div>
                            <div class="mb-3">
                                <label for="gcTaxId" class="form-label">P&amp;W Tax Bracket ID (100%)</label>
                                <input type="number" class="form-control @error('growth_circle_tax_id') is-invalid @enderror" id="gcTaxId"
                                       name="growth_circle_tax_id" value="{{ old('growth_circle_tax_id', $growthCircleTaxId) }}" min="0">
                                @error('growth_circle_tax_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label for="gcFallbackTaxId" class="form-label">Fallback Tax Bracket ID</label>
                                <input type="number" class="form-control @error('growth_circle_fallback_tax_id') is-invalid @enderror" id="gcFallbackTaxId"
                                       name="growth_circle_fallback_tax_id" value="{{ old('growth_circle_fallback_tax_id', $growthCircleFallbackTaxId) }}" min="0">
                                @error('growth_circle_fallback_tax_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label for="gcSourceAccount" class="form-label">Source Account</label>
                                <select class="form-select @error('growth_circle_source_account_id') is-invalid @enderror" id="gcSourceAccount" name="growth_circle_source_account_id">
                                    <option value="0">-- Select account --</option>
                                    @foreach ($sourceAccounts as $account)
                                        <option value="{{ $account->id }}" @selected((int) old('growth_circle_source_account_id', $growthCircleSourceAccountId) === $account->id)>
                                            {{ $account->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('growth_circle_source_account_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col">
                                    <label for="gcFoodPerCity" class="form-label">Food per city</label>
                                    <input type="number" class="form-control @error('growth_circle_food_per_city') is-invalid @enderror" id="gcFoodPerCity"
                                           name="growth_circle_food_per_city" value="{{ old('growth_circle_food_per_city', $growthCircleFoodPerCity) }}" min="0">
                                    @error('growth_circle_food_per_city')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col">
                                    <label for="gcUraniumPerCity" class="form-label">Uranium per city</label>
                                    <input type="number" class="form-control @error('growth_circle_uranium_per_city') is-invalid @enderror" id="gcUraniumPerCity"
                                           name="growth_circle_uranium_per_city" value="{{ old('growth_circle_uranium_per_city', $growthCircleUraniumPerCity) }}" min="0">
                                    @error('growth_circle_uranium_per_city')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="gcDiscordChannel" class="form-label">Abuse Alert Discord Channel ID</label>
                                <input type="text" class="form-control @error('growth_circle_discord_channel_id') is-invalid @enderror" id="gcDiscordChannel"
                                       name="growth_circle_discord_channel_id" value="{{ old('growth_circle_discord_channel_id', $growthCircleDiscordChannelId) }}">
                                @error('growth_circle_discord_channel_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button class="btn btn-primary">Save Growth Circles Settings</button>
                        </form>
                    </div>
                </div>
            @endcan

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
                                            @if ($enrollment->nation)
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
                                            @else
                                                <span class="text-muted small">Nation record unavailable</span>
                                            @endif
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
