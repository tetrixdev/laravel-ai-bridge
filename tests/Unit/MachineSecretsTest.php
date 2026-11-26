<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Events\MachineSecretChanged;
use Tetrix\AiBridge\Facades\AiBridge;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Secrets\SecretFrames;
use Tetrix\AiBridge\Secrets\SecretRequest;
use Tetrix\AiBridge\Server\BridgeWebSocketServer;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Secrets on the machine
|--------------------------------------------------------------------------
|
| Off unless the machine's operator opted in: only a bridge started with
| --machine-secrets announces `machine_secrets`, and only such a bridge is sent a
| secret_* frame. A value travels in, on secret_put, and nowhere else.
|
*/

const SECRET_VALUE = 'sk-live-Zq81vP0w4nT6hY2c';

/**
 * A serve process with four bridges: key-on (machine_secrets revision 2 and
 * bridge_prompt_max_bytes), key-r3 (revision 3, secret ends), key-r1 (revision 1), key-off
 * (neither: its operator did not opt in).
 */
function secretsRig(float $timeout = 5.0): object
{
    config()->set('ai-bridge.machine_secrets.timeout', $timeout);

    $rig = new stdClass();
    $rig->manager = new BridgeConnectionManager();
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());
    $rig->server = new BridgeWebSocketServer($rig->manager, $rig->handler, app(TokenManager::class));
    $rig->loop = new StreamSelectLoop();
    (new ReflectionProperty(BridgeWebSocketServer::class, 'loop'))->setValue($rig->server, $rig->loop);

    $rig->sent = [];
    $rig->answer = null;
    $rig->manager->setSendCallback(function (mixed $conn, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;
        if ($rig->answer !== null) {
            ($rig->answer)($payload);
        }

        return true;
    });

    $hellos = [
        'on' => ['machine_secrets' => ['revision' => 2], 'bridge_prompt_max_bytes' => 16384, 'reliable_delivery' => true],
        'r3' => ['machine_secrets' => ['revision' => 3], 'reliable_delivery' => true],
        'r1' => ['machine_secrets' => ['revision' => 1]],
        'off' => [],
    ];
    foreach ($hellos as $name => $fields) {
        $rig->manager->addConnection("key-{$name}", "conn-{$name}", "socket-{$name}");
        $rig->handler->handleMessage("conn-{$name}", null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => []] + $fields));
    }
    $rig->sent = [];

    $rig->from = fn (string $connectionId, array $frame) => $rig->handler->handleMessage($connectionId, null, json_encode($frame));

    $rig->logs = [];
    Log::listen(function (MessageLogged $e) use ($rig) {
        $rig->logs[] = $e->message.' '.json_encode($e->context);
    });

    return $rig;
}

/** POST to the serve process's internal API as a connection; every response written. */
function secretsPost(object $rig, string $path, array $body, string $as = 'key-on'): object
{
    $out = new stdClass();
    $out->responses = [];

    $tcp = Mockery::mock(ConnectionInterface::class);
    $tcp->shouldReceive('write')->andReturnUsing(function (string $raw) use ($out) {
        [$head, $json] = explode("\r\n\r\n", $raw, 2);
        preg_match('#^HTTP/1\.1 (\d+)#', $head, $m);
        $out->responses[] = ['status' => (int) $m[1], 'body' => json_decode($json, true), 'raw' => $raw];

        return true;
    });
    $tcp->shouldReceive('end');

    $token = app(TokenManager::class)->generate($as, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);
    $request = new PsrRequest('POST', $path, ['Authorization' => "Bearer {$token}"], json_encode($body));
    (new ReflectionMethod(BridgeWebSocketServer::class, 'handleHttpRequest'))->invoke($rig->server, $tcp, $request);

    return $out;
}

describe('the hello', function () {
    it('records machine_secrets and bridge_prompt_max_bytes, each on its own', function () {
        expect(MessageHandler::bridgeInfoFromHello(['machine_secrets' => ['revision' => 2], 'bridge_prompt_max_bytes' => 16384]))
            ->toMatchArray(['machine_secrets' => ['revision' => 2], 'bridge_prompt_max_bytes' => 16384])
            ->and(MessageHandler::bridgeInfoFromHello(['bridge_prompt_max_bytes' => 16384]))
            ->toMatchArray(['machine_secrets' => null, 'bridge_prompt_max_bytes' => 16384])
            ->and(MessageHandler::bridgeInfoFromHello(['machine_secrets' => true, 'bridge_prompt_max_bytes' => '16384']))
            ->toMatchArray(['machine_secrets' => null, 'bridge_prompt_max_bytes' => null]);
    });
});

describe('a bridge whose operator did not opt in', function () {
    it('is never sent a secret_* frame: every operation answers 409 machine_secrets_unsupported', function (array $body) {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/secrets', $body, 'key-off');

        expect($rig->sent)->toBe([])
            ->and($out->responses[0]['status'])->toBe(409)
            ->and($out->responses[0]['body']['code'])->toBe('machine_secrets_unsupported');
    })->with([
        'list' => [['op' => 'list']],
        'put' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE]],
        'describe' => [['op' => 'describe', 'name' => 'stripe', 'description' => 'Live key']],
        'remove' => [['op' => 'remove', 'name' => 'stripe']],
    ]);
});

describe('POST /api/secrets', function () {
    it('lists: sends secret_list and answers with the rows, reduced to name, description, updated_at and the end', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-on', ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true, 'secrets' => [
            ['name' => 'stripe', 'description' => 'Live key', 'updated_at' => '2026-11-20T10:00:00Z', 'value' => 'leaked?'],
            ['name' => '../etc', 'description' => null, 'updated_at' => null],
            ['name' => 'github', 'description' => 42],
        ]]);

        $out = secretsPost($rig, '/api/secrets', ['op' => 'list']);

        expect($rig->sent)->toHaveCount(1)
            ->and($rig->sent[0]['type'])->toBe(MessageTypes::SECRET_LIST)
            ->and($rig->sent[0]['id'])->toStartWith('sec-')
            ->and($out->responses[0])->toMatchArray(['status' => 200, 'body' => ['ok' => true, 'secrets' => [
                ['name' => 'stripe', 'description' => 'Live key', 'updated_at' => '2026-11-20T10:00:00Z', 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
                ['name' => 'github', 'description' => null, 'updated_at' => null, 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
            ]]]);
    });

    it('puts: the value is in the secret_put frame and nowhere else, not in the answer and not in a log', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-on', ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true, 'replaced' => false]);

        $out = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'description' => 'Live key', 'value' => SECRET_VALUE]);

        expect($rig->sent)->toHaveCount(1)
            ->and($rig->sent[0])->toMatchArray(['type' => MessageTypes::SECRET_PUT, 'name' => 'stripe', 'description' => 'Live key', 'value' => SECRET_VALUE])
            ->and($rig->sent[0])->not->toHaveKey('replace')
            ->and($out->responses[0]['body'])->toBe(['ok' => true, 'replaced' => false])
            ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE)
            ->and(implode("\n", $rig->logs))->not->toContain(SECRET_VALUE);
    });

    it('passes the machine\'s exists on, and sends replace only when asked, to a revision 2 bridge', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-on', ($f['replace'] ?? false) === true
            ? ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true, 'replaced' => true]
            : ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => false, 'code' => 'exists', 'error' => 'A secret named stripe exists.']);

        $refused = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE]);
        $replaced = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null]);

        expect($refused->responses[0]['body'])->toBe(['ok' => false, 'code' => 'exists', 'error' => 'A secret named stripe exists.'])
            ->and($rig->sent[1]['replace'])->toBeTrue()
            ->and($rig->sent[1])->toHaveKey('ends_at')
            ->and($rig->sent[1]['ends_at'])->toBeNull()
            ->and($rig->sent[1])->not->toHaveKey('end_kind')
            ->and($replaced->responses[0]['body'])->toBe(['ok' => true, 'replaced' => true]);
    });

    it('sends a revision 1 bridge nothing at all (409 machine_secrets_outdated): its plain put overwrote silently', function (array $body) {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/secrets', $body, 'key-r1');

        expect($out->responses[0]['status'])->toBe(409)
            ->and($out->responses[0]['body']['code'])->toBe('machine_secrets_outdated')
            ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE)
            ->and($rig->sent)->toBe([]);
    })->with([
        'put' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE]],
        'replace' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null]],
        'list' => [['op' => 'list']],
    ]);

    it('sends describe and remove as the machine expects them', function () {
        $rig = secretsRig();
        secretsPost($rig, '/api/secrets', ['op' => 'describe', 'name' => 'stripe', 'description' => 'Rotated in November']);
        secretsPost($rig, '/api/secrets', ['op' => 'remove', 'name' => 'stripe']);

        expect(array_map(fn ($f) => array_diff_key($f, ['id' => 1]), $rig->sent))->toBe([
            ['type' => MessageTypes::SECRET_DESCRIBE, 'name' => 'stripe', 'description' => 'Rotated in November'],
            ['type' => MessageTypes::SECRET_REMOVE, 'name' => 'stripe'],
        ]);
    });

    it('answers the machine\'s timeout as a result that is unknown', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-on', ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => false, 'code' => 'timeout', 'error' => 'stopped']);

        $out = secretsPost($rig, '/api/secrets', ['op' => 'remove', 'name' => 'stripe']);

        expect($out->responses[0]['body']['code'])->toBe('timeout')
            ->and($out->responses[0]['body']['error'])->toContain('result is unknown')
            ->and($out->responses[0]['body']['error'])->toContain('list the secrets again');
    });

    it('passes the store\'s store_unavailable on', function () {
        expect(SecretFrames::answerOf(['ok' => false, 'code' => 'store_unavailable', 'error' => 'The secret folder is a symlink.']))
            ->toBe(['ok' => false, 'code' => 'store_unavailable', 'error' => 'The secret folder is a symlink.']);
    });

    it('turns an unknown code into failed', function () {
        expect(SecretFrames::answerOf(['ok' => false, 'code' => 'kaboom', 'error' => 'no']))->toBe(['ok' => false, 'code' => 'failed', 'error' => 'no']);
    });

    it('answers 504 timeout when the machine never answers; a write says its result is unknown', function () {
        $rig = secretsRig(0.01);

        $put = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE]);
        $list = secretsPost($rig, '/api/secrets', ['op' => 'list']);
        $rig->loop->run();

        expect($put->responses[0]['status'])->toBe(504)
            ->and($put->responses[0]['body'])->toMatchArray(['code' => 'timeout', 'result_unknown' => true])
            ->and($list->responses[0]['body']['code'])->toBe('timeout')
            ->and($list->responses[0]['body'])->not->toHaveKey('result_unknown')
            ->and($rig->manager->hasPendingExchange(SecretFrames::exchangeKey('key-on', $rig->sent[0]['id'])))->toBeFalse();
    });

    it('says a write is unknown when the bridge drops after it was sent', function () {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/secrets', ['op' => 'remove', 'name' => 'stripe']);
        $rig->manager->removeConnection('key-on', 'websocket_closed');

        expect($out->responses[0]['status'])->toBe(404)
            ->and($out->responses[0]['body'])->toMatchArray(['code' => 'not_connected', 'result_unknown' => true]);
    });

    it('holds a write through a short drop and sends it to a bridge that comes back on revision 2', function () {
        $rig = secretsRig();
        $rig->manager->removeConnection('key-on', 'websocket_closed');

        $out = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE]);
        expect($rig->sent)->toBe([])->and($out->responses)->toBe([]);

        $rig->manager->addConnection('key-on', 'conn-on2', 'socket-on2');
        $rig->handler->handleMessage('conn-on2', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => [], 'machine_secrets' => ['revision' => 2], 'reliable_delivery' => true]));
        $rig->manager->reattachRunningTurns('key-on', []);
        $rig->manager->flushHeldFrames('key-on');

        expect($rig->sent)->toHaveCount(1)
            ->and($rig->sent[0]['type'])->toBe(MessageTypes::SECRET_PUT);
    });

    it('does not send a held write to an older bridge that took the machine\'s place: 409, nothing sent', function (array $comeback, string $code) {
        $rig = secretsRig();
        $rig->manager->removeConnection('key-on', 'websocket_closed');

        $out = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null]);

        $rig->manager->addConnection('key-on', 'conn-on2', 'socket-on2');
        $rig->handler->handleMessage('conn-on2', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => [], 'reliable_delivery' => true] + $comeback));
        $rig->manager->reattachRunningTurns('key-on', []);
        $rig->manager->flushHeldFrames('key-on');

        expect($rig->sent)->toBe([])
            ->and($out->responses)->toHaveCount(1)
            ->and($out->responses[0]['status'])->toBe(409)
            ->and($out->responses[0]['body']['code'])->toBe($code)
            ->and($out->responses[0]['body'])->not->toHaveKey('result_unknown')
            ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE);
    })->with([
        'revision 1' => [['machine_secrets' => ['revision' => 1]], 'machine_secrets_outdated'],
        'no revision' => [['machine_secrets' => []], 'machine_secrets_outdated'],
        'secrets off' => [[], 'machine_secrets_unsupported'],
    ]);

    it('does not let one bridge answer another\'s request', function () {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/secrets', ['op' => 'list']);
        ($rig->from)('conn-r1', ['type' => MessageTypes::SECRET_RESULT, 'id' => $rig->sent[0]['id'], 'ok' => true, 'secrets' => []]);

        expect($out->responses)->toBe([]);
    });

    it('refuses a malformed request with 400 without sending anything', function (array $body, string $code) {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/secrets', $body);

        expect($rig->sent)->toBe([])
            ->and($out->responses[0]['status'])->toBe(400)
            ->and($out->responses[0]['body']['code'])->toBe($code)
            ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE);
    })->with([
        'unknown op' => [['op' => 'get', 'name' => 'stripe'], 'invalid_request'],
        'trailing newline' => [['op' => 'remove', 'name' => "stripe\n"], 'invalid_name'],
        'dot dot' => [['op' => 'remove', 'name' => 'a..b'], 'invalid_name'],
        'path' => [['op' => 'remove', 'name' => 'a/b'], 'invalid_name'],
        'description with a NUL' => [['op' => 'describe', 'name' => 'stripe', 'description' => "a\0b"], 'invalid_request'],
        'the index file' => [['op' => 'remove', 'name' => 'index.json'], 'invalid_name'],
        'the index file, other case' => [['op' => 'remove', 'name' => 'INDEX.json'], 'invalid_name'],
        'escape sequence in a description' => [['op' => 'describe', 'name' => 'stripe', 'description' => "x\e[2Jy"], 'invalid_request'],
        'no value' => [['op' => 'put', 'name' => 'stripe'], 'invalid_request'],
        'replace not a boolean' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => 'yes'], 'invalid_request'],
    ]);
});

