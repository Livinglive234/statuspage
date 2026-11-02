<?php

return [
    // Bearer token the status service must send. If empty, the feed is disabled (503).
    'token' => env('STATUSPAGE_TOKEN', ''),

    // Base domain shown next to the subdomain field.
    'domain' => env('STATUSPAGE_DOMAIN', 'livenet.host'),

    // What status pages show unless a server overrides it. Edited in the plugin's Settings.
    'defaults' => [
        'version' => (bool) env('STATUSPAGE_DEFAULT_VERSION', true),
        'players' => (bool) env('STATUSPAGE_DEFAULT_PLAYERS', true),
        'player_names' => (bool) env('STATUSPAGE_DEFAULT_PLAYER_NAMES', false),
        'motd' => (bool) env('STATUSPAGE_DEFAULT_MOTD', true),
        'plugins' => (bool) env('STATUSPAGE_DEFAULT_PLUGINS', false),
    ],

    // Subdomains that can never be used for a status page.
    'reserved' => [
        'www', 'panel', 'wing1', 'wings', 'api', 'mail', 'smtp', 'ftp', 'sftp',
        'ssh', 'admin', 'status', 'play', 'mc', 'dev', 'staging', 'phpmyadmin',
        'pma', 'vpn', 'ns1', 'ns2',
    ],
];
