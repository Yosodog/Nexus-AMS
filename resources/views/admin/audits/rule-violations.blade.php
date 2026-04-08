@extends('layouts.admin')

@section('title', 'Audit Violations')

@section('content')
    @php
        $priorityBadgeClass = match ($rule->priority->value) {
            'high' => 'badge-error',
            'medium' => 'badge-warning',
            'low' => 'badge-info',
            default => 'badge-ghost',
        };
    @endphp

    <x-header :title="'Violations: ' . $rule->name" separator>
        <x-slot:subtitle>Live offenders for this rule. Rows clear automatically when targets comply.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-primary btn-outline btn-sm">
                    <x-icon name="o-pencil" class="size-4" />
                    Edit rule
                </a>
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-ghost btn-sm">Back to rules</a>
            </div>
        </x-slot:actions>
    </x-header>

    <x-card title="Current violations" subtitle="Rows are removed automatically after the next compliant audit pass.">
        <x-slot:menu>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $rule->target_type->value === 'nation' ? 'badge-primary' : 'badge-info' }}">
                    {{ ucfirst($rule->target_type->value) }} rule
                </span>
                <span class="badge {{ $priorityBadgeClass }}">
                    Priority: {{ ucfirst($rule->priority->value) }}
                </span>
                <code class="rounded-box bg-base-200 px-3 py-1 text-xs">{{ $rule->expression }}</code>
            </div>
        </x-slot:menu>

        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra">
                <thead>
                <tr>
                    <th scope="col">Target</th>
                    <th scope="col">First detected</th>
                    <th scope="col">Last evaluated</th>
                </tr>
                </thead>
                <tbody>
                @forelse($violations as $violation)
                    <tr>
                        <td>
                            @if($rule->target_type->value === 'nation')
                                <div class="font-semibold">
                                    <a
                                        href="https://politicsandwar.com/nation/id={{ $violation->nation?->id }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="link link-primary"
                                    >
                                        {{ $violation->nation?->leader_name ?? 'Unknown leader' }}
                                    </a>
                                </div>
                                <div class="text-sm text-base-content/60">
                                    <span>{{ $violation->nation?->nation_name ?? 'Unknown nation' }}</span>
                                    <span>· Score {{ number_format((float) $violation->nation?->score, 2) }}</span>
                                    <span>· Cities {{ $violation->nation?->num_cities }}</span>
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-2">
                                    <a
                                        href="https://politicsandwar.com/city/id={{ $violation->city?->id }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="link link-primary font-semibold"
                                    >
                                        {{ $violation->city?->name ?? 'Unknown city' }}
                                    </a>
                                    <span class="badge {{ $violation->city?->powered ? 'badge-success' : 'badge-ghost' }}">
                                        {{ $violation->city?->powered ? 'Powered' : 'Unpowered' }}
                                    </span>
                                </div>
                                <div class="text-sm text-base-content/60">
                                    <span>Infra {{ number_format((float) $violation->city?->infrastructure, 0) }}</span>
                                    <span>· Land {{ number_format((float) $violation->city?->land, 0) }}</span>
                                    @if($violation->nation)
                                        <span>·</span>
                                        <a
                                            href="https://politicsandwar.com/nation/id={{ $violation->nation->id }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="link link-hover"
                                        >
                                            {{ $violation->nation->leader_name }} ({{ $violation->nation->nation_name }})
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="font-semibold">{{ $violation->first_detected_at->diffForHumans() }}</div>
                            <div class="text-sm text-base-content/60">{{ $violation->first_detected_at->toDayDateTimeString() }}</div>
                        </td>
                        <td>
                            <div class="font-semibold">{{ $violation->last_evaluated_at->diffForHumans() }}</div>
                            <div class="text-sm text-base-content/60">{{ $violation->last_evaluated_at->toDayDateTimeString() }}</div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="py-6 text-center text-sm text-base-content/60">No current violations for this rule.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
