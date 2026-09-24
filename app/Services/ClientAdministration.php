<?php

namespace App\Services;

use App\Enums\ClientType;
use App\Enums\InvitationPurpose;
use App\Models\Client;
use App\Models\User;
use App\Services\Invitations\InvitationIssuer;
use App\Services\Invitations\IssuedInvitation;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Back office operations on clients. Every operation is recorded in the audit
 * log with the admin as causer; secrets (links, tokens) never are.
 */
final class ClientAdministration
{
    public const LOG_NAME = 'clients';

    public function __construct(
        private readonly InvitationIssuer $invitations,
        private readonly ClientTokenIssuer $tokens,
    ) {}

    public function invite(Client $client): IssuedInvitation
    {
        return $this->issueInvitation($client, InvitationPurpose::Initial, 'client.invited');
    }

    /** The current token stays valid until the new one is claimed (D5). */
    public function renew(Client $client): IssuedInvitation
    {
        return $this->issueInvitation($client, InvitationPurpose::Renewal, 'client.renewal_requested');
    }

    public function disable(Client $client): void
    {
        $client->disable();
        $this->audit($client, 'client.disabled');
    }

    public function enable(Client $client): void
    {
        $client->enable();
        $this->audit($client, 'client.enabled');
    }

    public function resetMachineBinding(Client $client): void
    {
        $client->resetMachineBinding();
        $this->audit($client, 'client.binding_reset');
    }

    /**
     * Service accounts are technical: their token is shown once to the admin,
     * no invitation (docs/PLAN.md 1.7).
     */
    public function issueServiceToken(Client $client): string
    {
        if ($client->type !== ClientType::Service) {
            throw new InvalidArgumentException('Cabinets receive their credentials through an invitation.');
        }

        $token = $this->tokens->issue($client);
        $this->audit($client, 'client.service_token_issued');

        return $token;
    }

    private function issueInvitation(Client $client, InvitationPurpose $purpose, string $event): IssuedInvitation
    {
        $issued = $this->invitations->issue($client, $purpose, $this->admin());

        $this->audit($client, $event, [
            'invitation_id' => $issued->invitation->id,
            'expires_at' => $issued->invitation->expires_at->toIso8601ZuluString(),
        ]);

        return $issued;
    }

    /**
     * @param  array<string, mixed>  $properties  Never put a secret here.
     */
    private function audit(Client $client, string $event, array $properties = []): void
    {
        activity(self::LOG_NAME)
            ->performedOn($client)
            ->causedBy($this->admin())
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }

    private function admin(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
