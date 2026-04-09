@extends('layouts.admin')

@section('content')
    @php
        $requirementOptions = [
            'urban_planning' => 'Urban Planning',
            'advanced_urban_planning' => 'Advanced Urban Planning',
            'center_for_civil_engineering' => 'Center for Civil Engineering',
            'advanced_engineering_corps' => 'Advanced Engineering Corps',
            'government_support_agency' => 'Government Support Agency',
        ];
    @endphp

    <x-header title="Rebuilding Management" separator>
        <x-slot:subtitle>Track estimates, payout throughput, and member eligibility through the current rebuilding cycle.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap justify-end gap-2">
                <form method="POST" action="{{ route('admin.rebuilding.toggle') }}">
                    @csrf
                    <button class="btn btn-{{ $enabled ? 'warning' : 'success' }} btn-sm" type="submit">
                        {{ $enabled ? 'Close Rebuilding' : 'Open Rebuilding' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.rebuilding.reset') }}" onsubmit="return confirm('Reset rebuilding for a new cycle?');">
                    @csrf
                    <button class="btn btn-error btn-outline btn-sm" type="submit">Reset Cycle</button>
                </form>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Current Cycle" :value="number_format($cycleId)" icon="o-arrow-path" :description="'Rebuilding is ' . ($enabled ? 'Open' : 'Closed')" color="text-primary" />
        <x-stat title="Eligible Estimate" :value="'$' . number_format((float) $estimateTotal)" icon="o-calculator" description="Projected total for current cycle payouts" color="text-success" />
        <x-stat title="Total Sent" :value="'$' . number_format((float) $totalSentThisCycle)" icon="o-banknotes" description="Approved rebuilding disbursements this cycle" color="text-info" />
        <x-stat title="Last Refresh" :value="$lastEstimateRefreshAt ? $lastEstimateRefreshAt->format('M d H:i') : 'Never'" icon="o-clock" description="Estimate snapshot refresh timestamp" color="text-warning" />
    </div>

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <x-card title="Request Pipeline">
            <div class="space-y-2 text-sm">
                <div class="font-semibold">{{ number_format($pendingCount) }} pending</div>
                <div class="text-base-content/60">{{ number_format($approvedCount) }} approved · {{ number_format($deniedCount) }} denied</div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge-ghost">Cycle {{ number_format($cycleId) }}</span>
                    <span class="badge badge-ghost">{{ number_format($estimateCount) }} with estimates</span>
                </div>
            </div>
        </x-card>

        <x-card title="Payout Quality">
            <div class="space-y-2 text-sm">
                <div class="font-semibold">{{ number_format($approvalRate, 1) }}%</div>
                <div class="text-base-content/60">Approval rate of decided requests</div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge-ghost">Avg payout ${{ number_format($averageApprovedPayout, 0) }}</span>
                    <span class="badge badge-ghost">Remaining est. ${{ number_format($estimatedButUnsent, 0) }}</span>
                </div>
            </div>
        </x-card>

        <x-card title="Eligibility Snapshot">
            <div class="space-y-2 text-sm">
                <div class="font-semibold">{{ number_format($applicantCount + $vacationCount + $ineligibleCount) }} excluded</div>
                <div class="text-base-content/60">Current cycle exclusion footprint</div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge-ghost">{{ number_format($applicantCount) }} applicants</span>
                    <span class="badge badge-ghost">{{ number_format($vacationCount) }} in VM</span>
                    <span class="badge badge-ghost">{{ number_format($ineligibleCount) }} ineligible</span>
                </div>
            </div>
        </x-card>
    </div>

    <x-card title="Refresh Estimates" class="mb-6">
        <form method="POST" action="{{ route('admin.rebuilding.refresh-estimates') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <label class="block space-y-2">
                <span class="text-sm font-medium">Cycle Override (optional)</span>
                <input type="number" class="input input-bordered" name="cycle_id" min="1" value="{{ $cycleId }}">
            </label>
            <button class="btn btn-primary" type="submit">Refresh Now</button>
        </form>
    </x-card>

    <x-card title="All Nations Rebuilding Overview" class="mb-6">
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra table-sm">
                <thead>
                <tr>
                    <th>Leader</th>
                    <th>Cities</th>
                    <th>Rebuilding Amount</th>
                    <th>Request Status</th>
                    <th>Approved</th>
                    <th>Flags</th>
                </tr>
                </thead>
                <tbody>
                @forelse($nationRows as $row)
                    @php
                        $statusBadgeClass = match ($row['status']) {
                            'approved' => 'badge-success',
                            'denied' => 'badge-error',
                            'pending' => 'badge-warning',
                            default => 'badge-ghost',
                        };
                    @endphp
                    <tr>
                        <td>
                            <a href="https://politicsandwar.com/nation/id={{ $row['nation_id'] }}" target="_blank" rel="noopener noreferrer" class="link link-primary">
                                {{ $row['leader_name'] ?: ('Nation #'.$row['nation_id']) }}
                            </a>
                        </td>
                        <td>{{ $row['city_count'] }}</td>
                        <td>${{ number_format((float) $row['estimated_amount']) }}</td>
                        <td><span class="badge {{ $statusBadgeClass }}">{{ $row['status'] === 'not_applied' ? 'Not Applied' : ucfirst($row['status']) }}</span></td>
                        <td><span class="badge {{ $row['is_approved'] ? 'badge-success' : 'badge-ghost' }}">{{ $row['is_approved'] ? 'Yes' : 'No' }}</span></td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                @if($row['is_applicant'])
                                    <span class="badge badge-ghost">Applicant</span>
                                @endif
                                @if($row['is_vacation_mode'])
                                    <span class="badge badge-neutral">VM</span>
                                @endif
                                @if($row['is_ineligible'])
                                    <span class="badge badge-warning">Ineligible</span>
                                @endif
                                @if(! $row['is_applicant'] && ! $row['is_vacation_mode'] && ! $row['is_ineligible'])
                                    <span class="text-base-content/60">-</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 text-center text-sm text-base-content/60">No nations available in alliance scope.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card title="Tier Configuration" class="mb-6">
        <form method="POST" action="{{ route('admin.rebuilding.tiers.store') }}" class="space-y-4 border-b border-base-300 pb-6">
            @csrf
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Name</span>
                    <input class="input input-bordered w-full" name="name" placeholder="Optional">
                </label>
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Min Cities</span>
                    <input type="number" class="input input-bordered w-full" name="min_city_count" min="1" required>
                </label>
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Max Cities</span>
                    <input type="number" class="input input-bordered w-full" name="max_city_count" min="1" placeholder="No max">
                </label>
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Target Infrastructure</span>
                    <input type="number" step="0.01" class="input input-bordered w-full" name="target_infrastructure" min="0" required>
                </label>
                <label class="label cursor-pointer justify-start gap-3 pt-7">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" class="toggle toggle-primary" name="is_active" value="1" checked>
                    <span class="label-text">Active</span>
                </label>
            </div>

            <div class="space-y-2">
                <div class="text-sm font-medium">Assumed Cost-Reduction Projects</div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($requirementOptions as $value => $label)
                        <label class="flex items-center gap-3 rounded-box border border-base-300 px-4 py-3">
                            <input class="checkbox checkbox-primary" type="checkbox" name="requirements[]" value="{{ $value }}" id="req-create-{{ $value }}">
                            <span class="text-sm">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end">
                <button class="btn btn-success" type="submit">Add Tier</button>
            </div>
        </form>

        <div class="mt-6 overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra table-sm">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Range</th>
                    <th>Target Infra</th>
                    <th>Calc Assumptions</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($tiers as $tier)
                    <tr>
                        <td>{{ $tier->name ?: '-' }}</td>
                        <td>{{ $tier->min_city_count }} - {{ $tier->max_city_count ?? '∞' }}</td>
                        <td>{{ number_format((float) $tier->target_infrastructure, 2) }}</td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                @forelse(($tier->requirements ?? []) as $requirement)
                                    <span class="badge badge-ghost">{{ str_replace('_', ' ', $requirement) }}</span>
                                @empty
                                    <span class="text-base-content/60">None</span>
                                @endforelse
                            </div>
                        </td>
                        <td>{{ $tier->is_active ? 'Yes' : 'No' }}</td>
                        <td>
                            <div class="space-y-3">
                                <form method="POST" action="{{ route('admin.rebuilding.tiers.update', $tier) }}" class="space-y-3">
                                    @csrf
                                    @method('PUT')
                                    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                                        <input class="input input-bordered input-sm w-full" name="name" value="{{ $tier->name }}" placeholder="Name">
                                        <input type="number" class="input input-bordered input-sm w-full" name="min_city_count" min="1" value="{{ $tier->min_city_count }}" required>
                                        <input type="number" class="input input-bordered input-sm w-full" name="max_city_count" min="1" value="{{ $tier->max_city_count }}" placeholder="Max">
                                        <input type="number" step="0.01" class="input input-bordered input-sm w-full" name="target_infrastructure" min="0" value="{{ $tier->target_infrastructure }}" required>
                                        <label class="label cursor-pointer justify-start gap-3">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" class="toggle toggle-primary toggle-sm" name="is_active" value="1" @checked($tier->is_active)>
                                            <span class="label-text">Active</span>
                                        </label>
                                    </div>
                                    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                        @foreach($requirementOptions as $value => $label)
                                            <label class="flex items-center gap-2 rounded-box border border-base-300 px-3 py-2">
                                                <input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="requirements[]" value="{{ $value }}" id="req-{{ $tier->id }}-{{ $value }}" @checked(in_array($value, $tier->requirements ?? [], true))>
                                                <span class="text-xs">{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                </form>
                                <form method="POST" action="{{ route('admin.rebuilding.tiers.destroy', $tier) }}" onsubmit="return confirm('Delete this tier?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-error btn-outline btn-sm" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card title="Ineligible Nations (Current Cycle)" class="mb-6">
        <form method="POST" action="{{ route('admin.rebuilding.ineligible.store') }}" class="mb-6 grid gap-4 md:grid-cols-[14rem_minmax(0,1fr)_auto]">
            @csrf
            <label class="block space-y-2">
                <span class="text-sm font-medium">Nation ID</span>
                <input type="number" class="input input-bordered w-full" name="nation_id" min="1" required>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Reason (optional)</span>
                <input type="text" class="input input-bordered w-full" name="reason" maxlength="255">
            </label>
            <div class="flex items-end">
                <button class="btn btn-warning w-full md:w-auto" type="submit">Mark Ineligible</button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra table-sm">
                <thead>
                <tr>
                    <th>Nation</th>
                    <th>Reason</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse($ineligible as $entry)
                    <tr>
                        <td>
                            @if ($entry->nation)
                                <a href="https://politicsandwar.com/nation/id={{ $entry->nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-primary">
                                    {{ $entry->nation->leader_name ?? ('Nation #'.$entry->nation->id) }}
                                </a>
                                <div class="text-sm text-base-content/60">{{ $entry->nation->nation_name ?? 'Unknown Nation' }}</div>
                            @else
                                <span class="text-base-content/60">{{ 'Nation #'.$entry->nation_id }}</span>
                            @endif
                        </td>
                        <td>{{ $entry->reason ?: '-' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.rebuilding.ineligible.destroy', $entry->id) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-error btn-outline btn-sm" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="py-6 text-center text-sm text-base-content/60">No ineligible nations this cycle.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($pending->isNotEmpty())
        <x-card title="Pending Requests" class="mb-6">
            <div class="space-y-4">
                @foreach ($pending as $req)
                    <article class="rounded-box border border-base-300 p-4">
                        <div class="mb-4">
                            @if ($req->nation)
                                <a href="https://politicsandwar.com/nation/id={{ $req->nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-primary font-semibold">
                                    {{ $req->nation->leader_name ?? ('Nation #'.$req->nation->id) }}
                                </a>
                                <div class="text-sm text-base-content/60">{{ $req->nation->nation_name ?? 'Unknown Nation' }}</div>
                            @else
                                <div class="font-semibold">{{ 'Nation #'.$req->nation_id }}</div>
                            @endif
                            <div class="mt-2 text-sm text-base-content/60">
                                Account: {{ $req->account?->name ?? 'Unknown' }} · Cities: {{ $req->city_count_snapshot }} · Target: {{ number_format((float) $req->target_infrastructure_snapshot, 2) }}
                            </div>
                            <div class="text-sm text-base-content/60">Estimated: ${{ number_format((float) $req->estimated_amount) }}</div>
                            @if($req->note)
                                <div class="text-sm text-base-content/60">Note: {{ $req->note }}</div>
                            @endif
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <form method="POST" action="{{ route('admin.rebuilding.approve', $req) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                                @csrf
                                @method('PATCH')
                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">Approved Amount</span>
                                    <input type="number" min="0" step="1" name="approved_amount" class="input input-bordered w-full" value="{{ (int) round($req->estimated_amount) }}">
                                </label>
                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">Review Note</span>
                                    <input type="text" name="review_note" class="input input-bordered w-full" maxlength="255" placeholder="Optional">
                                </label>
                                <div class="flex items-end">
                                    <button class="btn btn-success w-full sm:w-auto" type="submit">Approve</button>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.rebuilding.deny', $req) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                @csrf
                                @method('PATCH')
                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">Review Note</span>
                                    <input type="text" name="review_note" class="input input-bordered w-full" maxlength="255" placeholder="Optional">
                                </label>
                                <div class="flex items-end">
                                    <button class="btn btn-error w-full sm:w-auto" type="submit">Deny</button>
                                </div>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-card title="Cycle History">
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra table-sm">
                <thead>
                <tr>
                    <th>Nation</th>
                    <th>Status</th>
                    <th>Estimated</th>
                    <th>Approved</th>
                    <th>Created</th>
                </tr>
                </thead>
                <tbody>
                @forelse($history as $req)
                    <tr>
                        <td>
                            @if ($req->nation)
                                <a href="https://politicsandwar.com/nation/id={{ $req->nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-primary">
                                    {{ $req->nation->leader_name ?? ('Nation #'.$req->nation->id) }}
                                </a>
                                <div class="text-sm text-base-content/60">{{ $req->nation->nation_name ?? 'Unknown Nation' }}</div>
                            @else
                                <span class="text-base-content/60">{{ 'Nation #'.$req->nation_id }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $req->status === 'approved' ? 'badge-success' : ($req->status === 'denied' ? 'badge-error' : 'badge-ghost') }}">
                                {{ ucfirst($req->status) }}
                            </span>
                        </td>
                        <td>${{ number_format((float) $req->estimated_amount) }}</td>
                        <td>${{ number_format((float) ($req->approved_amount ?? 0)) }}</td>
                        <td>{{ $req->created_at->format('M d, Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-6 text-center text-sm text-base-content/60">No history records this cycle.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $history->links() }}</div>
    </x-card>
@endsection
