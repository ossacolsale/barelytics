# Path normalization contract

Accept origin-form UTF-8 paths only. Reject empty values, values over 512 UTF-8 bytes, control characters, malformed percent escapes, invalid UTF-8 after percent decoding, absolute URLs, network-path references (`//host`), query strings, fragments, and any decoded `@` (including encoded email addresses). Collapse repeated `/` characters. Replace path segments matching a long numeric ID (6+ digits), 26-character ULID alphabet, UUID, 16+ hexadecimal characters, or 32+ alphanumeric/underscore/hyphen characters with `:id`. If an identifier is percent-encoded, reject the path instead of storing its encoded spelling. Apply configured private-path exclusions after normalization and before any database write.

Exclusion patterns are exact paths or prefix patterns ending in `/*`; a prefix pattern matches the base path and all descendants. Default examples: `/admin/*`, `/admin.php`, `/account/*`, `/checkout/*`, `/customer/*`, `/patient/*`, `/profile/*`, `/private/*`.

Compare exclusions against the validated percent-decoded path, so `/%61dmin`
cannot evade `/admin/*`. Reject backslashes, encoded slashes, decoded percent
characters (nested encoding) and `.`/`..` segments before any write. Public safe
paths retain their existing escaped spelling; these rules do not rewrite legacy
aggregates. This is conservative rejection of ambiguous routing, not a guarantee
that all application-specific sensitive paths can be inferred automatically.
