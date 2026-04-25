# Sentinel Security Journal

## 2026-04-25 - XSS Bypass via Entity Decoding
**Vulnerability:** A custom HTML sanitizer implemented with DOMDocument was vulnerable to an XSS bypass because it used `htmlspecialchars_decode` on the final output string.
**Learning:** Decoding HTML entities after the sanitization phase is dangerous because entities like `&lt;script&gt;` are ignored by tag-based whitelists but become executable once decoded. Additionally, decoding `&quot;` can cause attribute breakouts.
**Prevention:** Never decode HTML entities in the final output of a sanitizer. Trust the DOM parser to handle entities correctly and keep them encoded in the result to ensure they are treated as literal text by the browser.
