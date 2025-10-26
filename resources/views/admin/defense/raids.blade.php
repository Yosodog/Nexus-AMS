@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Raid Finder</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Top Alliance Cap --}}
    <div class="card mt-4">
        <div class="card-header">Top Alliance Cap</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.raids.top-cap.update') }}" class="row g-3 align-items-center">
                @csrf
                <div class="col-auto">
                    <label for="top_cap" class="col-form-label">Exclude Top</label>
                </div>
                <div class="col-auto">
                    <input type="number" class="form-control" name="top_cap" id="top_cap"
                           value="{{ $topCap }}" min="1" max="100" required>
                </div>
                <div class="col-auto">
                    <label class="col-form-label">alliances from raids</label>
                </div>
                <div class="col-auto">
                    <button class="btn btn-success btn-sm" type="submit">Update</button>
                </div>
            </form>
        </div>
    </div>

    {{-- No-Raid Alliance List --}}
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>No-Raid Alliance List</span>
            <form method="POST" action="{{ route('admin.raids.no-raid.store') }}" class="d-flex align-items-center">
                @csrf
                <input type="number" class="form-control me-2" name="alliance_id" placeholder="Alliance ID" required>
                <button class="btn btn-primary btn-sm" type="submit">Add</button>
            </form>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Alliance ID</th>
                    <th>Alliance Name</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($noRaidList as $entry)
                    <tr>
                        <td>{{ $entry->id }}</td>
                        <td>{{ $entry->alliance_id }}</td>
                        <td><a href="https://politicsandwar.com/alliance/id={{ $entry->alliance_id }}" target="_blank">{{ $entry->alliance->name}}</a></td>
                        <td>
                            <form method="POST" action="{{ route('admin.raids.no-raid.destroy', $entry->id) }}"
                                  onsubmit="return confirm('Are you sure you want to remove this alliance?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted">No entries in no-raid list.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
