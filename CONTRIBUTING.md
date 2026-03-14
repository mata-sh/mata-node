
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

## Release & Changelog

Maintainers can generate a changelog when creating a new release tag using:

```bash
make changelog
```

This script builds a commit-based changelog to include in release notes.

---

## Tests

* Add or update tests for new features and bug fixes.
* Ensure all tests pass before pushing:

```bash
composer test
```

---