describe('SecretRequest', function () {
    it('takes names by the bridge\'s rule, anchored at the very end', function () {
        expect(SecretRequest::nameRefusal('stripe.live_key-2'))->toBeNull()
            ->and(SecretRequest::nameRefusal('9lives'))->toBeNull()
            ->and(SecretRequest::nameRefusal(str_repeat('a', 64)))->toBeNull()
            ->and(SecretRequest::nameRefusal(str_repeat('a', 65)))->not->toBeNull()
            ->and(SecretRequest::nameRefusal("stripe\n"))->not->toBeNull()
            ->and(SecretRequest::nameRefusal('.hidden'))->not->toBeNull()
            ->and(SecretRequest::nameRefusal('-x'))->not->toBeNull()
            ->and(SecretRequest::nameRefusal(''))->not->toBeNull();
    });

    it('counts a description in characters, not bytes', function () {
        expect(SecretRequest::descriptionRefusal(str_repeat('é', 1000)))->toBeNull()
            ->and(SecretRequest::descriptionRefusal(str_repeat('é', 1001)))->not->toBeNull()
            ->and(SecretRequest::descriptionRefusal("a\0b"))->not->toBeNull()
            ->and(SecretRequest::descriptionRefusal("\xC3\x28"))->not->toBeNull()
            ->and(SecretRequest::descriptionRefusal('-n'))->toBeNull()
            ->and(SecretRequest::descriptionRefusal("two\tparts\nand a line"))->toBeNull()
            ->and(SecretRequest::descriptionRefusal("bell\x07"))->not->toBeNull()
            ->and(SecretRequest::descriptionRefusal("c1\u{9B}"))->not->toBeNull();
    });

    it('takes a UTF-8 value of 1 byte to 64 KiB, and never quotes it when refusing', function () {
        expect(SecretRequest::valueRefusal('x'))->toBeNull()
            ->and(SecretRequest::valueRefusal(str_repeat('x', 65536)))->toBeNull()
            ->and(SecretRequest::valueRefusal(str_repeat('x', 65537)))->not->toBeNull()
            ->and(SecretRequest::valueRefusal(''))->not->toBeNull()
            ->and(SecretRequest::valueRefusal("\xFF".SECRET_VALUE))->not->toContain(SECRET_VALUE);
    });
});

describe('AiBridge::secrets()', function () {
    beforeEach(function () {
        $this->connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-on', 'name' => 'laptop']);
    });

    it('is supported only while the bridge is connected and announced revision 2 or newer', function (array $status, ?array $lastBridge, bool $supported) {
        Http::fake(['*/api/status' => Http::response($status)]);
        $this->connection->forceFill(['last_bridge' => $lastBridge])->save();

        expect(AiBridge::secrets()->supported($this->connection))->toBe($supported);
    })->with([
        'connected, revision 2' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => 2]]], null, true],
        'connected, newer revision' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => 3]]], null, true],
        'connected, restarted without --machine-secrets' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => null]], ['machine_secrets' => ['revision' => 2]], false],
        'connected, revision 1' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => 1]]], null, false],
        'connected, no revision' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => null]]], null, false],
        'off, remembered from the last hello' => [['connected' => false], ['machine_secrets' => ['revision' => 2]], false],
    ]);

    it('is not supported when the serve process cannot be reached, whatever was remembered', function () {
        Http::fake(['*/api/status' => Http::response('', 500)]);
        $this->connection->forceFill(['last_bridge' => ['machine_secrets' => ['revision' => 2]]])->save();

        expect(AiBridge::secrets()->supported($this->connection))->toBeFalse();
    });

    it('fires MachineSecretChanged and logs a line for every change, never the value or the description', function () {
        Http::fake(['*/api/secrets' => Http::sequence()
            ->push(['ok' => true, 'replaced' => true])
            ->push(['ok' => false, 'code' => 'not_found', 'error' => 'No secret named stripe.'])
            ->push(['ok' => false, 'code' => 'timeout', 'error' => 'The machine did not answer in time.'], 504)
            ->push(['ok' => true, 'secrets' => []])]);
        $events = [];
        Event::listen(MachineSecretChanged::class, function (MachineSecretChanged $e) use (&$events) {
            $events[] = [$e->operation, $e->name, $e->outcome, $e->resultUnknown, $e->replaced, $e->connectionId, $e->userId];
        });
        $logs = [];
        Log::listen(function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });

        AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, 'Private description', replace: true, noEnd: true);
        AiBridge::secrets()->remove($this->connection, 'stripe');
        AiBridge::secrets()->describe($this->connection, 'stripe', 'Private description');
        AiBridge::secrets()->list($this->connection);
        AiBridge::secrets()->remove($this->connection, 'a/b');

        $id = (int) $this->connection->id;
        expect($events)->toBe([
            ['put', 'stripe', 'ok', false, true, $id, null],
            ['remove', 'stripe', 'not_found', false, false, $id, null],
            ['describe', 'stripe', 'timeout', true, false, $id, null],
            ['remove', 'a/b', 'invalid_name', false, false, $id, null],
        ])
            ->and(array_values(array_filter($logs, fn ($l) => str_starts_with($l, 'AI Bridge: machine secret '))))->toHaveCount(4)
            ->and($logs[0])->toContain('"operation":"put"')->toContain('"name":"stripe"')->toContain('"duration_ms":')
            ->and(implode("\n", $logs))->not->toContain(SECRET_VALUE)
            ->and(implode("\n", $logs))->not->toContain('Private description');
    });

    it('still answers when a MachineSecretChanged listener throws', function () {
        Http::fake(['*/api/secrets' => Http::response(['ok' => true])]);
        Event::listen(MachineSecretChanged::class, fn () => throw new RuntimeException('listener broke'));

        expect(AiBridge::secrets()->remove($this->connection, 'stripe'))->toBe(['ok' => true]);
    });

    it('posts the operation to /api/secrets as the connection and returns the answer', function () {
        Http::fake(['*/api/secrets' => Http::response(['ok' => true, 'replaced' => true])]);

        $answer = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, 'Live key', replace: true, noEnd: true);

        expect($answer)->toBe(['ok' => true, 'replaced' => true]);
        Http::assertSent(function (HttpRequest $r) {
            return str_ends_with($r->url(), '/api/secrets')
                && $r->data() === ['op' => 'put', 'name' => 'stripe', 'description' => 'Live key', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null]
                && app(TokenManager::class)->validate(substr($r->header('Authorization')[0], 7), TokenManager::INTERNAL_RELAY_SCOPE)->sub === 'key-on';
        });
    });

    it('lists, and passes machine_secrets_unsupported on', function () {
        Http::fake(['*/api/secrets' => Http::sequence()
            ->push(['ok' => true, 'secrets' => [['name' => 'stripe', 'description' => null, 'updated_at' => null]]])
            ->push(['ok' => false, 'code' => 'machine_secrets_unsupported', 'error' => 'This bridge does not announce machine_secrets; it needs updating.'], 409)]);

        expect(AiBridge::secrets()->list($this->connection))->toBe(['ok' => true, 'secrets' => [['name' => 'stripe', 'description' => null, 'updated_at' => null]], 'ended' => []])
            ->and(AiBridge::secrets()->list($this->connection)['code'])->toBe('machine_secrets_unsupported');
    });

    it('refuses a bad request without reaching the serve process', function () {
        Http::fake();

        expect(AiBridge::secrets()->remove($this->connection, "stripe\n")['code'])->toBe('invalid_name')
            ->and(AiBridge::secrets()->describe($this->connection, 'stripe', "a\0b")['code'])->toBe('invalid_request')
            ->and(AiBridge::secrets()->put($this->connection, 'stripe', "\xFF\xFE")['code'])->toBe('invalid_request');
        Http::assertNothingSent();
    });

    it('marks a write whose answer never came back as unknown, and never repeats the value', function () {
        Http::fake(['*/api/secrets' => fn () => throw new ConnectionException('cURL error 28: timed out after '.SECRET_VALUE)]);
        $logs = [];
        Log::listen(function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });

        $put = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE);
        $list = AiBridge::secrets()->list($this->connection);

        expect($put)->toMatchArray(['ok' => false, 'code' => 'unreachable', 'result_unknown' => true])
            ->and(json_encode($put))->not->toContain(SECRET_VALUE)
            ->and(implode("\n", $logs))->not->toContain(SECRET_VALUE)
            ->and($list)->not->toHaveKey('result_unknown');
    });

    it('marks a timed-out write as unknown and scrubs the value from an error', function () {
        Http::fake(['*/api/secrets' => Http::sequence()
            ->push(['ok' => false, 'code' => 'timeout', 'error' => 'The machine did not answer in time.'], 504)
            ->push(['ok' => false, 'code' => 'failed', 'error' => 'could not store '.SECRET_VALUE])]);

        expect(AiBridge::secrets()->remove($this->connection, 'stripe'))->toMatchArray(['code' => 'timeout', 'result_unknown' => true])
            ->and(AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE)['error'])->toBe('could not store [value]');
    });

    it('marks a write the machine answered timeout (a 200 secret_result) as unknown', function () {
        Http::fake(['*/api/secrets' => Http::response(['ok' => false, 'code' => 'timeout', 'error' => 'The secret store did not finish within 15 s.'])]);

        expect(AiBridge::secrets()->describe($this->connection, 'stripe', 'x'))->toMatchArray(['ok' => false, 'code' => 'timeout', 'result_unknown' => true])
            ->and(AiBridge::secrets()->list($this->connection))->not->toHaveKey('result_unknown');
    });
});

