## 2025-05-14 - Robust HTML Sanitization in PageRenderer
**Vulnerability:** XSS (Cross-Site Scripting) via raw HTML in CMS-driven pages.
**Learning:** The application allowed raw HTML rendering via `{!! $content !!}` in `apply.blade.php` without any server-side sanitization in `PageRenderer::normalizeHtml`, which was a no-op. Using `DOMDocument` for fragment sanitization in PHP requires specific workarounds for UTF-8 (e.g., XML encoding prefix) and avoids deprecated `mb_convert_encoding` for entities.
**Prevention:** Always implement whitelist-based sanitization (tags AND attributes) for any HTML intended for raw output. Never trust client-side editors to provide safe HTML.
