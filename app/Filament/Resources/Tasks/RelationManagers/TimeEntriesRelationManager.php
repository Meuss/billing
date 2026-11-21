<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Domain\Time\Duration;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\Hours;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TimeEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timeEntries';

    protected static ?string $title = 'Time entries';

    // Relation managers are read-only on View pages by default; these are meant to be worked in.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('started_on')->label('Start date')->default(now())->required()->live(),
            TimePicker::make('started_time')->label('Start time')->seconds(false)->required(),
            Toggle::make('use_end_time')->label('Enter end time instead')->live()->columnSpanFull()->dehydrated(false),
            Select::make('duration')
                ->label('Duration')
                ->options(self::durationOptions())
                ->visible(fn (Get $get): bool => ! $get('use_end_time'))
                ->required(fn (Get $get): bool => ! $get('use_end_time')),
            DatePicker::make('ended_on')->label('End date')
                ->helperText('Leave blank to end on the start date.')
                ->visible(fn (Get $get): bool => (bool) $get('use_end_time')),
            TimePicker::make('ended_time')->label('End time')->seconds(false)
                ->visible(fn (Get $get): bool => (bool) $get('use_end_time'))
                ->required(fn (Get $get): bool => (bool) $get('use_end_time'))
                ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    $start = self::combine($get('started_on'), $get('started_time'));
                    $end = self::combine($get('ended_on') ?: $get('started_on'), $value);

                    if ($start && $end && $end->lessThanOrEqualTo($start)) {
                        $fail('The end must be after the start.');
                    }
                }),
            Textarea::make('description')->required()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('Start')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('ended_at')->label('End')->dateTime('d.m.Y H:i'),
                TextColumn::make('duration_minutes')->label('Duration')->state(fn (TimeEntry $record): string => Hours::format($record->duration_minutes)),
                TextColumn::make('description')->limit(80),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data, Action $action): Model => $this->guarded($action, function () use ($data): TimeEntry {
                        /** @var Task $task */
                        $task = $this->getOwnerRecord();
                        $entry = self::build($task, $data);
                        $entry->save();

                        return $entry;
                    })),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, TimeEntry $record): array => $data + [
                        'started_on' => $record->started_at->toDateString(),
                        'started_time' => $record->started_at->format('H:i'),
                        'duration' => $record->duration_minutes,
                    ])
                    ->using(fn (TimeEntry $record, array $data, Action $action): Model => $this->guarded($action, function () use ($record, $data): TimeEntry {
                        $built = self::build($record->task, $data);
                        $record->update([
                            'started_at' => $built->started_at,
                            'duration_minutes' => $built->duration_minutes,
                            'description' => $built->description,
                        ]);

                        return $record;
                    })),
                DeleteAction::make()
                    ->action(fn (TimeEntry $record, Action $action) => $this->guarded($action, function () use ($record, $action): void {
                        $record->delete();
                        $action->success();
                    })),
            ]);
    }

    /** Run a write, turning a refused (billed Task) write into a danger notification instead of a 500. */
    protected function guarded(Action $action, \Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    protected static function build(Task $task, array $data): TimeEntry
    {
        $start = self::combine($data['started_on'], $data['started_time']);

        if (filled($data['ended_time'] ?? null)) {
            return TimeEntry::fromStartAndEnd($task, $start, self::combine(($data['ended_on'] ?? null) ?: $data['started_on'], $data['ended_time']), $data['description']);
        }

        return TimeEntry::fromStartAndDuration($task, $start, (int) $data['duration'], $data['description']);
    }

    protected static function combine(mixed $date, mixed $time): ?CarbonImmutable
    {
        if (blank($date) || blank($time)) {
            return null;
        }

        return CarbonImmutable::parse(CarbonImmutable::parse($date)->toDateString().' '.CarbonImmutable::parse($time)->format('H:i'));
    }

    /** @return array<int, string> minutes => h:mm, in 15-minute steps up to 12:00 */
    protected static function durationOptions(): array
    {
        return collect(range(Duration::STEP_MINUTES, 720, Duration::STEP_MINUTES))
            ->mapWithKeys(fn (int $minutes) => [$minutes => Hours::format($minutes)])
            ->all();
    }
}
