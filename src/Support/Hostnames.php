<?php

namespace LiveNet\StatusPage\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LiveNet\StatusPage\Models\StatusPage;

class Hostnames
{
    /**
     * Public hostnames per server: the optional manual subdomain of a status page entry, plus every
     * subdomain created with the Subdomains plugin (read live, so renames are picked up automatically).
     * Only hostnames under the configured base domain are returned.
     *
     * @return array<int, array<int, string>> server id => hostnames
     */
    public static function all(?int $serverId = null): array
    {
        $domain = strtolower(trim((string) config('statuspage.domain', 'livenet.host'), '.'));
        $map = [];

        $manual = StatusPage::query()->whereNotNull('subdomain');
        if ($serverId !== null) {
            $manual->where('server_id', $serverId);
        }
        foreach ($manual->get(['server_id', 'subdomain']) as $entry) {
            $map[(int) $entry->server_id][] = strtolower($entry->subdomain . '.' . $domain);
        }

        if (self::subdomainsPluginAvailable()) {
            $query = DB::table('subdomains')
                ->join('cloudflare_domains', 'cloudflare_domains.id', '=', 'subdomains.domain_id')
                ->where('subdomains.record_type', '!=', 'SRV'); // SRV rows are helper records, not addresses
            if ($serverId !== null) {
                $query->where('subdomains.server_id', $serverId);
            }

            foreach ($query->get(['subdomains.server_id', 'subdomains.name', 'cloudflare_domains.name as domain', 'cloudflare_domains.prefix']) as $row) {
                $prefix = trim((string) $row->prefix, '.');
                $host = strtolower($row->name . ($prefix !== '' ? '.' . $prefix : '') . '.' . trim((string) $row->domain, '.'));

                if (str_ends_with($host, '.' . $domain)) {
                    $map[(int) $row->server_id][] = $host;
                }
            }
        }

        return array_map(fn (array $hosts) => array_values(array_unique($hosts)), $map);
    }

    /**
     * The Subdomains plugin is optional. Follow it only while it is installed and enabled: its classes
     * are only loaded when enabled, and its tables must exist (a disabled plugin keeps them).
     */
    public static function subdomainsPluginAvailable(): bool
    {
        static $available = null;

        return $available ??= class_exists(\Boy132\Subdomains\Models\Subdomain::class)
            && Schema::hasTable('subdomains')
            && Schema::hasTable('cloudflare_domains');
    }
}
