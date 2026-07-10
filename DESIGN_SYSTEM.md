# Nexus AMS design system

Nexus AMS uses one civic-operations design language across its public, member, and administrative surfaces. The interface should feel composed, trustworthy, and exact: an operational record rather than a generic SaaS dashboard or a military-themed game skin.

The implementation is server rendered with Laravel Blade and Livewire 3. Tailwind CSS 4 provides layout and utilities, DaisyUI 5 provides semantic tokens and low-level controls, and MaryUI 2 remains available where its behavior is useful. DaisyUI and MaryUI are building blocks, not the product identity.

## Source of truth

- Global tokens and reusable primitives: `resources/css/app.css`
- Theme initialization and persistence: `resources/views/components/theme-init.blade.php`
- Theme control: `resources/views/components/theme-picker.blade.php`
- Public shell: `resources/views/layouts/public.blade.php`
- Member shell: `resources/views/layouts/main.blade.php`
- Staff shell: `resources/views/layouts/admin.blade.php`
- Member navigation: `app/Livewire/AppHeader.php`
- Staff navigation: `app/Livewire/Admin/AppSidebar.php`
- Shared browser behavior: `resources/js/app.js`

Do not add a `tailwind.config.js`. Tailwind 4, DaisyUI themes, source discovery, and plugins are configured in CSS and Vite.

## Product modes

All three modes share typography, controls, status language, focus behavior, and theme tokens.

### Public

Public pages may use a more editorial composition and more breathing room. They should explain the alliance and its processes without invented claims, fabricated testimonials, or marketing filler. Use `layouts.public` for the homepage, application briefing, and authentication/onboarding flows.

### Member

Member pages prioritize the next useful action: account movement, assistance eligibility, readiness, pending requests, and security. Use `layouts.main`. Desktop navigation is domain based; mobile navigation is intentionally smaller and task oriented.

### Staff

Administrative pages are denser and permission scoped. Optimize for scanning, filtering, triage, safe approvals, and repeat work. Use `layouts.admin`. Never render an empty navigation group or expose a metric merely because the user has general admin access.

## Visual direction

- Commissioner is the body and interface family.
- Barlow Condensed is the display family for headings, labels, and operational wayfinding.
- Verdigris is the primary action color; brass is the secondary signal; terracotta is reserved for destructive or critical state.
- Hierarchy comes from typography, alignment, dividers, spacing, and density before elevation or color.
- Corners are compact. Most product surfaces use a `0.375rem` or `0.5rem` radius.
- Use one-pixel rules and restrained shadows. Do not create floating card galleries.
- Avoid decorative gradients, glassmorphism, glow fields, neon color, fake terminal styling, and ornamental charts.

## Themes

The supported preference values are `light`, `night`, and `auto`, stored under `nexus-theme` in local storage. `auto` follows the operating-system preference and responds live when it changes.

The early initializer applies `data-theme` and `color-scheme` before CSS loads. Do not duplicate this logic in a page or layout. Use `window.NexusTheme` only when JavaScript must read or change the preference. Theme changes dispatch `nexus:theme-changed`.

Use DaisyUI semantic tokens for product meaning:

| Meaning | Token |
|---|---|
| Primary action or current location | `primary` |
| Secondary emphasis | `secondary` |
| Informational state | `info` |
| Successful or complete state | `success` |
| Attention or pending state | `warning` |
| Failed, denied, or destructive state | `error` |
| Page and panel hierarchy | `base-100`, `base-200`, `base-300` |
| Text | `base-content` |

Do not hard-code a semantic product color in a Blade template. Both themes must provide readable default, muted, hover, focus, selected, disabled, and autofill states.

## Layout and hierarchy

Shared content width is controlled by `--nexus-content`. The layout already provides horizontal padding; pages should not add another full-page max-width wrapper without a specific reason.

Use these primitives when the structure matches:

- `.nexus-stack` for top-level vertical rhythm.
- `.nexus-page-header` with `__copy` and `__actions` for page identity and primary actions.
- `.nexus-page-title`, `.nexus-page-summary`, and `.nexus-section-title` for type hierarchy.
- `.nexus-panel` with `__header`, `__body`, and `__footer` for a bounded operational region.
- `.nexus-metrics` and `.nexus-metric` for connected summary values, not a grid of floating stat cards.
- `.nexus-empty-state` for an actionable absence of data.
- `.nexus-detail-grid` for compact label/value records.

Do not wrap trivial markup in a new component. Extract a reusable component when it owns a stable visual contract, behavior, authorization rule, or domain-specific representation.

## Actions and status

Action hierarchy is consistent across modes:

1. One primary action per local decision area.
2. Outline actions for safe alternatives.
3. Ghost actions for navigation or low-emphasis utilities.
4. Error actions only for destructive or irreversible work.

Button text must name the action. Do not use an ambiguous icon-only control unless a visible label would be genuinely redundant; icon-only controls still require an accessible name.

Use `.nexus-status` variants for compact state labels:

- `nexus-status--success`
- `nexus-status--warning`
- `nexus-status--error`
- `nexus-status--info`
- `nexus-status--neutral`

Pending is not success. Disabled is not complete. Status language should say what happened and, when useful, what the user can do next.

## Forms and high-risk workflows

