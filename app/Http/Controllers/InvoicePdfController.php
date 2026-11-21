<?php

namespace App\Http\Controllers;

use App\Domain\Invoicing\InvoicePdf;
use App\Models\Invoice;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class InvoicePdfController extends Controller
{
    /** A draft is rendered fresh with its watermark; an issued Invoice is always the PDF stored when it was sent. */
    public function __invoke(Invoice $invoice, InvoicePdf $pdf): Response
    {
        if ($invoice->isDraft()) {
            $bytes = $pdf->render($invoice, draft: true);
        } else {
            abort_unless($invoice->pdf_path && Storage::disk(InvoicePdf::DISK)->exists($invoice->pdf_path), 404);
            $bytes = Storage::disk(InvoicePdf::DISK)->get($invoice->pdf_path);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.InvoicePdf::filename($invoice).'"',
        ]);
    }
}
