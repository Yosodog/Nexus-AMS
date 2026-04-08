@extends('layouts.admin')

@section('content')
    @php
        $modalContext = old('modal_context');
        $activeMembers = $members->where('is_active', true);
    @endphp

    <div class="mb-6">
        <div class="flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <h3 class="mb-1">Payroll</h3>
                <p class="text-base-content/50 mb-0">Manage weekly payroll grades and member payouts.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('edit_payroll')
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGradeModal">
                        <i class="o-plus-circle me-1"></i> Add Grade
                    </button>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createMemberModal">
                        <i class="o-user-plus me-1"></i> Add Member
                    </button>
                @endcan
            </div>
        </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <x-stat title="Members"
                :value="number_format($members->count())"
                icon="o-users"
                color="text-primary"
                :description="number_format($activeMembers->count()) . ' active members'" />
        <x-stat title="Weekly Total"
                :value="'$' . number_format((float) $weeklyTotal, 2)"
                icon="o-calendar-days"
                color="text-success"
                description="Enabled and active payroll commitments" />
        <x-stat title="Daily Total"
                :value="'$' . number_format((float) $dailyTotal, 2)"
                icon="o-calendar"
                color="text-info"
                description="Weekly payroll divided across seven days" />
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-header flex justify-content-between align-items-center">
                    <span class="font-semibold">Payroll Grades</span>
                    <span class="badge bg-secondary-subtle text-base-content/50-emphasis">{{ $grades->count() }} grades</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Weekly</th>
                                <th>Daily</th>
                                <th>Status</th>
                                <th>Members</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($grades as $grade)
                                <tr>
                                    <td class="font-semibold">{{ $grade->name }}</td>
                                    <td>${{ number_format((float) $grade->weekly_amount, 2) }}</td>
                                    <td>${{ number_format((float) ($dailyAmounts[$grade->id] ?? 0), 2) }}</td>
                                    <td>
                                        <span class="badge text-bg-{{ $grade->is_enabled ? 'success' : 'secondary' }}">
                                            {{ $grade->is_enabled ? 'Enabled' : 'Disabled' }}
                                        </span>
                                    </td>
                                    <td>{{ $members->where('payroll_grade_id', $grade->id)->count() }}</td>
                                    <td class="text-right">
                                        @can('edit_payroll')
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                    data-bs-target="#editGradeModal-{{ $grade->id }}">
                                                Edit
                                            </button>
                                            <form class="d-inline" method="POST"
                                                  action="{{ route('admin.payroll.grades.destroy', $grade) }}"
                                                  onsubmit="return confirm('Remove this payroll grade?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-base-content/50">No payroll grades configured.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card shadow-sm h-100">
                <div class="card-header flex justify-content-between align-items-center">
                    <span class="font-semibold">Payroll Members</span>
                    <span class="badge bg-secondary-subtle text-base-content/50-emphasis">{{ $members->count() }} records</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Nation</th>
                                <th>Alliance</th>
                                <th>Grade</th>
                                <th>Weekly</th>
                                <th>Daily</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($members as $member)
                                @php
                                    $grade = $member->grade;
                                    $dailyAmount = $dailyAmounts[$member->payroll_grade_id] ?? '0.00';
                                @endphp
                                <tr>
                                    <td>
                                        <div class="font-semibold">{{ $member->nation?->leader_name ?? 'Nation #'.$member->nation_id }}</div>
                                        <div class="text-base-content/50 small">{{ $member->nation?->nation_name ?? 'Unknown nation' }}</div>
                                    </td>
                                    <td>
                                        @php
                                            $allianceId = $member->nation?->alliance_id;
                                            $allianceName = $allianceId ? ($allianceNames[$allianceId] ?? null) : null;
                                        @endphp
                                        {{ $allianceName ?? ($allianceId ? 'Alliance #'.$allianceId : '—') }}
                                    </td>
                                    <td>{{ $grade?->name ?? '—' }}</td>
                                    <td>${{ number_format((float) ($grade?->weekly_amount ?? 0), 2) }}</td>
                                    <td>${{ number_format((float) $dailyAmount, 2) }}</td>
                                    <td>
                                        <span class="badge text-bg-{{ $member->is_active ? 'success' : 'secondary' }}">
                                            {{ $member->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        @can('edit_payroll')
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                    data-bs-target="#editMemberModal-{{ $member->id }}">
                                                Edit
                                            </button>
                                            <form class="d-inline" method="POST"
                                                  action="{{ route('admin.payroll.members.destroy', $member) }}"
                                                  onsubmit="return confirm('Remove this member from payroll?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">Remove</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-base-content/50">No payroll members configured.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @can('edit_payroll')
        <div class="modal fade" id="createGradeModal" tabindex="-1" aria-labelledby="createGradeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.payroll.grades.store') }}">
                        @csrf
                        <input type="hidden" name="modal_context" value="createGradeModal">
                        <div class="modal-header">
                            <h5 class="modal-title" id="createGradeModalLabel">Add Payroll Grade</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="grade_name">Name</label>
                                <input type="text" class="form-control" id="grade_name" name="name" value="{{ old('name') }}" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="grade_weekly">Weekly Amount</label>
                                <input type="number" class="form-control" id="grade_weekly" name="weekly_amount" min="0" step="0.01" value="{{ old('weekly_amount') }}" required>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="grade_enabled" name="is_enabled" value="1" @checked(old('is_enabled', true))>
                                <label class="form-check-label" for="grade_enabled">Enabled</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Grade</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="createMemberModal" tabindex="-1" aria-labelledby="createMemberModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.payroll.members.store') }}">
                        @csrf
                        <input type="hidden" name="modal_context" value="createMemberModal">
                        <div class="modal-header">
                            <h5 class="modal-title" id="createMemberModalLabel">Add Payroll Member</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="member_nation">Nation ID</label>
                                <input type="number" class="form-control" id="member_nation" name="nation_id" min="1" value="{{ old('nation_id') }}" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="member_grade">Payroll Grade</label>
                                <select class="form-select" id="member_grade" name="payroll_grade_id" required>
                                    <option value="">Select a grade</option>
                                    @foreach ($grades as $grade)
                                        <option value="{{ $grade->id }}" @selected((int) old('payroll_grade_id') === $grade->id)>
                                            {{ $grade->name }} — ${{ number_format((float) $grade->weekly_amount, 2) }} / week
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        @foreach ($grades as $grade)
            <div class="modal fade" id="editGradeModal-{{ $grade->id }}" tabindex="-1"
                 aria-labelledby="editGradeModalLabel-{{ $grade->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('admin.payroll.grades.update', $grade) }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="modal_context" value="editGradeModal-{{ $grade->id }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editGradeModalLabel-{{ $grade->id }}">Edit {{ $grade->name }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label" for="grade_name_{{ $grade->id }}">Name</label>
                                    <input type="text" class="form-control" id="grade_name_{{ $grade->id }}" name="name"
                                           value="{{ $modalContext === 'editGradeModal-' . $grade->id ? old('name') : $grade->name }}" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="grade_weekly_{{ $grade->id }}">Weekly Amount</label>
                                    <input type="number" class="form-control" id="grade_weekly_{{ $grade->id }}" name="weekly_amount" min="0" step="0.01"
                                           value="{{ $modalContext === 'editGradeModal-' . $grade->id ? old('weekly_amount') : $grade->weekly_amount }}" required>
                                </div>
                                @php
                                    $editEnabled = $modalContext === 'editGradeModal-' . $grade->id
                                        ? (bool) old('is_enabled', $grade->is_enabled)
                                        : $grade->is_enabled;
                                @endphp
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="grade_enabled_{{ $grade->id }}" name="is_enabled" value="1" @checked($editEnabled)>
                                    <label class="form-check-label" for="grade_enabled_{{ $grade->id }}">Enabled</label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach

        @foreach ($members as $member)
            <div class="modal fade" id="editMemberModal-{{ $member->id }}" tabindex="-1"
                 aria-labelledby="editMemberModalLabel-{{ $member->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('admin.payroll.members.update', $member) }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="modal_context" value="editMemberModal-{{ $member->id }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editMemberModalLabel-{{ $member->id }}">Edit Nation #{{ $member->nation_id }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label" for="member_grade_{{ $member->id }}">Payroll Grade</label>
                                    <select class="form-select" id="member_grade_{{ $member->id }}" name="payroll_grade_id" required>
                                        @foreach ($grades as $grade)
                                            @php
                                                $selectedGrade = $modalContext === 'editMemberModal-' . $member->id
                                                    ? (int) old('payroll_grade_id')
                                                    : $member->payroll_grade_id;
                                            @endphp
                                            <option value="{{ $grade->id }}" @selected($selectedGrade === $grade->id)>
                                                {{ $grade->name }} — ${{ number_format((float) $grade->weekly_amount, 2) }} / week
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                @php
                                    $memberActive = $modalContext === 'editMemberModal-' . $member->id
                                        ? (bool) old('is_active', $member->is_active)
                                        : $member->is_active;
                                @endphp
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="member_active_{{ $member->id }}" name="is_active" value="1" @checked($memberActive)>
                                    <label class="form-check-label" for="member_active_{{ $member->id }}">Active</label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endcan

    @if ($modalContext)
        @push('scripts')
            <script>
                document.addEventListener('codex:page-ready', function () {
                    const modalId = @json($modalContext);
                    const modalElement = document.getElementById(modalId);
                    if (! modalElement) {
                        return;
                    }
                    modalElement.classList.add('show');
                    modalElement.style.display = 'flex';
                    document.body.classList.add('modal-open');
                    modalElement.dispatchEvent(new Event('show.bs.modal'));
                });
            </script>
        @endpush
    @endif
@endsection
