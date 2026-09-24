<?php

use App\Broadcasting\ConversationChannel;
use App\Broadcasting\NotificationChannel;
use App\Broadcasting\TaskChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (Phase 6)
|--------------------------------------------------------------------------
|
| The three channels master prompt Part D §10 names, and no fourth. Every one
| of them is PRIVATE — there is no public channel in this application and
| there must not be one, because a public channel is a payload with no reader
| named, and Part C §1's whole shape is that the reader decides what is in it.
|
| Each callback is a class in `app/Broadcasting/`, and each class asks a policy
| that already existed:
|
|   conversation.{conversation}  → ConversationPolicy::view   (decision 2-24)
|   notifications.{user}         → NotificationPolicy::viewAny + "is this you"
|   task.{task}                  → TaskPolicy::view
|
| A rule that will not go through a policy is a finding, not a licence to write
| it twice. There is exactly one such rule and it is in NotificationChannel:
| "this channel is yours" is identity, not authorization, and the file says so.
|
| **The 403 is Laravel's, not ours.** `POST /broadcasting/auth` answers 403 when
| a callback returns false and 403 when an implicitly bound id matches no row,
| so a non-member is refused the same way whether the subject exists or not.
| `tests/Feature/Realtime/BroadcastAuthTest.php` is the security test of this
| phase and asserts all three.
|
| Subscribing is not reading. Everything that arrives on these channels was
| already readable over HTTP by the person it reached; the socket saves them a
| request, it does not widen an answer.
|
*/

Broadcast::channel('conversation.{conversation}', ConversationChannel::class);
Broadcast::channel('notifications.{user}', NotificationChannel::class);
Broadcast::channel('task.{task}', TaskChannel::class);
