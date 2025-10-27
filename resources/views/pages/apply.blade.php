@extends('layouts.main')

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body prose max-w-none">
                @if ($content)
                    {!! $content !!}
                @else
                    <p class="text-base-content/70">
                        Applications are currently closed. Please check back soon for updates.
                    </p>
                @endif
            </div>
        </div>
    </div>
@endsection
