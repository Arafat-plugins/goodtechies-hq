<?php

namespace App\Services\Calendar;

use App\Models\Meeting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polish 030: the `api` driver — goodERP creates the Google Calendar event and its Meet link
 * itself, so the organiser never leaves the page (client, 2026-10-07: "create the meeting link
 * in current tab, do not move to google meet").
 *
 * One way: goodERP writes to Google and never reads back. Scheduling inserts an event with a
 * Meet conference, rescheduling patches its times and title, cancelling deletes it.
 *
 * **It never loses a meeting.** Until Google is connected (Admin → Settings), and whenever a
 * call fails, it behaves like the manual driver: the meeting is saved, the "Create Meet Link"
 * button and the paste box come back, and the failure is logged.
 */
class CalendarApiOneWay implements CalendarLink
{
    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

    public function __construct(private readonly GoogleOAuth $oauth) {}

    public function name(): string
    {
        return 'api';
    }

    public function createsLinksItself(): bool
    {
        return $this->oauth->connected();
    }

    public function startUrl(): ?string
    {
        return $this->oauth->connected() ? null : MeetLink::NEW_MEETING_URL;
    }

    public function schedule(Meeting $meeting): CalendarOutcome
    {
        if (! $this->oauth->connected()) {
            return CalendarOutcome::nothing();
        }

        // The organiser pasted a room of their own: keep it, and do not make a second one.
        $wantsConference = trim((string) $meeting->meet_link) === '';

        try {
            $body = $this->eventBody($meeting);

            if ($wantsConference) {
                $body['conferenceData'] = [
                    'createRequest' => [
                        'requestId' => (string) Str::uuid(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ];
            }

            $response = Http::withToken($this->oauth->accessToken())
                ->timeout(20)
                ->post($this->eventsUrl().'?conferenceDataVersion=1&sendUpdates=none', $body);

            if (! $response->successful()) {
                Log::warning('Google Calendar insert failed', ['meeting' => $meeting->getKey(), 'status' => $response->status()]);

                return CalendarOutcome::nothing();
            }

            $link = $wantsConference ? MeetLink::normalise($this->meetLinkFrom($response->json())) : null;

            return CalendarOutcome::linked((string) $response->json('id'), $link);
        } catch (Throwable $exception) {
            Log::warning('Google Calendar insert failed', ['meeting' => $meeting->getKey(), 'error' => $exception->getMessage()]);

            return CalendarOutcome::nothing();
        }
    }

    public function reschedule(Meeting $meeting): CalendarOutcome
    {
        if (! $this->oauth->connected()) {
            return CalendarOutcome::nothing();
        }

        // A meeting made before Google was connected has no event yet: make it now.
        if (trim((string) $meeting->google_event_id) === '') {
            return $this->schedule($meeting);
        }

        try {
            $response = Http::withToken($this->oauth->accessToken())
                ->timeout(20)
                ->patch($this->eventsUrl().'/'.rawurlencode((string) $meeting->google_event_id).'?sendUpdates=none', $this->eventBody($meeting));

            if (! $response->successful()) {
                Log::warning('Google Calendar patch failed', ['meeting' => $meeting->getKey(), 'status' => $response->status()]);
            }
        } catch (Throwable $exception) {
            Log::warning('Google Calendar patch failed', ['meeting' => $meeting->getKey(), 'error' => $exception->getMessage()]);
        }

        return CalendarOutcome::nothing();
    }

    public function cancel(Meeting $meeting): CalendarOutcome
    {
        if (trim((string) $meeting->google_event_id) === '' || ! $this->oauth->connected()) {
            return trim((string) $meeting->meet_link) === ''
                ? CalendarOutcome::nothing()
                : CalendarOutcome::manualActionNeeded(ManualLink::CANCEL_INSTRUCTION);
        }

        try {
            $response = Http::withToken($this->oauth->accessToken())
                ->timeout(20)
                ->delete($this->eventsUrl().'/'.rawurlencode((string) $meeting->google_event_id).'?sendUpdates=none');

            if ($response->successful() || $response->status() === 404 || $response->status() === 410) {
                return CalendarOutcome::remoteEventRemoved();
            }
        } catch (Throwable $exception) {
            Log::warning('Google Calendar delete failed', ['meeting' => $meeting->getKey(), 'error' => $exception->getMessage()]);
        }

        return CalendarOutcome::manualActionNeeded('The Google Calendar event could not be deleted — remove it in Google Calendar.');
    }

    /**
     * @return array<string, mixed>
     */
    private function eventBody(Meeting $meeting): array
    {
        $zone = (string) config('app.timezone');

        return [
            'summary' => (string) $meeting->title,
            'description' => (string) ($meeting->agenda ?? ''),
            'start' => ['dateTime' => $meeting->start_at->copy()->timezone($zone)->toIso8601String(), 'timeZone' => $zone],
            'end' => ['dateTime' => $meeting->end_at->copy()->timezone($zone)->toIso8601String(), 'timeZone' => $zone],
        ];
    }

    /**
     * @param  mixed  $event
     */
    private function meetLinkFrom($event): ?string
    {
        if (! is_array($event)) {
            return null;
        }

        if (is_string($event['hangoutLink'] ?? null)) {
            return $event['hangoutLink'];
        }

        foreach ($event['conferenceData']['entryPoints'] ?? [] as $entry) {
            if (($entry['entryPointType'] ?? null) === 'video' && is_string($entry['uri'] ?? null)) {
                return $entry['uri'];
            }
        }

        return null;
    }

    private function eventsUrl(): string
    {
        return sprintf(self::EVENTS_URL, rawurlencode((string) config('services.google_calendar.calendar_id', 'primary')));
    }
}
