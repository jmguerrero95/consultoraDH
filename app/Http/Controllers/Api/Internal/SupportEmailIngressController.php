<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\SupportEmailIngressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportEmailIngressController extends Controller
{
    public function __construct(
        private SupportEmailIngressService $service
    ) {}

    public function ingest(Request $request): JsonResponse
    {
        // HMAC validation is done in middleware
        $rawBody = $request->getContent();

        if (empty($rawBody)) {
            return response()->json(['message' => 'Cuerpo vacío'], 422);
        }

        $result = $this->service->processRawEmail($rawBody);

        return response()->json($result);
    }
}
