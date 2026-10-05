<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Employee;
use App\Services\EmployeeAdministrationService;
use App\Services\ExtensionPairingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin → employee page: disconnect one of that person's timer extensions
 * (docs/extension-api.md §1). An employee outside the requester's scope, or a device that is
 * not theirs, is 404.
 */
class EmployeeExtensionDeviceController extends Controller
{
    public function __construct(
        private readonly EmployeeAdministrationService $employees,
        private readonly ExtensionPairingService $pairing,
    ) {}

    public function destroy(Request $request, Employee $employee, Device $device): RedirectResponse
    {
        $subject = $this->employees->findFor($request->user(), $employee);

        if ($subject === null) {
            throw new NotFoundHttpException;
        }

        // Revoking a way to sign in is the same kind of act as switching the login off.
        Gate::authorize('deactivate', $subject);

        if ((int) $device->user_id !== (int) $subject->user_id) {
            throw new NotFoundHttpException;
        }

        $this->pairing->revoke($device, $request->user());

        return back()->with('success', sprintf(
            'Timer extension disconnected for %s.',
            $subject->user?->name ?? 'that employee',
        ));
    }
}