describe('bridge_prompt_max_bytes', function () {
    it('lets 16 KB through only to a bridge that announces it, and 8 KB to any other', function () {
        expect(AiRequestPayload::bridgePromptCap(['bridge_prompt_max_bytes' => 16384]))->toBe(16384)
            ->and(AiRequestPayload::bridgePromptCap(['bridge_prompt_max_bytes' => 65536]))->toBe(16384)
            ->and(AiRequestPayload::bridgePromptCap(['bridge_prompt_max_bytes' => 12000]))->toBe(8192)
            ->and(AiRequestPayload::bridgePromptCap(['machine_secrets' => ['revision' => 2]]))->toBe(8192)
            ->and(AiRequestPayload::bridgePromptCap([]))->toBe(8192);
    });

    it('refuses a turn whose bridge_prompt is over its bridge\'s cap before sending it', function () {
        $rig = secretsRig();
        $body = ['message' => 'hi', 'conversation_id' => 'c-1', 'bridge_prompt' => ['mode' => 'append', 'text' => str_repeat('a', 9000)]];

        $old = secretsPost($rig, '/api/request', $body, 'key-r1');
        $new = secretsPost($rig, '/api/request', $body, 'key-on');

        expect($old->responses[0]['status'])->toBe(400)
            ->and($old->responses[0]['body']['error'])->toBe(AiRequestPayload::BRIDGE_PROMPT_TOO_LARGE)
            ->and($new->responses[0]['status'])->toBe(200)
            ->and(array_column($rig->sent, 'type'))->toBe([MessageTypes::AI_REQUEST]);
    });

    it('still sends 8 KB to a bridge that does not announce it', function () {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/request', ['message' => 'hi', 'conversation_id' => 'c-1', 'bridge_prompt' => ['mode' => 'append', 'text' => str_repeat('a', 8192)]], 'key-off');

        expect($out->responses[0]['status'])->toBe(200);
    });
});

/*
|--------------------------------------------------------------------------
| Secrets with an end (machine_secrets revision 3)
|--------------------------------------------------------------------------
|
| A secret may end: `expire` keeps the entry without its value, `delete` removes it. Only a
| bridge at revision 3 is sent an end; an older one would keep the secret forever.
|
*/

/** An end `$seconds` from now, as it travels. */
function secretEnd(int $seconds = 3600): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
}

