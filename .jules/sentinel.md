# Sentinel Security Journal

## 2026-04-23 - CMS HTML Sanitization and XSS Prevention
**Vulnerability:** Stored XSS in CMS pages through un-sanitized raw HTML fragments rendered via Blade's `{!! !!}` syntax.
**Learning:** Even when using a structured editor (like Editor.js), a fallback for raw HTML fragments or direct DB edits can expose the application to XSS if the rendering service only performs basic operations like `trim()`. Manual HTML sanitization using `DOMDocument` is effective but requires careful handling of entity decoding to avoid re-introducing vulnerabilities (never call `html_entity_decode` on the entire sanitized output).

**Critical Implementation Learning:** When iterating over a `DOMNodeList` returned by `DOMXPath::query()`, the list is "live". Removing a node during iteration causes indices to shift, potentially skipping the next node in the list. This creates a security bypass if an attacker provides doubled invalid tags (e.g., `<script><script>`). Always use `iterator_to_array()` to convert the live list into a static array before iteration. Additionally, protocol validation for attributes like `href` or `src` must strip control characters and whitespace to prevent obfuscation bypasses (e.g., `java\0script:`).

**Prevention:** Always implement whitelist-based sanitization for any HTML content intended for unescaped rendering. Ensure custom implementations strictly filter tags, attributes, and URI schemes using static arrays for iteration and robust protocol checking.
