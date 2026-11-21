<?php

namespace App\Models;

use App\Enums\Language;
use App\Models\Concerns\Archivable;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'contact_person', 'address_line1', 'address_line2', 'postal_code', 'city', 'country',
    'email', 'phone', 'website', 'language', 'notes', 'invoice_ninja_id',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use Archivable, HasFactory;

    protected function casts(): array
    {
        return ['language' => Language::class];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
