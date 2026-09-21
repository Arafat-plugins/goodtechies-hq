<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

// `active` passes guests through; it only bounces a signed-in but deactivated user.
Route::get('/', HomeController::class)->middleware('active')->name('home');

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/employee.php';
require __DIR__.'/accountant.php';
require __DIR__.'/shared.php';