Every field needs a visible label. Place help text before validation feedback and keep feedback adjacent to the field. Use server-side validation and authorization even when client-side affordances exist.

Prefer MaryUI inputs when their generated markup fits the workflow; native controls with DaisyUI classes are also valid. Do not use Bootstrap form aliases such as `form-control`, `form-select`, `input-group`, or `form-check`.

For transfers, withdrawals, approvals, denials, recovery actions, and other consequential operations:

- Show actor, subject, amount/resources, destination, and current state before the action.
- Explain the consequence in the confirmation.
- Keep approve and deny visually distinct.
- Preserve self-approval and permission restrictions in backend code.
- On conflict or duplicate pending work, explain that the earlier record was preserved.
- Never rely on a client confirmation as the authorization control.

Use native `<dialog>` or the established MaryUI modal contract. Do not add Bootstrap modal markup, `data-bs-*` attributes, or global modal emulation.

## Tables and datasets

Every table needs a deliberate behavior:

- Wrap wide tables in an explicit horizontal-scroll region.
- Keep essential identity and action columns understandable on narrow screens; use a purpose-built mobile list when horizontal scanning is unreasonable.
- Use `scope="col"` on headers and a useful empty row.
- Align numeric values right and use tabular numerals.
- Mark a local, fully loaded table with `data-sortable="true"` only when client sorting is honest and useful.
- Mark non-sortable headers such as actions with `data-sortable="false"`.
- Never client-sort a single page of server-paginated data; sort in the query and preserve filters in pagination links.

The shared sorter adds keyboard-operated header buttons and `aria-sort`. It does not mutate tables unless explicitly enabled.

## Charts

Charts are page scoped through `<x-chart-js />`; they are not loaded by the shared layouts. Keep a chart only when it makes a comparison or trend easier to act on than a table or sentence.

The chart component applies theme-aware labels, rules, and scale colors and updates active charts after `nexus:theme-changed`. Series colors should come from `window.NexusCharts.colors()` or computed semantic CSS tokens. Always provide a textual summary or the source values near the chart, and provide an unavailable state when the library fails to load.

## Editors and authored content

CKEditor is loaded only on pages that need it. Preview, draft, publish, version, and restore actions remain separate. Admin-authored HTML must be sanitized on the server before it is rendered with `{!! !!}`.

Editor chrome and content must be readable in both themes. Do not add a second rich-text editor or restore the removed EditorJS dependency set without a concrete migration plan.

## Accessibility

- Every layout starts with a skip link and a focusable `<main id="main-content">` target.
- Use semantic landmarks and one page-level `h1`.
- Preserve a logical heading order.
- All menus, drawers, tabs, dialogs, forms, and sorting controls must work by keyboard.
- Use `aria-current="page"` for current navigation and `aria-live` for asynchronous feedback.
- Visible focus is mandatory; do not remove the shared focus ring.
- Do not communicate state with color alone.
- Respect `prefers-reduced-motion` and avoid automatic motion that is not necessary to understand a state change.
- External links that open a new tab use `rel="noopener"` and make the behavior clear when it is not obvious.

## Responsive behavior

Start with a single-column mobile layout and add density at content-driven breakpoints. Test at 390, 768, 1024, and 1440 pixels.

- Primary controls must remain reachable at 390 pixels.
- The member domain navigation appears at 1024 pixels; the mobile drawer covers smaller widths.
- Staff navigation remains permission coherent when collapsed or opened as a drawer.
- Do not reproduce the desktop sidebar verbatim as the mobile member navigation.
- Use gaps for groups and avoid margin-based sibling spacing.
- Long names, resource vectors, currency values, validation errors, and pending badges must not create page-level horizontal overflow.

## Livewire and JavaScript

Keep application state and authorization on the server. Use Livewire for server-driven interaction and Alpine for local disclosure, tabs, focus trapping, and drawers. Do not introduce a global variable when a module or DOM-scoped contract is sufficient.

Any initializer that may run after `livewire:navigated` must be idempotent. Bind delegated listeners once, mark enhanced nodes, and avoid duplicate event handlers. Use `wire:key` in rendered loops.

The shared `resources/js/app.js` is limited to cross-product contracts: theme controls, explicitly sortable tables, deposit requests, toast feedback, and page-ready signaling. Page-specific behavior belongs in a page-scoped Vite entry or script stack.

## Legacy rules

The active product uses no Bootstrap or AdminLTE presentation layer. Do not add:

- `.row` / `.col-*` grid aliases
- Bootstrap form, card, button, badge, list, modal, or spacing aliases
- `data-bs-*` behavior
- AdminLTE widgets or layout classes
- global table wrapping or sorting of unmarked tables
- duplicated theme resolvers

When changing an older view, migrate the caller to Tailwind/DaisyUI semantics instead of adding another compatibility selector.

## Verification

For UI changes, run the smallest relevant PHP tests, then:

```bash
herd php vendor/bin/pint --dirty
herd php artisan view:cache
npm run build
npm run test:browser
```

Inspect representative public, member, and staff pages in light and dark modes at 390, 768, 1024, and 1440 pixels. Verify keyboard focus, overflow, empty/error states, permission-limited navigation, and any dialog or editor touched by the change. Do not commit generated Vite assets.
