<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\NotificationStateChanged;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * §59: the internal notification inbox.
 *
 * Deliberately minimal: an unread count, a recent list, and marking read. It is not
 * chat, and it is not the A06 communications subsystem — it exists so a task reminder
 * and a scheduled report are visible to the person they concern.
 *
 * ## What is not in a notification
 *
 * §59 and §31: the payload carries a safe title, a safe message and a route, never a
 * dump of the record. A debtor's national identifier or a client's balance does not
 * belong in a table the topbar renders for anybody who walks past the screen; whoever
 * follows the link passes the same authorisation as any other request.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $recent = $user->notifications()
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn ($notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => [
                    'type' => $notification->data['type'] ?? null,
                    'title' => $notification->data['title'] ?? null,
                    'message' => $notification->data['message'] ?? null,
                    'route' => $notification->data['route'] ?? null,
                ],
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'data' => $recent,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Only the unread count.
     *
     * The bell needs a badge on every screen, not the fifteen most recent
     * bodies. Keeping the two apart means a normal page load costs one indexed
     * count instead of a list query plus hydration, and the list is only read
     * when somebody opens the panel.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification === null) {
            return response()->json(['message' => 'La notificación no existe.'], 404);
        }

        $notification->markAsRead();

        $unread = $request->user()->unreadNotifications()->count();

        $this->announce($request, 'read', (string) $notification->id, $unread);

        return response()->json(['unread' => $unread]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        $this->announce($request, 'read_all', null, 0);

        return response()->json(['unread' => 0]);
    }

    /**
     * Tell the other tabs that the badge changed, after the write is committed.
     *
     * The tab that made the request updates from the HTTP response; this is for
     * the second tab, which otherwise kept a stale number until it regained
     * focus. The payload is a signal and the resulting count, not the notification.
     */
    private function announce(Request $request, string $change, ?string $notificationId, int $unread): void
    {
        $userId = (int) $request->user()->id;

        DB::afterCommit(function () use ($userId, $change, $notificationId, $unread): void {
            broadcast(new NotificationStateChanged($userId, $change, $notificationId, $unread));
        });
    }
}
