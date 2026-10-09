<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\ClientDocumentRequest;
use App\Models\ContributionSheet;
use App\Models\MonthlyObligation;
use App\Models\OperationalTask;
use Carbon\CarbonImmutable;

final class CalendarService
{
    private const MAX_RANGE_DAYS = 92;

    /**
     * @return list<array<string, mixed>>
     */
    public function events(CarbonImmutable $start, CarbonImmutable $end, ?int $userId = null): array
    {
        if (abs($end->diffInDays($start)) > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException('El rango máximo del calendario es de 3 meses.');
        }

        $events = [];

        $tasks = OperationalTask::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('due_on', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('reminder_at', [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()]);
            })
            ->when($userId !== null, fn ($q) => $q->where('assigned_to', $userId))
            ->get();

        foreach ($tasks as $task) {
            if ($task->due_on !== null) {
                $events[] = [
                    'type' => 'task_due',
                    'date' => $task->due_on,
                    'title' => 'Tarea: '.$task->title,
                    'priority' => $task->priority->value,
                ];
            }
            if ($task->reminder_at !== null) {
                $events[] = [
                    'type' => 'task_reminder',
                    'date' => $task->reminder_at->toDateString(),
                    'title' => 'Recordatorio: '.$task->title,
                    'priority' => $task->priority->value,
                ];
            }
        }

        $requests = ClientDocumentRequest::query()
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('due_on')
            ->whereBetween('due_on', [$start->toDateString(), $end->toDateString()])
            ->get();

        foreach ($requests as $request) {
            $events[] = [
                'type' => 'document_request_due',
                'date' => $request->due_on,
                'title' => 'Solicitud: '.$request->title,
                'priority' => 'normal',
            ];
        }

        $sheets = ContributionSheet::query()
            ->whereIn('status', ['submitted', 'paid'])
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('submitted_on', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('paid_on', [$start->toDateString(), $end->toDateString()]);
            })
            ->get();

        foreach ($sheets as $sheet) {
            if ($sheet->submitted_on !== null) {
                $events[] = [
                    'type' => 'planilla_submitted',
                    'date' => $sheet->submitted_on->toDateString(),
                    'title' => 'Planilla enviada: '.$sheet->company->legal_name,
                    'priority' => 'normal',
                ];
            }
            if ($sheet->paid_on !== null) {
                $events[] = [
                    'type' => 'planilla_paid',
                    'date' => $sheet->paid_on->toDateString(),
                    'title' => 'Planilla pagada: '.$sheet->company->legal_name,
                    'priority' => 'normal',
                ];
            }
        }

        $obligations = MonthlyObligation::query()
            ->whereNotNull('due_on')
            ->whereBetween('due_on', [$start->toDateString(), $end->toDateString()])
            ->get();

        foreach ($obligations as $obligation) {
            $events[] = [
                'type' => 'obligation_due',
                'date' => $obligation->due_on,
                'title' => 'Obligación: '.$obligation->period->period_month->format('Y-m'),
                'priority' => 'normal',
            ];
        }

        usort($events, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $events;
    }
}
