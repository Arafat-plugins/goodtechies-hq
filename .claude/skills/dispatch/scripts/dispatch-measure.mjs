#!/usr/bin/env node
// dispatch-measure — rendered horizontal overflow, and optionally one computed style, per viewport width.
// Shipped with the dispatch skill; `/dispatch setup` copies it to <repo>/.claude/dispatch/.
// No dependencies of its own: it uses the repo's Playwright — Node from <repo>/node_modules, else
// Python from $DISPATCH_PYTHON or the repo's virtualenv. Never a global install, never the system python.
// Importing this file runs nothing; only `node dispatch-measure.mjs ...` does (see scripts/measure.test.mjs).
import { spawnSync } from 'node:child_process';
import { existsSync, realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const USAGE = `Usage (from the repo root):
  node .claude/dispatch/dispatch-measure.mjs <url> <width>... [--select <css selector> --prop <computed property>]
  node .claude/dispatch/dispatch-measure.mjs --probe     which renderer would be used; exit 0, or 3 with the fix

Examples:
  node .claude/dispatch/dispatch-measure.mjs http://localhost:5173/catalog 320 375 768 1280
  node .claude/dispatch/dispatch-measure.mjs http://localhost:5173/catalog 375 900 --select .grid --prop grid-template-columns
  node .claude/dispatch/dispatch-measure.mjs http://localhost:5173/ 375 --select :root --prop --brand

Output — a renderer line, then one line per width, nothing else:
  renderer: node playwright
  375px  overflow: no  .grid { grid-template-columns: 343px }
  320px  overflow: yes, 48px (scrollWidth 368 > clientWidth 320)
Quote the renderer line as the tool you rendered with.
--select/--prop read the first match only; the line says "(first of N)" when there are more.
--prop takes any computed property, custom properties (--brand) included.
For a column count, count the values grid-template-columns prints.

It starts nothing: the dev server must already be running.
Checks run in this order; the first that fails sets the exit code, with one line on stderr:
  1. arguments   -> exit 1  "<the problem> — see --help"; also Node older than 18
  2. dev server  -> exit 2  "dev server not reachable at <url>" (also on HTTP >= 400, a page that fails to
                           load or does not finish in time), or "<url> redirected to <final url>" — measure
                           the final URL, or give the page the session it needs; a redirect is never measured
  3. renderer    -> exit 3  "playwright not installed ..." or "chromium for playwright is not installed ...",
                           each with the fix for its ecosystem
Renderer lookup: $DISPATCH_PYTHON if set (exit 3 if it cannot import playwright); else Node "playwright"
from <current directory>/node_modules only; else Python "playwright" from .venv or venv. A virtualenv
outside the repo (poetry's default) needs DISPATCH_PYTHON.
Exit 0 means measured, not passed: read the lines.
Browsers: if .claude/dispatch/browsers/ exists next to this script and PLAYWRIGHT_BROWSERS_PATH
is unset, it is used (setup installs chromium there so nothing lands outside the repo).`;

const HERE = dirname(fileURLToPath(import.meta.url));
const VALUE_FLAGS = new Set(['--select', '--prop']);
const FLAGS = new Set([...VALUE_FLAGS, '--probe', '--help', '-h']);
const VENV_PYTHONS = ['.venv/bin/python', 'venv/bin/python', '.venv/Scripts/python.exe', 'venv/Scripts/python.exe'];
const LOAD_MS = 30000;
const IDLE_MS = 5000;
const BROWSERS = 'PLAYWRIGHT_BROWSERS_PATH="$PWD/.claude/dispatch/browsers"';

function die(code, msg) {
  console.error(msg);
  process.exit(code);
}

export function parseArgs(argv) {
  const o = { url: null, widths: [], select: null, prop: null, probe: false };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '-h' || a === '--help') return { help: true };
    if (a === '--probe') {
      o.probe = true;
    } else if (VALUE_FLAGS.has(a)) {
      const v = argv[++i];
      // Only one of our own flags counts as "no value": `--prop --brand` reads a custom property.
      if (v === undefined || v === '' || FLAGS.has(v)) return { error: `${a} needs a value` };
      o[a.slice(2)] = v;
    } else if (a.startsWith('-') && a !== '-') {
      return { error: `unknown option ${a}` };
    } else if (o.url === null) {
      o.url = a;
    } else if (/^[1-9]\d{0,4}$/.test(a)) {
      o.widths.push(Number(a));
    } else {
      return { error: `not a width in px: ${a}` };
    }
  }
  if (o.probe) {
    return o.url === null && !o.select && !o.prop ? { probe: true } : { error: '--probe takes no other arguments' };
  }
  if (o.url === null) return { error: 'missing <url>' };
  try {
    if (!/^https?:$/.test(new URL(o.url).protocol)) throw new Error();
  } catch {
    return { error: `not an http(s) URL: ${o.url}` };
  }
  if (o.widths.length === 0) return { error: 'give at least one width' };
  if (!o.select !== !o.prop) return { error: '--select and --prop go together' };
  // getPropertyValue wants kebab-case; accept gridTemplateColumns too. Custom properties are
  // case-sensitive and passed through untouched.
  if (o.prop && !o.prop.startsWith('--')) o.prop = o.prop.replace(/[A-Z]/g, (m) => '-' + m.toLowerCase());
  return o;
}

