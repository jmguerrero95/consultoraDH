<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class SupportPresenceController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();

        $ttl = 90; // seconds
        $key = "support:presence:{$user->id}";

        Redis::setex($key, $ttl, json_encode([
            'user_id' => $user->id,
            'name' => $user->name,
            'account_type' => $user->account_type,
            'last_heartbeat' => now()->toIso8601String(),
        ]));

        return response()->json(['status' => 'ok']);
    }
}
