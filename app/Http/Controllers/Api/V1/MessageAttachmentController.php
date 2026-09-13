<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageAttachmentRequest;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A file a message points at (image/document/etc). Deliberately separate
 * from MessageController::store: the client uploads the file here first,
 * gets back a path, then sends the actual message with that
 * `attachment_path` — reusing the existing message-creation flow instead of
 * duplicating it.
 */
class MessageAttachmentController extends Controller
{
    public function store(StoreMessageAttachmentRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('send', $conversation);

        $file = $request->file('file');
        $filename = (string) Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('message-attachments/'.$conversation->public_id, $filename, 'local');

        return response()->json([
            'data' => [
                'attachment_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
        ], 201);
    }

    public function show(Request $request, Message $message): BinaryFileResponse
    {
        $conversation = $message->conversation;
        abort_if($conversation === null, 404);

        Gate::authorize('view', $conversation);

        if ($message->attachment_path === null || $message->attachment_path === '') {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($message->attachment_path)) {
            abort(404);
        }

        return response()->file($disk->path($message->attachment_path));
    }
}