export function runtimeError(g = globalThis, version = process.version) {
  return typeof g.fetch === 'function' ? null : `needs Node 18+ (this is ${version}) — see --help`;
}

// Runs in the page. Kept self-contained: its source is also handed to Python Playwright.
export function measure([sel, prop]) {
  const d = document.documentElement;
  const r = { sw: d.scrollWidth, cw: d.clientWidth };
  if (sel) {
    try {
      const els = document.querySelectorAll(sel);
      r.matches = els.length;
      if (els.length) r.value = getComputedStyle(els[0]).getPropertyValue(prop).trim();
    } catch (e) {
      r.error = 'invalid selector';
    }
  }
  return r;
}

export function line(w, r, o) {
  let s = `${w}px  ` + (r.sw > r.cw
    ? `overflow: yes, ${r.sw - r.cw}px (scrollWidth ${r.sw} > clientWidth ${r.cw})`
    : 'overflow: no');
  if (o.select) {
    if (r.error) s += `  ${o.select}: ${r.error}`;
    else if (!r.matches) s += `  ${o.select}: no match`;
    else s += `  ${o.select} { ${o.prop}: ${r.value === '' ? '(empty)' : r.value} }` + (r.matches > 1 ? ` (first of ${r.matches})` : '');
  }
  return s;
}

// A redirect means another page was measured. Returns the final URL when it differs, else null.
export function redirectedTo(requested, final) {
  if (!final) return null;
  return new URL(requested).href === final ? null : final;
}

// Node Playwright from <root>/node_modules only. createRequire alone would also follow NODE_PATH
// and global folders — a machine-wide install the probes in status.md/setup.md would call missing.
export function repoPlaywrightPath(root = process.cwd()) {
  const dir = join(root, 'node_modules', 'playwright');
  if (!existsSync(join(dir, 'package.json'))) return null;
  try {
    const p = createRequire(join(root, 'package.json')).resolve('playwright', { paths: [root] });
    return p.startsWith(realpathSync(dir) + sep) ? p : null; // symlinked (pnpm) is fine; anything else is not
  } catch {
    return null;
  }
}

async function loadNodePlaywright(root) {
  const p = repoPlaywrightPath(root);
  if (!p) return null;
  try {
    const m = await import(pathToFileURL(p).href);
    return m && m.chromium ? m : m && m.default && m.default.chromium ? m.default : null;
  } catch {
    return null;
  }
}

function pyCanImport(py) {
  return spawnSync(py, ['-c', 'import playwright'], { stdio: 'ignore', timeout: 30000 }).status === 0;
}

