<?php

namespace ChristianHnl\PowerMetrics;

use App\Contracts\Plugins\HasPluginSettings;
use App\Models\Node;
use App\Traits\EnvironmentWriterTrait;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\SourceSettings;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Throwable;

class PowerMetricsPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'power-metrics';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "ChristianHnl\\PowerMetrics\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            ...config('power-metrics'),
            'hosts' => app(SourceSettings::class)->toFormData(),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('power-metrics::strings.settings.hosts'))
                ->description(trans('power-metrics::strings.settings.hosts_description'))
                ->icon('tabler-server-bolt')
                ->schema([
                    Repeater::make('hosts')
                        ->hiddenLabel()
                        ->schema(self::hostSchema())
                        ->itemLabel(fn (array $state) => $state['name'] ?? null)
                        ->addActionLabel(trans('power-metrics::strings.settings.add_host'))
                        ->collapsible()
                        ->reorderable(false)
                        ->defaultItems(0),
                ]),

            Section::make(trans('power-metrics::strings.settings.general'))
                ->icon('tabler-coin')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextInput::make('price_per_kwh')
                        ->label(trans('power-metrics::strings.settings.price_per_kwh'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->required(),
                    TextInput::make('currency')
                        ->label(trans('power-metrics::strings.settings.currency'))
                        ->required()
                        ->maxLength(8),
                    TextInput::make('retention_days')
                        ->label(trans('power-metrics::strings.settings.retention_days'))
                        ->numeric()
                        ->integer()
                        ->minValue(31)
                        ->required(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private static function hostSchema(): array
    {
        $is = fn (string $field, array|string $values) => fn ($get) => in_array($get($field), (array) $values, true);

        return [
            Hidden::make('id'),
            Hidden::make('has_direct_password'),
            Hidden::make('has_plug_auth'),

            TextInput::make('name')
                ->label(trans('power-metrics::strings.name'))
                ->placeholder('Proxmox')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true),
            Toggle::make('enabled')
                ->label(trans('power-metrics::strings.enabled'))
                ->default(true),

            // ---- 1. Direkte Messung ---------------------------------------------------------------
            Fieldset::make(trans('power-metrics::strings.chain.step_direct'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Select::make('direct_method')
                        ->label(trans('power-metrics::strings.settings.method'))
                        ->options(fn () => PowerSource::directMethods())
                        ->placeholder(trans('power-metrics::strings.settings.none'))
                        ->live()
                        ->columnSpanFull(),

                    // Push-Skript
                    Text::make(trans('power-metrics::strings.settings.push_help'))
                        ->visible($is('direct_method', PowerSource::DIRECT_PUSH))
                        ->columnSpanFull(),
                    TextInput::make('push_url')
                        ->label(trans('power-metrics::strings.push_url'))
                        ->visible($is('direct_method', PowerSource::DIRECT_PUSH))
                        ->formatStateUsing(fn () => url('/api/power-metrics/push'))
                        ->dehydrated(false)
                        ->readOnly()
                        ->copyable()
                        ->columnSpanFull(),
                    TextInput::make('token')
                        ->label(trans('power-metrics::strings.token'))
                        ->visible($is('direct_method', PowerSource::DIRECT_PUSH))
                        ->placeholder(trans('power-metrics::strings.settings.token_after_save'))
                        ->dehydrated(false)
                        ->readOnly()
                        ->copyable()
                        ->columnSpanFull(),
                    Toggle::make('regenerate_token')
                        ->label(trans('power-metrics::strings.regenerate_token'))
                        ->visible(fn ($get) => $get('direct_method') === PowerSource::DIRECT_PUSH && filled($get('token'))),

                    // BMC über Redfish
                    TextInput::make('direct_url')
                        ->label(trans('power-metrics::strings.settings.bmc_address'))
                        ->placeholder('192.168.0.10')
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('power-metrics::strings.settings.bmc_address_hint'))
                        ->visible($is('direct_method', PowerSource::DIRECT_REDFISH))
                        ->required(fn ($get) => $get('direct_method') === PowerSource::DIRECT_REDFISH)
                        ->columnSpanFull(),
                    TextInput::make('direct_username')
                        ->label(trans('power-metrics::strings.settings.username'))
                        ->visible($is('direct_method', PowerSource::DIRECT_REDFISH))
                        ->autocomplete(false),
                    TextInput::make('direct_password')
                        ->label(trans('power-metrics::strings.settings.password'))
                        ->visible($is('direct_method', PowerSource::DIRECT_REDFISH))
                        ->placeholder(fn ($get) => $get('has_direct_password') ? trans('power-metrics::strings.settings.unchanged') : null)
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password'),

                    // RAPL lokal
                    Text::make(trans('power-metrics::strings.settings.rapl_help'))
                        ->visible($is('direct_method', PowerSource::DIRECT_LOCAL_RAPL))
                        ->columnSpanFull(),

                    TextInput::make('direct_offset_watts')
                        ->label(trans('power-metrics::strings.settings.offset_watts'))
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('power-metrics::strings.settings.offset_watts_hint'))
                        ->visible($is('direct_method', [PowerSource::DIRECT_LOCAL_RAPL, PowerSource::DIRECT_PUSH]))
                        ->numeric()
                        ->default(0)
                        ->suffix('W'),
                ]),

            // ---- 2. Steckdose --------------------------------------------------------------------
            Fieldset::make(trans('power-metrics::strings.chain.step_plug'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Select::make('plug_type')
                        ->label(trans('power-metrics::strings.settings.plug_type'))
                        ->options(fn () => PowerSource::plugTypes())
                        ->placeholder(trans('power-metrics::strings.settings.none'))
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('plug_url')
                        ->label(fn ($get) => in_array($get('plug_type'), [PowerSource::PLUG_HOME_ASSISTANT, PowerSource::PLUG_CUSTOM], true)
                            ? 'URL'
                            : trans('power-metrics::strings.settings.plug_address'))
                        ->placeholder(fn ($get) => match ($get('plug_type')) {
                            PowerSource::PLUG_HOME_ASSISTANT => 'http://homeassistant.local:8123/api/states/sensor.server_power',
                            PowerSource::PLUG_CUSTOM => 'http://192.168.0.50/status',
                            default => '192.168.0.50',
                        })
                        ->visible(fn ($get) => filled($get('plug_type')))
                        ->required(fn ($get) => filled($get('plug_type')))
                        ->columnSpanFull(),
                    TextInput::make('plug_json_path')
                        ->label(trans('power-metrics::strings.json_path'))
                        ->placeholder(fn ($get) => $get('plug_type') === PowerSource::PLUG_HOME_ASSISTANT ? 'state' : 'meters.0.power')
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('power-metrics::strings.json_path_hint'))
                        ->visible($is('plug_type', [PowerSource::PLUG_HOME_ASSISTANT, PowerSource::PLUG_CUSTOM])),
                    TextInput::make('multiplier')
                        ->label(trans('power-metrics::strings.multiplier'))
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('power-metrics::strings.multiplier_hint'))
                        ->visible($is('plug_type', PowerSource::PLUG_CUSTOM))
                        ->numeric()
                        ->default(1),
                    TextInput::make('plug_auth')
                        ->label(fn ($get) => $get('plug_type') === PowerSource::PLUG_HOME_ASSISTANT
                            ? trans('power-metrics::strings.settings.ha_token')
                            : trans('power-metrics::strings.auth_header'))
                        ->placeholder(fn ($get) => $get('has_plug_auth') ? trans('power-metrics::strings.settings.unchanged') : null)
                        ->visible($is('plug_type', [PowerSource::PLUG_HOME_ASSISTANT, PowerSource::PLUG_CUSTOM]))
                        ->required(fn ($get) => $get('plug_type') === PowerSource::PLUG_HOME_ASSISTANT && !$get('has_plug_auth'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->columnSpanFull(),
                ]),

            // ---- 3. Schätzung --------------------------------------------------------------------
            Fieldset::make(trans('power-metrics::strings.chain.step_estimate'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Toggle::make('estimate_fallback')
                        ->label(trans('power-metrics::strings.estimate_fallback'))
                        ->helperText(trans('power-metrics::strings.estimation_fallback_description'))
                        ->default(true)
                        ->live()
                        ->columnSpanFull(),
                    Select::make('node_id')
                        ->label('Wings-Node')
                        ->options(fn () => Node::query()->orderBy('name')->pluck('name', 'id'))
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('power-metrics::strings.node_hint'))
                        ->visible(fn ($get) => (bool) $get('estimate_fallback'))
                        ->required(fn ($get) => (bool) $get('estimate_fallback'))
                        ->columnSpanFull(),
                    TextInput::make('idle_watts')
                        ->label(trans('power-metrics::strings.idle_watts'))
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('power-metrics::strings.idle_watts_hint'))
                        ->visible(fn ($get) => (bool) $get('estimate_fallback'))
                        ->placeholder(fn () => (string) config('power-metrics.default_idle_watts'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('W'),
                    TextInput::make('max_watts')
                        ->label(trans('power-metrics::strings.max_watts'))
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('power-metrics::strings.max_watts_hint'))
                        ->visible(fn ($get) => (bool) $get('estimate_fallback'))
                        ->placeholder(fn () => (string) config('power-metrics.default_max_watts'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('W'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        // Pelican verschluckt Fehler beim Speichern – darum hier selbst melden
        try {
            app(SourceSettings::class)->save($data['hosts'] ?? []);

            $this->writeToEnvironment([
                'POWER_METRICS_PRICE_PER_KWH' => $data['price_per_kwh'],
                'POWER_METRICS_CURRENCY' => $data['currency'],
                'POWER_METRICS_RETENTION_DAYS' => $data['retention_days'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(trans('power-metrics::strings.settings.save_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('power-metrics::strings.settings.saved'))
            ->success()
            ->send();
    }
}
