<?php

namespace LiveNet\StatusPage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LiveNet\StatusPage\Models\StatusPage;

class FeedController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('statuspage.token');

        // Fail closed: no token configured means the feed is off.
        if ($token === '') {
            return response()->json(['error' => 'feed disabled'], 503);
        }

        if (!hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $entries = StatusPage::query()
            ->where('enabled', true)
            ->with(['server.allocation', 'server.node'])
            ->get();

        $servers = $entries->map(function (StatusPage $entry) {
            $server = $entry->server;

            if (!$server || !$server->allocation) {
                return null;
            }

            // Deliberately no IPs, owners, emails, UUIDs or tokens.
            return [
                'subdomain' => $entry->subdomain,
                'node' => $server->node?->name,
                'port' => (int) $server->allocation->port,
                'query_port' => $entry->query_port,
                'proxy_protocol' => (bool) $entry->proxy_protocol,
                'show' => $entry->resolvedVisibility(),
            ];
        })->filter()->values();

        return response()
            ->json(['servers' => $servers])
            ->header('Cache-Control', 'no-store');
    }
}
