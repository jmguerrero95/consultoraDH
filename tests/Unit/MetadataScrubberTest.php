<?php

declare(strict_types=1);

use App\Domain\Audit\MetadataScrubber;

it('redacts values whose key looks like a secret', function (): void {
    $scrubber = new MetadataScrubber;

    $clean = $scrubber->scrub([
        'password' => 'hunter2',
        'current_password' => 'hunter2',
        'password_confirmation' => 'hunter2',
        'remember_token' => 'abc',
        'csrf_token' => 'xyz',
        'cookie' => 'laravel_session=...',
        'api_key' => 'k',
        'authorization' => 'Bearer ...',
    ]);

    foreach ($clean as $key => $value) {
        expect($value)->toBe(MetadataScrubber::REDACTED, "expected {$key} to be redacted");
    }
});

it('matches sensitive keys case insensitively and as substrings', function (): void {
    $scrubber = new MetadataScrubber;

    $clean = $scrubber->scrub([
        'PassWord' => 'a',
        'X-Password-Hash' => 'b',
        'userPassword' => 'c',
    ]);

    expect(array_values($clean))->toBe([
        MetadataScrubber::REDACTED,
        MetadataScrubber::REDACTED,
        MetadataScrubber::REDACTED,
    ]);
});

it('keeps harmless values untouched', function (): void {
    $scrubber = new MetadataScrubber;

    expect($scrubber->scrub([
        'email' => 'admin@consultora-dh.test',
        'status' => 'active',
        'changed' => ['name'],
    ]))->toBe([
        'email' => 'admin@consultora-dh.test',
        'status' => 'active',
        'changed' => ['name'],
    ]);
});

it('scrubs nested structures', function (): void {
    $scrubber = new MetadataScrubber;

    $clean = $scrubber->scrub([
        'user' => ['email' => 'a@b.test', 'password' => 'secret'],
    ]);

    expect($clean['user']['email'])->toBe('a@b.test')
        ->and($clean['user']['password'])->toBe(MetadataScrubber::REDACTED);
});

it('replaces objects rather than serialising them', function (): void {
    $scrubber = new MetadataScrubber;

    expect($scrubber->scrub(['model' => new stdClass])['model'])
        ->toBe(MetadataScrubber::REDACTED);
});

it('stops recursing instead of traversing a pathological structure', function (): void {
    $scrubber = new MetadataScrubber;

    $deep = 'bottom';

    for ($level = 0; $level < 12; $level++) {
        $deep = ['level'.$level => $deep];
    }

    $encoded = json_encode($scrubber->scrub($deep));

    // The value beyond the depth limit is dropped rather than followed.
    expect($encoded)->not->toContain('bottom');
});
