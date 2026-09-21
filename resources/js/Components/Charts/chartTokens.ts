import { onScopeDispose, ref, type Ref } from 'vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The one place a chart is allowed to learn a colour.
 *
 * Nothing under `Components/Charts` hard-codes a colour: every wrapper asks this
 * module, which resolves the CSS custom properties `resources/css/app.css` defines
 * and re-reads them whenever the `dark` class on `<html>` changes. Charts therefore
 * theme for free, and a token change lands in every chart without touching one.
 */
export interface ChartTokens {
    /** `--chart-1` … `--chart-5`, resolved. Slot 1 is the brand: the series being read. */
    series: string[];
    /** The six status colours, for a chart whose slices carry a status rather than a category. */
    status: Record<StatusKey, string>;
    /** The one faint horizontal rule, `--border`. */
    grid: string;
    /** Axis ticks and labels, `--muted-foreground`. */
    axis: string;
    /** Rendered values and the donut's centre label, `--foreground`. */
    text: string;
    /** The card behind the chart: also the 2 px gap drawn between adjacent fills. */
    surface: string;
    /** The tooltip surface, `--popover`, and its hairline. */
    overlay: string;
    border: string;
    /** `--font-sans`, so a chart never falls back to unovis's own font stack. */
    fontFamily: string;
}

const SERIES_VARS = ['--chart-1', '--chart-2', '--chart-3', '--chart-4', '--chart-5'] as const;

const STATUS_VARS: Record<StatusKey, string> = {
    backlog: '--status-backlog',
    todo: '--status-todo',
    progress: '--status-progress',
    review: '--status-review',
    changes: '--status-changes',
    done: '--status-done',
    waiting: '--status-waiting',
    cancelled: '--status-cancelled',
};

/**
 * Values only ever read in the browser; this is the shape returned before the first
 * read (and under any future SSR pass), never a colour decision of its own.
 */
const UNRESOLVED: ChartTokens = {
    series: SERIES_VARS.map(() => 'currentColor'),
    status: {
        backlog: 'currentColor',
        todo: 'currentColor',
        progress: 'currentColor',
        review: 'currentColor',
        changes: 'currentColor',
        done: 'currentColor',
        waiting: 'currentColor',
        cancelled: 'currentColor',
    },
    grid: 'currentColor',
    axis: 'currentColor',
    text: 'currentColor',
    surface: 'transparent',
    overlay: 'transparent',
    border: 'currentColor',
    fontFamily: 'inherit',
};

/**
 * One reusable probe element. Reading `getPropertyValue('--chart-2')` hands back the
 * raw `oklch(…)` token text; painting it onto a real element and reading `color` back
 * hands back a resolved `rgb(…)`, which is what unovis writes into SVG attributes.
 */
let probe: HTMLSpanElement | null = null;

function probeElement(): HTMLSpanElement {
    if (!probe) {
        probe = document.createElement('span');
        probe.setAttribute('aria-hidden', 'true');
        probe.style.cssText = 'position:absolute;width:0;height:0;opacity:0;pointer-events:none';
        document.body.append(probe);
    }

    return probe;
}

function resolve(variable: string): string {
    const element = probeElement();
    element.style.color = '';
    element.style.color = `var(${variable})`;

    return getComputedStyle(element).color || 'currentColor';
}

function read(): ChartTokens {
    if (typeof document === 'undefined' || !document.body) {
        return UNRESOLVED;
    }

    const status = {} as Record<StatusKey, string>;
    for (const [key, variable] of Object.entries(STATUS_VARS) as [StatusKey, string][]) {
        status[key] = resolve(variable);
    }

    return {
        series: SERIES_VARS.map(resolve),
        status,
        grid: resolve('--border'),
        axis: resolve('--muted-foreground'),
        text: resolve('--foreground'),
        surface: resolve('--card'),
        overlay: resolve('--popover'),
        border: resolve('--border'),
        fontFamily:
            getComputedStyle(document.documentElement).getPropertyValue('--font-sans').trim() || 'inherit',
    };
}

const tokens = ref<ChartTokens>(UNRESOLVED);
let observer: MutationObserver | null = null;
let consumers = 0;

/**
 * Resolved chart colours for the theme that is on screen right now.
 *
 * The `dark` class lives on `<html>` (T5), so one `MutationObserver` on that element's
 * class list is enough: when it flips, every mounted chart re-reads at once. The
 * observer is shared and only runs while at least one chart is mounted.
 */
