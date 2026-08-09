@extends('layouts.admin')

@section('title', 'Federation publication preview')

@section('content')
    <x-header title="Exact federation preview" separator use-h1>
        <x-slot:subtitle>These are the canonical bytes that will be signed and encrypted independently for each recipient.</x-slot:subtitle>
    </x-header>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-6">
            @foreach ($version->deliveries as $delivery)
                <section class="nexus-panel" aria-labelledby="recipient-{{ $delivery->id }}">
                    <div class="nexus-panel__header"><div><h2 id="recipient-{{ $delivery->id }}" class="nexus-section-title">{{ $delivery->link?->remote_display_name ?: $delivery->recipient_installation_id }}</h2><p class="mt-1 break-all text-xs nexus-text-muted">{{ $delivery->recipient_installation_id }}</p></div><span class="badge badge-outline">{{ number_format($delivery->payload_bytes) }} bytes</span></div>
                    <div class="nexus-panel__body space-y-3">
                        <p class="break-all font-mono text-xs"><strong>SHA-256:</strong> {{ $delivery->payload_hash }}</p>
                        <pre class="max-h-[34rem] overflow-auto rounded-md bg-neutral p-4 text-xs leading-6 text-neutral-content"><code>{{ $delivery->canonical_payload }}</code></pre>
                    </div>
                </section>
            @endforeach
        </div>

        <aside class="space-y-6 xl:sticky xl:top-4">
            <section class="nexus-panel">
                <div class="nexus-panel__header"><div><h2 class="nexus-section-title">Preview contract</h2><p class="mt-1 text-sm text-base-content/65">Any source, target, recipient, coalition, capability, generation, or byte change invalidates this preview.</p></div></div>
                <dl class="divide-y divide-base-300 text-sm"><div class="p-4"><dt class="nexus-text-muted">Publication</dt><dd class="mt-1 break-all font-mono text-xs">{{ $version->federation_publication_id }}</dd></div><div class="p-4"><dt class="nexus-text-muted">Version / revision</dt><dd class="mt-1 font-semibold">v{{ $version->version }} / r{{ $version->revision }}</dd></div><div class="p-4"><dt class="nexus-text-muted">Expiry</dt><dd class="mt-1 font-semibold">{{ $version->expires_at->toDayDateTimeString() }}</dd></div><div class="p-4"><dt class="nexus-text-muted">Preview SHA-256</dt><dd class="mt-1 break-all font-mono text-xs">{{ $version->preview_hash }}</dd></div></dl>
            </section>

            <section class="nexus-panel">
                <div class="nexus-panel__header"><h2 class="nexus-section-title">Always excluded</h2></div>
                <ul class="list-disc space-y-2 p-5 pl-9 text-sm leading-6 text-base-content/70">@foreach ($excludedCategories as $category)<li>{{ $category }}</li>@endforeach</ul>
            </section>

            <form method="POST" action="{{ route('admin.federation.publications.publish', $version) }}" data-confirm="Publish these exact payload bytes to every listed recipient? Future source changes require a new immutable version." data-confirm-title="Publish federation snapshot?" data-confirm-label="Publish version">
                @csrf
                <input type="hidden" name="preview_hash" value="{{ $version->preview_hash }}">
                <button class="btn btn-primary w-full" type="submit">Publish exact preview</button>
            </form>
            <a class="btn btn-ghost w-full" href="{{ route('admin.federation.index') }}#publish">Return without publishing</a>
        </aside>
    </div>
@endsection
