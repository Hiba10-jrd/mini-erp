<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
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

Route::view('administration/company', 'admin.company.index')
    ->middleware(['auth', 'auth.session', 'verified', 'can:company.administer'])
    ->name('admin.company.index');

Route::view('administration/commercial', 'admin.commercial.index')
    ->middleware(['auth', 'auth.session', 'verified', 'can:company.administer'])
    ->name('admin.commercial.index');

Route::view('administration/customers', 'admin.customers.index')
    ->middleware(['auth', 'auth.session', 'verified', 'can:customers.access'])
    ->name('admin.customers.index');

Route::get('administration/customers/{customer}', function (Customer $customer) {
    return view('admin.customers.show', compact('customer'));
})
    ->middleware(['auth', 'auth.session', 'verified', 'can:customers.access'])
    ->name('admin.customers.show');

Route::view('administration/suppliers', 'admin.suppliers.index')
    ->middleware(['auth', 'auth.session', 'verified', 'can:suppliers.access'])
    ->name('admin.suppliers.index');

Route::get('administration/suppliers/{supplier}', function (Supplier $supplier) {
    return view('admin.suppliers.show', compact('supplier'));
})
    ->middleware(['auth', 'auth.session', 'verified', 'can:suppliers.access'])
    ->name('admin.suppliers.show');

Route::view('administration/products', 'admin.products.index')
    ->middleware(['auth', 'auth.session', 'verified', 'can:products.access'])
    ->name('admin.products.index');

Route::get('administration/products/{product}', function (Product $product) {
    return view('admin.products.show', compact('product'));
})
    ->middleware(['auth', 'auth.session', 'verified', 'can:products.access'])
    ->name('admin.products.show');

require __DIR__.'/auth.php';
