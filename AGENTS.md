# Agent instructions

## Working rules

- Follow the user's request and the applicable instruction hierarchy. Inspect Git status and relevant files before editing; preserve unrelated work.
- Make the smallest complete change. Do not use documentation work as a reason to refactor application behavior or add process without a concrete benefit.
- Use progressive disclosure: for a narrow task, read its target files and applicable local instructions. For cross-cutting, architectural, ambiguous, risky, or resumed work, use [`llms.txt`](llms.txt) and the relevant entries in [`docs/README.md`](docs/README.md) or [`spec/README.md`](spec/README.md). Do not read unrelated documentation systematically.
- For resumed or unfinished work, verify relevant claims against Git, code, and available evidence. Keep handoffs limited to active work; do not create a history log.
- Protect secrets, privacy, stored analytics data, and existing compatibility guarantees. Do not add identifiers, tracking, or third-party telemetry. Destructive or external actions, publication, deployment, and material-cost actions require explicit authorization.

## Verification and documentation

- Add or update regression coverage for behavior changes. For PHP runtime changes, follow the full checks in [`CONTRIBUTING.md`](CONTRIBUTING.md), inspect applicable GitHub Actions workflows, and review the complete final diff. Report checks that could not run; never imply they passed.
- For documentation-only changes, validate affected links and instructions and run `git diff --check`.
- Update authoritative documentation when behavior or durable project guidance changes. Update `CHANGELOG.md` for user-visible, compatibility, security, or operational changes, following its existing release policy; internal documentation maintenance alone does not need a release entry.
- Code and tests describe current implementation; approved contracts in `spec/` describe intended behavior. Investigate and report disagreements instead of silently discarding either source.
- Do not commit, push, publish, deploy, or otherwise change external services unless explicitly requested.

## Incomplete work

For significant unfinished work, report its verified status, blocker or open decision, next action, and essential references. Do not leave completed work marked pending.
