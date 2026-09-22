@extends($pageLayout)

@section('title', $title . ' · ' . config('app.name'))

@section('content')
    <div class="mx-auto w-full max-w-5xl px-4 py-10 sm:px-6 sm:py-14">
        <header class="mb-8 border-b border-base-300 pb-6">
            <h1 class="font-display text-4xl font-bold text-base-content">{{ $title }}</h1>
        </header>

        <article class="prose max-w-none">
            {!! $content !!}
        </article>
    </div>
@endsection
