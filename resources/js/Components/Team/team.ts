import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The Team directory's payload, exactly as `TeamMemberResource` sends it.
 *
 * Ten keys, and the list is the feature: the plan's line for this screen ends *"no salary, no
 * tracking data"*, so the serializer is field-level and
 * `tests/Feature/Team/TeamDirectoryTest.php` asserts the exact set. If a field is missing from
 * this interface it is missing from the payload, not hidden by it — that is Part C §1's rule
 * and it is enforced on the server, never here.
 *
 * There is deliberately no `tracking_mode`, no clock time, no minute count and nothing
 * countable at all. `availability` is a status WORD (Part D §8's vocabulary) and never a
 * measure; nothing on this screen ranks or compares people (Part H §1).
 */
export interface TeamMember {
    id: number;
    name: string;
    /** `RoleName`'s value — `ADMIN`, `REMOTE_EMPLOYEE`, … — or null. Asserted on; not printed. */
    role: string | null;
    /** The word a person reads. Composed on the server so two screens cannot spell it two ways. */
    role_label: string;
    /**
     * `AttendanceStatus`'s value for today, from `AttendanceService::dayFor()` — the ONE
     * statement of what a day is (decisions 4-9, 5-2).
     *
     * Null in two different situations, which `availability_label` tells apart: a tracked
     * person whose day has not happened yet ("No record yet"), and somebody the roster has
     * nothing to say about at all ("Not tracked"). Never "Absent" by guess.
     */
    availability: string | null;
    availability_label: string;
    /** The `StatusBadge` key, resolved on the server. Null when there is no status to tint. */
    availability_tone: StatusKey | null;
    /** The company holiday today falls on, or null. A fact about the company, not the person. */
    holiday_name: string | null;
    is_you: boolean;
    /**
     * Where the DM button posts, resolved per reader. **Null means no button** — for yourself,
     * for somebody who holds no `messages.use`, and on a build where the Messages route does
     * not exist. A control the endpoint would refuse is not drawn (DESIGN.md §5.11), and
     * nothing here derives that from a role.
     */
    dm_url: string | null;
}
