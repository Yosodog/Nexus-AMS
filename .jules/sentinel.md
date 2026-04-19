## 2026-04-19 - Robust HTML Sanitization without external libraries
**Vulnerability:** Stored XSS via `{!! !!}` in Blade templates for CMS-driven content.
**Learning:** `strip_tags()` is insufficient for security because it does not filter or sanitize attributes, allowing event-based XSS (e.g., `onmouseover`) and URI-based XSS (e.g., `javascript:`).
**Prevention:** Use a `DOMDocument`-based sanitizer to explicitly whitelist tags and attributes. Ensure sensitive attributes like `href` and `src` are checked for dangerous URI schemes. When stripping unlisted tags, preserve their inner content by re-inserting child nodes before removal to avoid breaking page layout.
