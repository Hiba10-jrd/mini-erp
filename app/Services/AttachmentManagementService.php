<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

class AttachmentManagementService
{
    public function __construct(private AttachmentAuthorizationService $authorization) {}

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'document';

        return mb_substr(trim($name), 0, 200) ?: 'document';
    }

    public function upload(Model $parent, UploadedFile $file, array $attributes = []): Attachment
    {
        $this->authorization->authorize($parent, true);
        Validator::make([...$attributes, 'file' => $file], [
            'file' => ['required', 'file', 'max:5120', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
            'category' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $mime = MimeTypes::getDefault()->guessMimeType($file->getPathname());
        $name = self::cleanName($file->getClientOriginalName());
        if (! preg_match('/\.(pdf|jpe?g|png|webp)$/i', $name)
            || preg_match('/\.(php\d*|phtml|phar|js|html?|svg|exe|bat|cmd|sh|ps1|com)(?:\.|$)/i', $name)) {
            throw ValidationException::withMessages(['file' => __('Extension de fichier interdite.')]);
        }
        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['file' => __('Type de fichier interdit.')]),
        };
        $path = $parent->getMorphClass().'/'.$parent->getKey().'/'.Str::uuid().'.'.$extension;
        try {
            $stored = Storage::disk('attachments')->putFileAs(dirname($path), $file, basename($path));
            if ($stored !== $path) {
                throw new \RuntimeException('Attachment storage failed.');
            }

            return DB::transaction(function () use ($parent, $name, $path, $file, $attributes, $mime): Attachment {
                // Serialize additions/deletions with parent state transitions.
                $locked = $parent::query()->lockForUpdate()->findOrFail($parent->getKey());
                $this->authorization->authorize($locked, true);
                $attachment = Attachment::create([
                    'attachable_type' => $locked->getMorphClass(), 'attachable_id' => $locked->getKey(),
                    'original_name' => $name, 'path' => $path, 'mime_type' => $mime,
                    'size' => $file->getSize(), 'category' => $attributes['category'] ?? null,
                    'description' => $attributes['description'] ?? null, 'uploaded_by' => Auth::id(),
                ]);
                app(AuditTrailService::class)->record($attachment, 'attachment.uploaded', [], $attachment->getAttributes());

                return $attachment;
            });
        } catch (\Throwable $e) {
            Storage::disk('attachments')->delete($path);
            throw $e;
        }
    }

    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $parent = $attachment->attachable;
            abort_unless($parent, 404);
            $parent = $parent::query()->lockForUpdate()->findOrFail($parent->getKey());
            $this->authorization->authorize($parent, true);
            abort_unless($this->authorization->canDelete($parent), 403);
            $locked = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);
            $locked->delete();
            app(AuditTrailService::class)->record($locked, 'attachment.deleted', $locked->getAttributes(), []);
        });
    }
}
