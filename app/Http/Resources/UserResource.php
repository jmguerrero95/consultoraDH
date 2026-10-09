<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The representation of the signed in user sent to the interface.
 *
 * Never exposes the password hash, the remember token, the last sign in
 * address or anything else that is not needed to render the interface.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'initials' => $this->initials(),
            'roles' => $this->getRoleNames()->all(),
            'primary_role' => $this->primaryRoleName(),
            // The interface hides navigation entries the user cannot open.
            // This is presentation only: the server re-checks every request.
            'permissions' => $this->getAllPermissions()->pluck('name')->all(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),

            // §13 / §41: the interface has to know which of the two areas this account
            // belongs to so it can send a client to `/portal` and a colleague to the
            // administrative one. Presentation only — the server decides what each
            // account may read on every request regardless of what is claimed here.
            'account_type' => $this->account_type,
            'client_id' => $this->client_id === null ? null : (int) $this->client_id,
        ];
    }
}
