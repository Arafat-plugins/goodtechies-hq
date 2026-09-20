# Decisions log

This file records decisions taken where the spec or the master prompt was silent, or where the
build had to deviate from them. Each entry has a one-line reason. The same entries appear in
`PROGRESS.md` → the "Decisions made in Phase N" section for that phase.

## Phase 0 (17 Sep 2026)

| # | Decision | Reason |
| --- | --- | --- |
| 0-1 | Laravel **13** (13.32 at build time), not the spec's 11 | Laravel 11 no longer gets security fixes; the client confirms at GATE A |
| 0-2 | Vue pages and components are written in **TypeScript** (`<script setup lang="ts">`) | shadcn-vue components are TypeScript-first; the type check (`vue-tsc`) catches prop-contract drift |
| 0-3 | Tests run as **hq_app**; the schema is built as hq_migrator (`tests/Concerns/RefreshHqDatabase`) | Stricter than master prompt B§3 (which runs tests as the migrator): the tests exercise the real runtime grants |
| 0-4 | `user_project_permissions.project_id` has no foreign key until Phase 1 | The `projects` table does not exist yet; Phase 1 adds the FK |
| 0-5 | The MANAGER role lands in the **Employee** shell | MANAGER is dormant and has no UI in the MVP, so it gets the least-privilege shell |
| 0-6 | Default working week is **Sun–Thu, 09:00, 8 h** for office staff; Tapu's is Sun–Thu at 5 h, remote | The spec is silent; the client confirms at GATE A |
| 0-7 | Seeded logins are `*@goodtechies.test`; addresses can be overridden with `SEED_*_EMAIL`, passwords come from `SEED_PASSWORD` | No passwords or emails are hard-coded in the repo |
| 0-8 | Design tokens were derived by hand into DESIGN.md and `app.css` | The shadcn-tokens MCP was not reachable in the build session; its `get_theme` should read `app.css` back |
| 0-9 | Icons come from `@lucide/vue` | `lucide-vue-next` is deprecated upstream, and the shadcn-vue CLI generates `@lucide/vue` imports |
| 0-10 | Builds need **Node 22** (install.sh, CI) | The lockfile's packages require Node ≥ 22, and Node 20 is end-of-life |
| 0-11 | Admin → Settings is **read-only** in Phase 0, including the Backup health card | Settings editing belongs to Phase 12 |
| 0-12 | Login is throttled at 5/min per email + IP; the 2FA challenge at 5/min per pending user + IP | Brute-force protection (spec §36 rate limiting) |
| 0-13 | Password rule: at least 12 characters plus the HIBP k-anonymity breach check, no composition rules | Spec §36 |
| 0-14 | 2FA: TOTP with a ±30 s window and replay protection; 8 recovery codes, stored hashed and usable once | Spec §36 |
| 0-15 | The DB session timezone follows `DB_TIMEZONE` (default Asia/Dhaka) | Keeps `timestamptz` columns consistent with the app timezone on a UTC server |
| 0-16 | Backups dump the **database only** (as hq_migrator), encrypted with AES-256; hq_migrator gets CREATEDB for the `hq:verify-backup` scratch DB | Files are protected by bucket versioning and replication (master prompt B§4); the restore test needs a scratch database |
| 0-17 | The `hq-reverb` Supervisor program ships with `autostart=false` | Reverb is installed in Phase 6 |
| 0-18 | Changing the password rotates `remember_token` and deletes the user's other sessions | Otherwise a stolen device stays signed in |
| 0-19 | In Phase 0, "Active employees" is the only real number on the Admin dashboard; every other card is labelled "Arrives in Phase N" | No fake UI (master prompt 0.5) |
| 0-20 | Profile email is lower-cased on save | Login looks users up case-insensitively, so two accounts must never differ only by case |
