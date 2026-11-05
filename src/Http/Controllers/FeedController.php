<?php

namespace LiveNet\StatusPage\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LiveNet\StatusPage\Models\StatusPage;
use LiveNet\StatusPage\Support\Hostnames;

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

        $hostnames = Hostnames::all();
        $serverIds = array_keys($hostnames);

        $servers = Server::query()->whereIn('id', $serverIds)->with(['allocation', 'node'])->get()->keyBy('id');
        $entries = StatusPage::query()->whereIn('server_id', $serverIds)->get()->keyBy('server_id');

        $result = collect($serverIds)->map(function (int $id) use ($hostnames, $servers, $entries) {
            $server = $servers->get($id);

            if (!$server || !$server->allocation) {
                return null;
            }

            // A server without saved settings uses the defaults and is enabled.
            $entry = $entries->get($id) ?? new StatusPage(['enabled' => true]);

            if (!$entry->enabled) {
                return null;
            }

            $edition = $entry->resolvedEdition();

            // Modern Bedrock (NetherNet) has no ping a status service can send, so the panel reports
            // the server's state instead. retrieveStatus() is cached for 15 seconds.
            $state = null;
            if ($edition === 'bedrock') {
                try {
                    $state = $server->retrieveStatus()->value;
                } catch (\Throwable) {
                    $state = 'missing';
                }
            }

            // Deliberately no IPs, owners, emails, UUIDs, tokens or server names.
            return [
                'hostnames' => $hostnames[$id],
                'node' => $server->node?->name,
                'port' => (int) $server->allocation->port,
                'edition' => $edition,
                'state' => $state,
                'query_port' => $entry->query_port,
                'proxy_protocol' => (bool) $entry->proxy_protocol,
                'show' => $entry->resolvedVisibility(),
            ];
        })->filter()->values();

        return response()
            ->json(['app_name' => (string) config('app.name'), 'servers' => $result])
            ->header('Cache-Control', 'no-store');
    }
}