// Which Python to use. `has`/`canImport` are injectable for the unit test.
export function findPython(env = process.env, root = process.cwd(), has = existsSync, canImport = pyCanImport) {
  if (env.DISPATCH_PYTHON) {
    const py = env.DISPATCH_PYTHON;
    return canImport(py)
      ? { py }
      : { error: `DISPATCH_PYTHON=${py} cannot run "import playwright" — fix the path, or unset it to search .venv and venv` };
  }
  for (const rel of VENV_PYTHONS) {
    const py = join(root, rel);
    if (has(py) && canImport(py)) return { py };
  }
  return { py: null };
}

export function chromiumFix(kind, py) {
  return 'chromium for playwright is not installed (or cannot start here) — run: ' +
    (kind === 'node' ? `${BROWSERS} npx playwright install chromium` : `${BROWSERS} "${py}" -m playwright install chromium`);
}

export function missingMessage(root = process.cwd(), has = existsSync) {
  const node = has(join(root, 'package.json'));
  const python = ['pyproject.toml', 'requirements.txt', 'setup.py', 'Pipfile'].some((f) => has(join(root, f)));
  const what = node && !python ? 'a dev-only `playwright` in package.json'
    : python && !node ? '`playwright` in the project virtualenv (dev group)'
    : 'a dev-only, repo-local playwright';
  return 'playwright not installed — no Node "playwright" in ./node_modules and no Python "playwright" in ' +
    `$DISPATCH_PYTHON, .venv or venv. Fix: /dispatch setup (it proposes ${what}, chromium in ` +
    '.claude/dispatch/browsers/). Until then report every width as Not verified.';
}

export async function findRenderer({ env = process.env, root = process.cwd(), loadNode = loadNodePlaywright, pickPython = findPython } = {}) {
  if (env.DISPATCH_PYTHON) {
    const p = pickPython(env, root);
    return p.error ? { error: p.error } : { kind: 'python', py: p.py, label: `python ${p.py}` };
  }
  const pw = await loadNode(root);
  if (pw) return { kind: 'node', pw, label: 'node playwright' };
  const p = pickPython(env, root);
  if (p.py) return { kind: 'python', py: p.py, label: `python ${p.py}` };
  return { error: missingMessage(root) };
}

async function renderNode(pw, o) {
  let browser;
  try {
    browser = await pw.chromium.launch();
  } catch (e) {
    die(3, chromiumFix('node'));
  }
  const out = [];
  let loadError = null;
  try {
    if (o.probe) return out;
    const page = await browser.newPage();
    for (const w of o.widths) {
      await page.setViewportSize({ width: w, height: 900 });
      try {
        await page.goto(o.url, { waitUntil: 'load', timeout: LOAD_MS });
      } catch (e) {
        loadError = String(e.message || e).split('\n')[0];
        break;
      }
      await page.waitForLoadState('networkidle', { timeout: IDLE_MS }).catch(() => {});
      out.push([w, await page.evaluate(measure, [o.select, o.prop]), page.url()]);
    }
  } finally {
    await browser.close();
  }
  if (loadError) die(2, `dev server not reachable at ${o.url} — page did not load: ${loadError}`);
  return out;
}

const PY = `
import json, sys
from playwright.sync_api import sync_playwright
url, sel, prop, fn = sys.argv[1:5]
widths = [int(w) for w in sys.argv[5:]]
with sync_playwright() as p:
    try:
        b = p.chromium.launch()
    except Exception:
        print("NOCHROMIUM")
        sys.exit(3)
    if not url:
        b.close()
        print("PROBEOK")
        sys.exit(0)
    pg = b.new_page()
    for w in widths:
        pg.set_viewport_size({"width": w, "height": 900})
        try:
            pg.goto(url, wait_until="load", timeout=${LOAD_MS})
        except Exception as e:
            print("NOLOAD " + (str(e).splitlines() or [""])[0])
            b.close()
            sys.exit(2)
        try:
            pg.wait_for_load_state("networkidle", timeout=${IDLE_MS})
        except Exception:
            pass
        print(json.dumps([w, pg.evaluate(fn, [sel or None, prop or None]), pg.url]))
    b.close()
`;

