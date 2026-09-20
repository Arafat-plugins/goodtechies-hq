<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'greetingName' => Str::before(trim($request->user()->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
            'stats' => [
                'activeEmployees' => Employee::where('status', UserStatus::Active)->count(),
            ],
        ]);
    }
}
