<?php

use App\Shopify\PosSignature;

it('round-trips a signature with the same inputs', function () {
    $body = '{"email":"a@b.c","picked_number":4,"subtotal_cents":120000}';
    $timestamp = (string) time();
    $nonce = 'nonce-abc';
    $secret = 'super-secret-value';

    $signature = PosSignature::compute($timestamp, $nonce, $body, $secret);

    expect(PosSignature::verify($timestamp, $nonce, $body, $signature, $secret))->toBeTrue()
        ->and(PosSignature::verify($timestamp, $nonce, $body.' ', $signature, $secret))->toBeFalse()
        ->and(PosSignature::verify($timestamp, 'other-nonce', $body, $signature, $secret))->toBeFalse()
        ->and(PosSignature::verify($timestamp, $nonce, $body, $signature, 'wrong-secret'))->toBeFalse()
        ->and(PosSignature::verify('', $nonce, $body, $signature, $secret))->toBeFalse();
});

it('accepts timestamps inside the signing window and rejects stale ones', function () {
    $now = 1_700_000_000;
    expect(PosSignature::withinWindow((string) $now, $now))->toBeTrue()
        ->and(PosSignature::withinWindow((string) ($now - 299), $now))->toBeTrue()
        ->and(PosSignature::withinWindow((string) ($now - 301), $now))->toBeFalse()
        ->and(PosSignature::withinWindow((string) ($now + 301), $now))->toBeFalse()
        ->and(PosSignature::withinWindow('not-a-number', $now))->toBeFalse()
        ->and(PosSignature::withinWindow('', $now))->toBeFalse();
});
