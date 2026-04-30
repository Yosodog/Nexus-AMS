## 2025-05-15 - HTML Sanitization in PageRenderer
**Vulnerability:** XSS via unsanitized HTML in CMS pages.
**Learning:** The PageRenderer::normalizeHtml method was only trimming input, allowing malicious scripts to be rendered using the `{!! !!}` Blade syntax.
**Prevention:** Implement a whitelist-based HTML sanitizer using DOMDocument and DOMXPath, strictly validating tags, attributes, and URI protocols (blocking javascript:, data:, and vbscript:).
