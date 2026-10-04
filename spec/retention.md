# Retention contract

Retention is one of 30, 90, 180, or 365 days; invalid values fail closed to 180. Cleanup removes only aggregate rows older than the UTC cutoff, preserves data inside the configured period, is idempotent, and records `last_cleanup_at`. Work is bounded to at most 1,000 rows per table per invocation. PHP and WordPress must operate without a cron job; other runtimes may expose cleanup through an explicit maintenance API and opportunistic bounded cleanup.