export function pythonTimeoutMs(widths) {
  return 30000 + (LOAD_MS + IDLE_MS + 10000) * Math.max(1, widths);
}

// Turns a spawnSync result into { results } or { code, msg }. Pure: the unit test feeds it.
export function readPython(r, py, url) {
  if (r.error && r.error.code === 'ETIMEDOUT') {
    return { code: 2, msg: `dev server not reachable at ${url} — the page did not finish within the time limit (slow or hung page, or chromium hung); nothing was measured` };
  }
  if (r.error) return { code: 3, msg: `python playwright could not start (${py}): ${r.error.message}` };
  const lines = (r.stdout || '').split('\n').filter(Boolean);
  if (lines.includes('NOCHROMIUM')) return { code: 3, msg: chromiumFix('python', py) };
  const noload = lines.find((l) => l.startsWith('NOLOAD'));
  if (noload) return { code: 2, msg: `dev server not reachable at ${url} — page did not load: ${noload.slice(7)}` };
  if (r.status !== 0) return { code: 3, msg: `python playwright failed (${py}): ${(r.stderr || '').trim().split('\n').pop()}` };
  return { results: lines.filter((l) => l !== 'PROBEOK').map((l) => JSON.parse(l)) };
}

function renderPython(py, o) {
  const args = ['-c', PY, o.probe ? '' : o.url, o.select || '', o.prop || '', measure.toString(), ...o.widths.map(String)];
  const r = spawnSync(py, args, { encoding: 'utf8', timeout: pythonTimeoutMs(o.widths.length) });
  const x = readPython(r, py, o.url);
  if (x.code) die(x.code, x.msg);
  return x.results;
}

async function main(argv) {
  const o = parseArgs(argv);
  if (o.help) {
    console.log(USAGE);
    process.exit(0);
  }
  if (o.error) die(1, `dispatch-measure: ${o.error} — see --help`);
  const rt = runtimeError();
  if (rt) die(1, `dispatch-measure: ${rt}`);
  if (o.probe) o.widths = [];

  // 2. dev server — this script never starts one. A 3xx is a different page: refuse it here, and
  //    compare the final URL after rendering for client-side redirects.
  if (!o.probe) {
    let res;
    try {
      res = await fetch(o.url, { redirect: 'manual', signal: AbortSignal.timeout(5000) });
    } catch {
      die(2, `dev server not reachable at ${o.url}`);
    }
    if (res.status >= 400) die(2, `dev server not reachable at ${o.url} — it answered HTTP ${res.status}; measuring an error page proves nothing`);
    if (res.status >= 300) {
      const to = res.headers.get('location');
      die(2, `${o.url} redirected to ${to ? new URL(to, o.url).href : '(no Location)'} (HTTP ${res.status}) — measure the final URL, or give the page the session it needs`);
    }
  }

  // 3. renderer
  if (!process.env.PLAYWRIGHT_BROWSERS_PATH && existsSync(join(HERE, 'browsers'))) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = join(HERE, 'browsers');
  }
  const rd = await findRenderer();
  if (rd.error) die(3, rd.error);
  const results = rd.kind === 'node' ? await renderNode(rd.pw, o) : renderPython(rd.py, o);
  for (const [, , final] of results) {
    const to = redirectedTo(o.url, final);
    if (to) die(2, `${o.url} redirected to ${to} — measure the final URL, or give the page the session it needs`);
  }
  console.log(`renderer: ${rd.label}`);
  for (const [w, r] of results) console.log(line(w, r, o));
}

const invoked = (() => {
  try {
    return realpathSync(resolve(process.argv[1] || '')) === realpathSync(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
})();
if (invoked) await main(process.argv.slice(2));
