/**
 * A fresh idempotency key for one payment attempt.
 *
 * The key must be unpredictable and unique per attempt, so it comes from the
 * browser's cryptographic source and never from `Math.random`. `randomUUID` is
 * available in secure contexts; the fallback builds an RFC 4122 version 4 UUID
 * from `crypto.getRandomValues` so the value is the same shape everywhere.
 *
 * A retry reuses the key it was first sent with: that is what lets the server
 * tell a retry from a second payment. A new attempt, or an attempt whose
 * identifying data changed, gets a new key.
 */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    // Version 4 (0100) and variant 10xx, as RFC 4122 requires.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}
