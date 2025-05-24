@can('view-dd')
    <div class="mt-4">
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
        @can('view-dd')
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Direct Deposit Brackets</h5>

                        @can('manage-dd')
                            <form method="POST" action="{{ route('admin.dd.brackets.create') }}" class="d-flex align-items-center gap-2">
                                @csrf
                                <input type="number" name="city_number" class="form-control form-control-sm w-auto"
                                       min="0" required placeholder="City #" autofocus>
                                <button type="submit" class="btn btn-sm btn-success">Create Bracket</button>
                            </form>
                        @endcan
                    </div>
                </div>

                <div class="card-body">
                    @can('manage-dd')
                        <div class="alert alert-secondary small mb-3">
                            <strong>Tip:</strong> Enter new tax rates below and click "Apply to Selected" to update all checked brackets. The default bracket (City 0) cannot be deleted but can be edited.
                        </div>

                        <form method="POST" action="" id="bracket-form">
                            @csrf

                            <div class="row g-2 mb-3">
                                @foreach(array_chunk(\App\Services\PWHelperService::resources(), ceil(count(\App\Services\PWHelperService::resources()) / 2)) as $chunk)
                                    <div class="col-md-6">
                                        <div class="row g-2">
                                            @foreach($chunk as $resource)
                                                <div class="col-md-4">
                                                    <label class="form-label">{{ ucfirst($resource) }} %</label>
                                                    <input type="number" name="rates[{{ $resource }}]" class="form-control form-control-sm w-100"
                                                           min="0" max="100" step="0.01" placeholder="e.g. 10">
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach

                                <div class="col-md-12 mt-2 d-flex gap-2">
                                    <button type="submit"
                                            formaction="{{ route('admin.dd.brackets.update') }}"
                                            class="btn btn-primary btn-sm">
                                        Apply to Selected
                                    </button>

                                    <button type="submit"
                                            formaction="{{ route('admin.dd.brackets.delete') }}"
                                            onclick="return confirm('Are you sure you want to delete the selected brackets?');"
                                            class="btn btn-danger btn-sm">
                                        Delete Selected
                                    </button>
                                </div>
                            </div>
                            @endcan

                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead class="table-light">
                                    <tr>
                                        @can('manage-dd')
                                            <th style="width: 30px;"><input type="checkbox" id="check-all"></th>
                                        @endcan
                                        <th>City Number</th>
                                        @foreach(\App\Services\PWHelperService::resources() as $resource)
                                            <th>{{ ucfirst($resource) }} %</th>
                                        @endforeach
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($brackets as $bracket)
                                        <tr @if($bracket->city_number === 0) class="table-info" @endif>
                                            @can('manage-dd')
                                                <td>
                                                    <input type="checkbox"
                                                           name="selected[]"
                                                           value="{{ $bracket->id }}"
                                                           class="bracket-checkbox"
                                                           @if($bracket->city_number === 0) data-non-deletable @endif>
                                                </td>
                                            @endcan
                                            <td>
                                                {{ $bracket->city_number }}
                                                @if ($bracket->city_number === 0)
                                                    <span class="badge bg-primary ms-1">Default</span>
                                                @endif
                                            </td>
                                            @foreach(\App\Services\PWHelperService::resources() as $resource)
                                                <td>{{ number_format($bracket->$resource, 2) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @can('manage-dd')
                        </form>
                    @endcan
                </div>
            </div>
        @endcan

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

@section("scripts")
    <script>
        document.getElementById('check-all')?.addEventListener('change', function (e) {
            const checkboxes = document.querySelectorAll('.bracket-checkbox');
            checkboxes.forEach(cb => {
                if (!cb.hasAttribute('data-non-deletable')) {
                    cb.checked = e.target.checked;
                }
            });
        });
    </script>
@endsection