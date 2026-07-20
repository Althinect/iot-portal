# Contributing to IoT Portal

## 🔄 Git Workflow

This project follows a **streamlined git-flow** workflow with automated enforcement via GitHub Actions.

### Branch Strategy

- **`main`** - Production-ready code. All PRs merge here.
- **Feature branches** - Short-lived branches for individual user stories

### Working on a User Story

#### 1️⃣ Pick an Issue
Select an issue from the GitHub Project board (preferably from "Ready" or "Backlog" columns).

#### 2️⃣ Create Feature Branch
```bash
# Format: feature/us-<issue-number>-<short-slug>
git checkout main
git pull origin main
git checkout -b feature/us-1-device-types
```

**Branch naming rules** (enforced by CI):
- Must start with `feature/` or `hotfix/`
- Must include `us-<issue-number>`
- Must use kebab-case slug
- Example: `feature/us-7-parameter-definitions`

#### 3️⃣ Commit Your Work
Commit your changes with clear and descriptive commit messages.

**Examples:**
```bash
git commit -m "feat: add protocol types for MQTT"
git commit -m "fix: logic error in parameter validation"
```

#### 4️⃣ Push and Open PR
```bash
git push origin feature/my-feature-name
```

#### 5️⃣ Automated Checks
GitHub Actions will automatically run tests, Pint, and PHPStan to ensure code quality.

#### 6️⃣ Move Issue to "In Review"
Manually move the issue from "In Progress" → "In Review" on the GitHub Project board.

#### 7️⃣ Code Review
- If **changes requested**: Address feedback, push new commits
- If **approved**: Merge the PR (use **Squash and Merge** for clean history)

#### 8️⃣ Post-Merge
- Manually move issue to "Done" on the project board (or automate via GitHub Projects)
- Delete the feature branch

---

## 📋 Development Standards

### Code Quality Checklist
Before opening a PR, ensure:

```bash
# 1. Format code
vendor/bin/pint --dirty --format agent

# 2. Run static analysis
vendor/bin/phpstan analyse

# 3. Run tests
php artisan test --compact
```

### Testing Requirements
- **Every feature must have tests** (Pest 4)
- Tests should cover:
  - Model relationships and scopes
  - Policy authorization
  - Filament resource CRUD operations
  - Edge cases and validation rules

### Migration Standards
- Use descriptive migration names
- Always test `up()` and `down()` methods
- Include proper indexes and foreign keys
- Document complex schema changes

### Filament Resources
- Follow existing patterns in `app/Filament/`
- Use relation managers for nested resources
- Test CRUD operations manually in the browser before submitting PR
- Include appropriate permissions/policies

---

## 🤖 GitHub Actions Enforcement

GitHub Actions will automatically run tests and static analysis.

---

## 🎨 Code Style

This project uses:
- **Laravel Pint** for code formatting (PSR-12 with Laravel preset)
- **PHPStan** for static analysis (Level 8)
- **Rector** for automated refactoring

All enforced via CI pipeline.

---

## 🚀 Quick Reference

```bash
# Start new feature
git checkout -b feature/my-feature

# Commit
git commit -m "feat: my changes"

# Push and open PR
git push origin feature/my-feature

# Run quality checks locally
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
```

---

## 💡 Tips

1. **Keep commits atomic** - One logical change per commit
2. **Write descriptive commit messages**
3. **Test locally before pushing** - Run Pint, PHPStan, and tests
4. **Keep PRs focused**

---

## ❓ Questions?

Check the project documentation:
- [Backlog & User Stories](plan/03-backlog.md)
- [ERD Documentation](plan/01-erd-core.md)
- [Agent Guidelines](AGENTS.md)

Or open a discussion on GitHub!
