<?php

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Project;
use App\Models\RecurringGenerationLog;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\GenerationOutcome;
use App\Support\ProjectStatus;
use App\Support\RecurrenceFrequency;
use App\Support\RoleName;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The Recurring tab: the screens over the Phase 3 engine
|--------------------------------------------------------------------------
|
| The plan's line is "Recurring task templates under a project (list,
| create/edit: title template, checklist template, recurrence
| monthly/weekly/custom, default assignee, active toggle, next run preview,
| 'Generate now' button, generation log with duplicate warnings)". This file
| is that sentence, endpoint by endpoint.
|
| Two things it is deliberately NOT testing again: the engine's own rules
| (tests/Feature/Services/RecurringTaskEngineTest.php) and the period-key
| arithmetic (tests/Unit/RecurrenceRuleTest.php). What it asserts about those
| is that the screens ASK them — that "Generate now" goes through the engine,
| that a second press is the engine's duplicate warning and not a second task,
| and that the preview's dates are RecurrenceRule's.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $this->template = RecurringTask::where('project_id', $this->project->id)->firstOrFail();
});

/** The body a create or an edit sends, with only the named keys changed. */
function recurringPayload(array $overrides = []): array
{
    return [
        'title_template' => 'Monthly retainer — {period}',
        'checklist_template' => ['Rank review', 'Client report'],
        'frequency' => 'monthly',
        'day_of_month' => 1,
        'default_assignee_id' => null,
        'active' => true,
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

it('lists a project’s templates as JSON, with everything a row prints', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/recurring")
        ->assertOk();

    $row = collect($response->json('templates'))
        ->firstWhere('id', $this->template->id);

    expect($row)->not->toBeNull()
        ->and($row['title_template'])->toBe($this->template->title_template)
        ->and($row['frequency'])->toBe('monthly')
        // Recurrence in words, spelled on the server — the screen never assembles it.
        ->and($row['recurrence_summary'])->toBe('On the 1st of every month, due at the end of the period.')
        ->and($row['active'])->toBeTrue()
        ->and($row['checklist_template'])->toBeArray()
        ->and($row['default_assignee']['name'])->toBe('Tapu')
        // The next run is a block, because a date on its own does not say which period it is
        // for — and the period is what the generated task will be labelled with.
        ->and($row['next_run'])->toHaveKeys(['at', 'period', 'period_label', 'due_date'])
        // Nothing has run yet on a fresh seed, so there is no last outcome to print.
        ->and($row['last_run'])->toBeNull()
        ->and($row['stop_reason'])->toBeNull()
        ->and($row['permissions'])->toBe(['can_update' => true, 'can_generate' => true]);
});

it('gives the next run the date and period RecurrenceRule gives it', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05'));

    $row = collect($this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/recurring")
        ->json('templates'))->firstWhere('id', $this->template->id);

    $expected = $this->template->rule()->nextRunAt(Carbon::today());

    expect($row['next_run']['at'])->toBe($expected->toDateString())
        ->and($row['next_run']['at'])->toBe('2026-11-01')
        ->and($row['next_run']['period'])->toBe('2026-11')
        ->and($row['next_run']['period_label'])->toBe('November 2026');

    Carbon::setTestNow();
});

it('says why a template on a closed project will not run, before any run says it', function () {
    $this->project->forceFill(['status' => ProjectStatus::Cancelled->value])->save();

    $row = collect($this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/recurring")
        ->json('templates'))->firstWhere('id', $this->template->id);

    // The template is still `active` — the stop is derived, never stored (decision 3-4) — so
    // without this the row would read as a perfectly healthy retainer that produces nothing.
    expect($row['active'])->toBeTrue()
        ->and($row['stop_reason'])->toContain('Cancelled')
        ->and($row['stop_reason'])->toContain('recurring generation stops');
});

it('refuses the list to every role but an Admin', function (string $user) {
    $this->actingAs($this->{$user})
        ->getJson("/admin/projects/{$this->project->id}/recurring")
        ->assertForbidden();
})->with(['manager', 'tapu', 'yaseen', 'accountant']);

/*
|--------------------------------------------------------------------------
| Creating and editing — the three rule shapes
|--------------------------------------------------------------------------
*/

