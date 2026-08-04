@extends('layouts.admin')

@section('title', 'New Milcom plan')

@section('content')
    <div data-milcom-app="plan-create" data-api-base="{{ url('/api/v1/milcom') }}" class="contents">
        <x-header title="New mass war plan" separator use-h1>
            <x-slot:subtitle>Start the plan here. You will choose alliances, targets, and staffing next.</x-slot:subtitle>
        </x-header>

        @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'plans'])

        <div class="mx-auto grid max-w-4xl gap-6">
            <section class="nexus-panel">
                <form
                    method="POST"
                    action="{{ url('/api/v1/milcom/operations') }}"
                    class="grid gap-6 p-5 md:p-6"
                    data-milcom-command="create-plan"
                    data-success-redirect
                >
                    @csrf

                    <div class="grid gap-5 md:grid-cols-2">
                        <label class="block md:col-span-2">
                            <span class="label px-0">Plan name</span>
                            <input
                                name="name"
                                class="input w-full"
                                required
                                minlength="3"
                                maxlength="160"
                                placeholder="Coalition Dawn"
                                autofocus
                            >
                            <span class="mt-1 block text-xs text-base-content/60">Use a name your officers will recognize.</span>
                        </label>

                        <label class="block">
                            <span class="label px-0">Declaration deadline</span>
                            <input name="deadline_at" type="datetime-local" class="input w-full">
                            <span class="mt-1 block text-xs text-base-content/60">You can change the deadline for individual targets later.</span>
                        </label>

                        <label class="block">
                            <span class="label px-0">Wave</span>
                            <input name="wave" type="number" min="1" max="99" value="1" class="input w-full">
                        </label>

                        <label class="block">
                            <span class="label px-0">Default war type</span>
                            <select name="default_war_type" class="select w-full">
                                @foreach (['ORDINARY' => 'Ordinary', 'ATTRITION' => 'Attrition', 'RAID' => 'Raid'] as $value => $label)
                                    <option value="{{ $value }}" @selected(data_get($settings, 'default_war_type', 'ORDINARY') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block">
                            <span class="label px-0">Default reason</span>
                            <input
                                name="default_war_reason"
                                value="{{ data_get($settings, 'default_war_reason') }}"
                                maxlength="255"
                                class="input w-full"
                            >
                        </label>

                        <label class="block md:col-span-2">
                            <span class="label px-0">Discord forum ID (optional)</span>
                            <input
                                name="discord_forum_id"
                                value="{{ data_get($settings, 'forum_id') }}"
                                inputmode="numeric"
                                pattern="\d{17,20}"
                                class="input w-full"
                            >
                            <span class="mt-1 block text-xs text-base-content/60">Leave this blank to use the default forum from Milcom settings.</span>
                        </label>
                    </div>

                    <div class="alert alert-info items-start" role="note">
                        <x-icon name="o-information-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <p class="text-sm">Creating the plan does not notify anyone. Next, you will choose targets, review teams, approve them, and create the Discord rooms.</p>
                    </div>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <a href="{{ route('admin.milcom.plans') }}" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="o-arrow-right" class="size-5" aria-hidden="true" />
                            Create plan and choose targets
                        </button>
                    </div>
                </form>
            </section>

            <div class="hidden alert alert-error items-start" role="alert" data-milcom-feedback>
                <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div>
                    <div class="font-semibold" data-milcom-feedback-title>Could not create plan</div>
                    <p class="text-sm" data-milcom-feedback-message>Check the fields and try again.</p>
                </div>
            </div>
            <p class="sr-only" role="status" aria-live="polite" data-milcom-status></p>
        </div>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection
