<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Protocol;

use InvalidArgumentException;

/**
 * The one place an `ai_request` frame is shaped.
 *
 * There are two paths that send one — BridgeStream::buildRequestBody() for a
 * long-running process holding the WebSocket, and
 * BridgeWebSocketServer::apiRequest() for the PHP-FPM relay — and for a long
 * time they were two hand-maintained copies with a comment on each asking the
 * next person to keep them in sync. A field added to one and forgotten in the
 * other does not fail: it works under Octane and silently does nothing under
 * PHP-FPM, which is the deployment most apps actually run.
 *
 * So both now normalise their own inputs and call this. The comment asking for
 * vigilance is replaced by a function, and a test asserts the two agree.
 */
final class AiRequestPayload
{
    /**
     * Build the wire payload.
     *
     * Optional fields are OMITTED rather than sent empty. The bridge reads
     * every one of them as "absent means the default", and an explicit empty
     * value is only ever noise on the wire — but more importantly, keeping one
     * convention is what lets the two builders be compared at all.
     *
     * @param  array{
     *     request_id: string,
     *     conversation_id?: string|int|null,
     *     provider?: string|null,
     *     message: string,
     *     system_prompt?: string|null,
     *     options?: array<string, mixed>|null,
     *     cli_session_id?: string|null,
     *     history?: array<int, mixed>|null,
     *     tools?: array<int, mixed>|null,
     *     working_dir?: string|null,
     *     attachments?: array<int, array<string, mixed>>|null,
     * }  $input
     * @return array<string, mixed>
     */
    public static function build(array $input): array
    {
        $payload = [
            'type' => MessageTypes::AI_REQUEST,
            'request_id' => (string) $input['request_id'],
            'conversation_id' => (string) ($input['conversation_id'] ?? ''),
            'provider' => (string) ($input['provider'] ?? ''),
            'message' => (string) $input['message'],
        ];

        if (isset($input['system_prompt'])) {
            $payload['system_prompt'] = $input['system_prompt'];
        }

        $options = array_filter(
            $input['options'] ?? [],
            static fn ($value) => $value !== null,
        );
        if (! empty($options)) {
            $payload['options'] = $options;
        }

        // Always sent, including as null: the server owns the conversation →
        // CLI session mapping, so the bridge never has to guess whether a
        // conversation is new.
        $cliSessionId = $input['cli_session_id'] ?? null;
        $payload['cli_session_id'] = $cliSessionId;

        // History seeds a FRESH session only. A resumed CLI session already
        // holds its own context, so re-sending it there is wasted bytes the
        // bridge discards anyway.
        if ($cliSessionId === null && ! empty($input['history'])) {
            $payload['history'] = $input['history'];
        }

        if (! empty($input['tools'])) {
            $payload['tools'] = $input['tools'];
        }

        $workingDir = $input['working_dir'] ?? null;
        if (is_string($workingDir) && $workingDir !== '') {
            $payload['working_dir'] = $workingDir;
        }

        $attachments = self::normaliseAttachments($input['attachments'] ?? []);
        if (! empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Reduce each attachment to exactly the six fields the protocol defines.
     *
     * A malformed attachment throws rather than being skipped. Dropping one
     * quietly produces a turn where the assistant is simply never told about a
     * file the user watched themselves attach, and nothing anywhere says why.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<int, array<string, mixed>>
     */
    private static function normaliseAttachments(array $attachments): array
    {
        $out = [];

        foreach ($attachments as $index => $attachment) {
            if (! is_array($attachment)) {
                throw new InvalidArgumentException("Attachment #{$index} must be an array.");
            }

            foreach (['id', 'name', 'mime_type', 'size', 'sha256', 'url'] as $key) {
                if (! isset($attachment[$key])) {
                    throw new InvalidArgumentException(
                        "Attachment #{$index} is missing \"{$key}\". Attachments need id, name, "
                        .'mime_type, size, sha256 and url — the bridge verifies size and sha256 '
                        .'after downloading, and refuses a url that is not on this server.'
                    );
                }
            }

            $out[] = [
                'id' => (string) $attachment['id'],
                'name' => (string) $attachment['name'],
                'mime_type' => (string) $attachment['mime_type'],
                'size' => (int) $attachment['size'],
                'sha256' => strtolower((string) $attachment['sha256']),
                'url' => (string) $attachment['url'],
            ];
        }

        return $out;
    }
}
