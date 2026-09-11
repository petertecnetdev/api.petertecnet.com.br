<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Media\Services\ManagedFileStorageService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class MessageAttachmentDeliveryService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ManagedFileStorageService $files,
    ) {}

    public function response(int $attachmentId, int $userId)
    {
        $attachment = DB::table('message_attachments as a')
            ->join('messages as m', 'm.id', '=', 'a.message_id')
            ->join('conversation_participants as cp', 'cp.conversation_id', '=', 'm.conversation_id')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('a.id', $attachmentId)
            ->where('cp.user_id', $userId)
            ->where('c.app_id', $this->context->id())
            ->whereNull('m.deleted_at')
            ->select('a.*')
            ->first();

        abort_unless($attachment, 404, 'Anexo não encontrado.');
        abort_unless(
            $this->files->exists($attachment->storage_disk, $attachment->storage_path),
            404,
            'Arquivo não encontrado.'
        );

        return Storage::disk($attachment->storage_disk)->response(
            $attachment->storage_path,
            $attachment->original_name ?: basename($attachment->storage_path),
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
