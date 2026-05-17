<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the vendored, pre-built static assets for the chat Web Component
 * (the component itself + vendored pusher/echo). Public, allowlisted, cached.
 *
 * GET /ai-bridge/assets/{file}
 */
class AssetController extends Controller
{
    /**
     * Allowlist of servable files (prevents path traversal).
     *
     * @var array<string, string>
     */
    private const FILES = [
        'ai-bridge-chat.js' => 'application/javascript',
        'pusher.min.js' => 'application/javascript',
        'echo.iife.js' => 'application/javascript',
    ];

    public function show(string $file): Response
    {
        if (! array_key_exists($file, self::FILES)) {
            abort(404);
        }

        $path = dirname(__DIR__, 3).'/resources/dist/'.$file;
        if (! is_file($path)) {
            abort(404);
        }

        $response = new BinaryFileResponse($path);
        $response->setMaxAge(3600);
        $response->setPublic();
        $response->headers->set('Content-Type', self::FILES[$file]);

        return $response;
    }
}
