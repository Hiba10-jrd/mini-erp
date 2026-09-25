<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'auth.session', 'verified', 'can:erp.access'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'auth.session', 'can:erp.access'])
    ->name('profile');

Route::view('administration/users', 'admin.users.index')
    ->middleware(['auth', 'auth.session', 'verified', 'can:users.administer'])
    ->name('admin.users.index');

Route::view('administration/roles', 'admin.roles.index')
    ->middleware(['auth', 'auth.session', 'verified', 'can:roles.administer'])
    ->name('admin.roles.index');

require __DIR__.'/auth.php';
