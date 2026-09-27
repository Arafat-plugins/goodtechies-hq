<?php

namespace App\Http\Requests\Settings;

use App\Support\NotificationChannel;
use App\Support\NotificationType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One switch on Admin → Notifications: *this type, on this channel, on or off*
 * (master prompt Part D §20 — `notification_preferences (type, channel, enabled)`).
 *
 * ## One pair per request, not a whole grid
 *
 * The screen has twenty-one types on three channels and each control saves itself, so the
 * request is the pair it is about. A grid submit would have to say what an absent row meant —
 * and "absent means default" is the whole design of the table, so a payload in which absence
 * meant "off" would be the one shape this feature cannot have.
 *
 * ## A channel with no sender is refused HERE, not hidden in Vue
 *
 * Part E: *"other channels listed but disabled until spec post-MVP Phase 2"*. `web_push` and
 * `mail` are cases of `NotificationChannel` so that a later phase can turn one on, and no
 * sender is built for either — Part H §1 puts both out of scope for the MVP. A stored row
 * saying `web_push` is enabled would be a promise the application cannot keep, so it is not
 * merely un-drawn: `after()` below refuses it, against the type's **own** `channels()` list.
 *
 * That check is derived rather than written out, and the difference matters. Nothing here names
 * `in_app`. The day `NotificationType::channels()` gains a channel and a sender is built, that
 * channel becomes writable through this request, and this file does not change.
 *
 * Authorization is `can:settings.manage` on the route (Part C §1 — *Manage system settings*,
 * ADMIN only).
 */
class UpdateNotificationDefaultRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(NotificationType::values())],
            'channel' => ['required', 'string', Rule::in(array_column(NotificationChannel::cases(), 'value'))],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $type = $this->type();
                $channel = $this->channel();

                if (in_array($channel, $type->channels(), true)) {
                    return;
                }

                $validator->errors()->add(
                    'channel',
                    'Nothing sends on that channel yet, so it cannot be switched on.',
                );
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'There is no notification of that kind.',
            'channel.in' => 'There is no such channel.',
        ];
    }

    public function type(): NotificationType
    {
        return NotificationType::from((string) $this->input('type'));
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::from((string) $this->input('channel'));
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
