<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait Archivable
{
    public function initializeArchivable(): void
    {
        $this->mergeCasts(['archived_at' => 'datetime']);
    }

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('archived_at'));
    }

    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('archived_at'));
    }
}
