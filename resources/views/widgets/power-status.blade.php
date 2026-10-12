{{-- Inline-Styles statt Tailwind-Klassen: Pelican kompiliert keine Klassen aus Plugin-Views --}}
<x-filament-widgets::widget>
    <div wire:poll.30s style="display: flex; flex-direction: column; gap: 1rem;">
        @foreach ($issues as $issue)
            <x-filament::callout
                :color="$issue['color']"
                :icon="$issue['icon']"
                :heading="$issue['heading']"
                :description="$issue['description']"
            />
        @endforeach

        @if (count($sources))
            <x-filament::section compact>
                <x-slot name="heading">{{ trans('power-metrics::strings.sources') }}</x-slot>

                <ul style="display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none;">
                    @foreach ($sources as $source)
                        <li style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.75rem; padding: 0.5rem 0; {{ $loop->last ? '' : 'border-bottom: 1px solid rgba(128, 128, 128, 0.2);' }}">
                            <span style="font-weight: 600;">{{ $source['name'] }}</span>

                            @switch($source['mode'])
                                @case('direct')
                                    <x-filament::badge color="success" icon="tabler-gauge">{{ trans('power-metrics::strings.mode.direct') }}</x-filament::badge>
                                    @break
                                @case('plug')
                                    <x-filament::badge color="success" icon="tabler-plug-connected">{{ trans('power-metrics::strings.mode.plug') }}</x-filament::badge>
                                    @break
                                @case('estimated')
                                    <x-filament::badge color="warning" icon="tabler-calculator">{{ trans('power-metrics::strings.mode.estimated') }}</x-filament::badge>
                                    @break
                                @case('disabled')
                                    <x-filament::badge color="gray">{{ trans('power-metrics::strings.mode.disabled') }}</x-filament::badge>
                                    @break
                                @default
                                    <x-filament::badge color="danger" icon="tabler-plug-x">{{ trans('power-metrics::strings.mode.offline') }}</x-filament::badge>
                            @endswitch

                            @if ($source['watts'])
                                <span style="font-variant-numeric: tabular-nums;">{{ $source['watts'] }}</span>
                            @endif

                            <span style="font-size: 0.875rem; opacity: 0.7;">{{ $source['chain'] }}</span>

                            @if ($source['error'])
                                <span style="flex-basis: 100%; font-size: 0.875rem; color: var(--danger-600, #dc2626); overflow-wrap: anywhere;">{{ $source['error'] }}</span>
                            @endif

                            @if ($source['hint'])
                                <span style="flex-basis: 100%; font-size: 0.875rem; opacity: 0.7;">{{ $source['hint'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
