@props([
    'title' => '',      // The card's title
    'body' => '',       // The card's body text
    'extraClasses' => '' // Any extra classes for customization
])

<div class="card bg-base-100 shadow-xl card-bordered border-2 {{ $extraClasses }}">
    <div class="card-body">
        @if($title)
            <h2 class="card-title">{{ $title }}</h2>
        @endif

        {{ $slot }}

        @if($body)
            <p>{{ $body }}</p>
        @endif
    </div>
</div>
