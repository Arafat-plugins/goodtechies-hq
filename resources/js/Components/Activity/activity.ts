/**
 * One person's day of activity — the payload `BuildsActivityDay` sends to "My activity" and to
 * the Admin's activity page. Domains and states only: never a URL, a title, a score or a rank.
 */
import type { TimeDateNav } from '@/Components/Time/time';

export type ActivityState = 'active' | 'media' | 'call' | 'idle';

export type ActivitySiteKind = 'site' | 'other_app' | 'browser_internal' | 'private';

export interface ActivityMinute {
    minute: string;
    state: ActivityState;
    call_source: string | null;
    host: string | null;
}

export interface ActivitySession {
    id: number;
    task: { id: number; name: string } | null;
    project: { id: number; name: string } | null;
    started_at: string;
    ended_at: string | null;
    state: 'running' | 'paused' | 'stopped';
    state_label: string;
    elapsed_seconds: number;
    activity_source: 'extension' | 'web' | null;
    has_activity_data: boolean;
    minutes: ActivityMinute[];
}

export interface ActivitySummary {
    tracked_seconds: number;
    active_minutes: number;
    media_minutes: number;
    call_minutes: number;
    idle_minutes: number;
    idle_percent: number;
    discarded_seconds: number;
}

export interface ActivitySite {
    kind: ActivitySiteKind;
    host: string;
    seconds: number;
    share: number;
}

export interface ActivityLegendItem {
    state: ActivityState;
    label: string;
}

export interface ActivityDayPayload {
    date: TimeDateNav;
    employee: { id: number; name: string };
    sessions: ActivitySession[];
    summary: ActivitySummary;
    sites: ActivitySite[];
    /** Polish 031: websites left out for being under `min_minutes` in the day. */
    sites_hidden: { count: number; seconds: number; min_minutes: number };
    /** Polish 031: the month around this day, tracked seconds per date. */
    month: { label: string; previous: string; next: string; days: { date: string; seconds: number }[] };
    legend: ActivityLegendItem[];
}

const STATE_LABELS: Record<ActivityState, string> = {
    active: 'Active',
    media: 'Video or audio',
    call: 'Call',
    idle: 'Idle',
};

const STATE_CLASSES: Record<ActivityState, string> = {
    active: 'bg-chart-2',
    media: 'bg-chart-4',
    call: 'bg-chart-3',
    idle: 'bg-muted-foreground/40',
};

export function stateLabel(state: ActivityState): string {
    return STATE_LABELS[state];
}

/** Token classes only. A minute with no sample is a gap, drawn by the caller as `bg-transparent`. */
export function stateClass(state: ActivityState): string {
    return STATE_CLASSES[state];
}
