<section class="rounded-box border border-base-300 bg-base-100" id="alert-activity">
    <div class="flex flex-col gap-2 border-b border-base-300 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold">Activity and delivery receipts</h2>
            <p class="mt-1 text-sm text-base-content/60">The latest 30 web activities, including matches that Discord suppressed or could not deliver.</p>
        </div>
        <span class="badge badge-outline">30-day detail</span>
    </div>

    @if($activity->isEmpty())
        <div class="p-8 text-center">
            <div class="font-medium">No alert activity yet</div>
            <p class="mx-auto mt-1 max-w-lg text-sm text-base-content/60">Matches, tests, suppression reasons, and final delivery receipts will appear here. A missing Discord message will never erase the web record.</p>
        </div>
    @else
        <div class="divide-y divide-base-300">
            @foreach($activity as $item)
                @php
                    $payload = $item['payload'];
                    $summary = $payload['label'] ?? (
                        isset($payload['resource'])
                            ? ucfirst($payload['resource']).' '.($payload['direction'] ?? 'crossed').' '.number_format((float) ($payload['threshold'] ?? 0), 2)
                            : $item['event_label']
                    );
                @endphp
                <article class="p-5 sm:p-6 {{ $item['read_at'] ? '' : 'bg-primary/5' }}">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold">{{ $item['event_label'] }}</h3>
                                @if($item['is_test'])
                                    <span class="badge badge-info badge-outline">Test</span>
                                @endif
                                @if(! $item['read_at'])
                                    <span class="badge badge-primary badge-outline">Unread</span>
                                @endif
                                @if($item['stale_at'] && now()->isAfter($item['stale_at']))
                                    <span class="badge badge-warning badge-outline">Stale</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-base-content/80">{{ $summary }}</p>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-base-content/60">
                                <span>Occurred {{ \Illuminate\Support\Carbon::parse($item['occurred_at'])->diffForHumans() }}</span>
                                @if($item['observed_at'])
                                    <span>Source observed {{ \Illuminate\Support\Carbon::parse($item['observed_at'])->diffForHumans() }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            @foreach($item['deliveries'] as $delivery)
                                @php
                                    $tone = match($delivery['status']) {
                                        'delivered' => 'badge-success',
                                        'queued', 'scheduled', 'pending' => 'badge-info',
                                        'suppressed', 'superseded', 'cancelled' => 'badge-warning',
                                        default => 'badge-error',
                                    };
                                    $destination = $delivery['destination_kind'] === 'discord_dm' ? 'Discord DM' : ucfirst($delivery['destination_kind']);
                                @endphp
                                <span class="badge {{ $tone }} badge-outline" title="{{ $delivery['reason_code'] ? str_replace('_', ' ', $delivery['reason_code']) : $delivery['status'] }}">
                                    {{ $destination }} · {{ ucfirst($delivery['status']) }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-3">
                        <div class="text-xs text-base-content/60">
                            @foreach($item['deliveries'] as $delivery)
                                @if($delivery['reason_code'])
                                    <span class="mr-3">{{ ucfirst(str_replace('_', ' ', $delivery['reason_code'])) }}</span>
                                @endif
                                @if($delivery['batch'] && $delivery['batch']['attempt_count'] > 0)
                                    <span class="mr-3">{{ $delivery['batch']['attempt_count'] }} attempt{{ $delivery['batch']['attempt_count'] === 1 ? '' : 's' }}</span>
                                @endif
                            @endforeach
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if($item['deep_link_path'])
                                <a href="{{ url($item['deep_link_path']) }}" class="btn btn-xs btn-ghost">Open context</a>
                            @endif
                            <form method="POST" action="{{ url('/user/alerts/activity/'.$item['activity_id'].'/read') }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="read" value="{{ $item['read_at'] ? 0 : 1 }}">
                                <button class="btn btn-xs btn-outline">Mark {{ $item['read_at'] ? 'unread' : 'read' }}</button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
