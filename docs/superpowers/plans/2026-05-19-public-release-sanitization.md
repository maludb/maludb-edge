# Public Release Sanitization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare `maludb-edge` for a safe clean import into a public GitHub repository.

**Architecture:** Keep private-development history in the current private repository, add tracked guardrails that prevent accidental publication of local secrets/runtime artifacts, and publish to GitHub through a clean `git archive` import. Do not delete local working files unless explicitly requested; make them ignored and document rotation requirements.

**Tech Stack:** Git, Composer, PHP 8.3, Markdown documentation.

---

## Scope Check

This plan handles local repository hygiene and public-release preparation. It does not create the GitHub repository because the target GitHub owner/repo URL has not been provided.

## File Structure

- Create: `.gitignore` - blocks local env files, runtime DBs, vendor output, and local connection probes.
- Create: `.env.example` - safe placeholder environment configuration.
- Create: `SECURITY.md` - public security reporting and hygiene policy.
- Create: `docs/public-release-checklist.md` - repeatable public release audit and clean import procedure.
- Create: `docs/superpowers/plans/2026-05-19-public-release-sanitization.md` - this plan.
- Track: `html/composer.lock` - locked dependency set for reproducible app installs.

## Task 1: Add Public Hygiene Guardrails

- [ ] Add `.gitignore` with rules for `.env`, runtime DBs, `var/`, `html/test-connection.php`, `html/vendor/`, logs, temp files, and worktrees.
- [ ] Add `.env.example` with placeholder `MALUDB_EDGE_*` variables.
- [ ] Add `SECURITY.md` with private vulnerability reporting guidance and release hygiene reminders.
- [ ] Add `docs/public-release-checklist.md` with audit commands, clean import commands, and release blockers.
- [ ] Track `html/composer.lock` after verifying it references only public package sources.
- [ ] Commit with `chore: add public release guardrails`.

## Task 2: Verify Public Release Readiness

- [ ] Run `git diff --check`.
- [ ] Run `git status --short`.
- [ ] Run `git check-ignore -v html/test-connection.php var/edge.sqlite html/vendor`.
- [ ] Run a tracked-file secret pattern scan.
- [ ] Run a history secret pattern scan.
- [ ] Run the PHP test suite with local SQLite extension flags if needed.
- [ ] Report remaining blockers: license selection, credential rotation, GitHub remote URL.

## Task 3: Publish Clean GitHub Import

- [ ] Receive the target GitHub remote URL.
- [ ] Create a clean export with `git archive --format=tar HEAD`.
- [ ] Initialize a new repository in the export directory.
- [ ] Confirm ignored local artifacts are absent.
- [ ] Commit `Initial public release`.
- [ ] Push to the GitHub remote.
- [ ] Enable GitHub secret scanning and branch protection.
