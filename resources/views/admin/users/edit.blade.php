@extends('layouts.admin')

@section('content')
    @php
        $nation = $user->nation;
        $latestSignIn = $latestSignIn ?? optional($nation)->latestSignIn;
        $accounts = $accounts ?? collect();
        $resourceKeys = ['money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'];
        $accountTotalMoney = $accounts->sum('money');
        $roles = $user->roles->pluck('name');
    @endphp

    <div class="container py-4">
        <div class="row align-items-center mb-4 g-3">
            <div class="col-lg-8">
                <h3 class="mb-0 flex align-items-center gap-2">
                    <span class="text-primary-emphasis bg-primary-subtle rounded-circle p-2 d-inline-flex">
                        <i class="o-user-lines-fill"></i>
                    </span>
                    <span>Edit User: {{ $user->name }}</span>
                </h3>
                <p class="text-base-content/50 mb-0">Quickly review key information and manage access for this member.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <span class="badge {{ $user->is_admin ? 'badge-error' : 'badge-ghost' }} me-2">
                    <i class="o-shield-check-lock-fill me-1"></i>{{ $user->is_admin ? 'Administrator' : 'Standard User' }}
                </span>
                <span class="badge {{ $user->disabled ? 'text-bg-dark' : 'badge-success' }}">
                    <i class="o-bolt me-1"></i>{{ $user->disabled ? 'Disabled' : 'Active' }}
                </span>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="flex align-items-center justify-content-between mb-2">
                            <span class="text-uppercase text-base-content/50 small font-semibold">Nation</span>
                            @if($nation)
                                <span class="badge text-bg-light text-base-content/50">#{{ $nation->id }}</span>
                            @endif
                        </div>
                        @if($nation)
                            <h5 class="mb-1">
                                <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank"
                                   rel="noopener" class="link-underline link-underline-opacity-0">
                                    {{ $nation->leader_name ?? 'Unknown Leader' }}
                                </a>
                            </h5>
                            <p class="mb-0 text-base-content/50">{{ $nation->nation_name ?? '—' }}</p>
                        @else
                            <p class="mb-0 text-base-content/50">No nation linked</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <span class="text-uppercase text-base-content/50 small font-semibold">Score &amp; Cities</span>
                        <div class="flex align-items-baseline gap-2 mt-2">
                            <h4 class="mb-0">{{ $nation ? number_format((float) ($nation->score ?? 0), 2) : '—' }}</h4>
                            <span class="text-base-content/50">score</span>
                        </div>
                        <p class="mb-0 text-base-content/50">Cities: {{ $nation ? number_format((int) ($nation->num_cities ?? 0)) : '—' }}</p>
                        <p class="mb-0 text-base-content/50">Wars Won/Lost: {{ $nation ? number_format((int) ($nation->wars_won ?? 0)) : '0' }} /
                            {{ $nation ? number_format((int) ($nation->wars_lost ?? 0)) : '0' }}</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <span class="text-uppercase text-base-content/50 small font-semibold">Alliance</span>
                        @if(optional($nation)->alliance)
                            <h5 class="mb-1">{{ $nation->alliance->name }}</h5>
                            <p class="mb-0 text-base-content/50">Position: {{ ucfirst($nation->alliance_position ?? 'member') }}</p>
                            <p class="mb-0 text-base-content/50">Seniority: {{ number_format((int) ($nation->alliance_seniority ?? 0)) }} days</p>
                        @else
                            <p class="mb-0 text-base-content/50">No current alliance</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <span class="text-uppercase text-base-content/50 small font-semibold">Account Overview</span>
                        <h4 class="mb-1">{{ $accounts->count() }}</h4>
                        <p class="mb-0 text-base-content/50">Linked accounts</p>
                        <p class="mb-1 text-base-content/50">Total balance: ${{ number_format($accountTotalMoney, 2) }}</p>
                        <div class="flex flex-wrap gap-2">
                            @forelse($roles as $role)
                                <x-badge :value="\Illuminate\Support\Str::title($role)" class="badge-primary badge-outline badge-sm max-w-full" />
                            @empty
                                <x-badge value="No roles assigned" class="badge-ghost badge-sm" />
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.users.update', $user->id) }}" class="row g-4 mb-4">
            @csrf
            @method('PUT')

            <div class="col-12 col-xl-7">
                <div class="card shadow-sm mb-4 mb-xl-0">
                    <div class="card-header bg-primary text-white flex align-items-center gap-2">
                        <i class="o-user-fill"></i>
                        <span>Basic Information</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input name="name" type="text" class="form-control" value="{{ old('name', $user->name) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input name="email" type="email" class="form-control" value="{{ old('email', $user->email) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Is Admin</label>
                                <select name="is_admin" class="form-select">
                                    <option value="0" @selected(!$user->is_admin)>No</option>
                                    <option value="1" @selected($user->is_admin)>Yes</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Account Status</label>
                                <select name="disabled" class="form-select">
                                    <option value="0" @selected(!$user->disabled)>Enabled</option>
                                    <option value="1" @selected($user->disabled)>Disabled</option>
                                </select>
                            </div>
                            @if($nation)
                                <div class="col-md-6">
                                    <label class="form-label">Nation ID</label>
                                    <input name="nation_id" type="number" class="form-control" value="{{ old('nation_id', $user->nation_id) }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Verification</label>
                                    <select name="verified_at" class="form-select">
                                        <option value="" @selected(!$user->verified_at)>Not Verified</option>
                                        <option value="1" @selected($user->verified_at)>Verified</option>
                                    </select>
                                </div>
                            @endif
                            <div class="col-12">
                                <label for="roles" class="form-label">Roles</label>
                                <select name="roles[]" id="roles" class="form-select" multiple>
                                    @foreach($allRoles as $role)
                                        <option value="{{ $role->id }}"
                                                {{ $user->roles->pluck('id')->contains($role->id) ? 'selected' : '' }}>
                                            {{ ucfirst($role->name) }}{{ $role->protected ? ' (System)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">Hold Ctrl/Cmd to select multiple roles.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-warning text-dark flex align-items-center gap-2">
                        <i class="o-lock-closed-fill"></i>
                        <span>Change Password</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input name="password" type="password" class="form-control" placeholder="Leave blank to keep current">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input name="password_confirmation" type="password" class="form-control">
                        </div>
                        <p class="small text-base-content/50 mb-0">Passwords must be at least 8 characters long.</p>
                    </div>
                </div>
            </div>

            <div class="col-12 text-right">
                <button class="btn btn-success px-4">
                    <i class="o-check me-2"></i>Save Changes
                </button>
            </div>
        </form>

        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Discord</h5>
                            <small class="text-base-content/50">Bot-verified link status.</small>
                        </div>
                        <span class="badge {{ $discordAccount ? 'badge-success' : 'badge-ghost' }}">
                            {{ $discordAccount ? 'Linked' : 'Not linked' }}
                        </span>
                    </div>
                    <div class="card-body flex justify-content-between align-items-center">
                        <div>
                            @if($discordAccount)
                                <div class="font-semibold">{{ $discordAccount->discord_username }}</div>
                                <div class="text-base-content/50 small">ID: {{ $discordAccount->discord_id }}</div>
                                <div class="text-base-content/50 small">
                                    Linked {{ optional($discordAccount->linked_at)->diffForHumans() ?? 'recently' }}
                                </div>
                            @else
                                <p class="text-base-content/50 mb-0">No Discord account is currently linked.</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('admin.users.discord.unlink', $user) }}">
                            @csrf
                            <button class="btn btn-outline-danger" {{ $discordAccount ? '' : 'disabled' }}>
                                Admin Unlink
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Associated Accounts</h5>
                            <small class="text-base-content/50">Review balances and quick access to each account.</small>
                        </div>
                        <span class="badge text-bg-light text-base-content/50">{{ $accounts->count() }} linked</span>
                    </div>
                    <div class="card-body p-0">
                        @if($accounts->isEmpty())
                            <div class="p-4 text-center text-base-content/50">
                                <i class="o-wallet fs-4 d-block mb-2"></i>
                                No accounts are currently associated with this user.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Account</th>
                                        <th class="text-right">Money</th>
                                        <th class="text-right">Steel</th>
                                        <th class="text-right">Munitions</th>
                                        <th class="text-right">Food</th>
                                        <th>Status</th>
                                        <th class="text-right">Updated</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($accounts as $account)
                                        <tr>
                                            <td>
                                                <a href="{{ route('admin.accounts.view', $account->id) }}" class="font-semibold">
                                                    {{ $account->name }}
                                                </a>
                                                <div class="text-base-content/50 small">Nation #{{ $account->nation_id }}</div>
                                            </td>
                                            <td class="text-right">${{ number_format((float) $account->money, 2) }}</td>
                                            <td class="text-right">{{ number_format((float) $account->steel, 2) }}</td>
                                            <td class="text-right">{{ number_format((float) $account->munitions, 2) }}</td>
                                            <td class="text-right">{{ number_format((float) $account->food, 2) }}</td>
                                            <td>
                                                <span class="badge {{ $account->frozen ? 'badge-error' : 'badge-success' }}">
                                                    {{ $account->frozen ? 'Frozen' : 'Active' }}
                                                </span>
                                            </td>
                                            <td class="text-right text-base-content/50 small">
                                                {{ optional($account->updated_at)->diffForHumans() ?? '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Recent Transactions</h5>
                            <small class="text-base-content/50">The ten most recent transactions linked to this user.</small>
                        </div>
                        <span class="badge text-bg-light text-base-content/50">{{ $recentTransactions->count() }} records</span>
                    </div>
                    <div class="card-body p-0">
                        @if($recentTransactions->isEmpty())
                            <div class="p-4 text-center text-base-content/50">
                                <i class="o-document-text fs-4 d-block mb-2"></i>
                                No recent transactions were found for this user.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>When</th>
                                        <th>Type</th>
                                        <th>Source → Destination</th>
                                        <th>Resources</th>
                                        <th>Status</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($recentTransactions as $transaction)
                                        @php
                                            $resourceBreakdown = collect($resourceKeys)
                                                ->filter(fn($key) => (float) $transaction->$key !== 0.0)
                                                ->mapWithKeys(fn($key) => [$key => $transaction->$key]);

                                            $statusLabel = 'Completed';
                                            $statusClass = 'badge-success';

                                            if ($transaction->refunded_at) {
                                                $statusLabel = 'Refunded';
                                                $statusClass = 'badge-ghost';
                                            } elseif ($transaction->denied_at) {
                                                $statusLabel = 'Denied';
                                                $statusClass = 'badge-error';
                                            } elseif ($transaction->requires_admin_approval && !$transaction->approved_at) {
                                                $statusLabel = 'Awaiting Approval';
                                                $statusClass = 'badge-warning';
                                            } elseif ($transaction->is_pending) {
                                                $statusLabel = 'Pending';
                                                $statusClass = 'badge-warning';
                                            }
                                        @endphp
                                        <tr>
                                            <td>
                                                <div class="font-semibold">{{ optional($transaction->created_at)->format('M j, Y g:i A') ?? '—' }}</div>
                                                @if($transaction->nation)
                                                    <a href="https://politicsandwar.com/nation/id={{ $transaction->nation->id }}" target="_blank" rel="noopener" class="small text-base-content/50">
                                                        Nation #{{ $transaction->nation->id }}
                                                    </a>
                                                @endif
                                            </td>
                                            <td>
                                                <x-badge :value="\Illuminate\Support\Str::title(str_replace('_', ' ', $transaction->transaction_type))" class="badge-primary badge-outline badge-sm" />
                                            </td>
                                            <td>
                                                <div class="small text-base-content/50">From</div>
                                                <div class="font-semibold">
                                                    {{ optional($transaction->fromAccount)->name ?? '—' }}
                                                </div>
                                                <div class="small text-base-content/50">To</div>
                                                <div class="font-semibold">
                                                    {{ optional($transaction->toAccount)->name ?? '—' }}
                                                </div>
                                            </td>
                                            <td>
                                                @if($resourceBreakdown->isEmpty())
                                                    <span class="badge text-bg-light text-base-content/50">No resources</span>
                                                @else
                                                    <div class="flex flex-wrap gap-2">
                                                        @foreach($resourceBreakdown as $resource => $amount)
                                                            <span class="badge text-bg-light text-base-content/50">
                                                                {{ ucfirst($resource) }}:
                                                                {{ $resource === 'money' ? '$' : '' }}{{ number_format((float) $amount, $resource === 'money' ? 2 : 0) }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Latest Nation Sign-In Snapshot</h5>
                            <small class="text-base-content/50">Most recent military and resource data pulled from Politics &amp; War.</small>
                        </div>
                        @if($latestSignIn)
                            <span class="badge text-bg-light text-base-content/50">{{ $latestSignIn->created_at?->diffForHumans() }}</span>
                        @endif
                    </div>
                    <div class="card-body">
                        @if(!$nation)
                            <div class="text-center text-base-content/50 py-4">
                                <i class="o-exclamation-circle fs-4 d-block mb-2"></i>
                                This user is not linked to a nation, so no sign-in data is available.
                            </div>
                        @elseif(!$latestSignIn)
                            <div class="text-center text-base-content/50 py-4">
                                <i class="o-clock fs-4 d-block mb-2"></i>
                                No sign-in data has been recorded for this nation yet.
                            </div>
                        @else
                            <div class="row g-4">
                                <div class="col-12 col-lg-4">
                                    <div class="border rounded-3 p-3 h-100 bg-body-secondary">
                                        <h6 class="font-semibold mb-3 text-uppercase small">Nation Overview</h6>
                                        <dl class="row mb-0 small">
                                            <dt class="col-6 text-base-content/50">Score</dt>
                                            <dd class="col-6">{{ number_format((float) $latestSignIn->score, 2) }}</dd>
                                            <dt class="col-6 text-base-content/50">Cities</dt>
                                            <dd class="col-6">{{ number_format((int) $latestSignIn->num_cities) }}</dd>
                                            <dt class="col-6 text-base-content/50">Wars Won</dt>
                                            <dd class="col-6">{{ number_format((int) $latestSignIn->wars_won) }}</dd>
                                            <dt class="col-6 text-base-content/50">Wars Lost</dt>
                                            <dd class="col-6">{{ number_format((int) $latestSignIn->wars_lost) }}</dd>
                                            <dt class="col-6 text-base-content/50">Recorded</dt>
                                            <dd class="col-6">{{ $latestSignIn->created_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                                        </dl>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <div class="border rounded-3 p-3 h-100 bg-body-secondary">
                                        <h6 class="font-semibold mb-3 text-uppercase small">Military Forces</h6>
                                        <div class="row row-cols-2 g-3 small">
                                            <div class="col">
                                                <span class="text-base-content/50 d-block">Soldiers</span>
                                                <span class="font-semibold">{{ number_format((int) $latestSignIn->soldiers) }}</span>
                                            </div>
                                            <div class="col">
                                                <span class="text-base-content/50 d-block">Tanks</span>
                                                <span class="font-semibold">{{ number_format((int) $latestSignIn->tanks) }}</span>
                                            </div>
                                            <div class="col">
                                                <span class="text-base-content/50 d-block">Aircraft</span>
                                                <span class="font-semibold">{{ number_format((int) $latestSignIn->aircraft) }}</span>
                                            </div>
                                            <div class="col">
                                                <span class="text-base-content/50 d-block">Ships</span>
                                                <span class="font-semibold">{{ number_format((int) $latestSignIn->ships) }}</span>
                                            </div>
                                            <div class="col">
                                                <span class="text-base-content/50 d-block">Missiles</span>
                                                <span class="font-semibold">{{ number_format((int) $latestSignIn->missiles) }}</span>
                                            </div>
                                            <div class="col">
                                                <span class="text-base-content/50 d-block">Nukes</span>
                                                <span class="font-semibold">{{ number_format((int) $latestSignIn->nukes) }}</span>
                                            </div>
                                            <div class="col">
                                                <span class="text-base-content/50 d-block">Spies</span>
                                                <span class="font-semibold">{{ number_format((int) $latestSignIn->spies) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="border rounded-3 p-3 bg-body-secondary">
                                        <h6 class="font-semibold mb-3 text-uppercase small">Resource Holdings</h6>
                                        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-xl-6 g-3 small">
                                            @foreach($resourceKeys as $resource)
                                                <div class="col">
                                                    <div class="rounded-2 border px-3 py-2 h-100">
                                                        <span class="text-base-content/50 d-block">{{ ucfirst($resource) }}</span>
                                                        <span class="font-semibold">
                                                            {{ $resource === 'money' ? '$' : '' }}{{ number_format((float) $latestSignIn->$resource, $resource === 'money' ? 2 : 0) }}
                                                        </span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
