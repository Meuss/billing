<?php

namespace App\Console\Commands;

use App\Domain\Import\InvoiceNinjaImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('billing:import-invoice-ninja {path : Path to the Invoice Ninja backup.json}')]
#[Description('One-off import of Clients and Projects from an Invoice Ninja backup.json (idempotent)')]
class ImportInvoiceNinja extends Command
{
    public function handle(InvoiceNinjaImporter $importer): int
    {
        $path = $this->argument('path');
        $backup = is_file($path) ? json_decode(file_get_contents($path), true) : null;

        if (! is_array($backup)) {
            $this->components->error('Cannot read a valid Invoice Ninja backup.json at the given path.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($backup);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Clients created', 'Projects created', 'Skipped', 'Warnings'], [[
            $result['clients'], $result['projects'], $result['skipped'], count($result['warnings']),
        ]]);

        foreach ($result['warnings'] as $warning) {
            $this->components->warn("Empty required field {$warning}");
        }

        return self::SUCCESS;
    }
}
