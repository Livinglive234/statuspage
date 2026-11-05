<?php

namespace LiveNet\StatusPage\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use LiveNet\StatusPage\Filament\Components\VisibilityFields;
use LiveNet\StatusPage\Models\StatusPage;
use LiveNet\StatusPage\Support\Hostnames;

class ServerStatusPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-activity';

    protected static ?string $navigationLabel = 'Status Page';

    protected static ?string $title = 'Status Page';

    protected static ?int $navigationSort = 12;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    private static function currentServer(): ?Server
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Server ? $tenant : null;
    }

    /** @return array<int, string> public addresses of the current server */
    private static function hostnames(): array
    {
        $server = self::currentServer();

        return $server ? (Hostnames::all($server->id)[$server->id] ?? []) : [];
    }

    // Settings are created on first save; until then the server uses the panel-wide defaults.
    private static function entryOrNew(): ?StatusPage
    {
        $server = self::currentServer();

        return $server ? StatusPage::firstOrNew(['server_id' => $server->id], ['enabled' => true]) : null;
    }

    private static function isAdmin(): bool
    {
        $user = user();

        return $user && method_exists($user, 'isRootAdmin') && $user->isRootAdmin();
    }

    private static function isOwner(): bool
    {
        $user = user();
        $server = self::currentServer();

        return $user && $server && (int) $server->owner_id === (int) $user->id;
    }

    // Admins always see the page. Owners see it once the server has a public address.
    public static function canAccess(): bool
    {
        return self::isAdmin() || (self::isOwner() && self::hostnames() !== []);
    }

    public function mount(): void
    {
        $entry = self::entryOrNew();

        if ($entry) {
            $this->form->fill($entry->attributesToArray());
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Visibility')
                    ->description('Choose what visitors see on your public status page. Changes save automatically. "Use default" follows the panel-wide setting.')
                    ->schema([
                        ...VisibilityFields::make(autoSave: true),
                        Select::make('edition')
                            ->label('Minecraft edition')
                            ->options(StatusPage::EDITIONS)
                            ->default('java')
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->helperText('Bedrock (modern) pages only show Online/Offline. Bedrock Legacy (before 1.21.60) also shows MOTD, version and players. Bedrock cannot show player names or plugins.')
                            ->live()
                            ->afterStateUpdated(fn ($livewire) => $livewire->save()),
                        Toggle::make('proxy_protocol')
                            ->label('Server is behind a proxy (PROXY protocol)')
                            ->helperText('Turn on if this server sits behind a proxy such as Velocity with haproxy-protocol enabled. Leave off for normal servers. If the page shows Offline while the server is up, try switching this.')
                            ->live()
                            ->afterStateUpdated(fn ($livewire) => $livewire->save()),
                        Toggle::make('enabled')
                            ->label('Status page enabled')
                            ->helperText('Turn off to make the page return "not found".')
                            ->live()
                            ->afterStateUpdated(fn ($livewire) => $livewire->save()),
                    ])
                    ->columns(2),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $hostnames = self::hostnames();

        if ($hostnames === []) {
            return $schema->components([
                Section::make('No status page yet')
                    ->schema([
                        Text::make('This server has no public address yet. Create a subdomain for it, or ask an admin to add one, and its status page appears automatically.'),
                    ]),
            ]);
        }

        return $schema->components([
            Section::make(count($hostnames) > 1 ? 'Your status pages' : 'Your status page')
                ->schema([
                    TextEntry::make('status_urls')
                        ->label(count($hostnames) > 1 ? 'Addresses' : 'Address')
                        ->state(implode(', ', $hostnames))
                        ->copyable(),
                ]),
            EmbeddedSchema::make('form'),
        ]);
    }

    public function save(): void
    {
        $server = self::currentServer();

        abort_unless($server !== null, 404);
        abort_unless(self::isAdmin() || (self::isOwner() && self::hostnames() !== []), 403);

        $data = $this->form->getState();

        // Owners can only change visibility, the proxy and enabled switches, never the subdomain or ports.
        $entry = StatusPage::firstOrNew(['server_id' => $server->id]);
        $entry->fill(collect($data)->only([
            'show_version', 'show_players', 'show_player_names', 'show_motd', 'show_plugins', 'proxy_protocol', 'edition', 'enabled',
        ])->all());
        $entry->save();

        Notification::make()->title('Status page saved')->success()->send();
    }
}
