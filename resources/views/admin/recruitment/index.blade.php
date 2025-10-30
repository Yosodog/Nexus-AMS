@extends('layouts.admin')

@section('title', 'Recruitment Messaging')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-sm-6">
                    <h3 class="mb-0">Recruitment Messaging</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Message Templates</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.recruitment.update') }}">
                        @csrf

                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="recruitment_enabled"
                                   name="recruitment_enabled" value="1"
                                   {{ old('recruitment_enabled', $recruitmentEnabled) ? 'checked' : '' }}>
                            <label class="form-check-label" for="recruitment_enabled">
                                Enable automatic recruitment messages
                            </label>
                        </div>

                        <div class="mb-3">
                            <label for="primary_subject" class="form-label">Primary subject</label>
                            <input type="text"
                                   id="primary_subject"
                                   name="primary_subject"
                                   class="form-control @error('primary_subject') is-invalid @enderror"
                                   value="{{ old('primary_subject', $primarySubject) }}"
                                   maxlength="255"
                                   required>
                            @error('primary_subject')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="primary_message" class="form-label">Primary message</label>
                            <textarea id="primary_message"
                                      name="primary_message"
                                      class="form-control js-ckeditor @error('primary_message') is-invalid @enderror"
                                      rows="10"
                                      required>{!! old('primary_message', $primaryMessage) !!}</textarea>
                            @error('primary_message')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <p class="form-text mt-2">
                                This message is sent immediately after a nation becomes eligible.
                            </p>
                        </div>

                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="follow_up_enabled"
                                   name="follow_up_enabled" value="1"
                                   {{ old('follow_up_enabled', $followUpEnabled) ? 'checked' : '' }}>
                            <label class="form-check-label" for="follow_up_enabled">
                                Enable follow-up message (sent {{ \App\Services\RecruitmentService::FOLLOW_UP_DELAY_HOURS }} hours later)
                            </label>
                        </div>

                        <div class="mb-3">
                            <label for="follow_up_subject" class="form-label">Follow-up subject</label>
                            <input type="text"
                                   id="follow_up_subject"
                                   name="follow_up_subject"
                                   class="form-control @error('follow_up_subject') is-invalid @enderror"
                                   value="{{ old('follow_up_subject', $followUpSubject) }}"
                                   maxlength="255"
                                   required>
                            @error('follow_up_subject')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="follow_up_message" class="form-label">Follow-up message</label>
                            <textarea id="follow_up_message"
                                      name="follow_up_message"
                                      class="form-control js-ckeditor @error('follow_up_message') is-invalid @enderror"
                                      rows="10"
                                      required>{!! old('follow_up_message', $followUpMessage) !!}</textarea>
                            @error('follow_up_message')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <p class="form-text mt-2">
                                The follow-up is only sent if the nation is still unaffiliated when the delay expires.
                            </p>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                Save changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Send Test Message</h5>
                </div>
                <div class="card-body">
                    @if($userNationId)
                        <p class="text-muted small">
                            Test messages are sent to your nation (ID {{ $userNationId }}).
                        </p>

                        <form method="POST" action="{{ route('admin.recruitment.test') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="test_type" class="form-label">Message type</label>
                                <select id="test_type"
                                        name="type"
                                        class="form-select @error('type') is-invalid @enderror"
                                        required>
                                    <option value="primary" {{ old('type') === 'primary' ? 'selected' : '' }}>
                                        Primary message
                                    </option>
                                    <option value="follow_up" {{ old('type') === 'follow_up' ? 'selected' : '' }}>
                                        Follow-up message
                                    </option>
                                </select>
                                @error('type')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-outline-primary w-100">
                                Send test message
                            </button>
                        </form>
                    @else
                        <div class="alert alert-warning mb-0">
                            Add your nation ID to your profile to send test messages.
                        </div>
                    @endif
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Latest Recruited Nations</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th>Leader</th>
                                <th>Sent At</th>
                                <th>Follow Up</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($latestNations as $nation)
                                <tr>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $nation->nation_id }}" target="_blank">{{ $nation->nation?->leader_name ?? $nation->nation_id }}</a>@if ($nation->nation?->alliance_id == env('PW_ALLIANCE_ID')) ✅ @else ❌ @endif
                                    </td>
                                    <td>{{ $nation->primary_sent_at?->diffForHumans() ?? '—' }}</td>
                                    <td>{{ $nation->follow_up_scheduled_for?->diffForHumans() ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.ckeditor.com/4.22.1/standard-all/ckeditor.js" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof CKEDITOR === 'undefined') {
                console.warn('CKEditor failed to load for recruitment messaging.');
                return;
            }

            document.querySelectorAll('.js-ckeditor').forEach((element) => {
                if (!element.id) {
                    element.id = `editor-${Math.random().toString(36).substring(2, 8)}`;
                }

                CKEDITOR.replace(element.id, {
                    height: 260,
                    removePlugins: 'cloudservices,easyimage',
                });
            });
        });
    </script>
@endpush
