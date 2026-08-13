@extends('layouts.admin')

@section('content')
    <x-header title="Raid Finder" separator use-h1>
        <x-slot:subtitle>Manage target eligibility, the global no-raid alliance list, and the top-alliance exclusion cap.</x-slot:subtitle>
    </x-header>

    <div class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <x-card title="Raid Target Policy" subtitle="Set the global alliance and activity requirements for raid recommendations.">
            <form method="POST" action="{{ route('admin.raids.top-cap.update') }}" class="grid gap-4">
                @csrf
                <label class="grid gap-2">
                    <span class="text-sm font-medium">Exclude top alliances</span>
                    <input
                        type="number"
                        class="input"
                        name="top_cap"
                        id="top_cap"
                        value="{{ old('top_cap', $topCap) }}"
                        min="1"
                        max="1000"
                        required
                    >
                </label>

                <div class="grid gap-3 rounded-box border border-base-300 p-4">
                    <div class="grid gap-1">
                        <h3 class="font-semibold">Target activity</h3>
                        <p class="text-sm nexus-text-muted">
                            Targets above the city threshold must have been inactive for the required number of turns. Each turn is two hours.
                        </p>
                    </div>

                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Apply inactivity above city count</span>
                        <input
                            type="number"
                            class="input"
                            name="raid_activity_city_threshold"
                            id="raid_activity_city_threshold"
                            value="{{ old('raid_activity_city_threshold', $activityCityThreshold) }}"
                            min="0"
                            max="1000"
                            required
                        >
                        <span class="text-xs nexus-text-muted">Targets at or below this city count bypass the activity requirement.</span>
                    </label>

                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Minimum inactive turns</span>
                        <input
                            type="number"
                            class="input"
                            name="raid_minimum_inactive_turns"
                            id="raid_minimum_inactive_turns"
                            value="{{ old('raid_minimum_inactive_turns', $minimumInactiveTurns) }}"
                            min="0"
                            max="4380"
                            required
                        >
                        <span class="text-xs nexus-text-muted">Set this to 0 to disable the activity filter.</span>
                    </label>
                </div>

                <button class="btn btn-primary justify-self-start" type="submit">Update raid policy</button>
            </form>
        </x-card>

        <x-card title="No-Raid Alliance List" subtitle="Alliances listed here are excluded from the raid finder.">
            <x-slot:menu>
                <form method="POST" action="{{ route('admin.raids.no-raid.store') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Alliance ID</span>
                        <input type="number" class="input input-sm" name="alliance_id" placeholder="1234" required>
                    </label>
                    <button class="btn btn-primary btn-sm" type="submit">Add</button>
                </form>
            </x-slot:menu>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra table-sm" data-sortable="true">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Alliance ID</th>
                        <th>Alliance Name</th>
                            <th class="text-right" data-sortable="false">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($noRaidList as $entry)
                        <tr>
                            <td>{{ $entry->id }}</td>
                            <td>{{ $entry->alliance_id }}</td>
                            <td>
                                <a href="https://politicsandwar.com/alliance/id={{ $entry->alliance_id }}" target="_blank" rel="noopener" class="link link-primary">
                                    {{ $entry->alliance->name }}
                                </a>
                            </td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('admin.raids.no-raid.destroy', $entry->id) }}" data-confirm="Remove this alliance from the no-raid list?" data-confirm-title="Remove alliance?" data-confirm-label="Remove alliance" data-confirm-tone="error" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-error btn-outline btn-xs">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-sm nexus-text-muted">No entries in no-raid list.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
@endsection
