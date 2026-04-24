## 2025-05-15 - DOMDocument-based HTML Sanitization
**Vulnerability:** XSS through unsanitized HTML content in CMS pages.
**Learning:** Simple `trim()` or incomplete regex-based sanitization is insufficient for HTML. PHP's `DOMDocument` provides a more reliable way to parse and clean HTML, but requires specific handling for UTF-8 (using `xml encoding` and `htmlentities` tricks) and careful node iteration (using `iterator_to_array`) to avoid skipping elements during removal.
**Prevention:** Use a whitelist-based approach for tags and attributes. Always strip event handlers and validate URI schemes (`javascript:`, etc.) after removing whitespace and control characters that could be used for obfuscation.
