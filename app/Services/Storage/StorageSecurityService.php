<?php

namespace App\Services\Storage;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StorageSecurityService
{
    public function downloadPrivateFile(string $absolutePath, ?string $downloadName = null): BinaryFileResponse
    {
        $realPath = realpath($absolutePath);
        $storageRoot = realpath(storage_path('app'));

        abort_unless($realPath && $storageRoot && str_starts_with(str_replace('\\', '/', $realPath), str_replace('\\', '/', $storageRoot)), 403);
        abort_unless(is_file($realPath), 404);

        return response()->download($realPath, $downloadName, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
