<?php

namespace App\Http\Controllers\Accountant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Accountant/Dashboard', [
            'greetingName' => Str::before(trim($request->user()->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
        ]);
    }
}