it('creates a monthly template', function () {
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/recurring", recurringPayload([
            'day_of_month' => 15,
            'due_offset_days' => 20,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $template = RecurringTask::where('title_template', 'Monthly retainer — {period}')->firstOrFail();
    $rule = $template->rule();

    expect($template->project_id)->toBe($this->project->id)
        ->and($template->created_by)->toBe($this->admin->id)
        ->and($rule->frequency)->toBe(RecurrenceFrequency::Monthly)
        ->and($rule->dayOfMonth)->toBe(15)
        ->and($rule->dueOffsetDays)->toBe(20)
        ->and($template->checklistItems())->toBe(['Rank review', 'Client report'])
        // The preview column is filled at birth rather than at the first run, so a template
        // created today already says when it next fires.
        ->and($template->next_run_at)->not->toBeNull();
});

it('creates a weekly template', function () {
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/recurring", recurringPayload([
            'title_template' => 'Weekly standup — {period}',
            'frequency' => 'weekly',
            'weekday' => 3,
        ]))
        ->assertRedirect();

    $rule = RecurringTask::where('title_template', 'Weekly standup — {period}')->firstOrFail()->rule();

    expect($rule->frequency)->toBe(RecurrenceFrequency::Weekly)
        ->and($rule->weekday)->toBe(3)
        // The stored JSON is what RecurrenceRule::toArray() says it is — the form produced a
        // rule object, not a shape of its own.
        ->and($rule->toArray())->toBe(['frequency' => 'weekly', 'weekday' => 3]);
});

it('creates a custom every-N-days template from an anchor', function () {
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/recurring", recurringPayload([
            'title_template' => 'Fortnightly check — {period}',
            'frequency' => 'custom',
            'interval_days' => 14,
            'anchor' => '2026-10-07',
        ]))
        ->assertRedirect();

    $rule = RecurringTask::where('title_template', 'Fortnightly check — {period}')->firstOrFail()->rule();

    expect($rule->frequency)->toBe(RecurrenceFrequency::Custom)
        ->and($rule->intervalDays)->toBe(14)
        ->and($rule->anchor?->toDateString())->toBe('2026-10-07')
        // The period key of a custom rule is the date its period started — the engine's scheme,
        // reached through the rule the form built and not restated anywhere. Day 13 is still
        // the first cycle; day 14 starts the second.
        ->and($rule->periodKey(Carbon::parse('2026-10-20')))->toBe('2026-10-07')
        ->and($rule->periodKey(Carbon::parse('2026-10-21')))->toBe('2026-10-21');
});

it('refuses a day of the month a rule would silently clamp', function () {
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/recurring", recurringPayload(['day_of_month' => 31]))
        ->assertSessionHasErrors('day_of_month');

    expect(RecurringTask::where('title_template', 'Monthly retainer — {period}')->exists())->toBeFalse();
});

it('requires the parameter its frequency needs, and ignores the others', function () {
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/recurring", recurringPayload([
            'frequency' => 'custom',
            'interval_days' => null,
            'anchor' => null,
        ]))
        ->assertSessionHasErrors(['interval_days', 'anchor']);

    // A leftover weekday from an editor somebody toggled through is not an error: the rule for
    // this frequency never looks at it.
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/recurring", recurringPayload([
            'weekday' => 4,
            'interval_days' => 9,
        ]))
        ->assertSessionHasNoErrors();

    expect(RecurringTask::where('title_template', 'Monthly retainer — {period}')
        ->firstOrFail()->rule()->frequency)->toBe(RecurrenceFrequency::Monthly);
});

it('refuses a deactivated employee as the default assignee', function () {
    $employee = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $employee->forceFill(['status' => 'inactive'])->save();

    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/recurring", recurringPayload([
            'default_assignee_id' => $employee->id,
        ]))
        ->assertSessionHasErrors('default_assignee_id');
});

