@extends('layouts.main')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

        {{-- Target Nation Input --}}
        <div class="form-control w-full sm:max-w-xs">
            <label class="label">
                <span class="label-text">Target Nation ID</span>
            </label>
            <div class="join w-full">
                <input
                        type="number"
                        id="nationIdInput"
                        placeholder="e.g. 123456"
                        class="input input-bordered join-item w-full"
                />
                <button
                        class="btn btn-primary join-item"
                        onclick="redirectToNation()"
                >
                    Go
                </button>
            </div>
        </div>

        <script>
            function redirectToNation() {
                const nationId = document.getElementById('nationIdInput').value;
                if (nationId) {
                    const base = '{{ url('/defense/counters') }}';
                    window.location.href = `${base}/${nationId}`;
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                document.getElementById('nationIdInput')?.addEventListener('keyup', function (e) {
                    if (e.key === 'Enter') {
                        redirectToNation();
                    }
                });
            });
        </script>

        {{-- Target Info --}}
        @if($target)
            <div class="card bg-base-100 shadow-md border border-base-300">
                <div class="card-body">
                    <h2 class="card-title items-center gap-3">
                        <img src="{{ $target->flag }}" alt="Flag of {{ $target->leader_name }}"
                             class="w-8 h-5 rounded border border-base-300"/>
                        <div>
                            <a href="https://politicsandwar.com/nation/id={{ $target->id }}" target="_blank"
                               class="link link-hover text-info font-semibold">
                                {{ $target->leader_name }} of {{ $target->nation_name }}
                            </a>
                            <span class="badge badge-outline badge-info text-xs ml-2">ID: {{ $target->id }}</span>
                        </div>
                    </h2>

                    {{-- Target Stats --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
                        <div class="stat">
                            <div class="stat-title">Score</div>
                            <div class="stat-value text-primary">{{ number_format($target->score, 2) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Cities</div>
                            <div class="stat-value">{{ $target->num_cities }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Offensive Wars</div>
                            <div class="stat-value">{{ $target->offensive_wars_count }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Defensive Wars</div>
                            <div class="stat-value">{{ $target->defensive_wars_count }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Soldiers</div>
                            <div class="stat-value">{{ number_format($target->military->soldiers) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Tanks</div>
                            <div class="stat-value">{{ number_format($target->military->tanks) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Aircraft</div>
                            <div class="stat-value">{{ number_format($target->military->aircraft) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Ships</div>
                            <div class="stat-value">{{ number_format($target->military->ships) }}</div>
                        </div>
                    </div>

                    {{-- Range Box --}}
                    @php
                        $minRange = number_format($target->score * (4/7), 2);
                        $maxRange = number_format($target->score * 1.33, 2);
                    @endphp

                    <div class="mt-6 border border-info rounded-lg p-4 bg-base-100">
                        <h3 class="text-sm font-semibold text-info mb-1 uppercase">War Range</h3>
                        <p class="text-sm">
                            Nations with a score between
                            <span class="font-bold text-info">{{ $minRange }}</span> and
                            <span class="font-bold text-info">{{ $maxRange }}</span>
                            can declare war on this nation.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Nation Table --}}
        <x-utils.card :title="$target ? 'Matching Nation' : 'All Alliance Nation'">
            <div class="overflow-x-auto">
                <table class="table w-full">
                    <thead>
                    <tr>
                        <th>Leader</th>
                        <th>Score</th>
                        <th>Cities</th>
                        <th>Soldiers</th>
                        <th>Tanks</th>
                        <th>Aircraft</th>
                        <th>Ships</th>
                        <th>Off. Wars</th>
                        <th>Def. Wars</th>
                        @if($target)
                            <th>Match Score</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($nations as $nation)
                        <tr class="{{ $target && !$nation->in_range ? 'opacity-40' : (strtolower($nation->color) === 'beige' ? 'bg-warning/30' : '') }}">
                            <td>
                                <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank"
                                   class="link link-hover text-info font-semibold">
                                    {{ $nation->leader_name }}
                                </a>
                            </td>
                            <td>{{ $nation->score }}</td>
                            <td>{{ $nation->num_cities }}</td>
                            <td>{{ number_format($nation->military->soldiers) }}</td>
                            <td>{{ number_format($nation->military->tanks) }}</td>
                            <td>{{ number_format($nation->military->aircraft) }}</td>
                            <td>{{ number_format($nation->military->ships) }}</td>
                            <td>{{ $nation->offensive_wars_count }}</td>
                            <td>{{ $nation->defensive_wars_count }}</td>
                            @if($target)
                                <td>
                                    @if($nation->in_range)
                                        <div class="flex items-center gap-2">
                                            <progress class="progress progress-primary w-24"
                                                      value="{{ $nation->match_score }}" max="100"></progress>
                                            <span class="text-sm font-semibold">{{ $nation->match_score }}</span>
                                        </div>
                                    @else
                                        <span class="text-sm italic text-gray-500">Out of range</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $target ? 11 : 10 }}" class="text-center">No nations found.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-utils.card>
    </div>
@endsection