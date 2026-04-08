@extends('layouts.admin')

@section('content')
    @php
        $nation = $user->nation;
        $latestSignIn = $latestSignIn ?? optional($nation)->latestSignIn;
        $accounts = $accounts ?? collect();
        $resourceKeys = ['money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'];
        $accountTotalMoney = $accounts->sum('money');
        $roles = $user->roles->pluck('name');
        $selectedRoleIds = $user->roles->pluck('id')->all();
        $statusBadgeClass = $user->disabled ? 'badge-warning' : 'badge-success';
        $accountStateBadgeClass = $user->is_admin ? 'badge-error' : 'badge-ghost';
        $allianceName = $nation?->alliance?->name;
    @endphp

    <x-header :title="'Edit User: '.$user->name" separator>
        <x-slot:subtitle>Review access, linked nation data, Discord state, and recent financial activity for this member.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge {{ $accountStateBadgeClass }} badge-lg">{{ $user->is_admin ? 'Administrator' : 'Standard User' }}</span>
                <span class="badge {{ $statusBadgeClass }} badge-lg">{{ $user->disabled ? 'Disabled' : 'Active' }}</span>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-card>
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-base-content/55">Nation</p>
                            @if($nation)
                                <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener" class="mt-2 block text-lg font-semibold link link-primary">
                                    {{ $nation->leader_name ?? 'Unknown Leader' }}
                                </a>
                                <p class="text-sm text-base-content/60">{{ $nation->nation_name ?? '—' }}</p>
                            @else
                                <p class="mt-2 text-sm text-base-content/60">No nation linked.</p>
                            @endif
                        </div>
                        @if($nation)
                            <span class="badge badge-ghost">#{{ $nation->id }}</span>
                        @endif
                    </div>
                </div>
            </x-card>

            <x-card>
                <div class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-base-content/55">Score & Cities</p>
                    <div class="flex items-end gap-2">
                        <span class="text-2xl font-bold">{{ $nation ? number_format((float) ($nation->score ?? 0), 2) : '—' }}</span>
                        <span class="pb-1 text-sm text-base-content/60">score</span>
                    </div>
                    <div class="space-y-1 text-sm text-base-content/70">
                        <p>Cities: {{ $nation ? number_format((int) ($nation->num_cities ?? 0)) : '—' }}</p>
                        <p>Wars won/lost: {{ $nation ? number_format((int) ($nation->wars_won ?? 0)) : '0' }} / {{ $nation ? number_format((int) ($nation->wars_lost ?? 0)) : '0' }}</p>
                    </div>
                </div>
            </x-card>

            <x-card>
                <div class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-base-content/55">Alliance</p>
                    @if($allianceName)
                        <p class="text-lg font-semibold">{{ $allianceName }}</p>
                        <div class="space-y-1 text-sm text-base-content/70">
                            <p>Position: {{ ucfirst($nation->alliance_position ?? 'member') }}</p>
                            <p>Seniority: {{ number_format((int) ($nation->alliance_seniority ?? 0)) }} days</p>
                        </div>
                    @else
                        <p class="text-sm text-base-content/60">No current alliance.</p>
                    @endif
                </div>
            </x-card>

            <x-card>
                <div class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-base-content/55">Account Overview</p>
                    <p class="text-2xl font-bold">{{ $accounts->count() }}</p>
                    <div class="space-y-1 text-sm text-base-content/70">
                        <p>Linked accounts</p>
                        <p>Total balance: ${{ number_format($accountTotalMoney, 2) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @forelse($roles as $role)
                            <x-badge :value="\Illuminate\Support\Str::title($role)" class="badge-primary badge-outline badge-sm max-w-full" />
                        @empty
                            <x-badge value="No roles assigned" class="badge-ghost badge-sm" />
                        @endforelse
                    </div>
                </div>
            </x-card>
        </div>

        <form method="POST" action="{{ route('admin.users.update', $user->id) }}" class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,0.7fr)]">
            @csrf
            @method('PUT')

            <x-card title="Basic Information" subtitle="Update access state, verification, linked nation, and role assignments.">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-1">
                        <label for="user-name" class="text-sm font-semibold">Username</label>
                        <input id="user-name" name="name" type="text" class="input input-bordered w-full" value="{{ old('name', $user->name) }}">
                    </div>

                    <div class="space-y-1">
                        <label for="user-email" class="text-sm font-semibold">Email</label>
                        <input id="user-email" name="email" type="email" class="input input-bordered w-full" value="{{ old('email', $user->email) }}">
                    </div>

                    <div class="space-y-1">
                        <label for="user-admin" class="text-sm font-semibold">Is Admin</label>
                        <select id="user-admin" name="is_admin" class="select select-bordered w-full">
                            <option value="0" @selected(! $user->is_admin)>No</option>
                            <option value="1" @selected($user->is_admin)>Yes</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label for="user-disabled" class="text-sm font-semibold">Account Status</label>
                        <select id="user-disabled" name="disabled" class="select select-bordered w-full">
                            <option value="0" @selected(! $user->disabled)>Enabled</option>
                            <option value="1" @selected($user->disabled)>Disabled</option>
                        </select>
                    </div>

                    @if($nation)
                        <div class="space-y-1">
                            <label for="user-nation-id" class="text-sm font-semibold">Nation ID</label>
                            <input id="user-nation-id" name="nation_id" type="number" class="input input-bordered w-full" value="{{ old('nation_id', $user->nation_id) }}">
                        </div>

                        <div class="space-y-1">
                            <label for="user-verified" class="text-sm font-semibold">Verification</label>
                            <select id="user-verified" name="verified_at" class="select select-bordered w-full">
                                <option value="" @selected(! $user->verified_at)>Not Verified</option>
                                <option value="1" @selected($user->verified_at)>Verified</option>
                            </select>
                        </div>
                    @endif

                    <div class="space-y-1 md:col-span-2">
                        <label for="roles" class="text-sm font-semibold">Roles</label>
                        <select name="roles[]" id="roles" class="select select-bordered min-h-48 w-full" multiple size="{{ max(6, min(10, $allRoles->count())) }}">
                            @foreach($allRoles as $role)
                                <option value="{{ $role->id }}" @selected(in_array($role->id, $selectedRoleIds, true))>
                                    {{ ucfirst($role->name) }}{{ $role->protected ? ' (System)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-sm text-base-content/60">Hold Ctrl or Cmd to select multiple roles.</p>
                    </div>
                </div>
            </x-card>

            <x-card title="Change Password" subtitle="Leave both fields blank to keep the current password.">
                <div class="space-y-4">
                    <div class="space-y-1">
                        <label for="user-password" class="text-sm font-semibold">New Password</label>
                        <input id="user-password" name="password" type="password" class="input input-bordered w-full" placeholder="Leave blank to keep current">
                    </div>

                    <div class="space-y-1">
                        <label for="user-password-confirmation" class="text-sm font-semibold">Confirm Password</label>
                        <input id="user-password-confirmation" name="password_confirmation" type="password" class="input input-bordered w-full">
                    </div>

                    <div class="rounded-box bg-base-200/70 p-4 text-sm text-base-content/70">
                        Passwords must be at least 8 characters long.
                    </div>
                </div>
            </x-card>

            <div class="xl:col-span-2 flex justify-end">
                <button class="btn btn-success">
                    <x-icon name="o-check" class="size-4" />
                    Save Changes
                </button>
            </div>
        </form>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <x-card title="Discord" subtitle="Bot-verified link status.">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-2">
                        <span class="badge {{ $discordAccount ? 'badge-success' : 'badge-ghost' }}">
                            {{ $discordAccount ? 'Linked' : 'Not linked' }}
                        </span>
                        @if($discordAccount)
                            <div class="space-y-1 text-sm">
                                <p class="font-semibold">{{ $discordAccount->discord_username }}</p>
                                <p class="text-base-content/60">ID: {{ $discordAccount->discord_id }}</p>
                                <p class="text-base-content/60">Linked {{ optional($discordAccount->linked_at)->diffForHumans() ?? 'recently' }}</p>
                            </div>
                        @else
                            <p class="text-sm text-base-content/60">No Discord account is currently linked.</p>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('admin.users.discord.unlink', $user) }}">
                        @csrf
                        <button class="btn btn-error btn-outline btn-sm" @disabled(! $discordAccount)>
                            Admin Unlink
                        </button>
                    </form>
                </div>
            </x-card>

            <x-card title="Associated Accounts" :subtitle="$accounts->count().' linked'">
                @if($accounts->isEmpty())
                    <div class="rounded-box border border-dashed border-base-300 px-6 py-8 text-center text-base-content/60">
                        No accounts are currently associated with this user.
                    </div>
                @else
                    <div class="overflow-x-auto rounded-box border border-base-300">
                        <table class="table table-zebra">
                            <thead>
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
                                            <a href="{{ route('admin.accounts.view', $account->id) }}" class="font-semibold link link-primary">
                                                {{ $account->name }}
                                            </a>
                                            <div class="text-sm text-base-content/60">Nation #{{ $account->nation_id }}</div>
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
                                        <td class="text-right text-sm text-base-content/60">{{ optional($account->updated_at)->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <x-card title="Recent Transactions" :subtitle="$recentTransactions->count().' records'">
            @if($recentTransactions->isEmpty())
                <div class="rounded-box border border-dashed border-base-300 px-6 py-8 text-center text-base-content/60">
                    No recent transactions were found for this user.
                </div>
            @else
                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Type</th>
                                <th>Source to Destination</th>
                                <th>Resources</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentTransactions as $transaction)
                                @php
                                    $resourceBreakdown = collect($resourceKeys)
                                        ->filter(fn ($key) => (float) $transaction->$key !== 0.0)
                                        ->mapWithKeys(fn ($key) => [$key => $transaction->$key]);

                                    $statusLabel = 'Completed';
                                    $statusClass = 'badge-success';

                                    if ($transaction->refunded_at) {
                                        $statusLabel = 'Refunded';
                                        $statusClass = 'badge-ghost';
                                    } elseif ($transaction->denied_at) {
                                        $statusLabel = 'Denied';
                                        $statusClass = 'badge-error';
                                    } elseif ($transaction->requires_admin_approval && ! $transaction->approved_at) {
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
                                            <a href="https://politicsandwar.com/nation/id={{ $transaction->nation->id }}" target="_blank" rel="noopener" class="text-sm text-base-content/60 link link-hover">
                                                Nation #{{ $transaction->nation->id }}
                                            </a>
                                        @endif
                                    </td>
                                    <td>
                                        <x-badge :value="\Illuminate\Support\Str::title(str_replace('_', ' ', $transaction->transaction_type))" class="badge-primary badge-outline badge-sm" />
                                    </td>
                                    <td>
                                        <div class="text-sm text-base-content/60">From</div>
                                        <div class="font-semibold">{{ optional($transaction->fromAccount)->name ?? '—' }}</div>
                                        <div class="mt-2 text-sm text-base-content/60">To</div>
                                        <div class="font-semibold">{{ optional($transaction->toAccount)->name ?? '—' }}</div>
                                    </td>
                                    <td>
                                        @if($resourceBreakdown->isEmpty())
                                            <span class="badge badge-ghost">No resources</span>
                                        @else
                                            <div class="flex flex-wrap gap-2">
                                                @foreach($resourceBreakdown as $resource => $amount)
                                                    <span class="badge badge-ghost">
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
        </x-card>

        <x-card title="Latest Nation Sign-In Snapshot" :subtitle="$latestSignIn ? ($latestSignIn->created_at?->diffForHumans() ?? 'Recorded recently') : 'No snapshot available'">
            @if(! $nation)
                <div class="rounded-box border border-dashed border-base-300 px-6 py-8 text-center text-base-content/60">
                    This user is not linked to a nation, so no sign-in data is available.
                </div>
            @elseif(! $latestSignIn)
                <div class="rounded-box border border-dashed border-base-300 px-6 py-8 text-center text-base-content/60">
                    No sign-in data has been recorded for this nation yet.
                </div>
            @else
                <div class="grid gap-4 xl:grid-cols-[minmax(0,0.7fr)_minmax(0,0.7fr)_minmax(0,1fr)]">
                    <div class="rounded-box border border-base-300 bg-base-200/60 p-4">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-base-content/55">Nation Overview</h3>
                        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt class="text-base-content/60">Score</dt>
                            <dd>{{ number_format((float) $latestSignIn->score, 2) }}</dd>
                            <dt class="text-base-content/60">Cities</dt>
                            <dd>{{ number_format((int) $latestSignIn->num_cities) }}</dd>
                            <dt class="text-base-content/60">Wars Won</dt>
                            <dd>{{ number_format((int) $latestSignIn->wars_won) }}</dd>
                            <dt class="text-base-content/60">Wars Lost</dt>
                            <dd>{{ number_format((int) $latestSignIn->wars_lost) }}</dd>
                            <dt class="text-base-content/60">Recorded</dt>
                            <dd>{{ $latestSignIn->created_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                        </dl>
                    </div>

                    <div class="rounded-box border border-base-300 bg-base-200/60 p-4">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-base-content/55">Military Forces</h3>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2">
                                <div class="text-base-content/60">Soldiers</div>
                                <div class="font-semibold">{{ number_format((int) $latestSignIn->soldiers) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2">
                                <div class="text-base-content/60">Tanks</div>
                                <div class="font-semibold">{{ number_format((int) $latestSignIn->tanks) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2">
                                <div class="text-base-content/60">Aircraft</div>
                                <div class="font-semibold">{{ number_format((int) $latestSignIn->aircraft) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2">
                                <div class="text-base-content/60">Ships</div>
                                <div class="font-semibold">{{ number_format((int) $latestSignIn->ships) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2">
                                <div class="text-base-content/60">Missiles</div>
                                <div class="font-semibold">{{ number_format((int) $latestSignIn->missiles) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2">
                                <div class="text-base-content/60">Nukes</div>
                                <div class="font-semibold">{{ number_format((int) $latestSignIn->nukes) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2 col-span-2">
                                <div class="text-base-content/60">Spies</div>
                                <div class="font-semibold">{{ number_format((int) $latestSignIn->spies) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box border border-base-300 bg-base-200/60 p-4">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-base-content/55">Resource Holdings</h3>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach($resourceKeys as $resource)
                                <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2 text-sm">
                                    <div class="text-base-content/60">{{ ucfirst($resource) }}</div>
                                    <div class="font-semibold">
                                        {{ $resource === 'money' ? '$' : '' }}{{ number_format((float) $latestSignIn->$resource, $resource === 'money' ? 2 : 0) }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </x-card>
    </div>
@endsection
