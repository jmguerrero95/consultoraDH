<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\NotificationStateChanged;
use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
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
                    'task_id' => $notification->data['task_id'] ?? null,
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
     * Mark a task reminder as attended when the bell opens its own reminder.
     *
     * The Push "Descartar"/open actions write `reminder_dismissed_at` via the
     * signed URL (the only writer from the device). This endpoint is the
     * authenticated equivalent for the in-app bell: clicking a reminder
     * reads it, so it is attended. The session cookie authorises the call;
     * the notification must belong to the caller and must point at a task
     * assigned to the caller. A second call is a no-op — idempotent.
     *
     * Without this, opening a reminder from the bell left the task as
     * un-attended and the scheduler sent the second reminder anyway.
     */
    public function dismissTaskFromNotification(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification === null) {
            return response()->json(['message' => 'La notificación no existe.'], 404);
        }

        $data = is_array($notification->data) ? $notification->data : [];
        $taskId = isset($data['task_id']) ? (int) $data['task_id'] : 0;

        // A notification that is not a task reminder has no attention to
        // record. It is still marked read by the regular endpoint.
        if ($taskId <= 0) {
            return response()->json(['dismissed' => false, 'task_id' => null]);
        }

        $task = OperationalTask::query()->find($taskId);

        if ($task === null) {
            return response()->json(['dismissed' => false, 'task_id' => $taskId]);
        }

        // Only the assignee's own reminder may be acknowledged as attended
        // through their bell. Anything else is refused like the signed
        // endpoints do — the signature is not needed because the session
        // cookie already identifies the caller — but ownership of both the
        // notification and the task is enforced.
        if ((int) $task->assigned_to !== (int) $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($task->reminder_dismissed_at === null) {
            $task->forceFill(['reminder_dismissed_at' => now()])->save();
        }

        return response()->json([
            'dismissed' => true,
            'task_id' => $task->id,
            'dismissed_at' => $task->fresh()?->reminder_dismissed_at?->toIso8601String(),
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
