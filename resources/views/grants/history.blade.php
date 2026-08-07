@extends('layouts.main')

@php
    use App\Models\GrantApplication;
    use Illuminate\Support\Str;
@endphp

@section('content')
    <header class="rounded-lg border border-base-300 bg-base-100 p-6 shadow">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs uppercase tracking-wide nexus-text-muted">Financial assistance</p>
                <h1 class="text-3xl font-bold text-primary sm:text-4xl">My standard-grant history</h1>
                <p class="mt-2 max-w-3xl text-sm text-base-content/70">
                    Review the program version, recorded payout, and decision details for your standard-grant applications.
                </p>
            </div>
            <a href="{{ route('grants.city') }}" class="btn btn-ghost btn-sm">Back to grants</a>
        </div>
    </header>

    <section class="mt-6 space-y-4" aria-labelledby="grant-history-title">
        <h2 id="grant-history-title" class="sr-only">Grant applications</h2>

        @forelse($applications as $application)
            @php
                $submittedAt = $application->submittedAtForHistory();
                $decidedAt = $application->decidedAtForHistory();
                $payout = collect(GrantApplication::PAYOUT_COLUMNS)
                    ->mapWithKeys(fn (string $resource) => [$resource => (float) ($application->{$resource} ?? 0)])
                    ->filter(fn (float $amount) => $amount > 0);
                $statusPresentation = match ($application->status) {
                    'approved' => ['intent' => 'success', 'icon' => 'check-circle', 'label' => 'Approved'],
                    'denied' => ['intent' => 'failure', 'icon' => 'x-circle', 'label' => 'Denied'],
                    default => ['intent' => 'pending', 'icon' => 'clock', 'label' => 'Pending'],
                };
            @endphp

            <article class="rounded-xl border border-base-300 bg-base-100 p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide nexus-text-muted">Program</p>
                        <h3 class="text-lg font-semibold">
                            {{ $application->program_name_snapshot ?? 'Not recorded' }}
                        </h3>
                        <p class="text-sm nexus-text-muted">
                            Version {{ $application->program_version_snapshot ?? 'Not recorded' }}
                            · Request #{{ $application->id }}
                        </p>
                        <x-copy-action :value="(string) $application->id" label="grant request ID" class="mt-2" />
                    </div>
                    <x-nexus-status
                        :intent="$statusPresentation['intent']"
                        :icon="$statusPresentation['icon']"
                        :label="$statusPresentation['label']"
                    />
                </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)]">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide nexus-text-muted">Recorded payout</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse($payout as $resource => $amount)
                                <span class="nexus-status nexus-status--neutral">
                                    {{ Str::headline($resource) }}
                                    {{ $resource === 'money' ? '$'.number_format($amount, 0) : number_format($amount, 0) }}
                                </span>
                            @empty
                                <span class="text-sm nexus-text-muted">
                                    {{ $application->hasProgramSnapshot() ? 'No payout was configured.' : 'Not recorded' }}
                                </span>
                            @endforelse
                        </div>
                        <p class="mt-2 text-sm nexus-text-muted">
                            Account: {{ $application->account?->name ?? ('#'.$application->account_id) }}
                        </p>
                    </div>

                    <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                        <dt class="font-medium">Submitted</dt>
                        <dd>
                            @if($submittedAt)
                                <x-time.display :value="$submittedAt" label="Submitted" :show-exact="true" />
                            @else
                                Not recorded
                            @endif
                        </dd>
                        <dt class="font-medium">Decided</dt>
                        <dd>
                            @if($decidedAt)
                                <x-time.display :value="$decidedAt" label="Decided" :show-exact="true" />
                            @elseif($application->status === 'pending')
                                Awaiting decision
                            @else
                                Not recorded
                            @endif
                        </dd>
                        <dt class="font-medium">Disbursed</dt>
                        <dd>
                            @if($application->disbursed_at)
                                <x-time.display :value="$application->disbursed_at" label="Disbursed" :show-exact="true" />
                            @elseif($application->status === 'approved')
                                Not recorded
                            @else
                                Not applicable
                            @endif
                        </dd>
                    </dl>
                </div>

                @if($application->status !== 'pending')
                    <div class="mt-4 rounded-lg border border-base-300 bg-base-200/60 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide nexus-text-muted">Decision reason</p>
                        <p class="mt-1 font-medium">{{ $application->decision_reason_code?->label() ?? 'Not recorded' }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-base-content/75">
                            {{ $application->memberDecisionExplanation() ?? 'Not recorded' }}
                        </p>
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-base-300 bg-base-100 p-8 text-center">
                <h3 class="font-semibold">No standard-grant applications yet</h3>
                <p class="mt-1 text-sm nexus-text-muted">Applications will appear here after you submit them.</p>
            </div>
        @endforelse

        {{ $applications->links() }}
    </section>
@endsection