it('edits a template, including its switch, and refreshes the preview', function () {
    $this->actingAs($this->admin)
        ->put("/admin/recurring-tasks/{$this->template->id}", recurringPayload([
            'title_template' => 'Renamed — {period}',
            'frequency' => 'weekly',
            'weekday' => 5,
            'active' => false,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->template->refresh();

    expect($this->template->title_template)->toBe('Renamed — {period}')
        ->and($this->template->active)->toBeFalse()
        ->and($this->template->frequency)->toBe(RecurrenceFrequency::Weekly)
        // The column follows the new rule rather than the old one's leftover.
        ->and($this->template->next_run_at?->isFriday())->toBeTrue();
});

it('records the edit on the project’s own activity trail', function () {
    $this->actingAs($this->admin)
        ->put("/admin/recurring-tasks/{$this->template->id}", recurringPayload(['active' => false]))
        ->assertRedirect();

    expect(app(ActivityLogger::class)->for($this->project)
        ->pluck('description')
        ->contains(fn (string $line): bool => str_contains($line, 'switched off')))->toBeTrue();
});

it('will not let an edit move a template to another project', function () {
    $other = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();

    $this->actingAs($this->admin)
        ->put("/admin/recurring-tasks/{$this->template->id}", recurringPayload([
            'project_id' => $other->id,
        ]))
        ->assertRedirect();

    // `project_id` is not a field on the request and not fillable through this path, so the
    // template stays where it was rather than quietly re-pointing at another client's work.
    expect($this->template->refresh()->project_id)->toBe($this->project->id);
});

/*
|--------------------------------------------------------------------------
| Generate now
|--------------------------------------------------------------------------
*/

it('generates this period’s instance through the engine, checklist and all', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09'));

    $this->actingAs($this->admin)
        ->post("/admin/recurring-tasks/{$this->template->id}/generate")
        ->assertRedirect()
        ->assertSessionHas('success');

    $task = Task::where('recurring_task_id', $this->template->id)->firstOrFail();

    expect($task->recurring_period)->toBe('2026-10')
        ->and($task->title)->toBe('Buffalo Modular Monthly SEO — October 2026')
        // Created through TaskService, so it has the template's checklist and an assignee.
        ->and($task->checklistItems()->count())->toBe(5)
        ->and($task->assignees()->count())->toBe(1);

    expect(RecurringGenerationLog::where('recurring_task_id', $this->template->id)
        ->where('outcome', GenerationOutcome::Generated->value)->count())->toBe(1);

    Carbon::setTestNow();
});

it('answers a second press with the engine’s duplicate warning and no second task', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09'));

    $this->actingAs($this->admin)->post("/admin/recurring-tasks/{$this->template->id}/generate");

    $this->actingAs($this->admin)
        ->post("/admin/recurring-tasks/{$this->template->id}/generate")
        ->assertRedirect()
        // A skip is a refusal, not a success — and the sentence is the engine's own, so the
        // flash and the log row the reader then finds say the same words.
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'October 2026 already exists'));

    expect(Task::where('recurring_task_id', $this->template->id)->count())->toBe(1)
        ->and(RecurringGenerationLog::where('recurring_task_id', $this->template->id)
            ->where('outcome', GenerationOutcome::SkippedDuplicate->value)->count())->toBe(1);

    Carbon::setTestNow();
});

it('lets a forced run say the template is switched off, rather than doing nothing', function () {
    $this->template->forceFill(['active' => false])->save();

    $this->actingAs($this->admin)
        ->post("/admin/recurring-tasks/{$this->template->id}/generate")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(RecurringGenerationLog::where('recurring_task_id', $this->template->id)
        ->where('outcome', GenerationOutcome::SkippedInactive->value)->exists())->toBeTrue()
        ->and(Task::where('recurring_task_id', $this->template->id)->exists())->toBeFalse();
});

it('refuses Generate now to every role but an Admin', function (string $user) {
    $this->actingAs($this->{$user})
        ->post("/admin/recurring-tasks/{$this->template->id}/generate")
        ->assertForbidden();

    expect(Task::where('recurring_task_id', $this->template->id)->exists())->toBeFalse();
})->with(['manager', 'tapu', 'yaseen', 'accountant']);

/*
|--------------------------------------------------------------------------
| The log
|--------------------------------------------------------------------------
*/

