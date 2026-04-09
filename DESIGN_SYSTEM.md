# Nexus AMS — Design Language Specification

> **Reference implementation:** `resources/views/user/dashboard.php`
> **CSS tokens:** `resources/css/app.css` (search for `NEXUS DESIGN SYSTEM`)
> **Framework stack:** Laravel Blade + Tailwind CSS 4 + DaisyUI 5 + MaryUI 2 + Livewire 3

---

## Table of Contents

1. [Philosophy](#1-philosophy)
2. [Tech Stack & When to Use What](#2-tech-stack--when-to-use-what)
3. [Layout & Spacing](#3-layout--spacing)
4. [Card System](#4-card-system)
5. [Typography](#5-typography)
6. [Color System](#6-color-system)
7. [Stat Cards](#7-stat-cards)
8. [Status Indicators](#8-status-indicators)
9. [Buttons & Actions](#9-buttons--actions)
10. [Tables & Data Display](#10-tables--data-display)
11. [Forms](#11-forms)
12. [Modals & Drawers](#12-modals--drawers)
13. [Tabs](#13-tabs)
14. [Charts](#14-charts)
15. [Lists & List Items](#15-lists--list-items)
16. [Detail / Show Pages](#16-detail--show-pages)
17. [Sidebar Layouts](#17-sidebar-layouts)
18. [Steps & Timelines](#18-steps--timelines)
19. [Empty States](#19-empty-states)
20. [Alerts & Notifications](#20-alerts--notifications)
21. [Pagination](#21-pagination)
22. [Progress Bars](#22-progress-bars)
23. [Collapsible / Accordion](#23-collapsible--accordion)
24. [Copy to Clipboard](#24-copy-to-clipboard)
25. [Responsive Breakpoints](#25-responsive-breakpoints)
26. [Page Templates](#26-page-templates)
27. [Do's and Don'ts](#27-dos-and-donts)

---

## 1. Philosophy

The Nexus design language is built on three pillars:

- **Spacious clarity** — generous whitespace, clear visual hierarchy, never cramming content.
- **Soft depth** — subtle shadows, decorative gradient blurs, gentle hover interactions.
- **Purposeful color** — DaisyUI semantic tokens only; color always communicates meaning.

Every page should feel like a calm, modern dashboard — not a dense spreadsheet.

---

## 2. Tech Stack & When to Use What

### MaryUI Components (preferred for interactive/complex elements)

Use MaryUI (`<x-component>`) when it provides a component that fits. MaryUI handles accessibility, Livewire binding, and consistent styling. Always prefer MaryUI over hand-rolling equivalent HTML.

| Use MaryUI for | Component | Key props |
|---|---|---|
| Cards with title/actions | `<x-card>` | `title`, `subtitle`, `shadow`, `separator`, slots: `menu`, `actions`, `figure` |
| Page headers | `<x-header>` | `title`, `subtitle`, `separator`, `size`, `icon`, slots: `middle`, `actions` |
| Buttons | `<x-button>` | `label`, `icon`, `link`, `external`, `spinner`, `responsive`, `badge` |
| Badges | `<x-badge>` | `value`, `icon` + DaisyUI classes |
| Icons | `<x-icon>` | `name` (Heroicons `o-` prefix) |
| Text inputs | `<x-input>` | `label`, `icon`, `hint`, `prefix`, `suffix`, `clearable`, `money`, `inline` |
| Selects | `<x-select>` | `label`, `icon`, `placeholder`, `options`, `option-value`, `option-label` |
| Textareas | `<x-textarea>` | `label`, `hint`, `inline` |
| Checkboxes | `<x-checkbox>` | `label`, `right`, `hint` |
| Radios | `<x-radio>` | `label`, `options`, `inline` |
| Toggles | `<x-toggle>` | `label`, `right`, `hint` |
| Tables | `<x-table>` | `headers`, `rows`, `striped`, `with-pagination`, `sort-by`, `link` |
| Modals | `<x-modal>` | `title`, `subtitle`, `persistent`, `separator`, slot: `actions` |
| Drawers | `<x-drawer>` | `title`, `right`, `with-close-button`, slot: `actions` |
| Tabs | `<x-tabs>` + `<x-tab>` | `selected`, `label-class`, `active-class` / `name`, `label`, `icon` |
| Dropdowns | `<x-dropdown>` | `label`, `icon`, `right`, slot: `trigger` |
| Collapsibles | `<x-collapse>` | `name`, `separator`, `open`, slots: `heading`, `content` |
| Alerts | `<x-alert>` | `title`, `icon`, `description`, `shadow`, `dismissible`, slot: `actions` |
| List items | `<x-list-item>` | `item`, `avatar`, `value`, `sub-value`, slot: `actions` |
| Menu items | `<x-menu-item>` | `title`, `icon`, `link`, `badge`, `active`, `separator` |
| Nav bar | `<x-nav>` | `sticky`, `full-width`, slots: `brand`, `actions` |
| Stat display | `<x-stat>` | `title`, `value`, `description`, `icon`, `color` |

### DaisyUI Classes (for styling MaryUI/HTML elements)

Use DaisyUI utility classes for styling. Never hardcode hex colors.

### Tailwind (for layout and spacing)

Use Tailwind for grid, flex, spacing, responsive design, and custom one-off styling.

### Livewire (for server-driven interactivity)

Use Livewire (`wire:model`, `wire:click`, `wire:submit`) when:
- A form needs server-side validation without full page reload
- A component needs to poll or react to server events
- Navigation menus need live state (unread counts, notifications)
- Real-time search/filtering of data tables

Use Alpine.js (`x-data`, `x-show`, `@click`) for:
- Client-side-only UI toggles (tab switching, dropdowns, show/hide)
- Copy-to-clipboard
- Client-side form calculations
- Mobile menu open/close

### Custom CSS Tokens (defined in app.css)

Use the `nexus-*` CSS classes defined in `app.css` for consistent design language tokens. These are `@apply`-based abstractions over Tailwind classes.

---

## 3. Layout & Spacing

All user-facing pages extend `layouts.main`. Content goes in `@section('content')`.

The layout provides: gradient background, app header (Livewire), footer, toast system, and responsive container.

| Token | Value | Usage |
|---|---|---|
| Page max-width | Already set in `layouts.main` | Don't re-wrap |
| Section gap | `mt-6` between top-level sections | Vertical rhythm |
| Card inner padding | MaryUI `<x-card>` handles this, or `p-5 sm:p-6` | Standard padding |
| Grid gap (stats) | `gap-3 sm:gap-4` | Between stat cards |
| Grid gap (panels) | `gap-5` or `gap-6` | Between larger cards |

---

## 4. Card System

### Tier 1 — Hero Card (one per page, top of content)

The hero introduces the page. Use for: welcome banners, page identity, primary entity display.

```blade
<div class="nexus-hero">
    <div class="nexus-hero-blur-right"></div>
    <div class="nexus-hero-blur-left"></div>
    <div class="relative p-6 sm:p-8">
        {{-- Page title, identity, quick actions --}}
    </div>
</div>
```

CSS: `rounded-3xl`, `shadow-lg`, decorative gradient blurs.

### Tier 2 — Content Card (tables, charts, forms, major sections)

Use MaryUI `<x-card>` with Nexus styling classes:

```blade
<x-card
    class="nexus-card-elevated rounded-3xl"
    title="Section Title"
    shadow
    separator
>
    <x-slot:menu>
        <x-button label="Action" icon="o-plus" class="btn-ghost btn-sm rounded-xl" />
    </x-slot:menu>

    {{-- content --}}
</x-card>
```

For cards without MaryUI title/menu:

```blade
<div class="rounded-3xl border border-base-300/60 bg-base-100 p-5 shadow-md sm:p-6">
    {{-- freeform content --}}
</div>
```

### Tier 3 — Stat / Compact Card

```blade
<div class="nexus-stat-card group">
    <div class="pointer-events-none absolute -right-4 -top-4 h-16 w-16
         rounded-full bg-{accent}/10 blur-2xl transition-all group-hover:scale-150"></div>
    <div class="relative">
        {{-- stat content --}}
    </div>
</div>
```

CSS: `rounded-2xl`, `shadow-sm` -> `hover:shadow-md`, decorative blur scales on hover.

### Tier 4 — Banner Card (status alerts, compliance banners)

```blade
<div class="nexus-banner">
    {{-- status content with progress bar --}}
</div>
```

CSS: `rounded-3xl`, gradient border tint, subtle gradient background.

---

## 5. Typography

| Element | CSS class | Raw Tailwind |
|---|---|---|
| Page title (h1) | `.nexus-page-title` | `break-words text-2xl font-extrabold leading-tight sm:text-3xl` |
| Section heading | `.nexus-section-title` | `text-lg font-bold` |
| Overline label | `.nexus-overline` | `text-xs font-medium uppercase tracking-widest text-base-content/50` |
| Stat value | `.nexus-stat-value` | `text-xl font-extrabold sm:text-2xl` |
| Stat label | `.nexus-stat-label` | `text-xs font-semibold uppercase tracking-wide text-base-content/50` |
| Helper text | `.nexus-stat-helper` | `mt-0.5 text-xs text-base-content/50` |
| Body muted | `.nexus-body-muted` | `text-sm text-base-content/60` |

**Weight hierarchy:** `font-extrabold` for primary values > `font-bold` for section titles > `font-semibold` for labels > `font-medium` for body. Never use `font-normal` for anything the user needs to notice first.

---

## 6. Color System

Use **DaisyUI semantic tokens only**. Never hardcode hex values in templates.

| Purpose | Token | Usage |
|---|---|---|
| Primary accent | `primary` | Buttons, links, active tabs, score charts |
| Success / positive | `success` | "On target", received funds, met requirements |
| Warning / attention | `warning` | "Needs build", low compliance |
| Error / critical | `error` | Failed states, very low scores, destructive actions |
| Info | `info` | Neutral informational content |
| Accent | `accent` | Secondary highlights |
| Neutral | `neutral` | Inactive, disabled |
| Muted text | `base-content/50` or `/60` | Helper text, timestamps |
| Card background | `base-100` | All cards |
| Page background | `base-200/30` | Already in layout |
| Subtle fills | `base-200/60` | Inner boxes, zebra rows |

**Tinted backgrounds for status:**

```blade
{{-- Use nexus-status-pill classes --}}
<div class="nexus-status-pill nexus-status-pill-success">...</div>
<div class="nexus-status-pill nexus-status-pill-warning">...</div>
<div class="nexus-status-pill nexus-status-pill-error">...</div>
<div class="nexus-status-pill nexus-status-pill-info">...</div>
```

**Icon containers:**

```blade
<div class="nexus-icon-box bg-{accent}/10">
    <x-icon name="o-banknotes" class="size-4 text-{accent}" />
</div>

{{-- Larger variant --}}
<div class="nexus-icon-box-lg bg-{accent}/10">
    <x-icon name="o-banknotes" class="size-5 text-{accent}" />
</div>
```

---

## 7. Stat Cards

Grid: `grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3`

Each card:

```blade
<div class="nexus-stat-card group">
    <div class="pointer-events-none absolute -right-4 -top-4 h-16 w-16
         rounded-full bg-{accent}/10 blur-2xl transition-all group-hover:scale-150"></div>
    <div class="relative">
        <div class="mb-3 flex items-center gap-2">
            <div class="nexus-icon-box bg-{accent}/10">
                <x-icon name="{icon}" class="size-4 text-{accent}" />
            </div>
            <p class="nexus-stat-label">{label}</p>
        </div>
        <p class="nexus-stat-value">{value}</p>
        <p class="nexus-stat-helper">{description}</p>
    </div>
</div>
```

For simpler stat displays without the decorative card, use MaryUI's `<x-stat>`:

```blade
<x-stat title="Members" value="1,250" icon="o-users" color="text-primary" />
```

---

## 8. Status Indicators

### Badges (via MaryUI)

```blade
{{-- Soft badges for metadata/identity --}}
<x-badge value="Alliance Name" class="badge-soft badge-primary badge-sm" />
<x-badge value="14 cities" class="badge-soft badge-sm" />

{{-- Outline badges for compliance --}}
<x-badge value="On target" class="badge-success badge-outline badge-sm" />
<x-badge value="Needs build" class="badge-warning badge-outline badge-sm" />

{{-- With icon --}}
<x-badge value="Active" icon="o-check-circle" class="badge-success badge-sm" />
```

### Status pills (for prominent inline indicators)

```blade
<div class="nexus-status-pill nexus-status-pill-success">
    <x-icon name="o-check-circle" class="size-4" />
    Resources met
</div>
```

Available variants: `nexus-status-pill-success`, `-warning`, `-error`, `-info`, `-neutral`.

---

## 9. Buttons & Actions

Always use MaryUI `<x-button>`. Apply DaisyUI classes for variants.

```blade
{{-- Primary CTA --}}
<x-button
    label="Submit"
    icon="o-paper-airplane"
    class="btn-primary btn-sm rounded-xl shadow-sm shadow-primary/20"
/>

{{-- Ghost / secondary --}}
<x-button label="Cancel" class="btn-ghost btn-sm rounded-xl" />

{{-- Outline --}}
<x-button label="Export" icon="o-arrow-down-tray" class="btn-outline btn-sm rounded-xl" />

{{-- Destructive --}}
<x-button label="Delete" icon="o-trash" class="btn-error btn-sm rounded-xl" />

{{-- Link button with trailing arrow --}}
<x-button
    label="View all"
    icon-right="o-arrow-right"
    :link="route('accounts')"
    class="btn-ghost btn-sm rounded-xl"
    no-wire-navigate
/>

{{-- External link --}}
<x-button
    label="View on P&W"
    icon="o-arrow-top-right-on-square"
    link="https://example.com"
    external
    class="btn-primary btn-sm rounded-xl"
/>

{{-- With loading spinner (Livewire) --}}
<x-button
    label="Save"
    class="btn-primary btn-sm rounded-xl"
    spinner="save"
    wire:click="save"
/>

{{-- Responsive (icon only on mobile, label on desktop) --}}
<x-button label="Filter" icon="o-funnel" class="btn-ghost btn-sm rounded-xl" responsive />
```

**Button rules:**
- Radius is always `rounded-xl`
- Size is `btn-sm` for in-page actions, default for full-width/form submit
- Primary CTA gets `shadow-sm shadow-primary/20` for subtle depth
- One primary action per card, remaining actions are ghost/outline

---

## 10. Tables & Data Display

### Simple tables (MaryUI `<x-table>`)

Use when you have a structured `$headers` and `$rows` array:

```blade
@php
    $headers = [
        ['key' => 'name', 'label' => 'Name', 'sortable' => true],
        ['key' => 'amount', 'label' => 'Amount', 'class' => 'text-right tabular-nums'],
        ['key' => 'status', 'label' => 'Status'],
    ];
@endphp

<x-card class="nexus-card-elevated rounded-3xl" title="Transactions" shadow separator>
    <x-table
        :headers="$headers"
        :rows="$transactions"
        striped
        with-pagination
        class="nexus-table-header"
    >
        @scope('cell_status', $row)
            <x-badge
                value="{{ $row->status }}"
                class="badge-sm {{ $row->status === 'approved' ? 'badge-success' : 'badge-warning' }}"
            />
        @endscope
    </x-table>
</x-card>
```

### Custom tables (when you need full control)

```blade
<div class="overflow-x-auto">
    <table class="table">
        <thead>
            <tr class="nexus-table-header">
                <th>Column</th>
            </tr>
        </thead>
        <tbody>
            <tr class="nexus-table-row">
                <td>...</td>
            </tr>
        </tbody>
    </table>
</div>
```

### Mobile-responsive tables

For tables with many columns, provide a mobile card layout and a desktop table layout:

```blade
{{-- Mobile: card layout --}}
<div class="space-y-3 lg:hidden">
    @foreach($items as $item)
        <div class="flex items-center gap-3 rounded-xl border border-base-200/60 p-3">
            {{-- compact card representation --}}
        </div>
    @endforeach
</div>

{{-- Desktop: table layout --}}
<div class="hidden overflow-x-auto lg:block">
    <table class="table">...</table>
</div>
```

---

## 11. Forms

### Form sections

Wrap logical form groups in `nexus-form-section`:

```blade
<div class="nexus-form-section">
    <div class="nexus-form-section-header">
        <h3 class="nexus-section-title">Account Details</h3>
        <p class="nexus-body-muted mt-1">Update your personal information.</p>
    </div>

    <div class="nexus-form-grid">
        <x-input label="Name" wire:model="name" icon="o-user" />
        <x-input label="Email" wire:model="email" icon="o-envelope" type="email" />
    </div>

    <div class="nexus-form-actions">
        <x-button label="Cancel" class="btn-ghost rounded-xl" />
        <x-button label="Save Changes" class="btn-primary rounded-xl" spinner="save" wire:click="save" />
    </div>
</div>
```

### Form inputs (always MaryUI)

```blade
{{-- Text input with icon --}}
<x-input label="Nation Name" wire:model="nation_name" icon="o-flag" hint="Your in-game nation name" />

{{-- Money input --}}
<x-input label="Amount" wire:model="amount" money prefix="$" />

{{-- With clear button --}}
<x-input label="Search" wire:model.live="search" icon="o-magnifying-glass" clearable />

{{-- Select --}}
<x-select
    label="Account"
    wire:model="account_id"
    :options="$accounts"
    option-value="id"
    option-label="name"
    placeholder="Select an account"
    icon="o-building-library"
/>

{{-- Textarea --}}
<x-textarea label="Notes" wire:model="notes" hint="Optional transaction note" />

{{-- Checkbox --}}
<x-checkbox label="I agree to the terms" wire:model="agreed" />

{{-- Toggle --}}
<x-toggle label="Enable notifications" wire:model="notifications" right />

{{-- Radio group --}}
<x-radio
    label="Transfer type"
    :options="$types"
    wire:model="transfer_type"
    option-value="id"
    option-label="name"
/>
```

### Form layout

```blade
{{-- Single-column form --}}
<div class="space-y-4 max-w-lg">
    <x-input ... />
    <x-input ... />
    <x-button label="Submit" class="btn-primary rounded-xl w-full sm:w-auto" />
</div>

{{-- Two-column form --}}
<div class="nexus-form-grid">
    <x-input ... />
    <x-input ... />
    <x-select ... />
    <x-input ... />
</div>

{{-- Full-width field in a grid --}}
<div class="nexus-form-grid">
    <x-input ... />
    <x-input ... />
    <div class="sm:col-span-2">
        <x-textarea ... />
    </div>
</div>
```

### Inline forms (search bars, filters)

```blade
<div class="flex flex-wrap gap-3">
    <x-input
        wire:model.live.debounce.300ms="search"
        placeholder="Search..."
        icon="o-magnifying-glass"
        clearable
        class="w-full sm:w-64"
    />
    <x-select
        wire:model.live="status"
        :options="$statusOptions"
        placeholder="All statuses"
        class="w-full sm:w-48"
    />
</div>
```

---

## 12. Modals & Drawers

### Confirmation modal

```blade
<x-modal wire:model="showDeleteModal" title="Delete Account" separator>
    <p class="nexus-body-muted">
        Are you sure you want to delete this account? This action cannot be undone.
    </p>

    <x-slot:actions>
        <div class="nexus-modal-actions">
            <x-button label="Cancel" class="btn-ghost rounded-xl" @click="$wire.showDeleteModal = false" />
            <x-button label="Delete" icon="o-trash" class="btn-error rounded-xl" spinner="delete" wire:click="delete" />
        </div>
    </x-slot:actions>
</x-modal>
```

### Form drawer (side panel)

```blade
<x-drawer wire:model="showDrawer" title="New Transfer" right with-close-button separator>
    <div class="space-y-4">
        <x-input label="Amount" wire:model="amount" money prefix="$" />
        <x-select label="To" wire:model="destination" :options="$accounts" />
        <x-textarea label="Note" wire:model="note" />
    </div>

    <x-slot:actions>
        <div class="nexus-modal-actions">
            <x-button label="Cancel" class="btn-ghost rounded-xl" @click="$wire.showDrawer = false" />
            <x-button label="Send" class="btn-primary rounded-xl" spinner="send" wire:click="send" />
        </div>
    </x-slot:actions>
</x-drawer>
```

---

## 13. Tabs

Use MaryUI `<x-tabs>` + `<x-tab>`. Apply Nexus tab classes:

```blade
<x-card class="nexus-card-elevated rounded-3xl" title="Data Views" shadow>
    <x-tabs selected="overview-tab" label-class="nexus-tab" active-class="nexus-tab-active">
        <x-tab name="overview-tab" label="Overview" icon="o-squares-2x2">
            <div class="pt-4">
                {{-- tab content --}}
            </div>
        </x-tab>
        <x-tab name="details-tab" label="Details" icon="o-document-text">
            <div class="pt-4">
                {{-- tab content --}}
            </div>
        </x-tab>
        <x-tab name="history-tab" label="History" icon="o-clock">
            <div class="pt-4">
                {{-- tab content --}}
            </div>
        </x-tab>
    </x-tabs>
</x-card>
```

When tabs are controlled by Livewire (for lazy-loading tab content):

```blade
<x-tabs wire:model="activeTab" label-class="nexus-tab" active-class="nexus-tab-active">
    ...
</x-tabs>
```

---

## 14. Charts

All charts use Chart.js on `<canvas>` elements. Always wrap in a Tier 2 card.

### Shared options

```js
const baseFont = { family: "'Inter', 'system-ui', sans-serif", size: 11, weight: 500 };
const gridColor = 'rgba(0,0,0,0.04)';

const commonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'oklch(var(--b1))',
            titleColor: 'oklch(var(--bc))',
            bodyColor: 'oklch(var(--bc) / 0.7)',
            borderColor: 'oklch(var(--bc) / 0.1)',
            borderWidth: 1, padding: 10, cornerRadius: 10, boxPadding: 4,
        }
    },
    scales: {
        x: { grid: { display: false }, ticks: { font: baseFont, color: 'oklch(var(--bc) / 0.35)' } },
        y: { grid: { color: gridColor }, ticks: { font: baseFont, color: 'oklch(var(--bc) / 0.35)' }, beginAtZero: true },
    }
};

// For multi-series charts, extend with legend:
const multiLegendOptions = {
    ...commonOptions,
    plugins: {
        ...commonOptions.plugins,
        legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 16, font: baseFont } },
        tooltip: { ...commonOptions.plugins.tooltip, mode: 'index', intersect: false },
    }
};
```

### Chart styles

- **Line charts:** `tension: 0.4`, `borderWidth: 2`, `pointRadius: 2`, fill with `oklch(var(--p) / 0.08)`
- **Bar charts:** `borderRadius: 6`, `borderSkipped: false`
- **Canvas height:** `style="height: 280px"` (consistent)
- **Colors:** Use `oklch(var(--p))`, `oklch(var(--su))`, etc. for DaisyUI theme compat

---

## 15. Lists & List Items

Use MaryUI `<x-list-item>` for structured list displays:

```blade
<x-card class="nexus-card-elevated rounded-3xl" title="Recent Activity" shadow separator>
    <div class="space-y-1">
        @foreach($items as $item)
            <x-list-item :item="$item" no-separator>
                <x-slot:avatar>
                    <div class="nexus-icon-box bg-success/10">
                        <x-icon name="o-banknotes" class="size-4 text-success" />
                    </div>
                </x-slot:avatar>
                <x-slot:value>
                    <span class="font-bold tabular-nums">+${{ number_format($item->amount, 2) }}</span>
                </x-slot:value>
                <x-slot:sub-value>
                    {{ $item->created_at->format('M d, Y') }}
                </x-slot:sub-value>
                <x-slot:actions>
                    <x-button icon="o-eye" class="btn-ghost btn-xs rounded-lg" />
                </x-slot:actions>
            </x-list-item>
        @endforeach
    </div>
</x-card>
```

---

## 16. Detail / Show Pages

For entity detail pages (account view, loan details, grant details):

### Page header

```blade
<div class="nexus-hero">
    <div class="nexus-hero-blur-right"></div>
    <div class="nexus-hero-blur-left"></div>
    <div class="relative p-6 sm:p-8">
        <p class="nexus-overline">Account</p>
        <h1 class="nexus-page-title mt-1">{{ $account->name }}</h1>
        <div class="mt-2 flex flex-wrap gap-1.5">
            <x-badge value="{{ $account->type }}" class="badge-soft badge-primary badge-sm" />
            <x-badge value="Balance: ${{ number_format($account->balance, 2) }}" class="badge-soft badge-success badge-sm" />
        </div>
    </div>
</div>
```

### Key-value detail grid

```blade
<div class="mt-6 nexus-detail-grid">
    <div class="nexus-detail-item">
        <p class="nexus-detail-label">Created</p>
        <p class="nexus-detail-value">{{ $item->created_at->format('M d, Y') }}</p>
    </div>
    <div class="nexus-detail-item">
        <p class="nexus-detail-label">Status</p>
        <p class="nexus-detail-value">
            <x-badge value="Active" class="badge-success badge-sm" />
        </p>
    </div>
    <div class="nexus-detail-item">
        <p class="nexus-detail-label">Balance</p>
        <p class="nexus-detail-value">${{ number_format($item->balance, 2) }}</p>
    </div>
</div>
```

### Inline metric box (summary totals)

```blade
<div class="nexus-inline-metric">
    <p class="nexus-overline text-[10px]">Last 30 days</p>
    <p class="text-2xl font-extrabold">${{ number_format($total, 2) }}</p>
</div>
```

---

## 17. Sidebar Layouts

For pages with main content + sidebar (accounts, grants with eligibility sidebar):

```blade
<div class="nexus-sidebar-layout">
    <div class="nexus-sidebar-main">
        {{-- Primary content: tables, forms, charts --}}
        <x-card class="nexus-card-elevated rounded-3xl" title="Transactions" shadow separator>
            ...
        </x-card>
    </div>

    <div class="nexus-sidebar-aside">
        {{-- Secondary content: summaries, quick actions, status --}}
        <x-card class="nexus-card-base rounded-2xl" title="Quick Stats" shadow>
            ...
        </x-card>

        <x-card class="nexus-card-base rounded-2xl" title="Actions" shadow>
            ...
        </x-card>
    </div>
</div>
```

CSS: `grid gap-6 lg:grid-cols-3` — main gets `lg:col-span-2`, aside gets 1 column.

---

## 18. Steps & Timelines

For multi-step processes (grant applications, onboarding):

```blade
<div class="space-y-0">
    <div class="nexus-step">
        <div class="nexus-step-dot nexus-step-dot-complete">
            <x-icon name="o-check" class="size-3" />
        </div>
        <h4 class="font-semibold">Application Submitted</h4>
        <p class="nexus-body-muted mt-1">Your grant application has been received.</p>
        <p class="mt-1 text-xs text-base-content/40">Mar 15, 2026</p>
    </div>

    <div class="nexus-step">
        <div class="nexus-step-dot nexus-step-dot-active">2</div>
        <h4 class="font-semibold">Under Review</h4>
        <p class="nexus-body-muted mt-1">An officer is reviewing your application.</p>
    </div>

    <div class="nexus-step">
        <div class="nexus-step-dot">3</div>
        <h4 class="font-semibold text-base-content/40">Approved</h4>
        <p class="nexus-body-muted mt-1">Waiting for previous steps.</p>
    </div>
</div>
```

---

## 19. Empty States

Always provide a meaningful empty state. Never show an empty table with no explanation.

```blade
{{-- Inside a card or table --}}
<div class="nexus-empty-state">
    <x-icon name="o-inbox" class="mx-auto size-10 text-base-content/15" />
    <p class="mt-3 text-sm font-medium text-base-content/40">No transactions yet</p>
    <p class="mt-1 text-xs text-base-content/30">Transactions will appear here once you send or receive funds.</p>

    {{-- Optional CTA --}}
    <x-button label="Make a Transfer" icon="o-paper-airplane" class="btn-primary btn-sm rounded-xl mt-4" :link="route('transfers')" />
</div>
```

For MaryUI `<x-table>`, use `show-empty-text` and `empty-text` props:

```blade
<x-table :headers="$headers" :rows="$rows" show-empty-text empty-text="No records found." />
```

---

## 20. Alerts & Notifications

### Page-level alerts (MaryUI)

```blade
{{-- Info --}}
<x-alert title="Sync Complete" description="Your nation data was updated 5 minutes ago." icon="o-information-circle" class="rounded-2xl" dismissible />

{{-- Warning --}}
<x-alert title="Low Resources" description="Your steel is below the MMR requirement." icon="o-exclamation-triangle" class="alert-warning rounded-2xl" />

{{-- Error --}}
<x-alert title="Transfer Failed" icon="o-x-circle" class="alert-error rounded-2xl">
    <x-slot:actions>
        <x-button label="Retry" class="btn-sm btn-ghost rounded-xl" wire:click="retry" />
    </x-slot:actions>
</x-alert>

{{-- Success --}}
<x-alert title="Transfer Complete" description="$50,000 sent to Main Account." icon="o-check-circle" class="alert-success rounded-2xl" dismissible />
```

### Toast notifications

Already handled by `layouts.main`. Push toasts via Livewire:

```php
$this->dispatch('toast', message: 'Transfer complete!', type: 'success');
```

---

## 21. Pagination

Use MaryUI's `<x-table>` `with-pagination` prop for Livewire tables. For standard pagination:

```blade
<div class="mt-4">
    {{ $items->links() }}
</div>
```

---

## 22. Progress Bars

### Standard (inside comparison tables)

```blade
<progress class="progress progress-primary w-full" value="75" max="100"></progress>
```

### Hero-style (banners, MMR readiness)

```blade
<div class="nexus-progress-track">
    <div class="nexus-progress-fill bg-success" style="width: 85%"></div>
</div>
```

Color the fill: `bg-success`, `bg-warning`, or `bg-error` based on threshold.

---

## 23. Collapsible / Accordion

Use MaryUI `<x-collapse>`:

```blade
<x-collapse name="faq-1" separator>
    <x-slot:heading>
        <span class="font-semibold">How do I apply for a grant?</span>
    </x-slot:heading>
    <x-slot:content>
        <p class="nexus-body-muted">Navigate to the Grants section and select the grant type you want to apply for.</p>
    </x-slot:content>
</x-collapse>

<x-collapse name="faq-2" separator>
    <x-slot:heading>
        <span class="font-semibold">When is payroll processed?</span>
    </x-slot:heading>
    <x-slot:content>
        <p class="nexus-body-muted">Payroll is processed daily based on your assigned grade.</p>
    </x-slot:content>
</x-collapse>
```

---

## 24. Copy to Clipboard

Use Alpine.js for copy interactions:

```blade
<div x-data="{ copied: false }" class="flex items-center gap-2">
    <code class="rounded-lg bg-base-200/60 px-3 py-1.5 text-sm font-mono">{{ $token }}</code>
    <button
        @click="navigator.clipboard.writeText('{{ $token }}'); copied = true; setTimeout(() => copied = false, 1500)"
        class="btn btn-ghost btn-xs rounded-lg"
    >
        <span x-show="!copied"><x-icon name="o-clipboard-document" class="size-4" /></span>
        <span x-show="copied" x-cloak class="text-success"><x-icon name="o-check" class="size-4" /></span>
    </button>
</div>
```

---

## 25. Responsive Breakpoints

| Breakpoint | Usage |
|---|---|
| Default (mobile) | Single column, `p-4`, smaller text, card-based table views |
| `sm:` (640px) | Slightly larger padding `sm:p-5`/`sm:p-6`, `sm:text-2xl` values |
| `md:` (768px) | Two-column form grids |
| `lg:` (1024px) | Stat grids go 3-col, hero goes `lg:flex-row`, sidebar layouts activate, tables show |
| `xl:` (1280px) | Comparison tables switch card -> table, 2-col grids for large panels |

**Rule:** Every section must look good at 375px (mobile). Test by viewing the mobile card layout before the desktop table layout.

---

## 26. Page Templates

### Dashboard page

```
Hero (greeting + identity + quick links)
Banner (MMR / compliance status)
Stat card grid (2x3)
Inline card (payroll / special status)
Comparison tables grid (2-col)
Tabbed charts
Recent activity table
```

### Index / list page

```
Hero or x-header (page title + subtitle + filter actions)
Optional: filter bar (search + select dropdowns)
Content card with table
Pagination
```

### Detail / show page

```
Hero (entity identity + badges)
Detail grid (key-value pairs)
Sidebar layout:
  Main: tabbed content (overview, history, settings)
  Aside: summary cards, quick actions
```

### Form page

```
Hero or x-header (page title)
Form section(s) with nexus-form-section
  Header + description
  Form grid with MaryUI inputs
  Action bar (cancel + submit)
```

### Settings page

```
x-header (Settings)
Multiple nexus-form-sections (stacked):
  Account details
  Security / MFA
  API tokens
  Connected services
  Danger zone
```

---

## 27. Do's and Don'ts

### Do

- Use MaryUI components (`<x-card>`, `<x-button>`, `<x-input>`, etc.) instead of raw HTML
- Use `nexus-*` CSS classes from `app.css` for design system tokens
- Use `rounded-2xl` for small cards, `rounded-3xl` for large cards
- Use opacity modifiers (`/50`, `/60`, `/10`) for subtle layering
- Add `transition` and `hover:shadow-md` to interactive cards
- Use `group` + `group-hover:` for decorative blur scaling
- Put 3+ charts behind tabs
- Provide empty states with icon + text + optional CTA
- Use MaryUI `<x-list-item>` for structured list displays
- Use `wire:model` for form inputs in Livewire components
- Use Alpine.js `x-data`/`x-show` for client-side-only toggles
- Use `<x-button spinner="action">` for loading states
- Add mobile card views for complex tables (`lg:hidden` / `hidden lg:block`)

### Don't

- Use flat cards without borders — always include `border border-base-300/60`
- Use Bootstrap classes — this is Tailwind + DaisyUI only
- Hardcode hex colors — always use DaisyUI semantic tokens
- Use `shadow-xl` or heavier — max is `shadow-lg` (hero cards only)
- Skip the decorative blur on hero cards
- Put more than 6 stat cards in one row
- Build form inputs from scratch — use MaryUI components
- Build modals from scratch — use `<x-modal>`
- Use `<a>` or `<button>` directly — use `<x-button>`
- Write custom dropdown HTML — use `<x-dropdown>`
- Use `font-normal` for anything the user should notice first
- Show empty tables without an empty state message
- Forget `@push('scripts')` for Chart.js — never inline `<script>` in `<body>`
