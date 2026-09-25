<?php

// Behind a reverse proxy (staging: nginx on the host, D40), the client IP
// and scheme come from X-Forwarded-* headers, trusted only from the proxies
// listed in TRUSTED_PROXIES.

afterEach(function () {
    unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
});

it('reads the trusted proxies from TRUSTED_PROXIES', function () {
    $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = '172.16.0.0/12';

    $config = require config_path('trustedproxy.php');

    expect($config['proxies'])->toBe('172.16.0.0/12');
});

it('trusts no proxy by default', function () {
    expect(config('trustedproxy.proxies'))->toBeNull();
});

it('takes the client IP and scheme from a trusted proxy', function () {
    config(['trustedproxy.proxies' => '172.16.0.0/12']);
    $issued = issueInvitation();

    $this->post('/invite/'.$issued->plainToken.'/claim', [], [
        'REMOTE_ADDR' => '172.18.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk();

    expect($issued->invitation->fresh()->claimed_ip)->toBe('203.0.113.7')
        ->and(request()->isSecure())->toBeTrue();
});

it('ignores forwarded headers from an untrusted address', function () {
    $issued = issueInvitation();

    $this->post('/invite/'.$issued->plainToken.'/claim', [], [
        'REMOTE_ADDR' => '198.51.100.9',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ])->assertOk();

    expect($issued->invitation->fresh()->claimed_ip)->toBe('198.51.100.9');
});
