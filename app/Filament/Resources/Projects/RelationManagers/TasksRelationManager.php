<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $relatedResource = TaskResource::class;

    // Relation managers are read-only on View pages by default; these are meant to be worked in.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema, withProject: false);
    }

    public function table(Table $table): Table
    {
        return TasksTable::configure($table, withProject: false)
            ->headerActions([
                // No new work on an archived Project or one whose Client is archived.
                CreateAction::make()->hidden(function (): bool {
                    /** @var Project $project */
                    $project = $this->getOwnerRecord();

                    return $project->isArchived() || $project->client->isArchived();
                }),
            ]);
    }
}
