<?php

use App\Enums\InvitationPurpose;
use App\Models\Client;

describe('choosing the cabinet name before claiming', function () {
    it('shows the current name and a button to draw another one', function () {
        $issued = issueInvitation(Client::factory()->create(['name' => 'glitchy_pac_man']));

        $this->get('/invite/'.$issued->plainToken)
            ->assertOk()
            ->assertSee('glitchy_pac_man')
            ->assertSee('Another name')
            ->assertSee('action="'.route('invitations.rename', $issued->plainToken).'"', escape: false);
    });

    it('draws a new free name and comes back to the invitation page', function () {
        config(['maui.names.adjectives' => ['glitchy', 'laggy'], 'maui.names.heroes' => ['pac_man']]);
        $client = Client::factory()->create(['name' => 'glitchy_pac_man']);
        $issued = issueInvitation($client);

        $this->post('/invite/'.$issued->plainToken.'/name')
            ->assertRedirect(route('invitations.show', $issued->plainToken))
            ->assertStatus(303);

        expect($client->fresh()->name)->toBe('laggy_pac_man');
    });

    it('does not consume the invitation', function () {
        $issued = issueInvitation();

        $this->post('/invite/'.$issued->plainToken.'/name');

        expect($issued->invitation->fresh()->isClaimable())->toBeTrue()
            ->and($issued->invitation->client->tokens()->count())->toBe(0);
    });

    it('can be repeated until the owner is happy', function () {
        $issued = issueInvitation();
        $names = [];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/invite/'.$issued->plainToken.'/name')->assertStatus(303);
            $names[] = $issued->invitation->client->fresh()->name;
        }

        expect(array_unique($names))->toHaveCount(5);
    });

    it('shows the final name once the credentials are claimed', function () {
        $issued = issueInvitation(Client::factory()->create(['name' => 'glitchy_pac_man']));

        $this->post('/invite/'.$issued->plainToken.'/claim')->assertOk()->assertSee('glitchy_pac_man');
    });

    it('is not offered on a renewal: the cabinet keeps its name', function () {
        $client = Client::factory()->create(['name' => 'glitchy_pac_man']);
        $renewal = issueInvitation($client, InvitationPurpose::Renewal);

        $this->get('/invite/'.$renewal->plainToken)->assertOk()->assertDontSee('Another name');
        $this->post('/invite/'.$renewal->plainToken.'/name')->assertForbidden();

        expect($client->fresh()->name)->toBe('glitchy_pac_man');
    });

    it('is refused once the invitation is claimed or expired', function (Closure $makeUnavailable) {
        $client = Client::factory()->create(['name' => 'glitchy_pac_man']);
        $issued = issueInvitation($client);
        $makeUnavailable($this, $issued->plainToken);

        $this->post('/invite/'.$issued->plainToken.'/name')->assertGone();

        expect($client->fresh()->name)->toBe('glitchy_pac_man');
    })->with([
        'claimed' => [fn ($test, string $token) => $test->post('/invite/'.$token.'/claim')],
        'expired' => [fn ($test) => $test->travel(73)->hours()],
    ]);

    it('returns 404 for an unknown invitation', function () {
        $this->post('/invite/'.str_repeat('a', 48).'/name')->assertNotFound();
    });
});
