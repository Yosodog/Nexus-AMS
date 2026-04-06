## 2025-05-15 - [XSS vulnerability in PageRenderer]
**Vulnerability:** The PageRenderer used `{!! !!}` in Blade views to render CMS content without server-side sanitization.
**Learning:** The application allowed raw HTML content from the database to be rendered directly. Regex-based sanitization is insufficient and brittle.
**Prevention:** Use established sanitization libraries like HTMLPurifier or context-aware escaping. Always sanitize HTML on the server before rendering with unescaped Blade tags.