export function useChartTokens(): Ref<ChartTokens> {
    if (typeof document !== 'undefined') {
        if (consumers === 0) {
            tokens.value = read();
            observer = new MutationObserver(() => {
                tokens.value = read();
            });
            observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        }

        consumers += 1;

        onScopeDispose(() => {
            consumers -= 1;
            if (consumers === 0) {
                observer?.disconnect();
                observer = null;
            }
        });
    }

    return tokens;
}

/**
 * The same tokens as the `--vis-*` variables unovis reads for its own furniture —
 * axes, gridlines, tooltip, donut centre. Bound as an inline style on each chart's
 * root so it overrides the defaults unovis injects at `:root`.
 *
 * The house rules live here, once: one faint horizontal gridline and no mesh, our
 * font, a 2 px surface gap between adjacent fills, a tooltip on the popover surface
 * with `--elevation-overlay` (the token behind `shadow-overlay`).
 */
export function chartCssVars(t: ChartTokens): Record<string, string> {
    return {
        '--vis-font-family': t.fontFamily,
        '--vis-axis-font-family': t.fontFamily,
        '--vis-axis-grid-color': t.grid,
        '--vis-axis-domain-color': t.grid,
        '--vis-axis-tick-color': t.grid,
        '--vis-axis-tick-label-color': t.axis,
        '--vis-axis-label-color': t.axis,
        '--vis-axis-tick-label-font-size': '12px',
        '--vis-axis-label-font-size': '12px',
        '--vis-axis-tick-label-weight': '400',
        '--vis-crosshair-line-stroke-color': t.axis,
        '--vis-crosshair-circle-stroke-color': t.surface,
        '--vis-area-stroke-width': '2px',
        '--vis-donut-segment-stroke-color': t.surface,
        '--vis-donut-segment-stroke-width': '2',
        '--vis-donut-central-label-font-family': t.fontFamily,
        '--vis-donut-central-label-text-color': t.text,
        '--vis-donut-central-sub-label-font-family': t.fontFamily,
        '--vis-donut-central-sub-label-text-color': t.axis,
        '--vis-tooltip-background-color': t.overlay,
        '--vis-tooltip-border-color': t.border,
        '--vis-tooltip-text-color': t.text,
        '--vis-tooltip-border-radius': 'var(--radius-md)',
        '--vis-tooltip-padding': '0.375rem 0.625rem',
        '--vis-tooltip-box-shadow': 'var(--elevation-overlay)',
        '--vis-tooltip-backdrop-filter': 'none',
    };
}

/**
 * Round tick values from 0 to `max`, at most `count` of them.
 *
 * unovis draws its gridlines at **twice** the axis's tick count, so leaving the count
 * to it produces a striped fill rather than the one faint rule the house rules ask
 * for. Handing both the axis and the grid the same explicit values fixes that, and
 * keeps the labels on round numbers.
 */
export function niceTicks(max: number, count = 4): number[] {
    if (!Number.isFinite(max) || max <= 0) {
        return [0];
    }

    const rough = max / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough) ?? 10 * magnitude;

    const ticks: number[] = [];
    for (let value = 0; value <= max + step / 1e6; value += step) {
        ticks.push(Number(value.toFixed(6)));
    }

    return ticks;
}

/** Escapes a value before it goes into a tooltip's HTML string. */
export function chartEscape(value: string | number): string {
    return String(value).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

/**
 * One tooltip body, shared by all four wrappers: a label line and a value line in
 * `tabular-nums`, on the popover surface. Text wears text tokens; the mark beside it
 * carries the identity.
 */
export function chartTooltip(label: string | number, value: string, swatch?: string): string {
    const dot = swatch
        ? `<span style="display:inline-block;width:.5rem;height:.5rem;border-radius:9999px;background:${chartEscape(swatch)}"></span>`
        : '';

    return (
        '<div style="display:flex;align-items:center;gap:.5rem;font-size:.75rem;line-height:1rem">' +
        dot +
        `<span>${chartEscape(label)}</span>` +
        `<span style="font-variant-numeric:tabular-nums;font-weight:500">${chartEscape(value)}</span>` +
        '</div>'
    );
}
