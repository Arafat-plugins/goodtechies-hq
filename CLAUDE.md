# GoodTechies HQ — read this first (auto-loaded by Claude Code)

> Sub-agents: read [AGENTS.md](AGENTS.md) first. It is the repo map.

This repo is **GoodTechies HQ**, an internal agency operating system (Laravel + Inertia + Vue 3 + PostgreSQL + Reverb) built for one client, phase by phase, on their VPS. Nothing here is generic: the plan is written down and you follow it.

## The two files that run this project

1. `PROGRESS.md` — what is built, what is in progress, which GATE we are waiting on, decisions taken, questions for the client. **Read it first, every session.**
2. `docs/master-prompt-v1.md` — the plan: Part 0 (session protocol + kick-off prompts), Part B (locked stack, VPS layout), Part C (privacy/security rules that never bend), Part D (domain spec), Part E (the 13 vertical phases with tests and gates), Part I (design references in `docs/design-refs/`), Part J (how to split a phase into `dispatch` briefs), Part H (what must NOT be built). Read Part 0, Part C, Part J and the current phase's section of Part E before writing code.

If these two disagree with anything you assume, they win. If they are silent, pick the simplest option that satisfies the client spec and record it in `PROGRESS.md` → Decisions.

## How work is done here

- **Vertical slices, never horizontal layers.** A phase is done only when its feature is clickable end to end with real seeded data and `php artisan test` is green for it and every earlier phase.
- **Execution method: the `dispatch` skill** (`.claude/skills/dispatch`, from `Arafat-plugins/dispatch`). Sub-agents: read `AGENTS.md` first. Main session: plan → brief per Part J.2 → accept from the diff → `/dispatch verify`. Never build ahead of the current phase.
- **Stop at every GATE** listed in Part E and wait for the user.
- **Privacy is tested, not assumed:** every phase that touches projects, payroll, clients or finance ships negative permission tests in the same phase (Part C §1, Part F §2).
- **Design tokens come from the `shadcn-tokens` MCP** (`get_theme`, `get_spacing`, `px_to_tailwind`, `get_typography`, `get_component_styles`). Call it before writing any Tailwind class; never hard-code a colour or an off-scale spacing. Components are installed through the `shadcn-vue` MCP. Visual references: `docs/design-refs/` (Part I says what to take and what to leave).
- **Do not add** anything in Part H §1 (no screenshots, no productivity scores, no CRM, no push/email in MVP, …).
- **Commit** at the end of a phase with `Phase N: <slice name>`; update `PROGRESS.md` before committing.

## Commands (filled in by Phase 0)

- Setup: `composer install && npm install`, `php artisan migrate:fresh --seed --database=pgsql_migrator`
- Run: `npm run dev` + `php artisan serve`
- Test: `php artisan test` (all) · `php artisan test --group=permissions`
- Deploy: `deploy/deploy.sh` (see Part B §4)

## Tools folder

`tools/shadcn-tokens-mcp/` — the token MCP server (`setup-windows.bat` registers it), `install-dispatch.bat` installs the skill, `project-mcp.json` is the template for this repo's `.mcp.json`.
