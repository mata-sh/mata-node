
# Contributing Guide

Thank you for considering contributing to this project!  
This document explains how to set up your environment, run tests, and follow our workflow.

---

## Getting Started

1. **Clone the repository**
    ```bash
    git clone https://github.com/<your-username>/<your-repo>.git
    cd <your-repo>
    ```

2. **Set up the development environment**
   Run the setup script to install dependencies and configure Git hooks:

    ```bash
    make setup
    ```

   This will:

   * Install PHP dependencies via Composer
   * Symlink the provided `pre-commit` hook into `.git/hooks/`
   * Initialize config files

---

## Git Hooks

We provide a **pre-commit hook** in `.githooks/pre-commit`.

```bash
make setup
```
It runs code quality checks (PHPStan etc.) before commits.
If the hook blocks your commit, fix the reported issues and try again.

---

## Code Style & Quality

* Follow **PSR-12** coding standards.
* Run static analysis and linting locally before pushing:

```bash
composer lint
```

---

## Documentation

`docs/configuration.md`, `docs/log-parsers.md`, `docs/shell-command-surface.md` and
`docker/README.md` are also published in the Node section of the
[MATA Dashboard docs site](https://github.com/mata-sh/mata-dashboard/tree/main/scripts/docs).
Keep links between them relative, and link anything else by absolute GitHub URL, so they work
in both places. A new page must also be added to mata-dashboard's `sync-node-docs.sh` and nav.

---

## Release & Changelog

Update `CHANGELOG.md` in the same change as anything a node operator or the dashboard would
notice: endpoints, response shapes, config keys, security behavior, deployment. Add one concise
bullet under `## [Unreleased]` (create it above the latest release if missing), in `Added`,
`Changed`, `Fixed` or `Removed`. Say what changed and any action operators must take. Skip
refactors, tests and internal-only changes. Never name customers, deployments or internal hosts.

At release time `[Unreleased]` becomes the version heading. Releases are tagged with plain
semantic versions (for example `0.2.0`, no `v` prefix); pushing the tag runs the release
workflow, which publishes a GitHub release with a CycloneDX SBOM.

---

## Tests

* Add or update tests for new features and bug fixes.
* Ensure all tests pass before pushing:

```bash
composer test
```

---