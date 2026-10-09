<?php

use App\Enums\MemberStatus;
use App\Filament\Resources\MemberInvitations\Pages\ListMemberInvitations;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\Player;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// Members of the hiscores front and their invitations, in the back office (docs/DECISIONS.md D64, D65).

beforeEach(function () {
    config(['front.url' => 'https://hiscores.test']);
    $this->admin = User::factory()->withAppAuthentication()->create();
    $this->actingAs($this->admin);
});

function memberAudit(Member|MemberInvitation $subject, string $event): ?Activity
{
    return Activity::query()
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->where('event', $event)
        ->latest('id')
        ->first();
}

describe('invitations', function () {
    it('creates an invitation and shows its link once', function () {
        livewire(ListMemberInvitations::class)
            ->callAction('invite', ['label' => 'Discord server', 'max_uses' => 5, 'expires_in_days' => 7])
            ->assertActionMounted('showSecret')
            ->assertMountedActionModalSee('https://hiscores.test/invite/');

        $invitation = MemberInvitation::query()->sole();
        expect($invitation->label)->toBe('Discord server')
            ->and($invitation->max_uses)->toBe(5)
            ->and($invitation->expires_at?->isSameDay(now()->addDays(7)))->toBeTrue()
            ->and($invitation->created_by)->toBe($this->admin->id)
            ->and(memberAudit($invitation, 'member_invitation.created')?->causer_id)->toBe($this->admin->id);
    });

    it('creates an invitation without a limit nor an end', function () {
        livewire(ListMemberInvitations::class)
            ->callAction('invite', ['label' => 'Open', 'max_uses' => null, 'expires_in_days' => null])
            ->assertActionMounted('showSecret');

        $invitation = MemberInvitation::query()->sole();
        expect($invitation->max_uses)->toBeNull()
            ->and($invitation->expires_at)->toBeNull();
    });

    it('lists the invitations with their state', function () {
        $usable = issueMemberInvitation(['label' => 'Usable'])->invitation;
        $expired = issueMemberInvitation(['label' => 'Expired', 'expires_at' => now()->subDay()])->invitation;

        livewire(ListMemberInvitations::class)
            ->assertCanSeeTableRecords([$usable, $expired])
            ->assertTableColumnStateSet('state', 'usable', $usable)
            ->assertTableColumnStateSet('state', 'expired', $expired);
    });

    it('revokes an invitation, which keeps its members', function () {
        $invitation = issueMemberInvitation(['max_uses' => null])->invitation;
        $member = Member::factory()->create(['member_invitation_id' => $invitation->id]);

        livewire(ListMemberInvitations::class)->callTableAction('revoke', $invitation);

        expect($invitation->refresh()->isUsable())->toBeFalse()
            ->and($member->refresh()->isActive())->toBeTrue()
            ->and(memberAudit($invitation, 'member_invitation.revoked')?->causer_id)->toBe($this->admin->id);
    });

    it('does not offer to revoke an invitation that is already dead', function () {
        $invitation = issueMemberInvitation(['expires_at' => now()->subDay()])->invitation;

        livewire(ListMemberInvitations::class)->assertTableActionHidden('revoke', $invitation);
    });
});

describe('members', function () {
    it('lists the members with their players and invitation', function () {
        $invitation = issueMemberInvitation(['label' => 'Discord server'])->invitation;
        $member = Member::factory()->create(['username' => 'blinky', 'member_invitation_id' => $invitation->id]);
        $member->players()->attach(Player::factory()->create(['pseudo_3' => 'ACE']), ['linked_at' => now()]);

        livewire(ListMembers::class)
            ->assertCanSeeTableRecords([$member])
            ->assertSee('blinky')
            ->assertSee('ACE')
            ->assertSee('Discord server');
    });

    it('disables and enables a member, recorded with the admin', function () {
        $member = Member::factory()->create();

        livewire(ListMembers::class)->callTableAction('disable', $member);
        expect($member->refresh()->status)->toBe(MemberStatus::Disabled)
            ->and(memberAudit($member, 'member.disabled')?->causer_id)->toBe($this->admin->id);

        livewire(ListMembers::class)->callTableAction('enable', $member);
        expect($member->refresh()->status)->toBe(MemberStatus::Active)
            ->and(memberAudit($member, 'member.enabled'))->not->toBeNull();
    });
});
