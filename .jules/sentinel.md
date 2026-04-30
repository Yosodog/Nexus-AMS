## 2026-04-30 - Constant-Time Token Comparison
**Vulnerability:** Potential timing attacks and loose comparison bypasses in security-sensitive tokens (verification codes and API tokens).
**Learning:** Standard string comparisons (`!=`, `!==`) are not constant-time and can leak information about the secret being compared. Furthermore, loose comparison (`!=`) between a `null` database value and an empty user input (`""`) can evaluate to `true`, potentially bypassing authentication or verification checks.
**Prevention:** Always use `hash_equals()` for comparing secrets, tokens, or codes. Ensure that database values are verified to be strings before comparison to prevent type juggling or `null` matching issues.
