/**
 * Polish 026 (client: "time is international time but has to be am/pm"): the server sends wall
 * clock times as `HH:mm` (attendance, schedules), and every screen shows them as `9:37 am`.
 * Anything that is not an `HH:mm` string is returned unchanged.
 */
export function clock12(value: string | null | undefined): string {
    if (value == null) {
        return '';
    }

    const match = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(value.trim());

    if (match === null) {
        return value;
    }

    const hours = Number(match[1]);
    const suffix = hours >= 12 ? 'pm' : 'am';
    const hour = hours % 12 === 0 ? 12 : hours % 12;

    return `${hour}:${match[2]} ${suffix}`;
}