describe('POST /api/secrets with an end', function () {
    it('puts with an end to a revision 3 bridge: ends_at and end_kind in the frame, the value nowhere else', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-r3', ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true, 'replaced' => false]);
        $end = secretEnd();

        $out = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'deploy-token', 'value' => SECRET_VALUE, 'ends_at' => $end, 'end_kind' => 'delete'], 'key-r3');

        expect($rig->sent)->toHaveCount(1)
            ->and(array_diff_key($rig->sent[0], ['id' => 1]))->toBe(['type' => MessageTypes::SECRET_PUT, 'name' => 'deploy-token', 'value' => SECRET_VALUE, 'ends_at' => $end, 'end_kind' => 'delete'])
            ->and($out->responses[0]['body'])->toBe(['ok' => true, 'replaced' => false])
            ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE)
            ->and(implode("\n", $rig->logs))->not->toContain(SECRET_VALUE);
    });

    it('sends set_end as the machine expects it, and a null end alone', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-r3', ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true]);
        $end = secretEnd();

        $set = secretsPost($rig, '/api/secrets', ['op' => 'set_end', 'name' => 'api-key', 'ends_at' => $end, 'end_kind' => 'expire'], 'key-r3');
        $cleared = secretsPost($rig, '/api/secrets', ['op' => 'set_end', 'name' => 'api-key', 'ends_at' => null], 'key-r3');

        expect(array_map(fn ($f) => array_diff_key($f, ['id' => 1]), $rig->sent))->toBe([
            ['type' => MessageTypes::SECRET_SET_END, 'name' => 'api-key', 'ends_at' => $end, 'end_kind' => 'expire'],
            ['type' => MessageTypes::SECRET_SET_END, 'name' => 'api-key', 'ends_at' => null],
        ])
            ->and($set->responses[0]['body'])->toBe(['ok' => true])
            ->and($cleared->responses[0]['body'])->toBe(['ok' => true]);
    });

    it('sends a bridge below revision 3 nothing for an end (409 ends_unsupported)', function (array $body) {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/secrets', $body, 'key-on');

        expect($rig->sent)->toBe([])
            ->and($out->responses[0]['status'])->toBe(409)
            ->and($out->responses[0]['body']['code'])->toBe('ends_unsupported')
            ->and($out->responses[0]['body'])->not->toHaveKey('result_unknown')
            ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE)
            ->and(implode("\n", $rig->logs))->not->toContain(SECRET_VALUE);
    })->with([
        'put with an end' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'ends_at' => secretEnd(), 'end_kind' => 'expire']],
        'replace with an end' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => secretEnd(), 'end_kind' => 'delete']],
        'set_end' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => secretEnd(), 'end_kind' => 'expire']],
        'set_end to none' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => null]],
    ]);

    it('still refuses a revision 1 bridge as outdated first, and a bridge without secrets as unsupported', function () {
        $rig = secretsRig();
        $body = ['op' => 'set_end', 'name' => 'stripe', 'ends_at' => secretEnd(), 'end_kind' => 'expire'];

        expect(secretsPost($rig, '/api/secrets', $body, 'key-r1')->responses[0]['body']['code'])->toBe('machine_secrets_outdated')
            ->and(secretsPost($rig, '/api/secrets', $body, 'key-off')->responses[0]['body']['code'])->toBe('machine_secrets_unsupported')
            ->and($rig->sent)->toBe([]);
    });

    it('holds a put with an end through a short drop and sends it only to a bridge that comes back on revision 3', function (array $comeback, ?string $code) {
        $rig = secretsRig();
        $rig->manager->removeConnection('key-r3', 'websocket_closed');

        $out = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'ends_at' => secretEnd(), 'end_kind' => 'expire'], 'key-r3');
        expect($rig->sent)->toBe([]);

        $rig->manager->addConnection('key-r3', 'conn-r3b', 'socket-r3b');
        $rig->handler->handleMessage('conn-r3b', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => [], 'reliable_delivery' => true] + $comeback));
        $rig->manager->reattachRunningTurns('key-r3', []);
        $rig->manager->flushHeldFrames('key-r3');

        if ($code === null) {
            expect($rig->sent)->toHaveCount(1)->and($rig->sent[0]['ends_at'])->toBeString();
        } else {
            expect($rig->sent)->toBe([])
                ->and($out->responses[0]['status'])->toBe(409)
                ->and($out->responses[0]['body']['code'])->toBe($code)
                ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE);
        }
    })->with([
        'revision 3' => [['machine_secrets' => ['revision' => 3]], null],
        'revision 2' => [['machine_secrets' => ['revision' => 2]], 'ends_unsupported'],
        'revision 1' => [['machine_secrets' => ['revision' => 1]], 'machine_secrets_outdated'],
    ]);

    it('says a set_end is unknown when the machine never answers, or the bridge drops after it was sent', function () {
        $rig = secretsRig(0.01);
        $timedOut = secretsPost($rig, '/api/secrets', ['op' => 'set_end', 'name' => 'stripe', 'ends_at' => null], 'key-r3');
        $rig->loop->run();

        $rig2 = secretsRig();
        $dropped = secretsPost($rig2, '/api/secrets', ['op' => 'set_end', 'name' => 'stripe', 'ends_at' => secretEnd(), 'end_kind' => 'delete'], 'key-r3');
        $rig2->manager->removeConnection('key-r3', 'websocket_closed');

        expect($timedOut->responses[0]['status'])->toBe(504)
            ->and($timedOut->responses[0]['body'])->toMatchArray(['code' => 'timeout', 'result_unknown' => true])
            ->and($dropped->responses[0]['status'])->toBe(404)
            ->and($dropped->responses[0]['body'])->toMatchArray(['code' => 'not_connected', 'result_unknown' => true]);
    });

    it('lists the end of each secret and the ends the machine handled, reduced to what PROTOCOL.md documents', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-r3', ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true, 'secrets' => [
            ['name' => 'ci-token', 'description' => 'CI', 'updated_at' => '2026-11-20T10:00:00Z', 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
            ['name' => 'api-key', 'description' => null, 'updated_at' => '2026-11-20T10:00:00Z', 'ends_at' => '2026-12-01T15:00:00Z', 'end_kind' => 'expire', 'expired_at' => '2026-12-01T15:00:00Z', 'value' => 'leaked?'],
            ['name' => 'half', 'ends_at' => '2026-12-01T15:00:00Z', 'end_kind' => 'vanish'],
            ['name' => 'garbled', 'ends_at' => ['next friday'], 'end_kind' => 'expire', 'expired_at' => 42],
            ['name' => 'loose', 'ends_at' => '2026-12-01 15:00:00', 'end_kind' => 'expire', 'expired_at' => 'yesterday'],
            ['name' => 'unreal', 'ends_at' => '2026-02-30T15:00:00Z', 'end_kind' => 'delete', 'expired_at' => '2026-12-01T15:00:00.000Z'],
        ], 'ended' => [
            ['name' => 'deploy-token', 'kind' => 'delete', 'ended_at' => '2026-11-24T17:00:00Z', 'value' => 'leaked?'],
            ['name' => 'old', 'kind' => 'shred', 'ended_at' => '2026-11-24T17:00:00Z'],
            ['name' => '../etc', 'kind' => 'expire', 'ended_at' => '2026-11-24T17:00:00Z'],
            ['name' => 'late', 'kind' => 'expire', 'ended_at' => 'Tue, 24 Nov 2026 17:00:00 GMT'],
            'garbage',
        ]]);

        $out = secretsPost($rig, '/api/secrets', ['op' => 'list'], 'key-r3');

        expect($out->responses[0]['body'])->toBe(['ok' => true, 'secrets' => [
            ['name' => 'ci-token', 'description' => 'CI', 'updated_at' => '2026-11-20T10:00:00Z', 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
            ['name' => 'api-key', 'description' => null, 'updated_at' => '2026-11-20T10:00:00Z', 'ends_at' => '2026-12-01T15:00:00Z', 'end_kind' => 'expire', 'expired_at' => '2026-12-01T15:00:00Z'],
            ['name' => 'half', 'description' => null, 'updated_at' => null, 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
            ['name' => 'garbled', 'description' => null, 'updated_at' => null, 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
            ['name' => 'loose', 'description' => null, 'updated_at' => null, 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
            ['name' => 'unreal', 'description' => null, 'updated_at' => null, 'ends_at' => null, 'end_kind' => null, 'expired_at' => null],
        ], 'ended' => [
            ['name' => 'deploy-token', 'kind' => 'delete', 'ended_at' => '2026-11-24T17:00:00Z'],
            ['name' => 'late', 'kind' => 'expire', 'ended_at' => null],
        ]])
            ->and($out->responses[0]['raw'])->not->toContain('leaked?');
    });

    it('passes the machine\'s ended and invalid_end on', function () {
        expect(SecretFrames::answerOf(['ok' => false, 'code' => 'ended', 'error' => 'The secret api-key expired.']))
            ->toBe(['ok' => false, 'code' => 'ended', 'error' => 'The secret api-key expired.'])
            ->and(SecretFrames::answerOf(['ok' => false, 'code' => 'invalid_end', 'error' => 'In the past.'])['code'])->toBe('invalid_end')
            ->and(SecretFrames::answerOf(['ok' => false, 'code' => 'end_required', 'error' => 'State the end.'])['code'])->toBe('end_required')
            ->and(SecretFrames::answerOf(['ok' => false, 'code' => 'ends_unsupported', 'error' => 'x'])['code'])->toBe('failed');
    });

    it('refuses a malformed end with 400 without sending anything', function (array $body, string $code) {
        $rig = secretsRig();

        $out = secretsPost($rig, '/api/secrets', $body, 'key-r3');

        expect($rig->sent)->toBe([])
            ->and($out->responses[0]['status'])->toBe(400)
            ->and($out->responses[0]['body']['code'])->toBe($code)
            ->and($out->responses[0]['raw'])->not->toContain(SECRET_VALUE);
    })->with([
        'in the past' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'ends_at' => secretEnd(-60), 'end_kind' => 'expire'], 'invalid_end'],
        'more than 10 years ahead' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => secretEnd(11 * 366 * 86400), 'end_kind' => 'expire'], 'invalid_end'],
        'not UTC' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => '2030-01-01T10:00:00+01:00', 'end_kind' => 'expire'], 'invalid_end'],
        'fractional seconds' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => '2030-01-01T10:00:00.5Z', 'end_kind' => 'expire'], 'invalid_end'],
        'not a real day' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => '2030-02-30T10:00:00Z', 'end_kind' => 'expire'], 'invalid_end'],
        'an end without a kind' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'ends_at' => secretEnd()], 'invalid_end'],
        'a kind without an end' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'end_kind' => 'delete'], 'invalid_end'],
        'an unknown kind' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'ends_at' => secretEnd(), 'end_kind' => 'shred'], 'invalid_end'],
        'set_end without a kind' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => secretEnd()], 'invalid_end'],
        'set_end to none with a kind' => [['op' => 'set_end', 'name' => 'stripe', 'ends_at' => null, 'end_kind' => 'delete'], 'invalid_end'],
        'set_end without ends_at' => [['op' => 'set_end', 'name' => 'stripe'], 'invalid_request'],
        'set_end with a bad name' => [['op' => 'set_end', 'name' => 'a/b', 'ends_at' => null], 'invalid_name'],
        'replace without ends_at' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true], 'end_required'],
        'replace without ends_at, with a kind' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'end_kind' => 'delete'], 'end_required'],
        'replace with no end but a kind' => [['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null, 'end_kind' => 'delete'], 'invalid_end'],
    ]);

    it('sends a replace\'s explicit "no end" as ends_at: null, to a revision 3 and a revision 2 bridge alike', function (string $key, string $conn) {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)($conn, ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true, 'replaced' => true]);

        $out = secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null], $key);

        expect($rig->sent)->toHaveCount(1)
            ->and(array_diff_key($rig->sent[0], ['id' => 1]))->toBe(['type' => MessageTypes::SECRET_PUT, 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null])
            ->and($out->responses[0]['body'])->toBe(['ok' => true, 'replaced' => true]);
    })->with([
        'revision 3' => ['key-r3', 'conn-r3'],
        'revision 2' => ['key-on', 'conn-on'],
    ]);

    it('holds a replace with no end through a short drop and sends it to a bridge that comes back on revision 2', function () {
        $rig = secretsRig();
        $rig->manager->removeConnection('key-on', 'websocket_closed');

        secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null]);
        expect($rig->sent)->toBe([]);

        $rig->manager->addConnection('key-on', 'conn-on2', 'socket-on2');
        $rig->handler->handleMessage('conn-on2', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => [], 'machine_secrets' => ['revision' => 2], 'reliable_delivery' => true]));
        $rig->manager->reattachRunningTurns('key-on', []);
        $rig->manager->flushHeldFrames('key-on');

        expect($rig->sent)->toHaveCount(1)
            ->and(array_diff_key($rig->sent[0], ['id' => 1]))->toBe(['type' => MessageTypes::SECRET_PUT, 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null]);
    });

    it('sends a first put without an end with no ends_at, even when null was given', function () {
        $rig = secretsRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-r3', ['type' => MessageTypes::SECRET_RESULT, 'id' => $f['id'], 'ok' => true, 'replaced' => false]);

        secretsPost($rig, '/api/secrets', ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'ends_at' => null], 'key-r3');

        expect(array_diff_key($rig->sent[0], ['id' => 1]))->toBe(['type' => MessageTypes::SECRET_PUT, 'name' => 'stripe', 'value' => SECRET_VALUE]);
    });
});

