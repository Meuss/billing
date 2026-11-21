<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseReceiptController extends Controller
{
    public function __invoke(Expense $expense): StreamedResponse
    {
        abort_unless($expense->receipt_path && Storage::disk(Expense::RECEIPT_DISK)->exists($expense->receipt_path), 404);

        return Storage::disk(Expense::RECEIPT_DISK)->response($expense->receipt_path);
    }
}
