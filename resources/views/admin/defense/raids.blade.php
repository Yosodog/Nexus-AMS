@extends('layouts.admin')

@section('content')
    <x-header title="Raid Finder" separator>
        <x-slot:subtitle>Manage the global no-raid alliance list and the top-alliance exclusion cap.</x-slot:subtitle>
    </x-header>

    <div class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <x-card title="Top Alliance Cap" subtitle="Exclude the top-ranked alliances from raid recommendations.">
            <form method="POST" action="{{ route('admin.raids.top-cap.update') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <label class="block w-full max-w-xs space-y-2">
                    <span class="text-sm font-medium">Exclude top alliances</span>
                    <input
                        type="number"
                        class="input input-bordered"
                        name="top_cap"
                        id="top_cap"
                        value="{{ $topCap }}"
                        min="1"
                        max="100"
                        required
                    >
                </label>
                <button class="btn btn-primary" type="submit">Update</button>
            </form>
        </x-card>

        <x-card title="No-Raid Alliance List" subtitle="Alliances listed here are excluded from the raid finder.">
            <x-slot:menu>
                <form method="POST" action="{{ route('admin.raids.no-raid.store') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Alliance ID</span>
                        <input type="number" class="input input-bordered input-sm" name="alliance_id" placeholder="1234" required>
                    </label>
                    <button class="btn btn-primary btn-sm" type="submit">Add</button>
                </form>
            </x-slot:menu>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra table-sm">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Alliance ID</th>
                        <th>Alliance Name</th>
                        <th class="text-right">Actions</th>
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
                                <form method="POST" action="{{ route('admin.raids.no-raid.destroy', $entry->id) }}" onsubmit="return confirm('Are you sure you want to remove this alliance?')" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-error btn-outline btn-xs">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-sm text-base-content/60">No entries in no-raid list.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
@endsection
