# Behavior contracts

These documents define intended, runtime-neutral behavior. Consult the contract related to the code or requirement being changed; use the test vectors where available to compare implementations. The code and tests remain evidence of current implementation, so investigate any disagreement rather than assuming either source is current.

## Collection and data

- [Privacy model](privacy-model.md): Strict and Extended profiles, collected data, and operator responsibilities.
- [Path normalization](path-normalization.md): accepted paths, identifier removal, and private-path exclusions.
- [Bot filtering](bot-filtering.md): transient User-Agent matching and its limitations.
- [Storage model](storage-model.md): SQLite aggregates, schema, writes, and migration expectations.
- [Retention](retention.md): supported periods and bounded cleanup behavior.
- [Configuration](configuration.md): shared defaults and opt-in settings.

## Interfaces and assurance

- [Collector protocol](protocol.md): browser payload, endpoint responses, and failure behavior.
- [Administration HTTP API](admin/http-api.md): authenticated routes, request behavior, and response shape.
- [Privacy audit model](audit-model.md): technical evidence exposed by runtime audits.
- [Shared test vectors](test-vectors/): executable JSON cases for path normalization, bot filtering, and protocol behavior.
