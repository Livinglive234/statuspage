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
                ->helperText('Bedrock servers are pinged over UDP on the primary allocation port. Bedrock does not expose player names or plugins.'),
            TextInput::make('query_port')
                ->numeric()
                ->minValue(1)
                ->maxValue(65535)
                ->helperText('Java: Query port. Bedrock: UDP port to ping if it differs from the primary allocation. Leave empty to use the primary allocation port.'),
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
                    ->searchable()
                    ->formatStateUsing(fn (?string $state) => $state ? $state . '.' . config('statuspage.domain', 'livenet.host') : '—')
                    ->url(fn (StatusPage $record) => $record->subdomain ? 'https://' . $record->subdomain . '.' . config('statuspage.domain', 'livenet.host') : null)
                    ->openUrlInNewTab(),
                TextColumn::make('edition')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'bedrock' ? 'Bedrock' : 'Java'),
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
