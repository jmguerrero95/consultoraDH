<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\GeneratedReport;
use App\Models\ReportSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner a scheduled report is ready.
 *
 * §56: the message points at the report and never attaches it. A PDF of the cartera carries
 * every debtor's national identifier and balance; putting one in an inbox would copy the
 * data out of the access-controlled system that was built to hold it.
 */
final class ReportReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly GeneratedReport $report,
        private readonly ReportSchedule $schedule,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_ready',
            'title' => 'Reporte listo',
            'message' => sprintf(
                'El reporte programado «%s» está listo para consultar.',
                $this->schedule->name,
            ),
            'generated_report_id' => (int) $this->report->id,
            'route' => '/reportes',
        ];
    }
}
