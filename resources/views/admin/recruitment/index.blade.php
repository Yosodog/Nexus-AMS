@extends('layouts.admin')

@section('title', 'Recruitment Messaging')

@section('content')
    <x-header title="Recruitment Messaging" separator>
        <x-slot:subtitle>Manage the primary and follow-up messages sent to eligible recruits.</x-slot:subtitle>
    </x-header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
        <x-card title="Message Templates">
            <form method="POST" action="{{ route('admin.recruitment.update') }}" class="space-y-5">
                @csrf

                <x-toggle
                    id="recruitment_enabled"
                    label="Enable automatic recruitment messages"
                    name="recruitment_enabled"
                    value="1"
                    @checked(old('recruitment_enabled', $recruitmentEnabled))
                />

                <x-input
                    id="primary_subject"
                    label="Primary subject"
                    name="primary_subject"
                    :value="old('primary_subject', $primarySubject)"
                    error-field="primary_subject"
                    hint="Maximum 50 characters (in-game limit)."
                    maxlength="50"
                    required
                />

                <x-textarea
                    id="primary_message"
                    label="Primary message"
                    name="primary_message"
                    class="js-ckeditor"
                    error-field="primary_message"
                    hint="This message is sent immediately after a nation becomes eligible."
                    rows="10"
                    required
                >{!! old('primary_message', $primaryMessage) !!}</x-textarea>

                <x-toggle
                    id="follow_up_enabled"
                    :label="'Enable follow-up message (sent ' . \App\Services\RecruitmentService::FOLLOW_UP_DELAY_HOURS . ' hours later)'"
                    name="follow_up_enabled"
                    value="1"
                    @checked(old('follow_up_enabled', $followUpEnabled))
                />

                <x-input
                    id="follow_up_subject"
                    label="Follow-up subject"
                    name="follow_up_subject"
                    :value="old('follow_up_subject', $followUpSubject)"
                    error-field="follow_up_subject"
                    hint="Maximum 50 characters (in-game limit)."
                    maxlength="50"
                    required
                />

                <x-textarea
                    id="follow_up_message"
                    label="Follow-up message"
                    name="follow_up_message"
                    class="js-ckeditor"
                    error-field="follow_up_message"
                    hint="The follow-up is only sent if the nation is still unaffiliated when the delay expires."
                    rows="10"
                    required
                >{!! old('follow_up_message', $followUpMessage) !!}</x-textarea>

                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary">
                        Save changes
                    </button>
                </div>
            </form>
        </x-card>

        <div class="space-y-6">
            <x-card title="Send Test Message">
                @if($userNationId)
                    <p class="text-sm text-base-content/60">
                        Test messages are sent to your nation (ID {{ $userNationId }}).
                    </p>

                    <form method="POST" action="{{ route('admin.recruitment.test') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label for="test_type" class="fieldset-legend mb-0.5">Message type <span class="text-error">*</span></label>
                            <select id="test_type" name="type" class="select w-full @error('type') !select-error @enderror" required>
                                <option value="primary" @selected(old('type') === 'primary')>Primary message</option>
                                <option value="follow_up" @selected(old('type') === 'follow_up')>Follow-up message</option>
                            </select>
                            @error('type')
                            <div class="text-error">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-outline-primary w-full">
                            Send test message
                        </button>
                    </form>
                @else
                    <div class="alert alert-warning">
                        Add your nation ID to your profile to send test messages.
                    </div>
                @endif
            </x-card>

            <x-card title="Latest Recruited Nations">
                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table table-zebra">
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
                                    <div class="flex items-center gap-2">
                                        <a href="https://politicsandwar.com/nation/id={{ $nation->nation_id }}" target="_blank" rel="noopener">
                                            {{ $nation->nation?->leader_name ?? $nation->nation_id }}
                                        </a>
                                        <span class="badge {{ $nation->nation?->alliance_id == $primaryAllianceId ? 'badge-success' : 'badge-ghost' }}">
                                            {{ $nation->nation?->alliance_id == $primaryAllianceId ? 'Joined' : 'Pending' }}
                                        </span>
                                    </div>
                                </td>
                                <td>{{ $nation->primary_sent_at?->diffForHumans() ?? '—' }}</td>
                                <td>{{ $nation->follow_up_scheduled_for?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>
@endsection

@push('scripts')
    @vite('resources/js/ckeditor.js')
@endpush
