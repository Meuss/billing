<?php

namespace App\Domain\Invoicing;

use App\Enums\DiscountType;
use App\Enums\InvoiceLineType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Settings\BillingSettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the branded Invoice PDF with dompdf (ADR 0001).
 *
 * A draft is rendered from live data with a watermark. An issued Invoice is rendered once, from its
 * frozen line values, when it is sent; afterwards it is only ever served from the stored file.
 */
class InvoicePdf
{
    public const DISK = 'local';

    public const DIRECTORY = 'invoices';

    public function __construct(private BillingSettings $settings) {}

    /** The PDF bytes. */
    public function render(Invoice $invoice, bool $draft): string
    {
        $fontCache = storage_path('fonts');
        File::ensureDirectoryExists($fontCache);

        $dompdf = new Dompdf(new Options([
            'fontDir' => $fontCache,
            'fontCache' => $fontCache,
            'tempDir' => sys_get_temp_dir(),
            'chroot' => [resource_path('fonts/pdf'), public_path('brand')],
            'isRemoteEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'Schibsted Grotesk',
            'defaultPaperSize' => 'a4',
        ]));
        $dompdf->loadHtml(view('pdf.invoice', $this->viewData($invoice, $draft))->render());
        $dompdf->render();

        return $dompdf->output();
    }

    /** Renders the issued Invoice and keeps it under invoices/{number}.pdf on the private disk; returns the path. */
    public function store(Invoice $invoice): string
    {
        $path = self::DIRECTORY.'/'.$invoice->number.'.pdf';
        Storage::disk(self::DISK)->put($path, $this->render($invoice, draft: false));
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    public static function filename(Invoice $invoice): string
    {
        return $invoice->isDraft() ? "draft-{$invoice->getKey()}.pdf" : "{$invoice->number}.pdf";
    }

    /** @return array<string, mixed> */
    private function viewData(Invoice $invoice, bool $draft): array
    {
        // Fresh queries: Task Line hours of a draft follow the live Time Entries.
        $invoice->load([
            'client',
            'lines.project',
            'lines.expense',
            'lines.task.timeEntries' => fn ($entries) => $entries->orderBy('started_at'),
        ]);
        $lines = $invoice->lines;
        $locale = ($invoice->isDraft() ? $invoice->client->language : $invoice->language)->value;

        return [
            'invoice' => $invoice,
            'client' => $invoice->client,
            'sender' => $this->settings,
            'draft' => $draft,
            'locale' => $locale,
            'iban' => self::formatIban($this->settings->sender_iban),
            'projectGroups' => $lines->where('type', InvoiceLineType::Task)
                ->groupBy(fn (InvoiceLine $line) => $line->project_id)
                ->map(fn (Collection $group) => ['name' => $group->first()->project?->name, 'lines' => $group->values()])
                ->values(),
            'expenseLines' => $lines->where('type', InvoiceLineType::Expense)->values(),
            'itemLines' => $lines->where('type', InvoiceLineType::Item)->values(),
            'totals' => InvoiceTotals::for($invoice),
            'discountLabel' => self::discountLabel($invoice, $locale),
            'logo' => public_path('brand/logo-color.png'),
            'fonts' => resource_path('fonts/pdf'),
        ];
    }

    /** "CH9300762011623852957" → "CH93 0076 2011 6238 5295 7". */
    public static function formatIban(string $iban): string
    {
        return trim(chunk_split(strtoupper(preg_replace('/\s+/', '', $iban)), 4, ' '));
    }

    private static function discountLabel(Invoice $invoice, string $locale): string
    {
        $label = __('invoice.discount', [], $locale);
        if ($invoice->discount_type !== DiscountType::Percent) {
            return $label;
        }
        // Basis points: 1000 → "10", 1250 → "12.5".
        $fraction = rtrim(str_pad((string) ($invoice->discount_value % 100), 2, '0', STR_PAD_LEFT), '0');
        $percent = intdiv($invoice->discount_value, 100).($fraction === '' ? '' : '.'.$fraction);

        // French typesetting puts a (non-breaking) space before the percent sign.
        return $label.' '.$percent.($locale === 'fr' ? "\u{00A0}%" : '%');
    }
}
