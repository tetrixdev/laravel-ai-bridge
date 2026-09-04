<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Tetrix\AiBridge\AiBridgeManager;

/**
 * The HTTP half of attachments, spoken only to a connected bridge.
 *
 * Both routes sit behind `ai-bridge.token`, so the caller is a bridge holding
 * a connection token rather than a browser session — the bridge fetches a
 * user's attachment with the same credential it connects with, and uploads a
 * file the assistant produced with it too.
 *
 * The package stores nothing itself. Where the bytes live is the app's
 * business, registered through AiBridge::resolveAttachmentsUsing() and
 * AiBridge::storeAttachmentUsing(); see AiBridgeManager.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
    ) {}

    /**
     * GET /ai-bridge/attachments/{id} — stream one attachment to the bridge.
     *
     * The user is taken from the validated token, never from the request, and
     * handed to the app's resolver so the lookup can be scoped to that user's
     * own conversations.
     */
    public function show(Request $request, string $id): Response
    {
        $userId = (string) $request->attributes->get('bridge_user_id');

        $file = $this->manager->resolveAttachment($id, $userId);

        // One response for "no such attachment" and for "not yours". Telling
        // the two apart would turn this route into an oracle for which
        // attachment ids exist, which is exactly the enumeration the scoping
        // is there to prevent.
        if ($file === null || ! $file->isFile() || ! $file->isReadable()) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Attachment not found.',
            ], 404);
        }

        // BinaryFileResponse streams from disk rather than reading the file
        // into memory — these are the files too big to have gone over the
        // WebSocket in the first place.
        $response = new BinaryFileResponse($file->getPathname());
        // Never inline: the bridge is the only caller, and a Content-Type the
        // browser would render is not something to invite here.
        $response->setContentDisposition('attachment', $file->getFilename(), 'attachment');

        return $response;
    }

    /**
     * POST /ai-bridge/attachments — accept a file the assistant produced.
     *
     * The bridge calls this when the model invokes `bridge__attach_file`. The
     * response's `id` is what the bridge puts on its `attachment` stream event,
     * so the UI can render the file from the app's own store.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->manager->canStoreAttachments()) {
            // 501 rather than 500: nothing is broken, the app simply never
            // opted into the return direction. The bridge surfaces the message
            // to the model, which can then say so instead of retrying.
            return response()->json([
                'error' => 'not_supported',
                'message' => 'This application does not accept files from the assistant. '
                    .'Register one with AiBridge::storeAttachmentUsing().',
            ], 501);
        }

        if (! $request->hasFile('file') || ! $request->file('file')->isValid()) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'A valid "file" upload is required.',
            ], 422);
        }

        $userId = (string) $request->attributes->get('bridge_user_id');

        try {
            $stored = $this->manager->storeAttachment($request->file('file'), $userId);
        } catch (\Throwable $e) {
            Log::error('AI Bridge: storing an assistant attachment failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'store_failed',
                'message' => 'The attachment could not be stored.',
            ], 500);
        }

        // Only the fields the protocol defines. The app's store may return
        // whatever it likes — a disk name, an absolute path, a model — and
        // none of that is the bridge's business.
        return response()->json(array_intersect_key($stored, array_flip([
            'id', 'url', 'name', 'mime_type', 'size',
        ])), 201);
    }
}
