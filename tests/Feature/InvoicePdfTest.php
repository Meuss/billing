<?php

use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\InvoiceService;
use App\Enums\DiscountType;
use App\Enums\Language;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00'));
    Storage::fake('local');
});

/** Extracted text with whitespace collapsed, lowercased: dompdf may uppercase labels and split runs. */
function pdfText(string $bytes): string
{
    $text = (new Parser)->parseContent($bytes)->getText();

    return mb_strtolower(preg_replace('/\s+/u', ' ', $text));
}

/**
 * A draft with a project group (3 time entries, 7:15 h at CHF 120), an expense (49.90),
 * an item (2 × 150.00) and a 10% discount: subtotal 1'219.90, discount -121.99, rounding -0.01, total 1'097.90.
 */
function pdfSampleInvoice(Language $language = Language::Fr): Invoice
{
    $client = Client::factory()->create(['name' => 'Fromagerie Gruyère SA', 'language' => $language]);
    $project = Project::factory()->for($client)->create(['name' => 'Refonte du site']);
    $task = Task::factory()->for($project)->create(['name' => 'Page d\'accueil']);
    foreach ([['2026-11-02 09:00', 180, 'Maquette de la page'], ['2026-11-03 13:00', 150, 'Intégration responsive'], ['2026-11-04 08:00', 105, 'Corrections après retours']] as [$start, $minutes, $description]) {
        TimeEntry::fromStartAndDuration($task, CarbonImmutable::parse($start), $minutes, $description)->save();
    }
    $task->update(['status' => TaskStatus::Done]);
    $expense = Expense::factory()->for($client)->create(['supplier' => 'Infomaniak', 'description' => 'Hébergement annuel', 'cost_cents' => 4990, 'billed_amount_cents' => 4990]);

    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($client);
    $service->addTask($invoice, $task);
    $service->addExpense($invoice, $expense);
    $service->addItem($invoice, 'Formation', '2', 15000);
    $service->setDiscount($invoice, DiscountType::Percent, 1000);

    return $invoice->fresh();
}

test('pdf renders for fr and en', function () {
    $fr = app(InvoicePdf::class)->render(pdfSampleInvoice(Language::Fr), draft: true);
    expect($fr)->toStartWith('%PDF')
        ->and(pdfText($fr))->toContain('facture', 'échéance', 'total');

    $en = app(InvoicePdf::class)->render(pdfSampleInvoice(Language::En), draft: true);
    expect(pdfText($en))->toContain('invoice', 'due date', 'total')
        ->not->toContain('échéance');
});

test('draft pdf has watermark', function () {
    expect(pdfText(app(InvoicePdf::class)->render(pdfSampleInvoice(Language::Fr), draft: true)))->toContain('brouillon')
        ->and(pdfText(app(InvoicePdf::class)->render(pdfSampleInvoice(Language::En), draft: true)))->toContain('draft');

    $invoice = pdfSampleInvoice(Language::Fr);
    app(InvoiceService::class)->markSent($invoice, CarbonImmutable::today());
    expect(pdfText(app(InvoicePdf::class)->render($invoice->fresh(), draft: false)))->not->toContain('brouillon');
});

test('pdf lists project groups, time entries, expenses section, discount, rounding, total', function () {
    $text = pdfText(app(InvoicePdf::class)->render(pdfSampleInvoice(), draft: true));

    expect($text)->toContain(
        'refonte du site',
        "page d'accueil",
        'intégration responsive',
        '03.11.2026',
        '2:30',
        '7:15',
        'frais',
        'hébergement annuel',
        'formation',
        'rabais',
        'arrondi',
        "chf 1'219.90",
        'chf -121.99',
        'chf -0.01',
        "chf 1'097.90",
    );
});

test('pdf shows the sender, client, meta and iban in groups of four', function () {
    $settings = app(BillingSettings::class);
    $settings->sender_iban = 'CH9300762011623852957';
    $settings->footer_text = 'Merci pour votre confiance';
    $settings->save();

    $invoice = pdfSampleInvoice();
    app(InvoiceService::class)->markSent($invoice, CarbonImmutable::today());
    $text = pdfText(app(InvoicePdf::class)->render($invoice->fresh(), draft: false));

    expect($text)->toContain('thomas miller', 'fromagerie gruyère sa', '2026-024', '21.11.2026', '21.12.2026',
        'ch93 0076 2011 6238 5295 7', 'merci pour votre confiance');
});

test('markSent stores pdf and route serves the stored file even after settings change', function () {
    actingAs(User::factory()->withMfa()->create());
    $settings = app(BillingSettings::class);
    $settings->sender_name = 'Ancien Nom Sàrl';
    $settings->save();

    $invoice = pdfSampleInvoice();
    app(InvoiceService::class)->markSent($invoice, CarbonImmutable::today());
    $invoice->refresh();

    expect($invoice->pdf_path)->toBe('invoices/2026-024.pdf');
    Storage::disk('local')->assertExists('invoices/2026-024.pdf');

    $settings->sender_name = 'Nouveau Nom SA';
    $settings->save();

    $response = get(route('invoices.pdf', $invoice))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline', '2026-024.pdf')
        ->and(pdfText($response->getContent()))->toContain('ancien nom sàrl')->not->toContain('nouveau nom sa');
});

test('route renders a fresh draft pdf with watermark', function () {
    actingAs(User::factory()->withMfa()->create());
    $invoice = pdfSampleInvoice();

    $response = get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('inline', "draft-{$invoice->id}.pdf")
        ->and(pdfText($response->getContent()))->toContain('brouillon');
});

test('guest cannot download pdf', function () {
    $invoice = pdfSampleInvoice();

    get(route('invoices.pdf', $invoice))->assertRedirect();
});
