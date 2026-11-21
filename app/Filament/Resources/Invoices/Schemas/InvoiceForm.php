<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\DiscountType;
use App\Models\Invoice;
use App\Support\Chf;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/** The draft edit form: note and discount (lines are in the relation manager), with live totals. */
class InvoiceForm
{
    private const DECIMAL_REGEX = '/^\d{1,7}(\.\d{1,2})?$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')->columns(2)->schema([
                TextEntry::make('client_name')->label('Client')->state(fn (Invoice $record): string => $record->client->name),
                TextEntry::make('pdf_language')->label('PDF language')->state(fn (Invoice $record): string => $record->client->language->label()),
                Textarea::make('note')->label('Note')->helperText('Printed above the lines, e.g. the period covered.')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Discount')->columns(2)->schema([
                Select::make('discount_type')
                    ->label('Discount')
                    ->options(collect(DiscountType::cases())->mapWithKeys(fn (DiscountType $type) => [$type->value => $type->label()])->all())
                    ->placeholder('No discount')
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('discount_value', null)),
                TextInput::make('discount_value')
                    ->label(fn (Get $get): string => $get('discount_type') === DiscountType::Percent->value ? 'Percentage' : 'Amount')
                    ->prefix(fn (Get $get): ?string => $get('discount_type') === DiscountType::Amount->value ? 'CHF' : null)
                    ->suffix(fn (Get $get): ?string => $get('discount_type') === DiscountType::Percent->value ? '%' : null)
                    ->visible(fn (Get $get): bool => filled($get('discount_type')))
                    ->required(fn (Get $get): bool => filled($get('discount_type')))
                    ->live(onBlur: true)
                    ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        if (! preg_match(self::DECIMAL_REGEX, (string) $value) || Chf::toCents((string) $value) <= 0) {
                            $fail('Enter a positive number with at most two decimals.');
                        } elseif ($get('discount_type') === DiscountType::Percent->value && Chf::toCents((string) $value) > 10000) {
                            $fail('A percentage cannot exceed 100.');
                        }
                    }),
            ]),
            InvoiceTotalsSection::make(live: true),
        ]);
    }

    /** Stored discount (basis points or centimes) → form decimal string. */
    public static function fillDiscount(array $data): array
    {
        $value = $data['discount_value'] ?? null;
        $data['discount_value'] = $value === null ? null : Chf::fromCents((int) $value);

        return $data;
    }

    /**
     * Form state → [type, stored value]: percent as basis points ("12.5" → 1250), amount as centimes.
     * An unparseable value gives no discount (the field's own validation reports it).
     *
     * @return array{0: ?DiscountType, 1: ?int}
     */
    public static function discountFromForm(array $data): array
    {
        $type = DiscountType::tryFrom((string) ($data['discount_type'] ?? ''));
        $value = trim((string) ($data['discount_value'] ?? ''));

        if ($type === null || ! preg_match(self::DECIMAL_REGEX, $value)) {
            return [null, null];
        }

        return [$type, Chf::toCents($value)];
    }
}
