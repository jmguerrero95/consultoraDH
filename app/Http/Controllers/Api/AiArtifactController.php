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
        // Ownership, answered exactly like an artifact that does not exist.
        //
        // A 403 distinguishes "this id is real and it is not yours" from "this
        // id is not real", which is itself a disclosure about other people's
        // documents - and the ids here are sequential. Everything else in the
        // assistant answers a request for someone else's row with the same 404
        // as for a missing one; this is the same rule applied consistently.
        if ((int) $artifact->user_id !== (int) $request->user()->id) {
            abort(404, 'El artefacto no está disponible.');
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
