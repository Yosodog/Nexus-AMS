@props([
    'id' => 'form-errors',
    'title' => 'Check the form and try again.',
])

@if($errors->any())
    <div id="{{ $id }}" class="alert alert-error items-start" role="alert" aria-labelledby="{{ $id }}-title" tabindex="-1">
        <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <div>
            <p id="{{ $id }}-title" class="font-semibold">{{ $title }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
