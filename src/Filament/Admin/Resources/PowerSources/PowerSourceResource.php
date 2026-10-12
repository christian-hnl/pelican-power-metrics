<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Resources\PowerSources;

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
                    ->formatStateUsing(fn (string $state) => $state === PowerSource::TYPE_PUSH ? 'Push' : 'HTTP/JSON'),
                IconColumn::make('enabled')
                    ->label(trans('power-metrics::strings.enabled'))
                    ->boolean(),
                TextColumn::make('last_watts')
                    ->label(trans('power-metrics::strings.current'))
                    ->formatStateUsing(fn ($state) => PowerStats::watts($state === null ? null : (float) $state))
                    ->placeholder('–'),
                TextColumn::make('status')
                    ->label(trans('power-metrics::strings.status'))
                    ->badge()
                    ->state(fn (PowerSource $record) => $record->last_error && !$record->isOnline()
                        ? trans('power-metrics::strings.error')
                        : ($record->isOnline() ? trans('power-metrics::strings.online') : trans('power-metrics::strings.offline')))
                    ->color(fn (PowerSource $record) => $record->isOnline() ? 'success' : ($record->last_error ? 'danger' : 'gray'))
                    ->tooltip(fn (PowerSource $record) => $record->last_error),
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
                    ->live()
                    ->disabledOn('edit'),
                Toggle::make('enabled')
                    ->label(trans('power-metrics::strings.enabled'))
                    ->default(true)
                    ->inline(false),
                TextInput::make('multiplier')
                    ->label(trans('power-metrics::strings.multiplier'))
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
