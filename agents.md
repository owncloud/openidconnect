# agents.md -- OpenID Connect

## Repository Overview

ownCloud Server app enabling OpenID Connect (OIDC) authentication with external identity providers. Licensed under GPL-2.0.

## Architecture & Key Paths

- `lib/` -- PHP application logic
- `appinfo/` -- ownCloud app metadata
- `l10n/` -- Translation files
- `img/` -- Images
- `tests/` -- Unit tests
- `Makefile` -- Build and test automation
- `composer.json` -- PHP dependencies

## Development Conventions

- PHP code follows ownCloud coding standards
- Static analysis with PHPStan

## Build & Test Commands

```bash
make dev                      # Initialize dev environment
make dist                     # Build distribution package
make test-php-unit            # Run PHP unit tests
make test-php-style           # Check PHP code style
make clean                    # Clean build artifacts
```

## Important Constraints

- Licensed under GPL-2.0 (copyleft). Apache 2.0 migration planned.
- Requires a distributed memcache (Redis/Memcached).
- All contributions require a DCO sign-off.


## OSPO Policy Constraints

### GitHub Actions
- **Only** use actions owned by `owncloud`, created by GitHub (`actions/*`), verified on the GitHub Marketplace, or verified by the ownCloud Maintainers.
- Pin all actions to their full commit SHA (not tags): `uses: actions/checkout@<SHA> # vX.Y.Z`
- Never introduce actions from unverified third parties.

### Dependency Management
- Dependabot is configured for automated dependency updates.
- Review and merge Dependabot PRs as part of regular maintenance.
- Do not introduce new dependencies without discussion in an issue first.

### Git Workflow
- **Rebase policy**: Always rebase; never create merge commits. Use `git pull --rebase` and `git rebase` before pushing.
- **Signed commits**: All commits **must** be PGP/GPG signed (`git commit -S -s`).
- **DCO sign-off**: Every commit needs a `Signed-off-by` line (`git commit -s`).
- **Conventional Commits & Squash Merge**: Use the [Conventional Commits](https://www.conventionalcommits.org/) format where the repository enforces it. Many repos use squash merge, where the PR title becomes the commit message on the default branch — apply Conventional Commits format to PR titles as well. A reusable GitHub Actions workflow enforces this.

## Context for AI Agents

This app integrates with external OIDC providers for single sign-on. Configuration can be stored in `config.php` or in the `oc_appconfig` database table. Database configuration is preferred for clustered setups. The app supports multiple OIDC providers.
