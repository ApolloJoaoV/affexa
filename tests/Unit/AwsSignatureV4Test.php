<?php

declare(strict_types=1);

use App\Infrastructure\Marketplace\Signing\AwsSignatureV4;
use Carbon\CarbonImmutable;

it('derives the signing key exactly as the AWS specification does', function () {
    // Published test vector from the AWS Signature Version 4 documentation.
    // If this drifts, every Amazon call fails with an opaque 403, so it is
    // pinned to a known-good value rather than to our own implementation.
    $signer = new AwsSignatureV4(
        accessKey: 'AKIDEXAMPLE',
        secretKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        region: 'us-east-1',
        service: 'iam',
    );

    expect(bin2hex($signer->signingKey('20150830')))
        ->toBe('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9');
});

it('produces a complete authorization header', function () {
    $signer = new AwsSignatureV4('AKIDEXAMPLE', 'secret', 'us-east-1', 'ProductAdvertisingAPI');

    $headers = $signer->sign(
        method: 'POST',
        host: 'webservices.amazon.com.br',
        path: '/paapi5/searchitems',
        payload: '{"Keywords":"cafeteira"}',
        headers: ['content-type' => 'application/json; charset=utf-8'],
        now: CarbonImmutable::parse('2026-07-27 12:00:00', 'UTC'),
    );

    expect($headers['Authorization'])
        ->toContain('AWS4-HMAC-SHA256')
        ->toContain('Credential=AKIDEXAMPLE/20260727/us-east-1/ProductAdvertisingAPI/aws4_request')
        // Signed headers must be lowercase and sorted, or Amazon rejects it.
        ->toContain('SignedHeaders=content-type;host;x-amz-date')
        ->and($headers['x-amz-date'])->toBe('20260727T120000Z')
        ->and($headers['host'])->toBe('webservices.amazon.com.br');
});

it('is deterministic for the same request and moment', function () {
    $signer = new AwsSignatureV4('AKIDEXAMPLE', 'secret', 'us-east-1', 'ProductAdvertisingAPI');
    $at = CarbonImmutable::parse('2026-07-27 12:00:00', 'UTC');

    $first = $signer->sign('POST', 'host.test', '/p', '{"a":1}', now: $at);
    $second = $signer->sign('POST', 'host.test', '/p', '{"a":1}', now: $at);

    expect($first['Authorization'])->toBe($second['Authorization']);
});

it('changes the signature when the payload changes', function () {
    $signer = new AwsSignatureV4('AKIDEXAMPLE', 'secret', 'us-east-1', 'ProductAdvertisingAPI');
    $at = CarbonImmutable::parse('2026-07-27 12:00:00', 'UTC');

    // The payload hash is part of the canonical request, so tampering with the
    // body invalidates the signature.
    expect($signer->sign('POST', 'host.test', '/p', '{"a":1}', now: $at)['Authorization'])
        ->not->toBe($signer->sign('POST', 'host.test', '/p', '{"a":2}', now: $at)['Authorization']);
});

it('scopes the signature to the day', function () {
    $signer = new AwsSignatureV4('AKIDEXAMPLE', 'secret', 'us-east-1', 'ProductAdvertisingAPI');

    $today = $signer->sign('POST', 'host.test', '/p', '{}', now: CarbonImmutable::parse('2026-07-27 23:59:59', 'UTC'));
    $tomorrow = $signer->sign('POST', 'host.test', '/p', '{}', now: CarbonImmutable::parse('2026-07-28 00:00:01', 'UTC'));

    expect($today['Authorization'])->not->toBe($tomorrow['Authorization']);
});
