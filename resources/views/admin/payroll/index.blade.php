@extends('layouts.admin')

@section('content')
    @php
        $modalContext = old('modal_context');
        $activeMembers = $members->where('is_active', true);
    @endphp

    <x-header title="Payroll" separator>
        <x-slot:subtitle>Manage weekly payroll grades and member payouts.</x-slot:subtitle>
        @can('edit_payroll')
            <x-slot:actions>
                <div class="flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-sm" type="button" onclick="document.getElementById('createGradeModal').showModal()">
                        <x-icon name="o-plus-circle" class="size-4" />
                        Add Grade
                    </button>
                    <button class="btn btn-primary btn-outline btn-sm" type="button" onclick="document.getElementById('createMemberModal').showModal()">
                        <x-icon name="o-user-plus" class="size-4" />
                        Add Member
                    </button>
                </div>
            </x-slot:actions>
        @endcan
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <x-stat
            title="Members"
            :value="number_format($members->count())"
            icon="o-users"
            color="text-primary"
            :description="number_format($activeMembers->count()) . ' active members'"
        />
        <x-stat
            title="Weekly Total"
            :value="'$' . number_format((float) $weeklyTotal, 2)"
            icon="o-calendar-days"
            color="text-success"
            description="Enabled and active payroll commitments"
        />
        <x-stat
            title="Daily Total"
            :value="'$' . number_format((float) $dailyTotal, 2)"
            icon="o-calendar"
            color="text-info"
            description="Weekly payroll divided across seven days"
        />
    </div>

    <div class="grid gap-6">
        <x-card title="Payroll Grades" :subtitle="$grades->count() . ' grades'">
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra table-sm">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Weekly</th>
                        <th>Daily</th>
                        <th>Status</th>
                        <th>Members</th>
                        <th class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($grades as $grade)
                        <tr>
                            <td class="font-semibold">{{ $grade->name }}</td>
                            <td>${{ number_format((float) $grade->weekly_amount, 2) }}</td>
                            <td>${{ number_format((float) ($dailyAmounts[$grade->id] ?? 0), 2) }}</td>
                            <td>
                                <span class="badge {{ $grade->is_enabled ? 'badge-success' : 'badge-ghost' }}">
                                    {{ $grade->is_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td>{{ $members->where('payroll_grade_id', $grade->id)->count() }}</td>
                            <td class="text-right">
                                @can('edit_payroll')
                                    <div class="flex justify-end gap-2">
                                        <button
                                            class="btn btn-primary btn-outline btn-xs"
                                            type="button"
                                            onclick="document.getElementById('editGradeModal-{{ $grade->id }}').showModal()"
                                        >
                                            Edit
                                        </button>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.payroll.grades.destroy', $grade) }}"
                                            onsubmit="return confirm('Remove this payroll grade?');"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-error btn-outline btn-xs" type="submit">Delete</button>
                                        </form>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-sm text-base-content/60">No payroll grades configured.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Payroll Members" :subtitle="$members->count() . ' records'">
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra table-sm">
                    <thead>
                    <tr>
                        <th>Nation</th>
                        <th>Alliance</th>
                        <th>Grade</th>
                        <th>Weekly</th>
                        <th>Daily</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($members as $member)
                        @php
                            $grade = $member->grade;
                            $dailyAmount = $dailyAmounts[$member->payroll_grade_id] ?? '0.00';
                            $allianceId = $member->nation?->alliance_id;
                            $allianceName = $allianceId ? ($allianceNames[$allianceId] ?? null) : null;
                        @endphp
                        <tr>
                            <td>
                                <div class="font-semibold">{{ $member->nation?->leader_name ?? 'Nation #'.$member->nation_id }}</div>
                                <div class="text-sm text-base-content/60">{{ $member->nation?->nation_name ?? 'Unknown nation' }}</div>
                            </td>
                            <td>{{ $allianceName ?? ($allianceId ? 'Alliance #'.$allianceId : '—') }}</td>
                            <td>{{ $grade?->name ?? '—' }}</td>
                            <td>${{ number_format((float) ($grade?->weekly_amount ?? 0), 2) }}</td>
                            <td>${{ number_format((float) $dailyAmount, 2) }}</td>
                            <td>
                                <span class="badge {{ $member->is_active ? 'badge-success' : 'badge-ghost' }}">
                                    {{ $member->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-right">
                                @can('edit_payroll')
                                    <div class="flex justify-end gap-2">
                                        <button
                                            class="btn btn-primary btn-outline btn-xs"
                                            type="button"
                                            onclick="document.getElementById('editMemberModal-{{ $member->id }}').showModal()"
                                        >
                                            Edit
                                        </button>
                                        <form
                                            method="POST"
                                            action="{{ route('admin.payroll.members.destroy', $member) }}"
                                            onsubmit="return confirm('Remove this member from payroll?');"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-error btn-outline btn-xs" type="submit">Remove</button>
                                        </form>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-sm text-base-content/60">No payroll members configured.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    @can('edit_payroll')
        <dialog id="createGradeModal" class="modal">
            <div class="modal-box max-w-2xl">
                <form method="POST" action="{{ route('admin.payroll.grades.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="modal_context" value="createGradeModal">

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Add Payroll Grade</h3>
                            <p class="text-sm text-base-content/60">Create a reusable weekly amount for payroll members.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('createGradeModal').close()">✕</button>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Name</span>
                        <input type="text" class="input input-bordered" id="grade_name" name="name" value="{{ old('name') }}" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Weekly Amount</span>
                        <input type="number" class="input input-bordered" id="grade_weekly" name="weekly_amount" min="0" step="0.01" value="{{ old('weekly_amount') }}" required>
                    </label>

                    <label class="label cursor-pointer justify-start gap-3">
                        <input class="toggle toggle-primary" type="checkbox" id="grade_enabled" name="is_enabled" value="1" @checked(old('is_enabled', true))>
                        <span class="label-text">Enabled</span>
                    </label>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('createGradeModal').close()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Grade</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        <dialog id="createMemberModal" class="modal">
            <div class="modal-box max-w-2xl">
                <form method="POST" action="{{ route('admin.payroll.members.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="modal_context" value="createMemberModal">

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Add Payroll Member</h3>
                            <p class="text-sm text-base-content/60">Attach a nation to one of the configured payroll grades.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('createMemberModal').close()">✕</button>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Nation ID</span>
                        <input type="number" class="input input-bordered" id="member_nation" name="nation_id" min="1" value="{{ old('nation_id') }}" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Payroll Grade</span>
                        <select class="select select-bordered" id="member_grade" name="payroll_grade_id" required>
                            <option value="">Select a grade</option>
                            @foreach ($grades as $grade)
                                <option value="{{ $grade->id }}" @selected((int) old('payroll_grade_id') === $grade->id)>
                                    {{ $grade->name }} — ${{ number_format((float) $grade->weekly_amount, 2) }} / week
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('createMemberModal').close()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Member</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        @foreach ($grades as $grade)
            @php
                $editEnabled = $modalContext === 'editGradeModal-' . $grade->id
                    ? (bool) old('is_enabled', $grade->is_enabled)
                    : $grade->is_enabled;
            @endphp
            <dialog id="editGradeModal-{{ $grade->id }}" class="modal">
                <div class="modal-box max-w-2xl">
                    <form method="POST" action="{{ route('admin.payroll.grades.update', $grade) }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="modal_context" value="editGradeModal-{{ $grade->id }}">

                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">Edit {{ $grade->name }}</h3>
                                <p class="text-sm text-base-content/60">Update the weekly amount or disable this grade.</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('editGradeModal-{{ $grade->id }}').close()">✕</button>
                        </div>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Name</span>
                            <input
                                type="text"
                                class="input input-bordered"
                                id="grade_name_{{ $grade->id }}"
                                name="name"
                                value="{{ $modalContext === 'editGradeModal-' . $grade->id ? old('name') : $grade->name }}"
                                required
                            >
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Weekly Amount</span>
                            <input
                                type="number"
                                class="input input-bordered"
                                id="grade_weekly_{{ $grade->id }}"
                                name="weekly_amount"
                                min="0"
                                step="0.01"
                                value="{{ $modalContext === 'editGradeModal-' . $grade->id ? old('weekly_amount') : $grade->weekly_amount }}"
                                required
                            >
                        </label>

                        <label class="label cursor-pointer justify-start gap-3">
                            <input class="toggle toggle-primary" type="checkbox" id="grade_enabled_{{ $grade->id }}" name="is_enabled" value="1" @checked($editEnabled)>
                            <span class="label-text">Enabled</span>
                        </label>

                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-ghost" onclick="document.getElementById('editGradeModal-{{ $grade->id }}').close()">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
                <form method="dialog" class="modal-backdrop"><button>close</button></form>
            </dialog>
        @endforeach

        @foreach ($members as $member)
            @php
                $selectedGrade = $modalContext === 'editMemberModal-' . $member->id
                    ? (int) old('payroll_grade_id')
                    : $member->payroll_grade_id;
                $memberActive = $modalContext === 'editMemberModal-' . $member->id
                    ? (bool) old('is_active', $member->is_active)
                    : $member->is_active;
            @endphp
            <dialog id="editMemberModal-{{ $member->id }}" class="modal">
                <div class="modal-box max-w-2xl">
                    <form method="POST" action="{{ route('admin.payroll.members.update', $member) }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="modal_context" value="editMemberModal-{{ $member->id }}">

                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">Edit Nation #{{ $member->nation_id }}</h3>
                                <p class="text-sm text-base-content/60">Change the assigned grade or disable this payroll member.</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('editMemberModal-{{ $member->id }}').close()">✕</button>
                        </div>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Payroll Grade</span>
                            <select class="select select-bordered" id="member_grade_{{ $member->id }}" name="payroll_grade_id" required>
                                @foreach ($grades as $grade)
                                    <option value="{{ $grade->id }}" @selected($selectedGrade === $grade->id)>
                                        {{ $grade->name }} — ${{ number_format((float) $grade->weekly_amount, 2) }} / week
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <input type="hidden" name="is_active" value="0">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input class="toggle toggle-primary" type="checkbox" id="member_active_{{ $member->id }}" name="is_active" value="1" @checked($memberActive)>
                            <span class="label-text">Active</span>
                        </label>

                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-ghost" onclick="document.getElementById('editMemberModal-{{ $member->id }}').close()">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
                <form method="dialog" class="modal-backdrop"><button>close</button></form>
            </dialog>
        @endforeach
    @endcan

    @if ($modalContext)
        @push('scripts')
            <script>
                document.addEventListener('codex:page-ready', function () {
                    const modalId = @json($modalContext);
                    document.getElementById(modalId)?.showModal();
                });
            </script>
        @endpush
    @endif
@endsection
