<?php

namespace App\Services\Members;

use App\Models\Member;
use App\Models\MemberInvitation;
use Illuminate\Support\Facades\DB;

/**
 * Who may enter the front (docs/DECISIONS.md D64, D65): a member comes back
 * freely, an unknown Discord account needs an invitation that can still be used.
 */
final class MemberAccess
{
    public const LOG_NAME = 'members';

    public function enter(DiscordUser $user, ?string $invitationToken): Member
    {
        [$member, $joined] = DB::transaction(function () use ($user, $invitationToken): array {
            $member = Member::query()->where('discord_id', $user->id)->lockForUpdate()->first();
            if ($member !== null) {
                if (! $member->isActive()) {
                    throw new MemberAccessDenied('member_disabled');
                }

                return [$this->refresh($member, $user), false];
            }

            if ($invitationToken === null) {
                throw new MemberAccessDenied('invitation_required');
            }
            // Locked: two accounts must not both take the last use.
            $invitation = MemberInvitation::query()->forToken($invitationToken)->lockForUpdate()->first();
            if ($invitation === null || ! $invitation->isUsable()) {
                throw new MemberAccessDenied('invitation_unavailable');
            }

            $member = new Member;
            $member->discord_id = $user->id;
            $member->member_invitation_id = $invitation->id;
            $invitation->increment('uses');

            return [$this->refresh($member, $user), true];
        });

        if ($joined) {
            activity(self::LOG_NAME)
                ->performedOn($member)
                ->causedBy($member)
                ->event('member.joined')
                ->withProperties(['username' => $member->username, 'invitation' => $member->invitation?->label])
                ->log('member.joined');
        }

        return $member;
    }

    /** Discord names and avatars change: each login brings them up to date. */
    private function refresh(Member $member, DiscordUser $user): Member
    {
        $member->forceFill([
            'username' => $user->username,
            'display_name' => $user->displayName,
            'discord_avatar' => $user->avatar,
            'last_login_at' => now(),
        ])->save();

        return $member;
    }
}
