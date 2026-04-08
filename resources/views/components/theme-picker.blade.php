@php
    $themes = [
        ['key' => 'light', 'label' => 'Light'],
        ['key' => 'dark', 'label' => 'Dark'],
        ['key' => 'cupcake', 'label' => 'Cupcake'],
        ['key' => 'bumblebee', 'label' => 'Bumblebee'],
        ['key' => 'emerald', 'label' => 'Emerald'],
        ['key' => 'corporate', 'label' => 'Corporate'],
        ['key' => 'synthwave', 'label' => 'Synthwave'],
        ['key' => 'retro', 'label' => 'Retro'],
        ['key' => 'cyberpunk', 'label' => 'Cyberpunk'],
        ['key' => 'valentine', 'label' => 'Valentine'],
        ['key' => 'halloween', 'label' => 'Halloween'],
        ['key' => 'garden', 'label' => 'Garden'],
        ['key' => 'forest', 'label' => 'Forest'],
        ['key' => 'aqua', 'label' => 'Aqua'],
        ['key' => 'lofi', 'label' => 'Lofi'],
        ['key' => 'pastel', 'label' => 'Pastel'],
        ['key' => 'fantasy', 'label' => 'Fantasy'],
        ['key' => 'wireframe', 'label' => 'Wireframe'],
        ['key' => 'black', 'label' => 'Black'],
        ['key' => 'luxury', 'label' => 'Luxury'],
        ['key' => 'dracula', 'label' => 'Dracula'],
        ['key' => 'cmyk', 'label' => 'CMYK'],
        ['key' => 'autumn', 'label' => 'Autumn'],
        ['key' => 'business', 'label' => 'Business'],
        ['key' => 'acid', 'label' => 'Acid'],
        ['key' => 'lemonade', 'label' => 'Lemonade'],
        ['key' => 'night', 'label' => 'Night'],
        ['key' => 'coffee', 'label' => 'Coffee'],
        ['key' => 'winter', 'label' => 'Winter'],
        ['key' => 'dim', 'label' => 'Dim'],
        ['key' => 'nord', 'label' => 'Nord'],
        ['key' => 'sunset', 'label' => 'Sunset'],
        ['key' => 'caramellatte', 'label' => 'Caramellatte'],
        ['key' => 'abyss', 'label' => 'Abyss'],
        ['key' => 'silk', 'label' => 'Silk'],
    ];
@endphp

<div class="dropdown dropdown-end">
    <button tabindex="0" class="btn btn-ghost btn-circle" aria-label="Choose theme">
        <x-icon name="o-swatch" class="size-5" />
    </button>
    <div tabindex="0" class="dropdown-content z-[90] mt-2 w-72 rounded-box border border-base-300 bg-base-100 p-2 shadow-xl">
        <div class="mb-2 px-2 pt-1 text-xs font-semibold uppercase tracking-[0.18em] text-base-content/55">Theme Preview</div>
        <ul class="menu max-h-[26rem] gap-1 overflow-y-auto p-0">
            @foreach($themes as $theme)
                <li>
                    <a href="#"
                       data-theme-option="{{ $theme['key'] }}"
                       class="justify-between rounded-xl">
                        <span>{{ $theme['label'] }}</span>
                        <span class="badge badge-ghost badge-sm">{{ $theme['key'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</div>
