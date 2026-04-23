# Sentinel Security Journal

## 2026-04-23 - CMS HTML Sanitization and XSS Prevention
**Vulnerability:** Stored XSS in CMS pages through un-sanitized raw HTML fragments rendered via Blade's `{!! !!}` syntax.
**Learning:** Even when using a structured editor (like Editor.js), a fallback for raw HTML fragments or direct DB edits can expose the application to XSS if the rendering service only performs basic operations like `trim()`. Manual HTML sanitization using `DOMDocument` is effective but requires careful handling of entity decoding to avoid re-introducing vulnerabilities (never call `html_entity_decode` on the entire sanitized output).

**Critical Implementation Learning:**
1. **Live DOMNodeList Iteration:** When iterating over a `DOMNodeList` returned by `DOMXPath::query()`, the list is "live". Removing a node during iteration causes indices to shift, potentially skipping the next node in the list. This creates a security bypass if an attacker provides doubled invalid tags (e.g., `<script><script>`). Always use `iterator_to_array()` to convert the live list into a static array before iteration.
2. **Protocol Obfuscation:** Protocol validation for attributes like `href` or `src` must strip control characters and whitespace (e.g. `\x00-\x20`) from the attribute value before regex checks to prevent obfuscation bypasses (e.g., `java\0script:`).
3. **Unexpected Tag Handling:** Some XSS payloads use "unexpected" end tags (like `</textarea>`) to break out of elements. A robust sanitizer should identify these as invalid nodes and remove them.

**Prevention:** Always implement whitelist-based sanitization for any HTML content intended for unescaped rendering. Ensure custom implementations strictly filter tags, attributes, and URI schemes using static arrays for iteration and robust protocol checking.
