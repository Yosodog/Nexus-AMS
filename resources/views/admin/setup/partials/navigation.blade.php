<nav class="nexus-panel overflow-x-auto" aria-label="Alliance setup steps">
    <ol class="flex min-w-max items-center gap-1 p-2">
        @foreach (\App\Enums\AllianceSetupStep::cases() as $step)
            <li>
                <a
                    href="{{ route($step->routeName()) }}"
                    class="btn btn-sm {{ isset($currentStep) && $currentStep === $step ? 'btn-primary' : 'btn-ghost' }}"
                    @if (isset($currentStep) && $currentStep === $step) aria-current="step" @endif
                >
                    <span class="badge badge-sm">{{ $loop->iteration }}</span>
                    {{ $step->label() }}
                </a>
            </li>
        @endforeach
    </ol>
</nav>
