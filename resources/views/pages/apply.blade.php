@extends('layouts.main')

@section('content')
    <section class="apply-page-shell">
        <article class="apply-page-content">
            <div class="apply-page-richtext">
                {!! $content !!}
            </div>
        </article>
    </section>
@endsection
