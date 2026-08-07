@props([
    'errorBag' => 'default',
    'fieldIds' => [],
    'focus' => true,
    'id' => null,
    'only' => null,
    'title' => 'Check the form and try again.',
])

@php
    $summaryId = $id ?? 'form-errors-'.Illuminate\Support\Str::lower(Illuminate\Support\Str::random(8));
    $messageBag = isset($errors) && method_exists($errors, 'getBag')
        ? $errors->getBag($errorBag)
        : ($errors ?? new Illuminate\Support\MessageBag);
    $allowedKeys = $only === null ? null : collect($only)->map(static fn ($key) => (string) $key);
    $resolvedFieldIds = collect($fieldIds)->mapWithKeys(
        static fn ($fieldId, $key) => [(string) $key => (string) $fieldId]
    );
    $errorItems = collect($messageBag->getMessages())
        ->when($allowedKeys !== null, static fn ($messages) => $messages->only($allowedKeys->all()))
        ->flatMap(static fn ($messages, $key) => collect($messages)->map(static fn ($message) => [
            'field' => (string) $key,
            'message' => (string) $message,
        ]))
        ->values();
@endphp

@if ($errorItems->isNotEmpty())
    <div
        id="{{ $summaryId }}"
        {{ $attributes->class(['alert alert-error items-start']) }}
        role="alert"
        aria-labelledby="{{ $summaryId }}-title"
        tabindex="-1"
        data-form-error-summary
        data-focus-on-load="{{ $focus ? 'true' : 'false' }}"
    >
        <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <div>
            <p id="{{ $summaryId }}-title" class="font-semibold">{{ $title }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($errorItems as $item)
                    @php $targetId = $resolvedFieldIds->get($item['field']); @endphp
                    <li>
                        @if ($targetId)
                            <a
                                href="#{{ $targetId }}"
                                class="link link-hover font-medium"
                                data-form-error-link
                                data-error-key="{{ $item['field'] }}"
                            >{{ $item['message'] }}</a>
                        @else
                            <span>{{ $item['message'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
