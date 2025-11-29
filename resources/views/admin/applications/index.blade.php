@extends('layouts.admin')

@section('title', 'Applications')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-sm-6">
                    <h3 class="mb-0">Applications</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Discord &amp; Alliance Settings</h5>
                        <p class="text-muted mb-0 small">Control how the bot labels applicants and where approvals are announced.</p>
                    </div>
                    @unless($canManage)
                        <span class="badge text-bg-secondary">View only</span>
                    @endunless
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.applications.settings') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="applications_enabled"
                                           name="applications_enabled"
                                           value="1"
                                           @checked(old('applications_enabled', $settings['enabled']))
                                           @disabled(!$canManage)>
                                    <label class="form-check-label fw-semibold" for="applications_enabled">
                                        Enable application system
                                    </label>
                                    <div class="text-muted small">Disable to temporarily pause new Discord applications.</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="applications_approved_position_id" class="form-label fw-semibold">
                                    Approved position ID
                                    <i class="bi bi-question-circle ms-1 text-muted" data-bs-toggle="tooltip"
                                       title="Politics & War alliance position ID for fully approved members. The bot assigns this after approval."></i>
                                </label>
                                <input type="number"
                                       id="applications_approved_position_id"
                                       name="applications_approved_position_id"
                                       class="form-control @error('applications_approved_position_id') is-invalid @enderror"
                                       value="{{ old('applications_approved_position_id', $settings['approved_position_id']) }}"
                                       min="0"
                                       @disabled(!$canManage)
                                       required>
                                @error('applications_approved_position_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <label for="applications_discord_applicant_role_id" class="form-label fw-semibold">
                                    Applicant role ID
                                    <i class="bi bi-question-circle ms-1 text-muted" data-bs-toggle="tooltip"
                                       title="Discord role applied when someone starts an application. Used to gate interview channels."></i>
                                </label>
                                <input type="text"
                                       id="applications_discord_applicant_role_id"
                                       name="applications_discord_applicant_role_id"
                                       class="form-control @error('applications_discord_applicant_role_id') is-invalid @enderror"
                                       value="{{ old('applications_discord_applicant_role_id', $settings['discord_applicant_role_id']) }}"
                                       @disabled(!$canManage)
                                       maxlength="100">
                                @error('applications_discord_applicant_role_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <label for="applications_discord_ia_role_id" class="form-label fw-semibold">
                                    IA role ID
                                    <i class="bi bi-question-circle ms-1 text-muted" data-bs-toggle="tooltip"
                                       title="Discord role granted to IA reviewers so the bot can grant channel access and tag staff."></i>
                                </label>
                                <input type="text"
                                       id="applications_discord_ia_role_id"
                                       name="applications_discord_ia_role_id"
                                       class="form-control @error('applications_discord_ia_role_id') is-invalid @enderror"
                                       value="{{ old('applications_discord_ia_role_id', $settings['discord_ia_role_id']) }}"
                                       @disabled(!$canManage)
                                       maxlength="100">
                                @error('applications_discord_ia_role_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <label for="applications_discord_member_role_id" class="form-label fw-semibold">
                                    Member role ID
                                    <i class="bi bi-question-circle ms-1 text-muted" data-bs-toggle="tooltip"
                                       title="Discord role assigned after approval so members get the right server access."></i>
                                </label>
                                <input type="text"
                                       id="applications_discord_member_role_id"
                                       name="applications_discord_member_role_id"
                                       class="form-control @error('applications_discord_member_role_id') is-invalid @enderror"
                                       value="{{ old('applications_discord_member_role_id', $settings['discord_member_role_id']) }}"
                                       @disabled(!$canManage)
                                       maxlength="100">
                                @error('applications_discord_member_role_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="applications_approval_announcement_channel_id" class="form-label fw-semibold">
                                    Approval announcement channel ID
                                    <i class="bi bi-question-circle ms-1 text-muted" data-bs-toggle="tooltip"
                                       title="Channel where the bot announces approved applicants. Leave blank to skip announcements."></i>
                                </label>
                                <input type="text"
                                       id="applications_approval_announcement_channel_id"
                                       name="applications_approval_announcement_channel_id"
                                       class="form-control @error('applications_approval_announcement_channel_id') is-invalid @enderror"
                                       value="{{ old('applications_approval_announcement_channel_id', $settings['approval_announcement_channel_id']) }}"
                                       @disabled(!$canManage)
                                       maxlength="100">
                                @error('applications_approval_announcement_channel_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="applications_discord_interview_category_id" class="form-label fw-semibold">
                                    Interview category ID
                                    <i class="bi bi-question-circle ms-1 text-muted" data-bs-toggle="tooltip"
                                       title="Discord category where the bot should create interview channels. Leave blank to let the bot choose a default."></i>
                                </label>
                                <input type="text"
                                       id="applications_discord_interview_category_id"
                                       name="applications_discord_interview_category_id"
                                       class="form-control @error('applications_discord_interview_category_id') is-invalid @enderror"
                                       value="{{ old('applications_discord_interview_category_id', $settings['discord_interview_category_id']) }}"
                                       @disabled(!$canManage)
                                       maxlength="100">
                                @error('applications_discord_interview_category_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="text-muted small mt-1">Use the numeric category ID (right-click &amp; Copy ID in Discord).</div>
                            </div>
                            <div class="col-md-4">
                                <label for="applications_approval_message_template" class="form-label fw-semibold">
                                    Approval message template
                                    <i class="bi bi-question-circle ms-1 text-muted" data-bs-toggle="tooltip"
                                       title="Bot announcement content posted when an applicant is approved."></i>
                                </label>
                                <textarea id="applications_approval_message_template"
                                          name="applications_approval_message_template"
                                          class="form-control @error('applications_approval_message_template') is-invalid @enderror"
                                          rows="3"
                                          @disabled(!$canManage)>{{ old('applications_approval_message_template', $settings['approval_message_template']) }}</textarea>
                                @error('applications_approval_message_template')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        @if($canManage)
                            <div class="d-flex justify-content-end mt-4">
                                <button type="submit" class="btn btn-primary">
                                    Save settings
                                </button>
                            </div>
                        @endif
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Open Applications</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                            <tr>
                                <th>Leader</th>
                                <th>Nation</th>
                                <th>Discord</th>
                                <th>Created</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($openApplications as $application)
                                <tr>
                                    <td>{{ $application->leader_name_snapshot }}</td>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener">
                                            #{{ $application->nation_id }}
                                        </a>
                                    </td>
                                    <td>
                                        {{ $application->discord_username }}
                                        <div class="text-muted small">{{ $application->discord_user_id }}</div>
                                    </td>
                                    <td>{{ $application->created_at?->diffForHumans() ?? '—' }}</td>
                                    <td>
                                        <span class="badge text-bg-warning">Pending</span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-outline-primary">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No pending applications.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Recent Applications</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                            <tr>
                                <th>Leader</th>
                                <th>Nation</th>
                                <th>Discord</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th class="text-end">View</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($recentApplications as $application)
                                @php
                                    $status = $application->status->value ?? (string) $application->status;
                                    $statusClass = match($status) {
                                        \App\Enums\ApplicationStatus::Approved->value => 'text-bg-success',
                                        \App\Enums\ApplicationStatus::Denied->value => 'text-bg-danger',
                                        \App\Enums\ApplicationStatus::Cancelled->value => 'text-bg-secondary',
                                        default => 'text-bg-warning'
                                    };
                                @endphp
                                <tr>
                                    <td>{{ $application->leader_name_snapshot }}</td>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener">
                                            #{{ $application->nation_id }}
                                        </a>
                                    </td>
                                    <td>
                                        {{ $application->discord_username }}
                                        <div class="text-muted small">{{ $application->discord_user_id }}</div>
                                    </td>
                                    <td><span class="badge {{ $statusClass }}">{{ ucfirst(strtolower($status)) }}</span></td>
                                    <td>{{ $application->updated_at?->diffForHumans() ?? '—' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-outline-secondary">
                                            Open
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No applications to display.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
            new bootstrap.Tooltip(el);
        });
    </script>
@endpush
