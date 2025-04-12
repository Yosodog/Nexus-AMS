@extends('layouts.main')

@inject('settings', 'App\Services\SettingService')

@section('content')
    <div class="container mx-auto">

        <x-utils.card>

            <div class="flex items-center justify-between mb-4">
                <h1 class="text-2xl font-semibold">War Aid Dashboard</h1>

                <div class="tooltip" data-tip="{{ $settings::isWarAidEnabled() ? '' : 'War aid is disabled' }}">
                    <button
                            class="btn btn-primary"
                            @if ($settings::isWarAidEnabled())
                                onclick="document.getElementById('aid-request-modal').showModal()"
                            @else
                                disabled
                            @endif
                    >
                        Request War Aid
                    </button>
                </div>
            </div>

            <h2 class="text-xl font-bold mb-2">Your Active Wars</h2>
            @if($wars->isEmpty())
                <p class="text-gray-500">You have no active wars.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table w-full">
                        <thead>
                        <tr>
                            <th>Enemy</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Start</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($wars as $war)
                            <tr>
                                <td>{{ $war->opponent_name }}</td>
                                <td>{{ ucfirst($war->war_type) }}</td>
                                <td>{{ $war->status }}</td>
                                <td>{{ $war->start_date->format('M d, Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h2 class="text-xl font-bold mt-8 mb-2">Previous War Aid Requests</h2>
            <div class="overflow-x-auto">
                <table class="table w-full">
                    <thead>
                    <tr>
                        <th>Requested At</th>
                        <th>Note</th>
                        <th>Status</th>
                        <th>Money</th>
                        <th>Resources</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($requests as $req)
                        <tr>
                            <td>{{ $req->created_at->format('M d, Y H:i') }}</td>
                            <td>{{ $req->note }}</td>
                            <td>
                                <span class="badge badge-{{ $req->status === 'approved' ? 'success' : ($req->status === 'denied' ? 'error' : 'neutral') }}">
                                    {{ ucfirst($req->status) }}
                                </span>
                            </td>
                            <td>{{ number_format($req->money) }}</td>
                            <td>
                                @foreach(\App\Services\PWHelperService::resources(false, false, true) as $resource)
                                    @if($req->$resource > 0)
                                        <span class="badge badge-outline">{{ ucfirst($resource) }}: {{ $req->$resource }}</span>
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-gray-500">No previous requests.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-utils.card>
    </div>

    @if ($settings::isWarAidEnabled())
        {{-- Modal --}}
        <dialog id="aid-request-modal" class="modal">
            <div class="modal-box w-11/12 max-w-5xl">
                <form method="POST" action="{{ route('defense.war-aid.store') }}">
                    @csrf
                    <h3 class="font-bold text-lg">Request War Aid</h3>

                    <div class="form-control mt-4">
                        <label class="label">Account</label>
                        <select class="select select-bordered" name="account_id" required>
                            @foreach($nation->accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-control mt-4">
                        <label class="label">Note</label>
                        <textarea class="textarea textarea-bordered" name="note" required></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mt-4">
                        @foreach(\App\Services\PWHelperService::resources() as $resource)
                            <div>
                                <label class="label">{{ ucfirst($resource) }}</label>
                                <input type="number" name="{{ $resource }}" class="input input-bordered w-full" min="0" value="0">
                            </div>
                        @endforeach
                    </div>

                    <div class="modal-action">
                        <button type="submit" class="btn btn-primary">Submit</button>
                        <button type="button" class="btn" onclick="document.getElementById('aid-request-modal').close()">Cancel</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
@endsection