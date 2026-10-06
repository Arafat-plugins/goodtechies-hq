<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectTypeLabelsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;

/**
 * Polish 026: Admin renames the project types. The names live in the `project_type_labels`
 * setting, so `SettingsService::set()` stays the one writer — it checks `settings.manage` again
 * and writes the `configuration.changed` audit row — and `ProjectType::label()` reads them.
 */
class ProjectTypeController extends Controller
{
    public function update(UpdateProjectTypeLabelsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->set('project_type_labels', $request->labels(), $request->user());

        return back()->with('success', 'Project type names saved.');
    }
}
