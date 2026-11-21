<?php

namespace App\Domain\Import;

use App\Enums\Language;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** One-off import of Clients and Projects from an Invoice Ninja backup.json. Existing records are never touched. */
class InvoiceNinjaImporter
{
    private const DEFAULT_RATE_CENTS = 12000;

    /** ISO 3166-1 numeric (Invoice Ninja country_id) to alpha-2. */
    private const COUNTRIES = ['756' => 'CH', '250' => 'FR', '276' => 'DE', '40' => 'AT', '380' => 'IT', '438' => 'LI'];

    private const REQUIRED = ['address_line1', 'postal_code', 'city', 'email'];

    /**
     * @param  array<string, mixed>  $backup
     * @return array{clients: int, projects: int, skipped: int, warnings: list<string>}
     */
    public function import(array $backup): array
    {
        $this->validate($backup);

        return DB::transaction(function () use ($backup): array {
            $result = ['clients' => 0, 'projects' => 0, 'skipped' => 0, 'warnings' => []];
            $contacts = collect($backup['client_contacts'] ?? [])->groupBy('client_id');
            $clientIds = [];

            foreach ($backup['clients'] ?? [] as $row) {
                if ($row['is_deleted'] ?? false) {
                    continue;
                }

                $hashedId = (string) $row['hashed_id'];
                $existing = Client::where('invoice_ninja_id', $hashedId)->first();
                if ($existing) {
                    $clientIds[$hashedId] = $existing->id;
                    $result['skipped']++;

                    continue;
                }

                $attributes = $this->clientAttributes($row, $contacts->get($hashedId, collect()));
                foreach (self::REQUIRED as $field) {
                    if ($attributes[$field] === '') {
                        $result['warnings'][] = "{$hashedId}: {$field}";
                    }
                }

                $client = new Client($attributes + ['invoice_ninja_id' => $hashedId]);
                $this->applyArchive($client, $row);
                $client->save();
                $clientIds[$hashedId] = $client->id;
                $result['clients']++;
            }

            foreach ($backup['projects'] ?? [] as $row) {
                $clientId = $clientIds[$row['client_id'] ?? null] ?? null;
                if (($row['is_deleted'] ?? false) || $clientId === null) {
                    continue;
                }

                if (Project::where('invoice_ninja_id', (string) $row['hashed_id'])->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $project = new Project([
                    'client_id' => $clientId,
                    'name' => $row['name'],
                    'notes' => $this->nullable($row['private_notes'] ?? null),
                    'rate_cents' => $this->rateCents($row['task_rate'] ?? null),
                    'invoice_ninja_id' => (string) $row['hashed_id'],
                ]);
                $this->applyArchive($project, $row);
                $project->save();
                $result['projects']++;
            }

            return $result;
        });
    }

    /** @throws InvalidArgumentException when a row lacks the keys the import depends on. */
    private function validate(array $backup): void
    {
        $required = ['clients' => ['hashed_id', 'name'], 'projects' => ['hashed_id', 'name', 'client_id']];

        foreach ($required as $table => $keys) {
            foreach ($backup[$table] ?? [] as $index => $row) {
                foreach ($keys as $key) {
                    if (! is_scalar($row[$key] ?? null) || trim((string) $row[$key]) === '') {
                        throw new InvalidArgumentException("{$table} row {$index} has no {$key}");
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function clientAttributes(array $row, $contacts): array
    {
        $contact = $contacts->firstWhere('is_primary', true) ?? $contacts->first() ?? [];
        $languageId = (string) ($row['settings']['language_id'] ?? '');

        return [
            'name' => $row['name'],
            'contact_person' => $this->nullable(trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? ''))),
            'address_line1' => trim((string) ($row['address1'] ?? '')),
            'address_line2' => $this->nullable($row['address2'] ?? null),
            'postal_code' => trim((string) ($row['postal_code'] ?? '')),
            'city' => trim((string) ($row['city'] ?? '')),
            'country' => self::COUNTRIES[(string) ($row['country_id'] ?? '')] ?? 'CH',
            'email' => trim((string) ($contact['email'] ?? '')),
            'phone' => $this->nullable($contact['phone'] ?? null) ?? $this->nullable($row['phone'] ?? null),
            'website' => $this->nullable($row['website'] ?? null),
            'language' => $languageId === '1' ? Language::En : Language::Fr,
            'notes' => $this->nullable($row['private_notes'] ?? null),
        ];
    }

    /** Invoice Ninja "archived" is a set deleted_at with is_deleted false; an explicit archived_at wins. */
    private function applyArchive(Client|Project $model, array $row): void
    {
        $at = ($row['archived_at'] ?? null) ?: (($row['is_deleted'] ?? false) ? null : ($row['deleted_at'] ?? null));
        if ($at) {
            $model->archived_at = is_numeric($at) ? Carbon::createFromTimestamp((int) $at) : Carbon::parse($at);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Null when zero or equal to the default rate; otherwise centimes parsed from the decimal string, rounded half up. */
    private function rateCents(mixed $rate): ?int
    {
        [$whole, $fraction] = explode('.', trim((string) $rate).'.');
        $fraction = str_pad($fraction, 3, '0');
        $cents = (int) $whole * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $cents === 0 || $cents === self::DEFAULT_RATE_CENTS ? null : $cents;
    }
}
