<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Expense;
use App\Services\AttachmentAuthorizationService;
use App\Services\AttachmentManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment, AttachmentAuthorizationService $authorization)
    {
        abort_unless($attachment->attachable, 404);
        $authorization->authorize($attachment->attachable);
        abort_unless(Storage::disk('attachments')->exists($attachment->path), 404);

        return Storage::disk('attachments')->download($attachment->path, AttachmentManagementService::cleanName($attachment->original_name), ['X-Content-Type-Options' => 'nosniff']);
    }

    public function legacy(Expense $expense)
    {
        Gate::authorize('payments.view');
        $path = str_replace('\\', '/', (string) $expense->receipt_path);
        abort_unless(preg_match('#^expenses/receipts/[^/\x00-\x1F]+$#D', $path) && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, AttachmentManagementService::cleanName(basename($path)), ['X-Content-Type-Options' => 'nosniff']);
    }
}