describe('SecretRequest::endRefusal()', function () {
    it('takes an end after now and at most 10 years ahead, in the wire form only', function () {
        $now = new DateTimeImmutable('2026-11-24T12:00:00Z');

        expect(SecretRequest::endRefusal('2026-11-24T12:00:01Z', $now))->toBeNull()
            ->and(SecretRequest::endRefusal('2036-11-24T12:00:00Z', $now))->toBeNull()
            ->and(SecretRequest::endRefusal('2036-11-24T12:00:01Z', $now))->not->toBeNull()
            ->and(SecretRequest::endRefusal('2026-11-24T12:00:00Z', $now))->not->toBeNull()
            ->and(SecretRequest::endRefusal("2026-12-01T15:00:00Z\n", $now))->not->toBeNull()
            ->and(SecretRequest::endRefusal('2026-12-01 15:00:00', $now))->not->toBeNull()
            ->and(SecretRequest::endRefusal(1764601200, $now))->not->toBeNull()
            ->and(SecretRequest::endKindRefusal('expire'))->toBeNull()
            ->and(SecretRequest::endKindRefusal('delete'))->toBeNull()
            ->and(SecretRequest::endKindRefusal('Delete'))->not->toBeNull();
    });
});

describe('AiBridge::secrets() with an end', function () {
    beforeEach(function () {
        $this->connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-r3', 'name' => 'laptop']);
    });

    it('supports ends only while the bridge is connected and announced revision 3 or newer', function (array $status, bool $supported) {
        Http::fake(['*/api/status' => Http::response($status)]);

        expect(AiBridge::secrets()->supportsEnds($this->connection))->toBe($supported);
    })->with([
        'connected, revision 3' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => 3]]], true],
        'connected, newer revision' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => 4]]], true],
        'connected, revision 2' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => 2]]], false],
        'connected, secrets off' => [['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => null]], false],
        'off' => [['connected' => false], false],
    ]);

    it('sends a put\'s end in UTC with a Z, expire by default', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => true, 'replaced' => false])]);
        $end = new DateTimeImmutable('+2 days 13:00:00', new DateTimeZone('Europe/Amsterdam'));
        $utc = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        AiBridge::secrets()->put($this->connection, 'deploy-token', SECRET_VALUE, endsAt: $end);
        AiBridge::secrets()->put($this->connection, 'deploy-token', SECRET_VALUE, replace: true, endsAt: $end, endKind: 'delete');

        $bodies = [];
        Http::assertSent(function (HttpRequest $r) use (&$bodies) {
            if (str_ends_with($r->url(), '/api/secrets')) {
                $bodies[] = $r->data();
            }

            return true;
        });
        expect($bodies)->toBe([
            ['op' => 'put', 'name' => 'deploy-token', 'value' => SECRET_VALUE, 'ends_at' => $utc, 'end_kind' => 'expire'],
            ['op' => 'put', 'name' => 'deploy-token', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => $utc, 'end_kind' => 'delete'],
        ]);
    });

    it('sets and removes an end with setEnd()', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => true])]);
        $end = new DateTimeImmutable('@'.(time() + 7200));

        expect(AiBridge::secrets()->setEnd($this->connection, 'api-key', $end, 'delete'))->toBe(['ok' => true])
            ->and(AiBridge::secrets()->setEnd($this->connection, 'api-key', $end))->toBe(['ok' => true])
            ->and(AiBridge::secrets()->setEnd($this->connection, 'api-key', null))->toBe(['ok' => true]);

        $bodies = [];
        Http::assertSent(function (HttpRequest $r) use (&$bodies) {
            if (str_ends_with($r->url(), '/api/secrets')) {
                $bodies[] = $r->data();
            }

            return true;
        });
        $wire = $end->format('Y-m-d\TH:i:s\Z');
        expect($bodies)->toBe([
            ['op' => 'set_end', 'name' => 'api-key', 'ends_at' => $wire, 'end_kind' => 'delete'],
            ['op' => 'set_end', 'name' => 'api-key', 'ends_at' => $wire, 'end_kind' => 'expire'],
            ['op' => 'set_end', 'name' => 'api-key', 'ends_at' => null],
        ]);
    });

    it('sends nothing for an end to a connected bridge on revision 2: ends_unsupported, audited, never with the value', function () {
        Http::fake([
            '*/api/status' => Http::response(['connected' => true, 'providers' => [], 'bridge' => ['machine_secrets' => ['revision' => 2]]]),
            '*/api/secrets' => Http::response(['ok' => true]),
        ]);
        $events = [];
        Event::listen(MachineSecretChanged::class, function (MachineSecretChanged $e) use (&$events) {
            $events[] = [$e->operation, $e->outcome, $e->resultUnknown];
        });
        $logs = [];
        Log::listen(function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });

        $put = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, endsAt: new DateTimeImmutable('+1 hour'));
        $set = AiBridge::secrets()->setEnd($this->connection, 'stripe', null);
        $plain = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, replace: true, noEnd: true);

        expect($put)->toMatchArray(['ok' => false, 'code' => 'ends_unsupported'])
            ->and($put)->not->toHaveKey('_status')
            ->and($put)->not->toHaveKey('result_unknown')
            ->and($set['code'])->toBe('ends_unsupported')
            ->and($plain)->toBe(['ok' => true])
            ->and($events)->toBe([['put', 'ends_unsupported', false], ['set_end', 'ends_unsupported', false], ['put', 'ok', false]])
            ->and(json_encode($put))->not->toContain(SECRET_VALUE)
            ->and(implode("\n", $logs))->not->toContain(SECRET_VALUE);
        $secretRequests = [];
        Http::assertSent(function (HttpRequest $r) use (&$secretRequests) {
            if (str_ends_with($r->url(), '/api/secrets')) {
                $secretRequests[] = $r->data();
            }

            return true;
        });
        expect($secretRequests)->toBe([['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null]]);
    });

    it('leaves an end to a bridge it cannot see to the serve process, and passes its ends_unsupported on', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => false, 'code' => 'ends_unsupported', 'error' => 'The bridge on this machine cannot give a secret an end.'], 409)]);
        $events = [];
        Event::listen(MachineSecretChanged::class, function (MachineSecretChanged $e) use (&$events) {
            $events[] = [$e->operation, $e->outcome, $e->resultUnknown];
        });
        $logs = [];
        Log::listen(function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });

        $put = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, endsAt: new DateTimeImmutable('+1 hour'));
        $set = AiBridge::secrets()->setEnd($this->connection, 'stripe', null);

        expect($put)->toBe(['ok' => false, 'code' => 'ends_unsupported', 'error' => 'The bridge on this machine cannot give a secret an end.'])
            ->and($set['code'])->toBe('ends_unsupported')
            ->and($set)->not->toHaveKey('result_unknown')
            ->and($events)->toBe([['put', 'ends_unsupported', false], ['set_end', 'ends_unsupported', false]])
            ->and(json_encode($put))->not->toContain(SECRET_VALUE)
            ->and(implode("\n", $logs))->not->toContain(SECRET_VALUE);
    });

    it('audits a set_end with its end, never a value', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => true])]);
        $logs = [];
        Log::listen(function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });
        $end = new DateTimeImmutable('@'.(time() + 7200));

        AiBridge::secrets()->setEnd($this->connection, 'api-key', $end, 'delete');
        AiBridge::secrets()->setEnd($this->connection, 'api-key', null);

        $logs = array_values(array_filter($logs, fn ($l) => str_starts_with($l, 'AI Bridge: machine secret ')));
        expect($logs[0])->toStartWith('AI Bridge: machine secret set_end')
            ->toContain('"ends_at":"'.$end->format('Y-m-d\TH:i:s\Z').'"')
            ->toContain('"end_kind":"delete"')
            ->and($logs[1])->toContain('"ends_at":null')->toContain('"end_kind":null');
    });

    it('audits a put with its end, and a replace with no end as none', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => true, 'replaced' => true])]);
        $logs = [];
        Log::listen(function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });
        $end = new DateTimeImmutable('@'.(time() + 7200));

        AiBridge::secrets()->put($this->connection, 'deploy-token', SECRET_VALUE, endsAt: $end, endKind: 'delete');
        AiBridge::secrets()->put($this->connection, 'deploy-token', SECRET_VALUE, replace: true, noEnd: true);
        AiBridge::secrets()->describe($this->connection, 'deploy-token', 'Deploy');

        $logs = array_values(array_filter($logs, fn ($l) => str_starts_with($l, 'AI Bridge: machine secret ')));
        expect($logs[0])->toStartWith('AI Bridge: machine secret put')
            ->toContain('"ends_at":"'.$end->format('Y-m-d\TH:i:s\Z').'"')
            ->toContain('"end_kind":"delete"')
            ->and($logs[1])->toContain('"replaced":true')->toContain('"ends_at":null')->toContain('"end_kind":null')
            ->and($logs[2])->not->toContain('ends_at')
            ->and(implode("\n", $logs))->not->toContain(SECRET_VALUE);
    });

    it('carries the end asked for on MachineSecretChanged, null when none was sent', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => true])]);
        $events = [];
        Event::listen(MachineSecretChanged::class, function (MachineSecretChanged $e) use (&$events) {
            $events[] = [$e->operation, $e->endsAt, $e->endKind];
        });
        $end = new DateTimeImmutable('@'.(time() + 7200));
        $wire = $end->format('Y-m-d\TH:i:s\Z');

        AiBridge::secrets()->put($this->connection, 'deploy-token', SECRET_VALUE, endsAt: $end, endKind: 'delete');
        AiBridge::secrets()->put($this->connection, 'deploy-token', SECRET_VALUE, replace: true, noEnd: true);
        AiBridge::secrets()->setEnd($this->connection, 'deploy-token', $end);
        AiBridge::secrets()->setEnd($this->connection, 'deploy-token', null);
        AiBridge::secrets()->remove($this->connection, 'deploy-token');

        expect($events)->toBe([
            ['put', $wire, 'delete'],
            ['put', null, null],
            ['set_end', $wire, 'expire'],
            ['set_end', null, null],
            ['remove', null, null],
        ]);
    });

    it('marks a set_end whose outcome cannot be known as unknown', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::sequence()
            ->push(['ok' => false, 'code' => 'timeout', 'error' => 'The machine did not answer in time.'], 504)]);

        expect(AiBridge::secrets()->setEnd($this->connection, 'stripe', null))->toMatchArray(['code' => 'timeout', 'result_unknown' => true]);
    });

    it('marks a set_end that never reached the serve process as unknown', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => fn () => throw new ConnectionException('timed out')]);

        expect(AiBridge::secrets()->setEnd($this->connection, 'stripe', new DateTimeImmutable('+1 hour')))
            ->toMatchArray(['ok' => false, 'code' => 'unreachable', 'result_unknown' => true]);
    });

    it('refuses an end in the past, or a kind without an end, without reaching the serve process', function () {
        Http::fake();

        expect(AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, endsAt: new DateTimeImmutable('-1 minute'))['code'])->toBe('invalid_end')
            ->and(AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, endKind: 'delete')['code'])->toBe('invalid_end')
            ->and(AiBridge::secrets()->setEnd($this->connection, 'stripe', new DateTimeImmutable('+1 hour'), 'shred')['code'])->toBe('invalid_end')
            ->and(AiBridge::secrets()->setEnd($this->connection, 'stripe', new DateTimeImmutable('+11 years'))['code'])->toBe('invalid_end')
            ->and(AiBridge::secrets()->setEnd($this->connection, 'stripe', null, 'delete'))->toMatchArray(['code' => 'invalid_end', 'error' => 'ends_at and end_kind go together: give both or neither.'])
            ->and(AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, endKind: 'delete')['error'])->toBe('ends_at and end_kind go together: give both or neither.');
        Http::assertNothingSent();
    });

    it('refuses a replace that states no end (end_required), or both an end and noEnd (invalid_end), without reaching the serve process', function () {
        Http::fake();
        $events = [];
        Event::listen(MachineSecretChanged::class, function (MachineSecretChanged $e) use (&$events) {
            $events[] = [$e->operation, $e->outcome];
        });

        $forgotten = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, replace: true);
        $kindOnly = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, replace: true, endKind: 'delete');
        $both = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, replace: true, endsAt: new DateTimeImmutable('+1 hour'), noEnd: true);
        $noEndWithKind = AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, replace: true, endKind: 'delete', noEnd: true);

        expect($forgotten)->toMatchArray(['ok' => false, 'code' => 'end_required'])
            ->and($kindOnly['code'])->toBe('end_required')
            ->and($both)->toMatchArray(['ok' => false, 'code' => 'invalid_end'])
            ->and($noEndWithKind)->toMatchArray(['ok' => false, 'code' => 'invalid_end'])
            ->and($events)->toBe([['put', 'end_required'], ['put', 'end_required'], ['put', 'invalid_end'], ['put', 'invalid_end']])
            ->and(json_encode([$forgotten, $kindOnly, $both]))->not->toContain(SECRET_VALUE);
        Http::assertNothingSent();
    });

    it('sends noEnd as ends_at: null, on a replace and on a first put', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => true, 'replaced' => true])]);

        AiBridge::secrets()->put($this->connection, 'stripe', SECRET_VALUE, replace: true, noEnd: true);
        AiBridge::secrets()->put($this->connection, 'other', SECRET_VALUE, noEnd: true);

        $bodies = [];
        Http::assertSent(function (HttpRequest $r) use (&$bodies) {
            if (str_ends_with($r->url(), '/api/secrets')) {
                $bodies[] = $r->data();
            }

            return true;
        });
        expect($bodies)->toBe([
            ['op' => 'put', 'name' => 'stripe', 'value' => SECRET_VALUE, 'replace' => true, 'ends_at' => null],
            ['op' => 'put', 'name' => 'other', 'value' => SECRET_VALUE, 'ends_at' => null],
        ]);
    });

    it('rounds an end with sub-seconds up to the next whole second, so one second ahead is never now', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::response(['ok' => true])]);
        $whole = time() + 3600;
        $fraction = DateTimeImmutable::createFromFormat('U.u', $whole.'.250000');

        $soon = AiBridge::secrets()->setEnd($this->connection, 'stripe', new DateTimeImmutable('+1 second'));
        AiBridge::secrets()->setEnd($this->connection, 'stripe', $fraction);
        AiBridge::secrets()->setEnd($this->connection, 'stripe', new DateTimeImmutable('@'.$whole));

        $ends = [];
        Http::assertSent(function (HttpRequest $r) use (&$ends) {
            if (str_ends_with($r->url(), '/api/secrets')) {
                $ends[] = $r->data()['ends_at'];
            }

            return true;
        });
        expect($soon)->toBe(['ok' => true])
            ->and($ends[1])->toBe(gmdate('Y-m-d\TH:i:s\Z', $whole + 1))
            ->and($ends[2])->toBe(gmdate('Y-m-d\TH:i:s\Z', $whole));
    });

    it('lists ended records, and an empty list when the bridge sends none', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false]), '*/api/secrets' => Http::sequence()
            ->push(['ok' => true, 'secrets' => [], 'ended' => [['name' => 'deploy-token', 'kind' => 'delete', 'ended_at' => '2026-11-24T17:00:00Z']]])
            ->push(['ok' => true, 'secrets' => []])]);

        expect(AiBridge::secrets()->list($this->connection)['ended'])->toBe([['name' => 'deploy-token', 'kind' => 'delete', 'ended_at' => '2026-11-24T17:00:00Z']])
            ->and(AiBridge::secrets()->list($this->connection)['ended'])->toBe([]);
    });
});
