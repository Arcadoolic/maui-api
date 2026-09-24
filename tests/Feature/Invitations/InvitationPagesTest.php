<?php

use App\Enums\InvitationPurpose;
use App\Models\Client;
use App\Services\Invitations\InvitationIssuer;
use App\Services\Invitations\IssuedInvitation;
use App\Support\ConfigurationString;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

function issueInvitation(?Client $client = null, InvitationPurpose $purpose = InvitationPurpose::Initial): IssuedInvitation
{
    return app(InvitationIssuer::class)->issue($client ?? Client::factory()->create(), $purpose);
}

/** Extracts the MAUI1 configuration string displayed on the claimed page. */
function configurationStringFrom(TestResponse $response): string
{
    preg_match('/MAUI1\.[A-Za-z0-9_-]+/', $response->getContent(), $matches);

    return $matches[0] ?? '';
}

describe('GET /invite/{token}', function () {
    it('shows a claim button without consuming the invitation', function () {
        $issued = issueInvitation();

        $this->get('/invite/'.$issued->plainToken)
            ->assertOk()
            ->assertSee('Get my credentials')
            ->assertSee('action="'.url('/invite/'.$issued->plainToken.'/claim').'"', escape: false);

        expect($issued->invitation->fresh()->claimed_at)->toBeNull()
            ->and($issued->invitation->client->tokens()->count())->toBe(0);
    });

    it('is never cached, indexed or leaked through the referrer', function () {
        $response = $this->get('/invite/'.issueInvitation()->plainToken);

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
            ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    });

    it('returns 404 for an unknown invitation', function () {
        $this->get('/invite/'.str_repeat('a', 48))->assertNotFound()->assertSee('This invitation link is not valid');
    });

    it('returns 410 with an explicit message for an expired invitation', function () {
        $issued = issueInvitation();
        $this->travel(73)->hours();

        $this->get('/invite/'.$issued->plainToken)->assertGone()->assertSee('This invitation has expired');
    });

    it('returns 410 with an explicit message for a claimed invitation', function () {
        $issued = issueInvitation();
        $this->post('/invite/'.$issued->plainToken.'/claim')->assertOk();

        $this->get('/invite/'.$issued->plainToken)->assertGone()->assertSee('already been used');
    });
});

describe('POST /invite/{token}/claim', function () {
    it('issues credentials displayed once as a MAUI1 configuration string', function () {
        config(['app.url' => 'https://api.example.org']);
        $issued = issueInvitation();
        $client = $issued->invitation->client;

        $response = $this->post('/invite/'.$issued->plainToken.'/claim')
            ->assertOk()
            ->assertSee('will not be shown again');

        $credentials = ConfigurationString::decode(configurationStringFrom($response));
        expect($credentials['url'])->toBe('https://api.example.org')
            ->and($credentials['key'])->toBe($client->public_key);

        $this->getJson('/api/v1/ping', cabinetHeaders($client, $credentials['token']))->assertOk();
    });

    it('records when and from where the invitation was claimed', function () {
        $this->freezeSecond();
        $issued = issueInvitation();

        $this->post('/invite/'.$issued->plainToken.'/claim', [], ['REMOTE_ADDR' => '203.0.113.7'])->assertOk();

        $invitation = $issued->invitation->fresh();
        expect($invitation->claimed_at->equalTo(now()))->toBeTrue()
            ->and($invitation->claimed_ip)->toBe('203.0.113.7');
    });

    it('can be claimed only once', function () {
        $issued = issueInvitation();
        $this->post('/invite/'.$issued->plainToken.'/claim')->assertOk();

        $this->post('/invite/'.$issued->plainToken.'/claim')->assertGone();

        expect($issued->invitation->client->tokens()->count())->toBe(1);
    });

    it('issues nothing for an expired invitation', function () {
        $issued = issueInvitation();
        $this->travel(73)->hours();

        $this->post('/invite/'.$issued->plainToken.'/claim')->assertGone();

        expect($issued->invitation->client->tokens()->count())->toBe(0);
    });

    it('is not cached', function () {
        $response = $this->post('/invite/'.issueInvitation()->plainToken.'/claim');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('renewal', function () {
    it('keeps the old token valid until the new one is claimed, then revokes it and resets the binding', function () {
        [$client, $oldToken] = cabinetWithToken();
        $this->getJson('/api/v1/ping', cabinetHeaders($client, $oldToken, fingerprint('cabinet-a')))->assertOk();

        $renewal = issueInvitation($client, InvitationPurpose::Renewal);
        $this->getJson('/api/v1/ping', cabinetHeaders($client, $oldToken, fingerprint('cabinet-a')))->assertOk();

        $response = $this->post('/invite/'.$renewal->plainToken.'/claim')->assertOk();
        $newToken = ConfigurationString::decode(configurationStringFrom($response))['token'];

        $this->getJson('/api/v1/ping', cabinetHeaders($client, $oldToken, fingerprint('cabinet-a')))->assertUnauthorized();
        $this->getJson('/api/v1/ping', cabinetHeaders($client, $newToken, fingerprint('cabinet-b')))
            ->assertOk()
            ->assertJsonPath('machine.newly_bound', true);
    });
});

it('does not re-enable a disabled client when its renewal is claimed', function () {
    [$client] = cabinetWithToken();
    $client->disable();
    $renewal = issueInvitation($client, InvitationPurpose::Renewal);

    $response = $this->post('/invite/'.$renewal->plainToken.'/claim')->assertOk();
    $newToken = ConfigurationString::decode(configurationStringFrom($response))['token'];

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $newToken))
        ->assertForbidden()
        ->assertJsonPath('code', 'client_disabled');
});

it('keeps the strict headers on error responses', function (Closure $trigger) {
    $response = $trigger($this);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
})->with([
    'rate limited (429)' => [function ($test) {
        for ($i = 0; $i < 10; $i++) {
            $test->get('/invite/'.str_repeat('a', 48));
        }

        return $test->get('/invite/'.str_repeat('a', 48));
    }],
    'server error (500)' => [function ($test) {
        $token = issueInvitation()->plainToken;
        // Real failure while claiming. PostgreSQL DDL is transactional: the
        // RefreshDatabase transaction restores the table after the test.
        Schema::drop('personal_access_tokens');

        return $test->post('/invite/'.$token.'/claim');
    }],
]);

it('rate limits invitation pages per IP', function () {
    $token = str_repeat('a', 48);

    for ($i = 0; $i < 10; $i++) {
        $this->get('/invite/'.$token)->assertNotFound();
    }

    $this->get('/invite/'.$token)->assertTooManyRequests();
});
