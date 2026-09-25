<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'can:erp.access'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'can:erp.access'])
    ->name('profile');

require __DIR__.'/auth.php';