it('reads the generation log back as sentences', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09'));

    $this->actingAs($this->admin)->post("/admin/recurring-tasks/{$this->template->id}/generate");
    $this->actingAs($this->admin)->post("/admin/recurring-tasks/{$this->template->id}/generate");

    $entries = $this->actingAs($this->admin)
        ->getJson("/admin/recurring-tasks/{$this->template->id}/log")
        ->assertOk()
        ->json('entries');

    // Newest first.
    expect($entries)->toHaveCount(2)
        ->and($entries[0]['outcome'])->toBe('skipped_duplicate')
        ->and($entries[0]['outcome_label'])->toBe('Skipped — already generated')
        ->and($entries[0]['is_warning'])->toBeTrue()
        ->and($entries[0]['period_label'])->toBe('October 2026')
        ->and($entries[0]['sentence'])->toContain('already exists')
        // The row is clickable: the task it refers to is named and located, never serialised
        // whole.
        ->and($entries[0]['task'])->toHaveKeys(['id', 'title'])
        ->and($entries[1]['outcome'])->toBe('generated')
        ->and($entries[1]['is_warning'])->toBeFalse()
        // The engine leaves a clean generation silent; the resource still gives it a sentence,
        // because "generated" on its own is not one.
        ->and($entries[1]['sentence'])->toBe('Generated the instance for October 2026.');

    Carbon::setTestNow();
});

it('flags a generation that happened while the previous period was still open', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09'));
    $this->actingAs($this->admin)->post("/admin/recurring-tasks/{$this->template->id}/generate");

    Carbon::setTestNow(Carbon::parse('2026-11-09'));
    $this->actingAs($this->admin)->post("/admin/recurring-tasks/{$this->template->id}/generate");

    $entries = $this->actingAs($this->admin)
        ->getJson("/admin/recurring-tasks/{$this->template->id}/log")
        ->json('entries');

    expect($entries[0]['outcome'])->toBe('generated')
        // Generated AND a warning: the instance was made, and last month's is still open.
        ->and($entries[0]['is_warning'])->toBeTrue()
        ->and($entries[0]['sentence'])->toContain('October 2026')
        ->and($entries[0]['previous_open_task'])->not->toBeNull();

    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| The next-run preview
|--------------------------------------------------------------------------
*/

it('previews an unsaved rule with RecurrenceRule’s own answers', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05'));

    $preview = $this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/recurring/preview?".http_build_query([
            'frequency' => 'monthly',
            'day_of_month' => 15,
            'due_offset_days' => 20,
        ]))
        ->assertOk()
        ->json('preview');

    expect($preview['at'])->toBe('2026-10-15')
        ->and($preview['period'])->toBe('2026-10')
        ->and($preview['period_label'])->toBe('October 2026')
        // due_offset_days counts from the period START on all three frequencies — one knob.
        ->and($preview['due_date'])->toBe('2026-10-21')
        ->and($preview['summary'])->toBe('On the 15th of every month, due 20 days after the period starts.');

    Carbon::setTestNow();
});

it('previews a weekly rule with the ISO week the run falls in', function () {
    // 29 December 2025 is in ISO week 1 of 2026 — the case the key scheme uses the ISO year for.
    Carbon::setTestNow(Carbon::parse('2025-12-27'));

    $preview = $this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/recurring/preview?".http_build_query([
            'frequency' => 'weekly',
            'weekday' => 1,
        ]))
        ->assertOk()
        ->json('preview');

    expect($preview['at'])->toBe('2025-12-29')
        ->and($preview['period'])->toBe('2026-W01')
        ->and($preview['period_label'])->toBe('Week 1, 2026');

    Carbon::setTestNow();
});

it('refuses to preview a rule it would not store', function () {
    $this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/recurring/preview?".http_build_query([
            'frequency' => 'custom',
            'interval_days' => 900,
            'anchor' => '2026-10-07',
        ]))
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Absent, not forbidden
|--------------------------------------------------------------------------
*/

it('answers 404 for a template that is not there', function () {
    $this->actingAs($this->admin)
        ->getJson('/admin/recurring-tasks/999999/log')
        ->assertNotFound();
});

it('answers 404 rather than 403 for a template outside the requester’s scope', function () {
    // The branch `surface:admin` hides on this surface, reached directly: an Admin who can see
    // no projects must be told the template is ABSENT, because a 403 would confirm that a
    // template with that id exists on a project they may not see (Part C). The seeded Admin is
    // used rather than a fresh one so the request still clears two-factor enrolment — this row
    // is about the record, not about the shell.
    $this->admin->employee->role->permissions()->detach(
        Permission::where('key', App\Support\Permission::ProjectsView->value)->firstOrFail()
    );

    $this->actingAs($this->admin->fresh())
        ->getJson("/admin/recurring-tasks/{$this->template->id}/log")
        ->assertNotFound();

    $this->actingAs($this->admin->fresh())
        ->put("/admin/recurring-tasks/{$this->template->id}", recurringPayload())
        ->assertNotFound();
});
