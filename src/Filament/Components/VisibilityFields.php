<?php

namespace LiveNet\StatusPage\Filament\Components;

use Filament\Forms\Components\Select;

class VisibilityFields
{
    private const LABELS = [
        'version' => 'Server version',
        'players' => 'Player count',
        'player_names' => 'Player names',
        'motd' => 'MOTD',
        'plugins' => 'Plugin list',
    ];

    /** @return Select[] */
    public static function make(bool $autoSave = false): array
    {
        $defaults = config('statuspage.defaults', []);

        return collect(self::LABELS)->map(function (string $label, string $aspect) use ($defaults, $autoSave) {
            $default = ($defaults[$aspect] ?? false) ? 'shown' : 'hidden';

            $field = Select::make('show_' . $aspect)
                ->label($label)
                ->options([
                    'default' => "Use default ($default)",
                    'show' => 'Show',
                    'hide' => 'Hide',
                ])
                ->default('default')
                ->selectablePlaceholder(false)
                ->formatStateUsing(fn ($state) => $state === null ? 'default' : ($state ? 'show' : 'hide'))
                ->dehydrateStateUsing(fn ($state) => match ($state) {
                    'show' => true,
                    'hide' => false,
                    default => null,
                });

            // On the owner's page there is no Save button: persist as soon as the value changes.
            if ($autoSave) {
                $field->live()->afterStateUpdated(fn ($livewire) => $livewire->save());
            }

            return $field;
        })->values()->all();
    }
}
