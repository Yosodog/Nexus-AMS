@extends('layouts.main')

@section('content')
    <div class="mx-auto w-full max-w-5xl space-y-6 prose">
        <div class="space-y-6 leading-relaxed break-words">
            {!! $content !!}
        </div>
    </div>
@endsection
