<?php

namespace App\Services\Members;

use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Back office operations on the front's members and their invitations,
 * recorded in the audit log with the admin as causer (docs/DECISIONS.md D64, D65).
 */
final class MemberAdministration
{
    public function __construct(private readonly MemberInvitationIssuer $issuer) {}

    public function invite(string $label, ?int $maxUses, ?DateTimeInterface $expiresAt): IssuedMemberInvitation
    {
        $admin = Auth::user();
        $issued = $this->issuer->issue($label, $maxUses, $expiresAt, $admin instanceof User ? $admin : null);
        $this->audit($issued->invitation, 'member_invitation.created', ['label' => $label, 'max_uses' => $maxUses]);

        return $issued;
    }

    /** The link stops working; the members it let in stay. */
    public function revoke(MemberInvitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->audit($invitation, 'member_invitation.revoked', ['label' => $invitation->label]);
    }

    /** Logged out at its next request, and refused at the login. */
    public function disable(Member $member): void
    {
        $member->disable();
        $this->audit($member, 'member.disabled', ['username' => $member->username]);
    }

    public function enable(Member $member): void
    {
        $member->enable();
        $this->audit($member, 'member.enabled', ['username' => $member->username]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(Model $subject, string $event, array $properties = []): void
    {
        activity(MemberAccess::LOG_NAME)
            ->performedOn($subject)
            ->causedBy(Auth::user())
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
