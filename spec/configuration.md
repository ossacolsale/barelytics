# Configuration contract

All runtimes have the same effective values: `pageviews=true`; `country_collection=false`; `referrer_collection=false`; `browser_collection=false`; `device_collection=false`; `os_collection=false`; `retention_days=180`; default private paths and bot patterns as described by the other contract files. Every missing or invalid optional setting evaluates to false. Invalid retention uses 180 days. An Extended-profile save requires explicit acknowledgement; Strict reset disables all five dimensions. `automation_collection=true` is a fixed capability under contract revision 2, not an optional human dimension. `daily_reach_available` is true only for the PHP reference transport; `daily_reach_enabled=false` by default and independently requires Extended acknowledgement. Other adapters remain unavailable. Strict reset disables reach as well as all dimensions. See [daily-reach.md](daily-reach.md). `reporting_day=UTC` is fixed. Storage location is configured per application and must be private to the application.

PHP schema 5 independently defaults `daily_page_reach_enabled=false`, requires
Extended acknowledgement, and advertises `daily_page_reach_available` only in
standalone PHP. The existing `BARELYTICS_DAILY_REACH_ALLOWED=0` switch disables
both metrics. Either enabled setting selects Extended/IndexedDB; Strict reset
disables both. WordPress/native Page Reach capability remains explicitly false.
