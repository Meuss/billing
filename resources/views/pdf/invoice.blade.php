@php
    use App\Support\Chf;
    use App\Support\Hours;

    $t = fn (string $key): string => __("invoice.$key", [], $locale);
    $date = fn ($value): string => $value ? $value->format('d.m.Y') : '—';
    $quantity = fn (string $value): string => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $t('title') }} {{ $invoice->number }}</title>
<style>
    @font-face { font-family: 'Schibsted Grotesk'; font-weight: 400; font-style: normal; src: url('{{ $fonts }}/SchibstedGrotesk-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'Schibsted Grotesk'; font-weight: 700; font-style: normal; src: url('{{ $fonts }}/SchibstedGrotesk-Bold.ttf') format('truetype'); }
    @font-face { font-family: 'Schibsted Grotesk'; font-weight: 800; font-style: normal; src: url('{{ $fonts }}/SchibstedGrotesk-ExtraBold.ttf') format('truetype'); }
    @font-face { font-family: 'DM Mono'; font-weight: 400; font-style: normal; src: url('{{ $fonts }}/DMMono-Medium.ttf') format('truetype'); }

    @page { margin: 44pt 50pt 84pt 50pt; }
    body { font-family: 'Schibsted Grotesk'; font-size: 9pt; line-height: 1.25; color: #121212; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 0; vertical-align: top; text-align: left; font-weight: 400; }

    .label { font-family: 'DM Mono'; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.12em; color: #6b6b67; }
    .mono { font-family: 'DM Mono'; font-size: 8.5pt; }
    .num { text-align: right; white-space: nowrap; }
    .muted { color: #6b6b67; }
    .strong { font-weight: 700; }

    .sender { text-align: right; font-size: 8pt; line-height: 1.3; }
    .sender .name { font-weight: 700; font-size: 9pt; }

    .intro { margin-top: 34pt; }
    .client { font-size: 9.5pt; line-height: 1.3; }
    .client .name { font-weight: 700; }
    .meta td { padding: 1.5pt 0; }
    .meta td.label { width: 70pt; padding-top: 3.5pt; }

    h1 { font-weight: 800; font-size: 24pt; letter-spacing: -0.02em; line-height: 1; margin: 30pt 0 0 0; }
    h1 .status { font-family: 'DM Mono'; font-weight: 400; font-size: 7pt; letter-spacing: 0.12em; text-transform: uppercase; color: #6b6b67; }
    .note { margin-top: 8pt; font-size: 9pt; white-space: pre-line; }

    .lines { margin-top: 20pt; }
    .lines .section td { padding: 16pt 0 4pt 0; border-bottom: 1px solid #e5e5e2; }
    .lines .section td.title { font-weight: 700; font-size: 10pt; padding-bottom: 4pt; }
    .lines .section td.label { vertical-align: bottom; padding-bottom: 5.5pt; }
    .lines tr.first-section td { padding-top: 0; }
    .lines .line td { padding: 6pt 0 2pt 0; }
    .lines td.end { border-bottom: 1px solid #e5e5e2; padding-bottom: 6pt; }
    .lines .col-qty { width: 56pt; }
    .lines .col-unit { width: 86pt; }
    .lines .col-amount { width: 86pt; }
    .supplier { font-size: 8pt; color: #6b6b67; }

    .entries { margin: 1pt 0 0 0; }
    .entries td { font-size: 8pt; line-height: 1.25; padding: 1pt 0; }
    .entries td.e-date { width: 52pt; font-family: 'DM Mono'; font-size: 7.5pt; color: #6b6b67; padding-top: 2pt; }
    .entries td.e-duration { width: 30pt; font-family: 'DM Mono'; font-size: 7.5pt; color: #6b6b67; padding-top: 2pt; }

    .totals { margin-top: 14pt; }
    .totals .spacer { width: 52%; }
    .totals td { padding: 2.5pt 0; }
    .totals td.label { padding-top: 4.5pt; }
    .totals tr.total td { padding-top: 9pt; padding-bottom: 7pt; border-bottom: 2px solid #ff6a13; }
    .totals tr.total td.word { font-weight: 800; font-size: 13pt; letter-spacing: -0.02em; }
    .totals tr.total td.amount { font-family: 'DM Mono'; font-size: 12pt; padding-top: 10.5pt; }
    .totals tr.total td.spacer { border-bottom: none; }

    .footer { position: fixed; left: 0; right: 0; bottom: -50pt; height: 30pt; border-top: 1px solid #e5e5e2; padding-top: 8pt; font-size: 7.5pt; color: #6b6b67; }
    .footer td { vertical-align: top; }
    .footer .iban { text-align: right; white-space: nowrap; }

    .watermark { position: fixed; top: 300pt; left: -40pt; right: -40pt; text-align: center; font-weight: 800; font-size: 96pt; letter-spacing: 0.04em; color: #f1f0ed; transform: rotate(-32deg); z-index: -1; }
</style>
</head>
<body>
@if ($draft)
    <div class="watermark">{{ $t('watermark') }}</div>
@endif

<div class="footer">
    <table>
        <tr>
            <td>{{ $sender->footer_text }}</td>
            @if ($iban !== '')
                <td class="iban"><span class="label">{{ $t('iban') }}</span>&nbsp;&nbsp;<span class="mono" style="color:#121212">{{ $iban }}</span></td>
            @endif
        </tr>
    </table>
</div>

<table>
    <tr>
        <td><img src="{{ $logo }}" style="width: 150pt;" alt="{{ $sender->sender_name }}"></td>
        <td class="sender">
            <div class="name">{{ $sender->sender_name }}</div>
            <div>{{ $sender->sender_address_line1 }}</div>
            <div>{{ trim($sender->sender_postal_code.' '.$sender->sender_city) }}@if ($sender->sender_country && $sender->sender_country !== 'CH'), {{ $sender->sender_country }}@endif</div>
            @foreach ([$sender->sender_email, $sender->sender_phone, $sender->sender_website] as $contact)
                @if (filled($contact))<div>{{ $contact }}</div>@endif
            @endforeach
        </td>
    </tr>
</table>

<table class="intro">
    <tr>
        <td style="width: 58%;">
            <div class="label" style="margin-bottom: 5pt;">{{ $t('bill_to') }}</div>
            <div class="client">
                <div class="name">{{ $client->name }}</div>
                @if (filled($client->contact_person))<div>{{ $client->contact_person }}</div>@endif
                <div>{{ $client->address_line1 }}</div>
                @if (filled($client->address_line2))<div>{{ $client->address_line2 }}</div>@endif
                <div>{{ trim($client->postal_code.' '.$client->city) }}</div>
                @if ($client->country && $client->country !== 'CH')<div>{{ $client->country }}</div>@endif
            </div>
        </td>
        <td>
            <table class="meta">
                <tr><td class="label">{{ $t('number') }}</td><td class="mono">{{ $invoice->number ?? '—' }}</td></tr>
                <tr><td class="label">{{ $t('sent_on') }}</td><td class="mono">{{ $date($invoice->sent_on) }}</td></tr>
                <tr><td class="label">{{ $t('due_on') }}</td><td class="mono">{{ $date($invoice->due_on) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<h1>{{ $t('title') }}@if ($invoice->number) {{ $invoice->number }}@endif @if ($draft)&nbsp;<span class="status">{{ $t('draft') }}</span>@endif</h1>
@if (filled($invoice->note))
    <div class="note">{{ $invoice->note }}</div>
@endif

<table class="lines">
    @foreach ($projectGroups as $group)
        <tr class="section {{ $loop->first ? 'first-section' : '' }}">
            <td class="title">{{ $group['name'] }}</td>
            <td class="label num col-qty">{{ $t('hours') }}</td>
            <td class="label num col-unit">{{ $t('rate') }}</td>
            <td class="label num col-amount">{{ $t('amount') }}</td>
        </tr>
        @foreach ($group['lines'] as $line)
            <tr class="line">
                <td class="strong">{{ $line->description }}</td>
                <td class="mono num">{{ Hours::format($line->billableMinutes()) }}</td>
                <td class="mono num">{{ Chf::format($line->unit_price_cents) }}{{ $t('per_hour') }}</td>
                <td class="mono num">{{ Chf::format($line->amountCents()) }}</td>
            </tr>
            <tr>
                <td class="end" colspan="4">
                    @if ($line->task->timeEntries->isNotEmpty())
                        <table class="entries">
                            @foreach ($line->task->timeEntries as $entry)
                                <tr>
                                    <td class="e-date">{{ $entry->started_at->format('d.m.Y') }}</td>
                                    <td class="e-duration">{{ Hours::format($entry->duration_minutes) }}</td>
                                    <td>{{ $entry->description }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </td>
            </tr>
        @endforeach
    @endforeach

    @if ($expenseLines->isNotEmpty())
        <tr class="section {{ $projectGroups->isEmpty() ? 'first-section' : '' }}">
            <td class="title" colspan="3">{{ $t('expenses') }}</td>
            <td class="label num col-amount">{{ $t('amount') }}</td>
        </tr>
        @foreach ($expenseLines as $line)
            <tr class="line">
                <td class="end" colspan="3">
                    {{ $line->description }}
                    @if ($line->expense)
                        <span class="supplier">&nbsp;·&nbsp;{{ $line->expense->supplier }}@if (filled($line->expense->original_amount_note)) ({{ $line->expense->original_amount_note }})@endif</span>
                    @endif
                </td>
                <td class="mono num end">{{ Chf::format($line->amountCents()) }}</td>
            </tr>
        @endforeach
    @endif

    @if ($itemLines->isNotEmpty())
        <tr class="section {{ $projectGroups->isEmpty() && $expenseLines->isEmpty() ? 'first-section' : '' }}">
            <td class="title">{{ $t('items') }}</td>
            <td class="label num col-qty">{{ $t('quantity') }}</td>
            <td class="label num col-unit">{{ $t('unit_price') }}</td>
            <td class="label num col-amount">{{ $t('amount') }}</td>
        </tr>
        @foreach ($itemLines as $line)
            <tr class="line">
                <td class="end">{{ $line->description }}</td>
                <td class="mono num end">{{ $quantity((string) $line->quantity) }}</td>
                <td class="mono num end">{{ Chf::format($line->unit_price_cents) }}</td>
                <td class="mono num end">{{ Chf::format($line->amountCents()) }}</td>
            </tr>
        @endforeach
    @endif
</table>

<table class="totals">
    <tr>
        <td class="spacer"></td>
        <td class="label">{{ $t('subtotal') }}</td>
        <td class="mono num">{{ Chf::format($totals->subtotalCents) }}</td>
    </tr>
    @if ($totals->discountCents !== 0)
        <tr>
            <td class="spacer"></td>
            <td class="label">{{ $discountLabel }}</td>
            <td class="mono num">{{ Chf::format(-$totals->discountCents) }}</td>
        </tr>
    @endif
    @if ($totals->roundingCents !== 0)
        <tr>
            <td class="spacer"></td>
            <td class="label">{{ $t('rounding') }}</td>
            <td class="mono num">{{ Chf::format($totals->roundingCents) }}</td>
        </tr>
    @endif
    <tr class="total">
        <td class="spacer"></td>
        <td class="word">{{ $t('total') }}</td>
        <td class="amount num">{{ Chf::format($totals->totalCents) }}</td>
    </tr>
</table>
</body>
</html>
