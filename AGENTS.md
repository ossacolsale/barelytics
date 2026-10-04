# Repository instructions

## After every code change

- Treat tests and verification as required for every request that changes application, test, build, or CI code, even when the request does not mention tests.
- Before finishing, inspect the complete diff and run the checks relevant to the change. For runtime PHP changes, run the full project checks listed in `CONTRIBUTING.md`: PHP syntax checks, `php tests/run.php`, `bash tests/integration.sh`, and `git diff --check`.
- Add or update regression tests for the behavior being changed. Run the complete suite after the final code edit, not only before it.
- When a check cannot run, make a reasonable attempt to provide its prerequisites in the local environment. If it remains unavailable, state exactly which check was skipped or blocked and why; never describe a skipped check as passing or imply the change is fully verified.

## GitHub Actions CI

- Inspect the applicable files under `.github/workflows/` for every code change. Confirm that the workflow triggers on the relevant branch and paths, uses supported runtime versions, installs required extensions and tools, and runs the checks that cover the changed behavior.
- Keep local verification aligned with CI, including PHP versions and extensions such as PDO SQLite. If local and CI environments differ, identify the difference and verify against the closest available setup.
- Review workflow syntax and any affected cache keys, permissions, secrets, service containers, and artifact dependencies when changing CI configuration.
- Do not claim that remote GitHub Actions passed unless the relevant run was actually inspected. If GitHub status is unavailable, report that limitation separately from local test results.

## Documentation and changelog

- Review all relevant documentation after each code change. Update the README, installation/deployment guides, configuration, privacy, security, or API guidance when the behavior or instructions they describe change.
- Update `CHANGELOG.md` for user-visible features, fixes, compatibility changes, security changes, and operational changes. Add entries under `Unreleased` unless the release version is explicitly being prepared.
- Keep documentation consistent with the implementation and tests. Do not edit unrelated documentation merely to create activity; if no documentation or changelog change is warranted, make that judgment explicitly during review.

## Completion report

- Summarize the implementation and link the important changed files.
- List the checks that ran and their results, identify any skipped checks, and state whether remote GitHub Actions was inspected.
- Mention documentation and changelog updates, or note briefly when neither needed a change.
