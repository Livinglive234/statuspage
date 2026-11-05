<?php

namespace LiveNet\StatusPage\Filament\Admin\Resources\StatusPages;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use LiveNet\StatusPage\Filament\Components\VisibilityFields;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\Pages\CreateStatusPage;
use LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\Pages\EditStatusPage;
use LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\Pages\ListStatusPages;
use LiveNet\StatusPage\Models\StatusPage;
use LiveNet\StatusPage\Support\Hostnames;

class StatusPageResource extends Resource
{
    protected static ?string $model = StatusPage::class;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-activity';

    protected static ?string $navigationLabel = 'Status Pages';

    protected static ?string $modelLabel = 'Status Page';

    protected static ?string $pluralModelLabel = 'Status Pages';

    // Root admins only, regardless of role permissions.
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && method_exists($user, 'isRootAdmin') && $user->isRootAdmin();
    }

    /** @return array<int, string> public addresses of a record's server (looked up once per request) */
    private static function hostnamesFor(StatusPage $record): array
    {
        static $all = null;
        $all ??= Hostnames::all();

        return $all[$record->server_id] ?? [];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('server_id')
                ->label('Server')
                ->relationship('server', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('subdomain')
                ->maxLength(63)
                ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                ->notIn(config('statuspage.reserved', []))
                ->unique(ignoreRecord: true)
                ->suffix('.' . config('statuspage.domain', 'livenet.host'))
                ->helperText('Optional extra address. Servers with a subdomain from the Subdomains plugin get a status page automatically; leave this empty to use only those. Lowercase letters, numbers and hyphens only.'),
            Select::make('edition')
                ->options(StatusPage::EDITIONS)
                ->default('java')
                ->required()
                ->native(false)
                ->helperText('Bedrock (modern, NetherNet) is checked over HTTP on the TCP port. Bedrock Legacy (before 1.21.60) is pinged over UDP. Both show MOTD, version and player count; neither shows player names or plugins.'),
            TextInput::make('query_port')
                ->numeric()
                ->minValue(1)
                ->maxValue(65535)
                ->helperText('Java: Query port. Bedrock: port to check if it differs from the primary allocation. Leave empty to use the primary allocation port.'),
            Toggle::make('proxy_protocol')
                ->label('Server uses PROXY protocol')
                ->helperText('Turn on for servers that require the HAProxy/PROXY protocol header, such as Velocity with haproxy-protocol enabled. Leave off for normal servers.')
                ->default(false),
            Section::make('What the page shows')
                ->description('Defaults come from the plugin settings. The server owner can change these too.')
                ->schema(VisibilityFields::make())
                ->columns(2),
            Toggle::make('enabled')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('server.name')
                    ->label('Server')
                    ->searchable(),
                TextColumn::make('subdomain')
                    ->label('Addresses')
                    ->searchable()
                    ->state(fn (StatusPage $record) => self::hostnamesFor($record) ?? [])
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->url(fn (StatusPage $record) => ($h = (self::hostnamesFor($record)[0] ?? null)) ? 'https://' . $h : null)
                    ->openUrlInNewTab(),
                TextColumn::make('edition')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => StatusPage::EDITIONS[$state] ?? 'Java'),
                IconColumn::make('enabled')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatusPages::route('/'),
            'create' => CreateStatusPage::route('/create'),
            'edit' => EditStatusPage::route('/{record}/edit'),
        ];
    }
}
