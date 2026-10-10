<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A06: support centre configuration
|--------------------------------------------------------------------------
|
| Every value here is authoritative configuration reached through `config()`,
| never `env()` at the call site, so a cached configuration and a test both see
| the same mapping. Each one documents the variable it reads.
|
| The inbound secret is the credential an external mail provider signs with. It
| is never logged, never returned by an endpoint, and its absence is a 503
| rather than a permissive fallback: an endpoint that cannot verify a signature
| must not accept the request unverified.
|
*/
return [
    /*
     * Shared secret used to sign inbound webhook payloads (HMAC-SHA256).
     * SUPPORT_INBOUND_SECRET — required; no default, so a missing value is a
     * hard configuration error rather than an empty string that signs nothing.
     */
    'inbound_secret' => env('SUPPORT_INBOUND_SECRET'),

    /*
     * The domain inbound mail is accepted for, used to decide whether an
     * inbound message belongs to this deployment before it is correlated.
     * SUPPORT_INBOUND_DOMAIN
     */
    'inbound_domain' => env('SUPPORT_INBOUND_DOMAIN'),

    /*
     * Largest inbound payload accepted, in bytes. The limit is applied before
     * any MIME parsing so an oversized body is refused without being decoded.
     * SUPPORT_INBOUND_MAX_BYTES — default 10 MiB.
     */
    'inbound_max_bytes' => (int) env('SUPPORT_INBOUND_MAX_BYTES', 10_485_760),

    /*
     * Accepted clock difference between the signed timestamp and this server,
     * in seconds. Both an old and a future timestamp outside this window are
     * refused, so a captured signature cannot be replayed indefinitely.
     * SUPPORT_INBOUND_TIMESTAMP_SKEW_SECONDS — default 300 (5 minutes).
     */
    'inbound_timestamp_skew_seconds' => (int) env('SUPPORT_INBOUND_TIMESTAMP_SKEW_SECONDS', 300),

    /*
     * How long a seen nonce is remembered, in seconds. A nonce is claimed only
     * after the signature has been verified, and the TTL bounds how long a
     * valid request may be replayed.
     * SUPPORT_INBOUND_NONCE_TTL_SECONDS — default 300 (5 minutes).
     */
    'inbound_nonce_ttl_seconds' => (int) env('SUPPORT_INBOUND_NONCE_TTL_SECONDS', 300),

    /*
     * Minimum delay, in minutes, between a staff reply and the fallback email
     * that tells the client a human has answered. Zero would send a fallback
     * for every reply, which is the behaviour this exists to prevent.
     * SUPPORT_FALLBACK_DELAY_MINUTES — default 15.
     */
    'fallback_delay_minutes' => (int) env('SUPPORT_FALLBACK_DELAY_MINUTES', 15),
];
