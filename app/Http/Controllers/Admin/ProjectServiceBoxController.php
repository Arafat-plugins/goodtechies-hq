<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectServiceBoxesRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;

/**
 * Polish 033: the Admin edits the service boxes of Admin → Projects by client. They live in the
 * `project_service_boxes` setting, so `SettingsService::set()` stays the one writer — it checks
 * `settings.manage` again and writes the `configuration.changed` audit row.
 */
class ProjectServiceBoxController extends Controller
{
    public function update(UpdateProjectServiceBoxesRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->set('project_service_boxes', $request->boxes(), $request->user());

        return back()->with('success', 'Service boxes saved.');
    }
}
