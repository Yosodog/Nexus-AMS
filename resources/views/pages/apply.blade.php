@extends('layouts.main')

@section('content')
    <div class="max-w-5xl mx-auto space-y-6 prose">
        <div class="space-y-6 leading-relaxed">
            {!! $content !!}
        </div>
    </div>
@endsection
