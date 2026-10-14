/**
 * Polish 075/080: a project's folder-tile colour (status tokens only), varied by project id so
 * the cards and the project page agree.
 */
const TILES = [
    'border-status-progress-border bg-status-progress-bg text-status-progress-fg',
    'border-status-done-border bg-status-done-bg text-status-done-fg',
    'border-status-changes-border bg-status-changes-bg text-status-changes-fg',
    'border-status-review-border bg-status-review-bg text-status-review-fg',
    'border-status-backlog-border bg-status-backlog-bg text-status-backlog-fg',
    'border-status-waiting-border bg-status-waiting-bg text-status-waiting-fg',
];

export function projectTile(id: number): string {
    return TILES[id % TILES.length];
}
