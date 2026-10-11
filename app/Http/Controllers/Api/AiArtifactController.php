<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AiArtifact;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private artifact download endpoint.
 *
 * Only the artifact owner can download their artifacts.
 * Expired artifacts are refused.
 */
final class AiArtifactController extends Controller
{
    public function download(Request $request, AiArtifact $artifact): StreamedResponse
    {
        // Check ownership
        if ((int) $artifact->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        // Check expiration
        if ($artifact->isExpired()) {
            abort(404, 'El artefacto ha expirado.');
        }

        $disk = Storage::disk('local');
        $path = $artifact->storage_path;

        if (! $disk->exists($path)) {
            abort(404, 'El archivo no existe.');
        }

        return response()->streamDownload(function () use ($disk, $path): void {
            echo $disk->get($path);
        }, $artifact->display_filename, [
            'Content-Type' => $artifact->mime_type,
            'Content-Length' => $artifact->size_bytes,
        ]);
    }
}
