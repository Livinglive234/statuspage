<?php

namespace LiveNet\StatusPage\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use Filament\Facades\Filament;
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

    private static function entry(): ?StatusPage
    {
        $server = self::currentServer();

        return $server ? StatusPage::query()->where('server_id', $server->id)->first() : null;
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

    // Admins always see the page. Owners only see it once an admin has given the server a subdomain.
    public static function canAccess(): bool
    {
        return self::isAdmin() || (self::isOwner() && self::entry() !== null);
    }

    public function mount(): void
    {
        $entry = self::entry();

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
        $entry = self::entry();

        if (!$entry) {
            return $schema->components([
                Section::make('No status page yet')
                    ->schema([
                        Text::make('This server has no subdomain. Add it under Status Pages in the admin panel to create its status page.'),
                    ]),
            ]);
        }

        $url = 'https://' . $entry->subdomain . '.' . config('statuspage.domain', 'livenet.host');

        return $schema->components([
            Section::make('Your status page')
                ->schema([
                    TextEntry::make('status_url')
                        ->label('Address')
                        ->state($url)
                        ->url($url, true)
                        ->copyable(),
                ]),
            EmbeddedSchema::make('form'),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::isAdmin() || self::isOwner(), 403);

        $entry = self::entry();
        abort_unless($entry !== null, 404);

        $data = $this->form->getState();

        // Owners can only change visibility and the enabled switch, never the subdomain or ports.
        $entry->update(collect($data)->only([
            'show_version', 'show_players', 'show_player_names', 'show_motd', 'show_plugins', 'enabled',
        ])->all());

        Notification::make()->title('Status page saved')->success()->send();
    }
}
