## 2026-04-28 - Robust HTML Sanitization in CMS Rendering
**Vulnerability:** XSS in PageRenderer
**Learning:** The `PageRenderer` service was rendering raw HTML strings from the CMS without any sanitization, relying only on `trim()`. This allowed arbitrary script execution when CMS content was displayed via `{!! $content !!}` in Blade views. Using `DOMDocument` with a strict whitelist of tags and attributes provides a robust defense-in-depth layer when a dedicated sanitization library is not available.
**Prevention:** Always sanitize HTML fragments before rendering them with unescaped Blade tags. Use a whitelist-based approach for tags and attributes, and explicitly block dangerous URI schemes like `javascript:`, `data:`, and `vbscript:`.
