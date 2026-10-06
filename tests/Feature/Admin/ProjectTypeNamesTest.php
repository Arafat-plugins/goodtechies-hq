<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\ProjectType;

/*
| Polish 026: the Admin renames the project types. The names are the `project_type_labels`
| setting; `ProjectType::label()` reads them everywhere, a blank box puts the built-in name back.
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

it('renames a type everywhere the type is named, and audits it', function () {
    $this->actingAs($this->admin)
        ->put('/admin/project-types', ['labels' => ['seo' => '  Search Engine  ', 'other' => '']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    app()->forgetScopedInstances();

    expect(ProjectType::Seo->label())->toBe('Search Engine')
        ->and(ProjectType::Other->label())->toBe('Other')
        ->and(ProjectType::Seo->defaultLabel())->toBe('SEO')
        ->and(AuditLog::where('event', AuditEvent::ConfigurationChanged->value)->count())->toBe(1);

    $props = $this->actingAs($this->admin)->get('/admin/projects/create')->assertOk()->inertiaProps();

    expect(collect($props['projectTypes'])->firstWhere('value', 'seo')['label'])->toBe('Search Engine')
        ->and($props['projectTypeDefaults']['seo'])->toBe('SEO')
        ->and($props['canRenameProjectTypes'])->toBeTrue();
});

it('puts the built-in name back when the box is emptied', function () {
    $this->actingAs($this->admin)->put('/admin/project-types', ['labels' => ['seo' => 'Search']]);
    $this->actingAs($this->admin)->put('/admin/project-types', ['labels' => ['seo' => '']]);

    app()->forgetScopedInstances();

    expect(ProjectType::Seo->label())->toBe('SEO');
});

it('refuses an unknown type and an overlong name', function () {
    $this->actingAs($this->admin)
        ->put('/admin/project-types', ['labels' => ['crm' => 'CRM', 'seo' => str_repeat('x', 61)]])
        ->assertSessionHasErrors(['labels', 'labels.seo']);
});

it('does not let an employee rename the types', function () {
    $this->actingAs($this->yaseen)
        ->put('/admin/project-types', ['labels' => ['seo' => 'Mine']])
        ->assertForbidden();

    expect(ProjectType::Seo->label())->toBe('SEO');
});
