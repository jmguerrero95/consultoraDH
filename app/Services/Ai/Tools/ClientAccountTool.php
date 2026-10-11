<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Receivables\ReceivablesService;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;

/**
 * One client's account statement.
 *
 * A03's `clientAccount()` is the authority for what a client owes; this tool
 * reports its summary and does not restate the arithmetic. A portal account may
 * only ask about its own client, which is the same rule its portal screen applies.
 */
final class ClientAccountTool extends AbstractAiTool
{
    public function __construct(private readonly ReceivablesService $receivables) {}

    public function name(): string
    {
        return 'client_account';
    }

    public function description(): string
    {
        return 'Estado de cuenta de un cliente: saldos, obligaciones pendientes y vencidas. Una cuenta de portal solo puede consultar su propia cuenta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'client_id' => ['type' => 'integer', 'description' => 'Identificador del cliente.'],
                'as_of' => ['type' => 'string', 'description' => 'Fecha de corte YYYY-MM-DD. Opcional.'],
            ],
            'required' => ['client_id'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        return [
            'client_id' => $this->requireId($arguments, 'client_id'),
            'as_of' => $this->optionalDate($arguments, 'as_of'),
        ];
    }

    public function describe(array $arguments): string
    {
        return 'Consultar la cuenta del cliente #'.$arguments['client_id'].'.';
    }

    public function execute(array $arguments, User $user): array
    {
        $clientId = $arguments['client_id'];

        // A portal account gets its own account and nothing else. Checked before
        // the permission because the permission does not describe this boundary:
        // it is ownership, not a capability.
        if ($user->account_type === 'client') {
            if ((int) $user->client_id !== $clientId) {
                throw new AiToolRejected('Solo puede consultar su propia cuenta.');
            }
        } else {
            $this->authorize($user, 'receivables.view');
        }

        $client = Client::find($clientId);

        if ($client === null) {
            return ['found' => false, 'message' => 'No existe un cliente con ese identificador.'];
        }

        $asOf = $arguments['as_of'] === null ? null : Carbon::parse($arguments['as_of'])->startOfDay();

        $account = $this->receivables->clientAccount($client, $asOf);

        return [
            'found' => true,
            'client_id' => $client->id,
            'full_name' => trim($client->first_names.' '.$client->last_names),
            'as_of' => ($asOf ?? Carbon::now())->toDateString(),
            'summary' => $account['summary'] ?? null,
            'obligations' => $account['obligations'] ?? $account['months'] ?? [],
        ];
    }
}
