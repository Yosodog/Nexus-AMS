@extends('layouts.main')

@section('content')
    <div class="mx-auto w-full min-w-0 space-y-6">
        <div class="rounded-lg border border-base-300 bg-base-100 p-6 shadow">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Defense desk</p>
                    <h1 class="text-3xl font-bold leading-tight">Blockade relief</h1>
                    <p class="text-sm text-base-content/70">Request naval help or claim a request you are currently eligible to support.</p>
                </div>
                <button class="btn btn-primary" @disabled($blockadedWars->isEmpty()) onclick="document.getElementById('blockade-relief-modal').showModal()">
                    Request relief
                </button>
            </div>
        </div>

        <x-utils.card>
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-xl font-semibold">Available requests</h2>
                <span class="badge badge-outline">{{ $availableRequests->count() }} eligible</span>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="table w-full" data-sortable="false">
                    <thead>
                    <tr>
                        <th>Member</th>
                        <th>Blockader</th>
                        <th>Deadline</th>
                        <th>Context</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($availableRequests as $reliefRequest)
                        <tr>
                            <td>{{ $reliefRequest->requester?->nation_name ?? 'Unknown nation' }}</td>
                            <td>{{ $reliefRequest->blockadingNation?->nation_name ?? '#'.$reliefRequest->blockading_nation_id }}</td>
                            <td>{{ $reliefRequest->deadline_at->diffForHumans() }}</td>
                            <td>{{ $reliefRequest->note ?: 'No additional context.' }}</td>
                            <td>
                                <form method="POST" action="{{ route('defense.blockade-relief.claim', $reliefRequest) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">Claim</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-base-content/55">No relief requests currently match your nation.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-utils.card>

        <x-utils.card>
            <h2 class="text-xl font-semibold">Your requests</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="table w-full" data-sortable="false">
                    <thead>
                    <tr>
                        <th>War</th>
                        <th>Blockader</th>
                        <th>Status</th>
                        <th>Claimed by</th>
                        <th>Deadline</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($requests as $reliefRequest)
                        <tr>
                            <td>#{{ $reliefRequest->war_id }}</td>
                            <td>{{ $reliefRequest->blockadingNation?->nation_name ?? '#'.$reliefRequest->blockading_nation_id }}</td>
                            <td><span class="badge badge-outline">{{ ucfirst($reliefRequest->status->value) }}</span></td>
                            <td>{{ $reliefRequest->claimer?->nation_name ?? '—' }}</td>
                            <td>{{ $reliefRequest->deadline_at->format('M j, Y H:i') }}</td>
                            <td>
                                @if ($reliefRequest->isActive())
                                    <form method="POST" action="{{ route('defense.blockade-relief.cancel', $reliefRequest) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-ghost">Cancel</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-base-content/55">You have not requested blockade relief.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-utils.card>
    </div>

    <dialog id="blockade-relief-modal" class="modal" aria-label="Request blockade relief">
        <div class="modal-box w-11/12 max-w-2xl">
            <form method="POST" action="{{ route('defense.blockade-relief.store') }}" class="space-y-4">
                @csrf
                <h3 class="text-lg font-bold">Request blockade relief</h3>

                <div class="grid gap-2">
                    <label class="label" for="relief-war">Blockaded war</label>
                    <select id="relief-war" class="select w-full" name="war_id" required>
                        @foreach ($blockadedWars as $war)
                            @php($opponent = $war->att_id === auth()->user()->nation_id ? $war->defender : $war->attacker)
                            <option value="{{ $war->id }}">War #{{ $war->id }} vs {{ $opponent?->nation_name ?? 'Unknown nation' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-2">
                    <label class="label" for="relief-deadline">Deadline</label>
                    <select id="relief-deadline" class="select w-full" name="deadline_hours">
                        @foreach ([2, 4, 6, 12, 24] as $hours)
                            <option value="{{ $hours }}" @selected($hours === 6)>{{ $hours }} hours</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-2">
                    <label class="label" for="relief-note">Optional context</label>
                    <textarea id="relief-note" class="textarea w-full" name="note" maxlength="255" placeholder="Timing or coordination details"></textarea>
                </div>

                <p class="text-sm text-base-content/60">Claiming is a coordination signal only. Nexus will never declare a war or make an assignment automatically.</p>

                <div class="modal-action">
                    <button type="submit" class="btn btn-primary">Open request</button>
                    <button type="button" class="btn" onclick="document.getElementById('blockade-relief-modal').close()">Cancel</button>
                </div>
            </form>
        </div>
    </dialog>
@endsection
