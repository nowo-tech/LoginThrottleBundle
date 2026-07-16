# Release

This checklist helps maintainers prepare and publish a release safely.

## Pre-release

Run the full release pipeline:

```bash
make release-check
```

Expected steps:

- Composer validation and lock sync
- Code style checks
- Static analysis (Rector dry run + PHPStan)
- Test suite with coverage
- Demo verification flow

## Tag and publish

1. Update `docs/CHANGELOG.md` and `docs/UPGRADING.md`.
2. Run `make release-check`.
3. Create an annotated tag (`vX.Y.Z`).
4. Push the commit and tag.
5. Confirm GitHub workflows `release.yml` and `sync-releases.yml` completed successfully.

## Post-release checks

- Verify Packagist metadata is updated.
- Confirm the release notes contain tag message and changelog context.
- Validate installation in a clean Symfony app.

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.
