<?php

use App\Http\Controllers\ExpenseReceiptController;
use App\Http\Controllers\InvoicePdfController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// The Filament admin panel serves the whole app from `/`.

// Receipts and invoice PDFs live on the private disk; serve them behind the panel's own web and auth middleware.
$panel = Filament::getPanel('admin');

Route::middleware([
    ...$panel->getMiddleware(),
    ...$panel->getAuthMiddleware(),
    $panel->getMultiFactorAuthenticationRequiredMiddleware(),
])->group(function (): void {
    Route::get('expenses/{expense}/receipt', ExpenseReceiptController::class)->name('expenses.receipt');
    Route::get('invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
});
