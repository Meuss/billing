<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BillingSettings extends Settings
{
    public string $sender_name;

    public string $sender_address_line1;

    public string $sender_postal_code;

    public string $sender_city;

    public string $sender_country;

    public string $sender_email;

    public string $sender_phone;

    public string $sender_website;

    public string $sender_iban;

    public string $footer_text;

    public int $default_rate_cents;

    public int $number_seed_year;

    public int $number_seed_next;

    public static function group(): string
    {
        return 'billing';
    }
}
