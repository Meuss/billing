<?php

namespace App\Filament\Pages;

use App\Settings\BillingSettings;
use App\Support\Chf;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageBillingSettings extends SettingsPage
{
    protected static string $settings = BillingSettings::class;

    protected static ?string $navigationLabel = 'Billing settings';

    protected static ?string $title = 'Billing settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $slug = 'settings';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sender')->columns(2)->schema([
                TextInput::make('sender_name')->required()->maxLength(255),
                TextInput::make('sender_email')->email()->required()->maxLength(255),
                TextInput::make('sender_address_line1')->label('Address')->maxLength(255),
                TextInput::make('sender_postal_code')->label('Postal code')->maxLength(20),
                TextInput::make('sender_city')->label('City')->required()->maxLength(255),
                TextInput::make('sender_country')->label('Country (ISO code)')->required()->maxLength(2),
                TextInput::make('sender_phone')->tel()->maxLength(50),
                TextInput::make('sender_website')->maxLength(255),
                TextInput::make('sender_iban')
                    ->label('IBAN')
                    ->helperText('Swiss or Liechtenstein IBAN. Spaces are removed on save.')
                    ->maxLength(40)
                    ->rule(fn (): Closure => static::ibanRule()),
                Textarea::make('footer_text')->label('Invoice footer text')->columnSpanFull(),
            ]),
            Section::make('Rates')->schema([
                TextInput::make('default_rate')
                    ->label('Default hourly rate')
                    ->prefix('CHF')
                    ->required()
                    ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')
                    ->validationMessages(['regex' => 'Enter an amount in CHF with at most two decimals, e.g. 135.00.']),
            ]),
            Section::make('Invoice numbering')->description('Next number is YYYY-NNN, continuing from this seed.')->columns(2)->schema([
                TextInput::make('number_seed_year')->label('Seed year')->numeric()->integer()->required()->minValue(2000)->maxValue(2999),
                TextInput::make('number_seed_next')->label('Next number in seed year')->numeric()->integer()->required()->minValue(1),
            ]),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['default_rate'] = static::centsToChf((int) $data['default_rate_cents']);
        unset($data['default_rate_cents']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['default_rate_cents'] = static::chfToCents((string) $data['default_rate']);
        unset($data['default_rate']);

        foreach (['sender_address_line1', 'sender_postal_code', 'sender_phone', 'sender_website', 'footer_text'] as $key) {
            $data[$key] = (string) ($data[$key] ?? '');
        }

        $data['sender_iban'] = static::normaliseIban((string) ($data['sender_iban'] ?? ''));

        return $data;
    }

    public static function chfToCents(string $chf): int
    {
        return Chf::toCents($chf);
    }

    public static function centsToChf(int $cents): string
    {
        return Chf::fromCents($cents);
    }

    public static function normaliseIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban));
    }

    public static function isValidSwissIban(string $iban): bool
    {
        $iban = static::normaliseIban($iban);

        if (! preg_match('/^(CH|LI)\d{2}[0-9A-Z]{17}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    protected static function ibanRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && ! static::isValidSwissIban((string) $value)) {
                $fail('Enter a valid Swiss or Liechtenstein IBAN.');
            }
        };
    }
}
