# Pegwork

Pegwork Platform for WordPress: application-builder plugin. See architecture.md.

BMemory specs are canonical (`specification/wordpress-platform-plugin.md`, `decision/wordpress-platform-plugin-packaging.md`); local docs are copies. Update BMemory via the gateway only.

Gotcha: this folder is under Mead Drive (synced), which creates "<name> 2.<ext>" duplicates, even inside .git. Stage with explicit paths, never `git add -A` or `git add .`.

## Git (standing authorization, Brandon 2026-10-17)

Do all git work without asking: commit, push, open PRs, merge PRs, delete merged branches. Code changes: one branch and PR per session, merged once CI is green. Docs-only changes may go straight to `main`. Still ask before force-pushing or adding another remote.

## Project docs
- `architecture.md`: durable reference facts (envs, packaging, decisions, gotchas).
- `roadmap.md`: planned work and goals, with status.
- `changelog.md`: dated, newest-first log of changes made.
- `memory.md`: context and decision rationale not derivable from code or git.

Onboarding complete 2026-10-17.
