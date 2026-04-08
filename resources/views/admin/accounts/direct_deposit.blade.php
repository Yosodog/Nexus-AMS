@can('view-dd')
    {{-- DD Settings --}}
    <x-card title="Direct Deposit Settings" class="mb-4">
        <form method="POST" action="{{ route('admin.dd.settings') }}">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <x-input label="Direct Deposit Tax ID" type="number"
                         name="direct_deposit_tax_id"
                         value="{{ old('direct_deposit_tax_id', $ddTaxId) }}"
                         @cannot('manage-dd') disabled @endcannot
                         hint="The in-game tax bracket ID members must be assigned to. Must be 100% money and 100% resource taxes."
                         required />
                <x-input label="Fallback Tax ID" type="number"
                         name="direct_deposit_fallback_tax_id"
                         value="{{ old('direct_deposit_fallback_tax_id', $fallbackTaxId) }}"
                         @cannot('manage-dd') disabled @endcannot
                         hint="Used when a member unenrolls and their original bracket cannot be restored."
                         required />
            </div>
            @can('manage-dd')
                <x-button label="Save Settings" type="submit" icon="o-check" class="btn-primary" />
            @endcan
        </form>
    </x-card>

    {{-- DD Brackets Table --}}
    @can('view-dd')
        <x-card class="mb-4">
            <x-slot:title>Direct Deposit Brackets</x-slot:title>
            <x-slot:menu>
                @can('manage-dd')
                    <form method="POST" action="{{ route('admin.dd.brackets.create') }}" class="flex items-center gap-2">
                        @csrf
                        <input type="number" name="city_number" class="input input-sm w-24"
                               min="0" required placeholder="City #">
                        <x-button label="Create Bracket" type="submit" icon="o-plus" class="btn-success btn-sm" />
                    </form>
                @endcan
            </x-slot:menu>

            @can('manage-dd')
                <x-alert class="alert-info mb-4">
                    <strong>Tip:</strong> Enter new tax rates below and click "Apply to Selected" to update all checked brackets. The default bracket (City 0) cannot be deleted but can be edited.
                </x-alert>

                <form method="POST" action="" id="bracket-form">
                    @csrf
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                        @foreach(\App\Services\PWHelperService::resources() as $resource)
                            <x-input :label="ucfirst($resource) . ' %'" type="number"
                                     name="rates[{{ $resource }}]"
                                     min="0" max="100" step="0.01" placeholder="e.g. 10" />
                        @endforeach
                    </div>
                    <div class="flex gap-2 mb-4">
                        <x-button label="Apply to Selected" type="submit" formaction="{{ route('admin.dd.brackets.update') }}" class="btn-primary btn-sm" />
                        <x-button label="Delete Selected" type="submit" formaction="{{ route('admin.dd.brackets.delete') }}"
                                  onclick="return confirm('Are you sure you want to delete the selected brackets?');"
                                  class="btn-error btn-sm" />
                    </div>
            @endcan

            <div class="overflow-x-auto">
                <table class="table table-sm table-zebra">
                    <thead>
                        <tr class="text-base-content/60">
                            @can('manage-dd')
                                <th class="w-8">
                                    <input type="checkbox" id="check-all" class="checkbox checkbox-sm checkbox-primary">
                                </th>
                            @endcan
                            <th>City Number</th>
                            @foreach(\App\Services\PWHelperService::resources() as $resource)
                                <th>{{ ucfirst($resource) }} %</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($brackets as $bracket)
                            <tr @class(['bg-info/10' => $bracket->city_number === 0])>
                                @can('manage-dd')
                                    <td>
                                        <input type="checkbox"
                                               name="selected[]"
                                               value="{{ $bracket->id }}"
                                               class="checkbox checkbox-sm bracket-checkbox"
                                               @if($bracket->city_number === 0) data-non-deletable @endif>
                                    </td>
                                @endcan
                                <td>
                                    {{ $bracket->city_number }}
                                    @if ($bracket->city_number === 0)
                                        <x-badge  value="Default" class="badge-primary badge-sm ml-1" />
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
        </x-card>
    @endcan

    {{-- Current Enrollments --}}
    <x-card title="Current Enrollments">
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra">
                <thead>
                    <tr class="text-base-content/60">
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
                                <a href="https://politicsandwar.com/nation/id={{ $enrollment->nation_id }}" target="_blank" class="link link-primary">
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
    </x-card>
@endcan

@push('scripts')
    <script>
        document.getElementById('check-all')?.addEventListener('change', function (e) {
            document.querySelectorAll('.bracket-checkbox').forEach(cb => {
                if (!cb.hasAttribute('data-non-deletable')) cb.checked = e.target.checked;
            });
        });
    </script>
@endpush
