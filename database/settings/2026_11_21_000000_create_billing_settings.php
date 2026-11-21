<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.sender_name', 'Thomas Miller');
        $this->migrator->add('billing.sender_address_line1', '');
        $this->migrator->add('billing.sender_postal_code', '');
        $this->migrator->add('billing.sender_city', 'Bulle');
        $this->migrator->add('billing.sender_country', 'CH');
        $this->migrator->add('billing.sender_email', 'contact@thomasmiller.ch');
        $this->migrator->add('billing.sender_phone', '+41 79 560 20 71');
        $this->migrator->add('billing.sender_website', 'thomasmiller.ch');
        $this->migrator->add('billing.sender_iban', '');
        $this->migrator->add('billing.footer_text', '');
        $this->migrator->add('billing.default_rate_cents', 12000);
        $this->migrator->add('billing.number_seed_year', 2026);
        $this->migrator->add('billing.number_seed_next', 24);
    }
};
