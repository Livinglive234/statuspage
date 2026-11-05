<?php

namespace LiveNet\StatusPage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property string $subdomain
 * @property int|null $query_port
 * @property bool|null $show_version
 * @property bool|null $show_players
 * @property bool|null $show_player_names
 * @property bool|null $show_motd
 * @property bool|null $show_plugins
 * @property bool $proxy_protocol
 * @property string $edition
 * @property bool $enabled
 */
class StatusPage extends Model
{
    protected $table = 'statuspage_entries';

    protected $fillable = [
        'server_id',
        'subdomain',
        'query_port',
        'show_version',
        'show_players',
        'show_player_names',
        'show_motd',
        'show_plugins',
        'proxy_protocol',
        'edition',
        'enabled',
    ];

    public const EDITIONS = [
        'java' => 'Java',
        'bedrock' => 'Bedrock (modern)',
        'bedrock_legacy' => 'Bedrock Legacy (<1.21.60 / 26.60)',
    ];

    /** Always one of the known editions; anything else (including null) is treated as Java. */
    public function resolvedEdition(): string
    {
        return array_key_exists((string) $this->edition, self::EDITIONS) ? $this->edition : 'java';
    }

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'query_port' => 'integer',
            'show_version' => 'boolean',
            'show_players' => 'boolean',
            'show_player_names' => 'boolean',
            'show_motd' => 'boolean',
            'show_plugins' => 'boolean',
            'proxy_protocol' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /** Aspects a status page can show, with the config/env key of their default. */
    public const ASPECTS = ['version', 'players', 'player_names', 'motd', 'plugins'];

    /** @return array<string, bool> resolved visibility: the server's own choice, else the plugin default */
    public function resolvedVisibility(): array
    {
        $defaults = config('statuspage.defaults', []);

        return collect(self::ASPECTS)
            ->mapWithKeys(fn (string $aspect) => [
                $aspect => (bool) ($this->{'show_' . $aspect} ?? ($defaults[$aspect] ?? false)),
            ])
            ->all();
    }

    /**
     * Visibility columns are tri-state: true = show, false = hide, null = use the default.
     * Form values ('show', 'hide', 'default') are normalised here so every save path behaves the same.
     */
    public function setAttribute($key, $value)
    {
        if (is_string($key) && str_starts_with($key, 'show_') && in_array(substr($key, 5), self::ASPECTS, true)) {
            $value = match (true) {
                $value === 'show', $value === true, $value === 1, $value === '1' => true,
                $value === 'hide', $value === false, $value === 0, $value === '0' => false,
                default => null,
            };
        }

        return parent::setAttribute($key, $value);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Server::class, 'server_id');
    }
}
