<?php

namespace ChristianHnl\PowerMetrics;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;

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

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "ChristianHnl\\PowerMetrics\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "ChristianHnl\\PowerMetrics\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return config('power-metrics');
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('price_per_kwh')
                ->label(trans('power-metrics::strings.settings.price_per_kwh'))
                ->numeric()
                ->minValue(0)
                ->step(0.0001)
                ->required()
                ->default(fn () => config('power-metrics.price_per_kwh')),
            TextInput::make('currency')
                ->label(trans('power-metrics::strings.settings.currency'))
                ->required()
                ->maxLength(8)
                ->default(fn () => config('power-metrics.currency')),
            TextInput::make('retention_days')
                ->label(trans('power-metrics::strings.settings.retention_days'))
                ->numeric()
                ->integer()
                ->minValue(31)
                ->required()
                ->default(fn () => config('power-metrics.retention_days')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'POWER_METRICS_PRICE_PER_KWH' => $data['price_per_kwh'],
            'POWER_METRICS_CURRENCY' => $data['currency'],
            'POWER_METRICS_RETENTION_DAYS' => $data['retention_days'],
        ]);

        Notification::make()
            ->title(trans('power-metrics::strings.settings.saved'))
            ->success()
            ->send();
    }
}
