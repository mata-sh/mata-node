
# Contributing Guide

Thank you for considering contributing to this project!  
This document explains how to set up your environment, run tests, and follow our workflow.

---

## 🚀 Getting Started

1. **Clone the repository**
   ```bash
   git clone https://github.com/<your-username>/<your-repo>.git
   cd <your-repo>
    ```

2. **Set up the development environment**
   Run the setup script to install dependencies and configure Git hooks:

   ```bash
   ./scripts/dev/setup.sh
   ```

   This will:

   * Install PHP dependencies via Composer
   * Symlink the provided `pre-commit` hook into `.git/hooks/`

---

## ✅ Git Hooks

We provide a **pre-commit hook** in `.githooks/pre-commit`.
It runs code quality checks (PHPStan, PHP CodeSniffer, etc.) before commits.
If the hook blocks your commit, fix the reported issues and try again.

---

## 🔧 Code Style & Quality

* Follow **PSR-12** coding standards.
* Run static analysis and linting locally before pushing:

  ```bash
  composer run lint
  composer run analyse
  ```
* Keep functions small and focused. Refactor legacy code incrementally, not in massive rewrites.

---

## 📦 Branching & Commits

* Use **feature branches** (`feature/...`), **bugfix branches** (`fix/...`), or **hotfix branches** (`hotfix/...`).

* Write clear, descriptive commit messages:

  ```
  type(scope): short description

  [optional longer description]
  ```

  Example:

  ```
  fix(router): prevent double slashes in generated URLs
  ```

* Types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`.

---

## 🛠 Release & Changelog

Maintainers can generate a changelog when creating a new release tag using:

```bash
./scripts/release/generate-changelog.sh
```

This script builds a commit-based changelog to include in release notes.

---

## 🧪 Tests

* Add or update tests for new features and bug fixes.
* Ensure all tests pass before pushing:

  ```bash
  composer run test
  ```

---

## 📖 Documentation

* Update the README or relevant docs for any new features.
* Keep `CONTRIBUTING.md` updated if workflows/scripts change.

---

## 💡 Tips

* Run `composer outdated` to check for outdated dependencies.
* Use `php -S localhost:8000 -t public` for quick local testing if needed.
* Keep PRs small and focused — easier to review and merge.

---