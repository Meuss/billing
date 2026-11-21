<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $relatedResource = ProjectResource::class;

    // Relation managers are read-only on View pages by default; these are meant to be worked in.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema, withClient: false);
    }

    public function table(Table $table): Table
    {
        return ProjectsTable::configure($table, withClient: false)
            ->headerActions([
                CreateAction::make()->hidden(fn (): bool => $this->getOwnerRecord()->isArchived()),
            ]);
    }
}
