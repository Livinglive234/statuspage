<?php

namespace LiveNet\StatusPage;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Set;

class StatusPagePlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'statuspage';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $resources = plugin_path($this->getId(), "src/Filament/$id/Resources");
        if (is_dir($resources)) {
            $panel->discoverResources($resources, "LiveNet\\StatusPage\\Filament\\$id\\Resources");
        }

        $pages = plugin_path($this->getId(), "src/Filament/$id/Pages");
        if (is_dir($pages)) {
            $panel->discoverPages($pages, "LiveNet\\StatusPage\\Filament\\$id\\Pages");
        }
    }

    public function boot(Panel $panel): void {}

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        return [
            'token' => config('statuspage.token', ''),
            'default_version' => config('statuspage.defaults.version'),
            'default_players' => config('statuspage.defaults.players'),
            'default_player_names' => config('statuspage.defaults.player_names'),
            'default_motd' => config('statuspage.defaults.motd'),
            'default_plugins' => config('statuspage.defaults.plugins'),
        ];
    }

    /** @return \Filament\Schemas\Components\Component[] */
    public function getSettingsForm(): array
    {
        return [
            TextInput::make('token')
                ->label('Feed token')
                ->helperText('The status service on the VPS sends this as a Bearer token. Leave empty to disable the feed.')
                ->minLength(32)
                ->maxLength(128)
                ->regex('/^[A-Za-z0-9]+$/')
                ->suffixAction(
                    Action::make('generate')
                        ->icon('tabler-refresh')
                        ->tooltip('Generate a new token')
                        ->action(fn (Set $set) => $set('token', bin2hex(random_bytes(32))))
                ),
            Section::make('Shown by default')
                ->description('What status pages show unless a server overrides it. Server owners can change their own.')
                ->schema([
                    Toggle::make('default_version')->label('Server version'),
                    Toggle::make('default_players')->label('Player count'),
                    Toggle::make('default_player_names')->label('Player names'),
                    Toggle::make('default_motd')->label('MOTD'),
                    Toggle::make('default_plugins')->label('Plugin list'),
                ]),
        ];
    }

    /** @param array<mixed, mixed> $data */
    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'STATUSPAGE_TOKEN' => (string) ($data['token'] ?? ''),
            'STATUSPAGE_DEFAULT_VERSION' => !empty($data['default_version']) ? 'true' : 'false',
            'STATUSPAGE_DEFAULT_PLAYERS' => !empty($data['default_players']) ? 'true' : 'false',
            'STATUSPAGE_DEFAULT_PLAYER_NAMES' => !empty($data['default_player_names']) ? 'true' : 'false',
            'STATUSPAGE_DEFAULT_MOTD' => !empty($data['default_motd']) ? 'true' : 'false',
            'STATUSPAGE_DEFAULT_PLUGINS' => !empty($data['default_plugins']) ? 'true' : 'false',
        ]);

        Notification::make()
            ->title('Status page settings saved')
            ->success()
            ->send();
    }
}
