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
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
            Select::make('duration')
                ->label('Duration')
                ->options(fn (Get $get): array => self::durationOptions((int) $get('duration')))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    if (blank($get('duration'))) {
                        return;
                    }

                    $end = self::combine($get('ended_on') ?: $get('started_on'), $get('ended_time'))
                        ?? Duration::floorToFiveMinutes(CarbonImmutable::now());

                    self::fillTimes($set, $end->subMinutes((int) $get('duration')), $end);
                })
                ->columnSpanFull(),
            DatePicker::make('started_on')->label('Start date')->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::startChanged($get, $set)),
            TimePicker::make('started_time')->label('Start time')->seconds(false)->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::startChanged($get, $set)),
            DatePicker::make('ended_on')->label('End date')->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::endChanged($get, $set)),
            TimePicker::make('ended_time')->label('End time')->seconds(false)->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::endChanged($get, $set))
                ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    $start = self::combine($get('started_on'), $get('started_time'));
                    $end = self::combine($get('ended_on') ?: $get('started_on'), $value);

                    if ($start && $end && $end->lessThanOrEqualTo($start)) {
                        $fail('The end must be after the start.');
                    }
                }),
            Textarea::make('description')->columnSpanFull(),
        ]);
    }

    /** Start edited: floor it to 5 minutes and let the end follow (duration stays). */
    protected static function startChanged(Get $get, Set $set): void
    {
        $start = self::combine($get('started_on'), $get('started_time'));

        if (! $start) {
            return;
        }

        $start = Duration::floorToFiveMinutes($start);
        $set('started_on', $start->toDateString());
        $set('started_time', $start->format('H:i'));

        if (filled($get('duration'))) {
            $end = $start->addMinutes((int) $get('duration'));
            $set('ended_on', $end->toDateString());
            $set('ended_time', $end->format('H:i'));
        }
    }

    /** End edited: floor it, recompute the duration (rounded up to 15 minutes), then the end follows. */
    protected static function endChanged(Get $get, Set $set): void
    {
        $end = self::combine($get('ended_on') ?: $get('started_on'), $get('ended_time'));

        if (! $end) {
            return;
        }

        $end = Duration::floorToFiveMinutes($end);
        $start = self::combine($get('started_on'), $get('started_time'));

        if ($start && $end->greaterThan($start)) {
            $minutes = Duration::roundUp((int) ceil($start->diffInSeconds($end) / 60));
            $set('duration', $minutes);
            $end = $start->addMinutes($minutes);
        }

        $set('ended_on', $end->toDateString());
        $set('ended_time', $end->format('H:i'));
    }

    protected static function fillTimes(Set $set, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $set('started_on', $start->toDateString());
        $set('started_time', $start->format('H:i'));
        $set('ended_on', $end->toDateString());
        $set('ended_time', $end->format('H:i'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (TimeEntry $record): string => filled($record->description) ? $record->description : $record->started_at->format('d.m.Y H:i'))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('Start')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('ended_at')->label('End')->dateTime('d.m.Y H:i'),
                TextColumn::make('duration_minutes')->label('Duration')->state(fn (TimeEntry $record): string => Hours::format($record->duration_minutes)),
                TextColumn::make('description')->limit(80)->placeholder('-'),
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
                        'ended_on' => $record->ended_at->toDateString(),
                        'ended_time' => $record->ended_at->format('H:i'),
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
        $minutes = (int) $data['duration'];
        $description = filled($data['description'] ?? null) ? $data['description'] : null;
        $start = self::combine($data['started_on'] ?? null, $data['started_time'] ?? null);

        if (! $start) {
            // Nothing entered: the entry ends now (floored to 5 minutes, never in the future) and starts a duration earlier.
            $end = self::combine(($data['ended_on'] ?? null) ?: ($data['started_on'] ?? null), $data['ended_time'] ?? null)
                ?? Duration::floorToFiveMinutes(CarbonImmutable::now());
            $start = $end->subMinutes($minutes);
        }

        return TimeEntry::fromStartAndDuration($task, $start, $minutes, $description);
    }

    protected static function combine(mixed $date, mixed $time): ?CarbonImmutable
    {
        if (blank($date) || blank($time)) {
            return null;
        }

        return CarbonImmutable::parse(CarbonImmutable::parse($date)->toDateString().' '.CarbonImmutable::parse($time)->format('H:i'));
    }

    /**
     * @return array<int, string> minutes => HH:MM, in 15-minute steps up to 12:00 (plus the current value if outside)
     */
    protected static function durationOptions(int $current = 0): array
    {
        $minutes = range(Duration::STEP_MINUTES, 720, Duration::STEP_MINUTES);

        if ($current > 0 && ! in_array($current, $minutes, true)) {
            $minutes[] = $current;
            sort($minutes);
        }

        return collect($minutes)
            ->mapWithKeys(fn (int $m) => [$m => sprintf('%02d:%02d', intdiv($m, 60), $m % 60)])
            ->all();
    }
}
