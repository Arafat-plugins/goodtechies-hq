# Examples

## `AGENTS.example.md`

An anonymized `AGENTS.md`, adapted from one that `/dispatch bootstrap` generated for a large
WordPress commerce plugin — ~27 stylesheets, 30 Gutenberg blocks, 168 smoke tests, two coexisting
PHP generations. Names, paths and test names are invented; the structure and proportions are real.

Worth reading for the three sections that carry the most weight in practice:

- **Do NOT** — names the committed build output, the vendored directories, and the edition
  boundary that breaks things when ignored. These are rules an agent cannot infer from the file
  it is editing.
- **Known-failing baseline** — 11 of 168 smoke tests already fail on a clean checkout. Without
  this list, every agent re-investigates them and reports them as regressions it caused.
- **What not to bother reading** — a direct instruction not to spend context, with a reason per
  entry.

Two things to copy from how it is written:

**Generate facts, do not inherit them.** The breakpoint table was built by reading the actual
`@media` queries. The prose in the project's existing CLAUDE.md said "900 and 620/640"; the real
set included 560 and differed per stylesheet. A "do not invent breakpoints" rule with a wrong
list is worse than no rule at all.

**Date anything measured.** The known-failing baseline is a measurement with a shelf life. Say
when it was taken, at which commit, with which command — and say `not measured — <reason>`
for any suite you could not run. Bootstrap runs the suites to fill this in; it never writes
"none" from not looking.

**The markers.** The first and last lines are `<!-- dispatch:map v1 -->` and
`<!-- /dispatch:map -->`. They are how the skill tells its own map from an `AGENTS.md` some
other tool wrote; on a foreign file bootstrap appends a marked region instead of overwriting.

**Surfaces and capabilities.** The *Surfaces* table lets a brief name the owning files without
a grep, and is generated from the code (shortcode classes, `register_rest_route`), grouped where
a surface has many rows. *Verification capabilities* is what `/dispatch setup` recorded: how
widths get rendered, where design rules live, and which read-only database user checks use.

Note it is ~190 lines for a repo of that size. If your map approaches the cost of the territory,
it has stopped being a map.
