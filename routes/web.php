<?php

use App\Http\Controllers\ExpenseReceiptController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// The Filament admin panel serves the whole app from `/`.

// Receipts live on the private disk; stream them behind the panel's own web and auth middleware.
$panel = Filament::getPanel('admin');

Route::middleware([
    ...$panel->getMiddleware(),
    ...$panel->getAuthMiddleware(),
    $panel->getMultiFactorAuthenticationRequiredMiddleware(),
])->get('expenses/{expense}/receipt', ExpenseReceiptController::class)->name('expenses.receipt');
