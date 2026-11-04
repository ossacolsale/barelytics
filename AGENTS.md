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

## Versions and releases

- User-visible fixes and features must have a release target. If package/runtime versions still match the latest published tag, prepare the next SemVer version: patch for fixes, minor for backward-compatible features, major for breaking public contracts. Reuse an already advanced unreleased version instead of bumping it on every edit; raise that target if the accumulated scope requires a larger SemVer increment. Schema and privacy-contract revisions remain separate from the application version.
- Follow [the release procedure](CONTRIBUTING.md#versions-and-github-releases): align all runtime/package/plugin versions and runtime history markers, validate them, update release notes, and run the required checks. Do not leave completed user-visible work indefinitely under the last published version.
- Version preparation is local work and does not authorize publication. An explicit user request for a GitHub release authorizes the necessary commit, push, new immutable tag and release workflow after validation; it does not authorize deployment to a website or publishing to package registries. Ordinary implementation requests do not authorize these external actions. Report the prepared version when publication has not been requested.
- Before tagging, verify the exact commit's CI is green. Never move an existing release tag or bypass a failed check. Monitor the release workflow through its result and verify the published assets; report an actual failure or missing access without calling the release complete.

## Incomplete work

For significant unfinished work, report its verified status, blocker or open decision, next action, and essential references. Do not leave completed work marked pending.
