<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tetrix\AiBridge\AiBridgeManager;

/**
 * Channel-authorization endpoint for the AI Bridge private Reverb channels.
 *
 * POST /ai-bridge/broadcasting/auth
 *
 * Laravel's stock /broadcasting/auth requires an authenticated user for any
 * private channel — it rejects the request before the channel callback runs.
 * AI Bridge, by design, owns no user concept: access is scoped through the
 * project-supplied resolvers (AiBridge::resolveConversationsUsing / …
 * Connections). This endpoint applies that same resolver-based check and signs
 * the Pusher-protocol auth response itself, so the chat component works in
 * apps with or without Laravel authentication.
 *
 * Only the package's own channels are servable here:
 *   {prefix}.conversation.{id}   — authorised via the conversations resolver
 *   {prefix}.connection.{id}     — authorised via the connections resolver
 */
class BroadcastAuthController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
    ) {}

    public function authenticate(Request $request): JsonResponse
    {
        $channelName = (string) $request->input('channel_name', '');
        $socketId = (string) $request->input('socket_id', '');

        if ($channelName === '' || $socketId === '') {
            return response()->json(['error' => 'bad_request'], 422);
        }

        // pusher-js sends private channels prefixed with "private-"; the
        // signature is computed over the name exactly as sent, but resolver
        // matching works on the bare name.
        $bareName = str_starts_with($channelName, 'private-')
            ? substr($channelName, 8)
            : $channelName;

        if (! $this->authorizes($request, $bareName)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        [$key, $secret] = $this->broadcasterCredentials();
        if ($key === '' || $secret === '') {
            return response()->json(['error' => 'broadcasting_not_configured'], 500);
        }

        // Pusher/Reverb private-channel auth: HMAC-SHA256 over
        // "{socket_id}:{channel_name}", returned as "{app_key}:{signature}".
        $signature = hash_hmac('sha256', $socketId.':'.$channelName, $secret);

        return response()->json(['auth' => $key.':'.$signature]);
    }

    /**
     * Whether the current request may subscribe to the given channel.
     *
     * Delegates to the project-supplied resolvers: a client may listen on a
     * conversation/connection only if that row is visible to it.
     */
    private function authorizes(Request $request, string $bareName): bool
    {
        $prefix = preg_quote(
            (string) config('ai-bridge.persistence.channel_prefix', 'ai-bridge'),
            '/',
        );

        if (preg_match('/^'.$prefix.'\.conversation\.(.+)$/', $bareName, $m) === 1) {
            return $this->manager->conversationsQuery($request)
                ->whereKey($m[1])->exists();
        }

        if (preg_match('/^'.$prefix.'\.connection\.(.+)$/', $bareName, $m) === 1) {
            return $this->manager->connectionsQuery($request)
                ->whereKey($m[1])->exists();
        }

        // Not an AI Bridge channel — this endpoint does not authorize it.
        return false;
    }

    /**
     * Resolve the broadcaster's app key + secret for signing.
     *
     * @return array{0: string, 1: string}  [key, secret]
     */
    private function broadcasterCredentials(): array
    {
        $name = (string) config('ai-bridge.broadcasting.connection', 'reverb');
        $connection = (array) config('broadcasting.connections.'.$name, []);

        return [
            (string) ($connection['key'] ?? ''),
            (string) ($connection['secret'] ?? ''),
        ];
    }
}
