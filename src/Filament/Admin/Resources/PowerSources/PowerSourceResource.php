<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Resources\PowerSources;

use App\Models\Node;
use ChristianHnl\PowerMetrics\Filament\Admin\Resources\PowerSources\Pages\ManagePowerSources;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\PowerStats;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PowerSourceResource extends Resource
{
    protected static ?string $model = PowerSource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-plug-connected';

    protected static ?string $slug = 'power-sources';

    protected static ?int $navigationSort = 91;

    public static function getNavigationLabel(): string
    {
        return trans('power-metrics::strings.sources');
    }

    public static function getModelLabel(): string
    {
        return trans('power-metrics::strings.source');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('power-metrics::strings.sources');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(trans('power-metrics::strings.name'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label(trans('power-metrics::strings.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        PowerSource::TYPE_PUSH => 'Push',
                        PowerSource::TYPE_HTTP_JSON => 'HTTP/JSON',
                        default => trans('power-metrics::strings.mode.estimated'),
                    }),
                IconColumn::make('enabled')
                    ->label(trans('power-metrics::strings.enabled'))
                    ->boolean(),
                TextColumn::make('last_watts')
                    ->label(trans('power-metrics::strings.current'))
                    ->formatStateUsing(fn ($state) => PowerStats::watts($state === null ? null : (float) $state))
                    ->placeholder('–'),
                TextColumn::make('mode')
                    ->label(trans('power-metrics::strings.status'))
                    ->badge()
                    ->state(fn (PowerSource $record) => $record->enabled ? $record->mode() : 'disabled')
                    ->formatStateUsing(fn (string $state) => trans("power-metrics::strings.mode.$state"))
                    ->color(fn (string $state) => match ($state) {
                        PowerSource::MODE_MEASURED => 'success',
                        PowerSource::MODE_ESTIMATED => 'warning',
                        PowerSource::MODE_OFFLINE => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state) => match ($state) {
                        PowerSource::MODE_MEASURED => 'tabler-gauge',
                        PowerSource::MODE_ESTIMATED => 'tabler-calculator',
                        default => null,
                    })
                    ->tooltip(fn (PowerSource $record) => $record->last_error),
                TextColumn::make('node.name')
                    ->label('Node')
                    ->placeholder('–')
                    ->toggleable(),
                TextColumn::make('last_sample_at')
                    ->label(trans('power-metrics::strings.last_seen'))
                    ->since()
                    ->placeholder(trans('power-metrics::strings.never'))
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('regenerate_token')
                    ->label(trans('power-metrics::strings.regenerate_token'))
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (PowerSource $record) => $record->type === PowerSource::TYPE_PUSH)
                    ->action(fn (PowerSource $record) => $record->update(['token' => PowerSource::generateToken()])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false),
            ])
            ->emptyStateIcon('tabler-plug-connected')
            ->emptyStateHeading(trans('power-metrics::strings.no_sources'))
            ->emptyStateDescription(trans('power-metrics::strings.no_sources_description'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(trans('power-metrics::strings.name'))
                    ->required()
                    ->maxLength(100),
                Select::make('type')
                    ->label(trans('power-metrics::strings.type'))
                    ->options(fn () => PowerSource::types())
                    ->default(PowerSource::TYPE_PUSH)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live(),
                Toggle::make('enabled')
                    ->label(trans('power-metrics::strings.enabled'))
                    ->default(true)
                    ->inline(false),
                TextInput::make('multiplier')
                    ->label(trans('power-metrics::strings.multiplier'))
                    ->visible(fn ($get) => $get('type') !== PowerSource::TYPE_ESTIMATE)
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('power-metrics::strings.multiplier_hint'))
                    ->numeric()
                    ->default(1)
                    ->minValue(0)
                    ->required(),

                // Push
                TextInput::make('push_url')
                    ->label(trans('power-metrics::strings.push_url'))
                    ->visible(fn ($get) => $get('type') === PowerSource::TYPE_PUSH)
                    ->formatStateUsing(fn () => url('/api/power-metrics/push'))
                    ->dehydrated(false)
                    ->readOnly()
                    ->copyable()
                    ->columnSpanFull(),
                TextInput::make('token')
                    ->label(trans('power-metrics::strings.token'))
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('power-metrics::strings.token_hint'))
                    ->visible(fn ($get, string $operation) => $operation === 'edit' && $get('type') === PowerSource::TYPE_PUSH)
                    ->readOnly()
                    ->dehydrated(false)
                    ->copyable()
                    ->columnSpanFull(),

                // HTTP/JSON
                TextInput::make('url')
                    ->label(trans('power-metrics::strings.url'))
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('power-metrics::strings.url_hint'))
                    ->visible(fn ($get) => $get('type') === PowerSource::TYPE_HTTP_JSON)
                    ->required(fn ($get) => $get('type') === PowerSource::TYPE_HTTP_JSON)
                    ->url()
                    ->maxLength(1000)
                    ->columnSpanFull(),
                TextInput::make('json_path')
                    ->label(trans('power-metrics::strings.json_path'))
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('power-metrics::strings.json_path_hint'))
                    ->visible(fn ($get) => $get('type') === PowerSource::TYPE_HTTP_JSON)
                    ->maxLength(200),
                TextInput::make('auth_header')
                    ->label(trans('power-metrics::strings.auth_header'))
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('power-metrics::strings.auth_header_hint'))
                    ->visible(fn ($get) => $get('type') === PowerSource::TYPE_HTTP_JSON)
                    ->password()
                    ->revealable()
                    ->maxLength(1000),

                // Schätzung (eigener Typ oder Fallback für messende Quellen)
                Section::make(trans('power-metrics::strings.estimation'))
                    ->description(fn ($get) => $get('type') === PowerSource::TYPE_ESTIMATE
                        ? trans('power-metrics::strings.estimation_description')
                        : trans('power-metrics::strings.estimation_fallback_description'))
                    ->icon('tabler-calculator')
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Toggle::make('estimate_fallback')
                            ->label(trans('power-metrics::strings.estimate_fallback'))
                            ->visible(fn ($get) => $get('type') !== PowerSource::TYPE_ESTIMATE)
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),
                        Select::make('node_id')
                            ->label('Node')
                            ->options(fn () => Node::query()->orderBy('name')->pluck('name', 'id'))
                            ->required(fn ($get) => $get('type') === PowerSource::TYPE_ESTIMATE)
                            ->visible(fn ($get) => $get('type') === PowerSource::TYPE_ESTIMATE || $get('estimate_fallback'))
                            ->hintIcon('tabler-question-mark')
                            ->hintIconTooltip(trans('power-metrics::strings.node_hint'))
                            ->columnSpanFull(),
                        TextInput::make('idle_watts')
                            ->label(trans('power-metrics::strings.idle_watts'))
                            ->visible(fn ($get) => $get('type') === PowerSource::TYPE_ESTIMATE || $get('estimate_fallback'))
                            ->numeric()
                            ->minValue(0)
                            ->suffix('W')
                            ->placeholder(fn () => (string) config('power-metrics.default_idle_watts'))
                            ->hintIcon('tabler-question-mark')
                            ->hintIconTooltip(trans('power-metrics::strings.idle_watts_hint')),
                        TextInput::make('max_watts')
                            ->label(trans('power-metrics::strings.max_watts'))
                            ->visible(fn ($get) => $get('type') === PowerSource::TYPE_ESTIMATE || $get('estimate_fallback'))
                            ->numeric()
                            ->minValue(0)
                            ->suffix('W')
                            ->placeholder(fn () => (string) config('power-metrics.default_max_watts'))
                            ->hintIcon('tabler-question-mark')
                            ->hintIconTooltip(trans('power-metrics::strings.max_watts_hint')),
                    ]),
            ])
            ->columns([
                'default' => 1,
                'md' => 2,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePowerSources::route('/'),
        ];
    }
}
