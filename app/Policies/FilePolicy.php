<?php

namespace App\Policies;

use App\Models\File;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and remove a file (master prompt Part C, Phase 2 slice 4).
 *
 * Deny by default, like every policy here: no before() bypass, and an Admin passes the same
 * checks as everybody else.
 *
 * ## Viewing follows the owning record, and nothing else
 *
 * There is no `files.view` permission and there should not be one. A file is exactly as visible
 * as the thing it hangs off: a task attachment is visible to whoever may see the task, which
 * for an employee means the tasks they are ASSIGNED to; a project file to whoever may see the
 * project; a client file to whoever may see the client. Delegating to the owner's own policy is
 * what makes that true by construction — a rule stated twice is a rule that can be changed
 * once. The Accountant holds none of the owning permissions and so is refused everywhere
 * without appearing in this file at all.
 *
 * A file whose owner has gone is visible to nobody. That is deliberate rather than defensive:
 * the alternative to "no owner, no answer" is a file that outlives its access rule.
 *
 * ## Deleting is the uploader or an Admin
 *
 * Straight from the plan. Note what it is NOT: it is not "whoever may edit the task". Somebody
 * else's upload is somebody else's, and a Manager who may reassign the task still may not quietly
 * remove the evidence on it. The Admin half is the escape hatch for the file that should not be
 * there at all.
 *
 * Seeing it comes first. A file you may not see is not a file you may delete, whoever uploaded
 * it — the uploader clause widens who may delete a visible file, it does not reach past the
 * visibility rule.
 */
class FilePolicy extends Policy
{
    /**
     * A file is as visible as the record it belongs to.
     */
    public function view(User $user, File $file): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        $owner = $file->owner();

        return $owner !== null && Gate::forUser($user)->allows('view', $owner);
    }

    /**
     * Downloading IS viewing. It is a separate ability only so that the download route has
     * something to name; if the two could ever differ, one of them would be wrong.
     */
    public function download(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    /**
     * Replacing a file with a new version is a change to the owning record, so it takes the
     * same ability attaching one does — `update` on the owner — and not the delete rule.
     *
     * Uploading a better version of somebody else's file is ordinary work on a shared task;
     * deleting theirs is not, and the old version survives a replacement anyway. That is the
     * whole difference between the two and it is why they do not share a rule.
     */
    public function replace(User $user, File $file): bool
    {
        if (! $this->view($user, $file)) {
            return false;
        }

        // A message attachment is never replaced, by anybody — not even by the person who
        // posted it. A message is a statement made at a time and the next message is answering
        // it; swapping the file underneath rewrites what the room was talking about. There is
        // no message edit endpoint either, for the same reason, so this is that rule applied to
        // the half of a message that happens to live in another table. Attach the new file to a
        // new message.
        if ($file->message_id !== null) {
            return false;
        }

        $owner = $file->owner();

        return $owner !== null && Gate::forUser($user)->allows('update', $owner);
    }

    /**
     * The uploader or an Admin, and only among people who can see the file at all.
     */
    public function delete(User $user, File $file): bool
    {
        if (! $this->view($user, $file)) {
            return false;
        }

        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $file->uploaded_by !== null
            && (int) $file->uploaded_by === (int) $user->getKey();
    }
}
