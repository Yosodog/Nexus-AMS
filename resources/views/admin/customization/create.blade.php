@extends('layouts.admin')

@section('title', 'Create Custom Page')

@section('content')
    <x-header title="Create custom page" separator use-h1>
        <x-slot:subtitle>Choose the page URL and audience. You can write and publish the page after it is created.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.customization.index') }}" class="btn btn-ghost btn-sm">
                <x-icon name="o-arrow-left" class="size-4" />
                Back to pages
            </a>
        </x-slot:actions>
    </x-header>

    <x-card title="Page details" class="max-w-3xl">
        <form method="POST" action="{{ route('admin.customization.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="customization-page-title" class="fieldset-legend">Title</label>
                <input
                    id="customization-page-title"
                    name="title"
                    type="text"
                    class="input w-full"
                    value="{{ old('title') }}"
                    maxlength="255"
                    required
                    autofocus
                >
                @error('title')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="customization-page-slug" class="fieldset-legend">Slug</label>
                <div class="join w-full">
                    <span class="join-item flex items-center border border-base-300 bg-base-200 px-3 text-sm nexus-text-muted">/pages/</span>
                    <input
                        id="customization-page-slug"
                        name="slug"
                        type="text"
                        class="input join-item w-full"
                        value="{{ old('slug') }}"
                        maxlength="100"
                        pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                        placeholder="about-us"
                        required
                    >
                </div>
                <p class="mt-1 text-sm nexus-text-muted">Use lowercase letters, numbers, and single hyphens. The slug cannot be changed after creation.</p>
                @error('slug')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="customization-page-description" class="fieldset-legend">Search description <span class="font-normal nexus-text-muted">(optional)</span></label>
                <textarea
                    id="customization-page-description"
                    name="description"
                    class="textarea w-full"
                    rows="3"
                    maxlength="320"
                >{{ old('description') }}</textarea>
                <p class="mt-1 text-sm nexus-text-muted">Used for search metadata when the page is public.</p>
                @error('description')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <fieldset>
                <legend class="fieldset-legend">Audience</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="cursor-pointer rounded-box border border-base-300 p-4 has-[:checked]:border-primary has-[:checked]:bg-primary/10">
                        <span class="flex items-start gap-3">
                            <input type="radio" name="audience" value="public" class="radio radio-primary mt-0.5" @checked(old('audience', 'public') === 'public')>
                            <span>
                                <span class="block font-semibold">Public</span>
                                <span class="mt-1 block text-sm nexus-text-muted">Anyone can view the page after it is published.</span>
                            </span>
                        </span>
                    </label>
                    <label class="cursor-pointer rounded-box border border-base-300 p-4 has-[:checked]:border-primary has-[:checked]:bg-primary/10">
                        <span class="flex items-start gap-3">
                            <input type="radio" name="audience" value="member" class="radio radio-primary mt-0.5" @checked(old('audience') === 'member')>
                            <span>
                                <span class="block font-semibold">Alliance members</span>
                                <span class="mt-1 block text-sm nexus-text-muted">Accepted members of the primary alliance or enabled offshores can view it.</span>
                            </span>
                        </span>
                    </label>
                </div>
                @error('audience')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="flex flex-wrap justify-end gap-2 border-t border-base-300 pt-4">
                <a href="{{ route('admin.customization.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <x-icon name="o-plus" class="size-4" />
                    Create page
                </button>
            </div>
        </form>
    </x-card>
@endsection
