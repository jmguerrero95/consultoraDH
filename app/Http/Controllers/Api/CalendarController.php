<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Operations\CalendarService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        $start = CarbonImmutable::parse($request->string('start')->toString())->startOfDay();
        $end = CarbonImmutable::parse($request->string('end')->toString())->endOfDay();

        try {
            $events = $this->calendar->events($start, $end);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['events' => $events]);
    }
}
