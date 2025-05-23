@can('view-dd')
    <div class="mt-5">
        {{-- DD Settings --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Direct Deposit Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.dd.settings') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Direct Deposit Tax ID</label>
                            <input type="number" class="form-control"
                                   name="direct_deposit_tax_id"
                                   value="{{ old('direct_deposit_tax_id', $ddTaxId) }}"
                                   @cannot('manage-dd') disabled @endcannot required>
                            <div class="form-text">
                                This is the <strong>in-game tax bracket ID</strong> that members must be assigned to for Direct Deposit.
                                It must be created in-game with <strong>100% money</strong> and <strong>100% resource taxes</strong>.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Fallback Tax ID</label>
                            <input type="number" class="form-control"
                                   name="direct_deposit_fallback_tax_id"
                                   value="{{ old('direct_deposit_fallback_tax_id', $fallbackTaxId) }}"
                                   @cannot('manage-dd') disabled @endcannot required>
                            <div class="form-text">
                                If a member unenrolls from Direct Deposit and their original in-game tax bracket cannot be restored,
                                Nexus will assign them to this fallback bracket. It should be a valid in-game tax ID with standard rates.
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3"
                            @cannot('manage-dd') disabled @endcannot>
                        Save Settings
                    </button>
                </form>
            </div>
        </div>

        {{-- DD Brackets Table --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Direct Deposit Brackets</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-bordered table-striped mb-0">
                    <thead>
                    <tr>
                        <th>City Number</th>
                        @foreach (\App\Services\PWHelperService::resources() as $resource)
                            <th>{{ ucfirst($resource) }} %</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($brackets as $bracket)
                        <tr>
                            <td>{{ $bracket->city_number }}</td>
                            @foreach(\App\Services\PWHelperService::resources() as $resource)
                                <td>{{ number_format($bracket->$resource, 2) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Current Enrollments Table --}}
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Current Enrollments</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Nation ID</th>
                        <th>Account</th>
                        <th>User</th>
                        <th>Enrolled At</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($enrollments as $enrollment)
                        <tr>
                            <td>
                                <a href="https://politicsandwar.com/nation/id={{ $enrollment->nation_id }}" target="_blank">
                                    {{ $enrollment->nation_id }}
                                </a>
                            </td>
                            <td>{{ $enrollment->account->name }}</td>
                            <td>{{ optional($enrollment->account->user)->name ?? 'Deleted' }}</td>
                            <td>{{ $enrollment->enrolled_at->format('M d, Y H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endcan