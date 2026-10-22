<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a response from putting an **old** session back in the browser.
 *
 * ## The race this exists for
 *
 * Laravel re-sends the session cookie on **every** response that touched the
 * session, with the id that request was using. That is normally invisible,
 * because the browser already holds that same cookie.
 *
 * It stops being invisible the moment a request **regenerates** the session —
 * and signing in regenerates it, deliberately, because that is what defeats
 * session fixation.
 *
 * So this happens:
 *
 *   1. the browser holds session S1 (a colleague's, or an anonymous one);
 *   2. the sign in page's own `GET /api/auth/me` is still in flight, carrying S1;
 *   3. the sign in regenerates: S2 replaces S1, and S1 is destroyed;
 *   4. the in-flight request answers, and its response re-sends the cookie
 *      with **S1**;
 *   5. the browser takes S1 back — the session the sign in just created is
 *      gone, and the browser is the person it was before.
 *
 * The user sees "I signed in and it threw me back to the login screen", and
 * nobody can say why: the server's answer was a 200 and the session worked. The
 * A06 journey saw it as a portal client being refused by the portal's own
 * endpoint, which is what a stale session looks like from the outside.
 *
 * ## Where this has to sit, and why
 *
 * The cookie is put on the response by `StartSession`, **after** the middleware
 * inside it have run:
 *
 *     EncryptCookies ─ decrypts the request, encrypts the response
 *       └ StartSession ─ reads the session, runs the request, adds the cookie
 *           └ … the middleware inside StartSession …
 *
 * So a middleware placed **inside** `StartSession` inspects the response *before*
 * the cookie exists on it, and cannot remove it. A middleware placed **outside**
 * `StartSession` sees the response with the cookie already on it, and the cookie
 * is still encrypted there — because `EncryptCookies` encrypts the response on
 * its way out.
 *
 * This class therefore **replaces** `EncryptCookies` in the `web` group rather
 * than joining it: it has to be the outermost of the two to see the encrypted
 * response cookie, and it has to be able to decrypt it to compare it with what
 * the request arrived with. Extending `EncryptCookies` gives it both, and it
 * keeps the framework's own behaviour — including the list of cookies it does
 * not encrypt — exactly as it was.
 *
 * ## The rule
 *
 * A cookie whose value is **exactly** the one the request arrived with is
 * already in the browser: sending it again changes nothing. Except when the
 * browser has moved on — which is the race above — and then sending it undoes
 * what happened in between.
 *
 * So the response does not carry it. The browser keeps what it has, which is
 * the truth: the newest session.
 *
 * ## What it does not touch
 *
 *  - A response that carries a **different** id — the sign in, or a logout, or
 *    the first request of a browser that had no cookie — is sent untouched, and
 *    that is where the new session reaches the browser.
 *  - The session is still regenerated on sign in. The defence against session
 *    fixation is not weakened; what changes is that a stale response can no
 *    longer take it away.
 *
 * ## What it costs, stated plainly
 *
 * A response that repeats the same id no longer refreshes the **cookie's
 * expiry**: the browser keeps the cookie it already has, with the expiry it
 * was given when the session was created. So the session a person holds is
 * bounded by `session.lifetime` counted from the last time the id changed —
 * that is, from the sign in — rather than sliding forward with every request.
 *
 * That is the same trade every framework that does not re-send the session
 * cookie on every response makes, and it is the price of removing the race:
 * the server cannot tell a current response from a late one, so the only
 * rule it can apply is "do not repeat what the browser already holds". The
 * server-side session still expires on its own idle timeout
 * (`session.lifetime` of inactivity), so the server never trusts a session
 * longer than it did before.
 */
final class PreserveSessionCookies extends EncryptCookies
{
    /**
     * The cookies whose value is the session's identity.
     *
     * The session cookie carries the session id, and the CSRF cookie carries
     * the token that lives inside that session. Both are re-sent by every
     * response, and both have to move together: a browser holding the new
     * session with the old token would be refused with a 419 on its next
     * write.
     *
     * @return list<string>
     */
    private function guardedCookieNames(): array
    {
        return array_values(array_filter([
            $this->getSessionCookieName(),
            (string) config('sanctum.csrf_cookie', 'XSRF-TOKEN'),
        ]));
    }

    private function getSessionCookieName(): string
    {
        return (string) config('session.cookie', config('app.name', 'laravel_session'));
    }

    public function handle($request, Closure $next)
    {
        // What the browser sent, exactly as it sent it: still encrypted, because
        // the parent has not touched the request yet. Reading it here — and
        // decrypting it below with the framework's own methods — makes the
        // comparison independent of how the parent's own decryption goes.
        $arrived = [];

        if ($request instanceof Request) {
            foreach ($this->guardedCookieNames() as $name) {
                $value = $request->cookies->get($name);

                if (is_string($value) && $value !== '') {
                    $arrived[$name] = $value;
                }
            }
        }

        // The parent's job: decrypt the request's cookies and encrypt the
        // response's. Everything the framework does with cookies stays exactly
        // as it was.
        $response = parent::handle($request, $next);

        if (! $request instanceof Request) {
            return $response;
        }

        foreach ($this->guardedCookieNames() as $name) {
            $this->removeRepeatedCookie($arrived[$name] ?? null, $response, $name);
        }

        return $response;
    }

    /**
     * Drop the cookie from the response when it repeats what the browser sent.
     *
     * @param  string|null  $arrived  the raw value the request carried
     */
    private function removeRepeatedCookie(?string $arrived, Response $response, string $name): void
    {
        if ($arrived === null) {
            return;
        }

        // The value the browser holds, read the same way on both sides.
        $held = $this->plainValue($name, $arrived);

        if ($held === null) {
            return;
        }

        $cookie = null;

        foreach ($response->headers->getCookies() as $candidate) {
            if ($candidate->getName() === $name) {
                $cookie = $candidate;

                break;
            }
        }

        if ($cookie === null) {
            return;
        }

        // What the response carries. It is encrypted, because the parent
        // encrypted it on the way out.
        $sent = $this->plainValue($name, $cookie->getValue());

        if ($sent === null || $sent !== $held) {
            return;
        }

        $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
    }

    /**
     * The value of a cookie as the browser sent it, encrypted or not.
     *
     * `decryptCookie` answers the value **with the framework's prefix on it**;
     * `validateValue` is what takes the prefix off. Both are the framework's own
     * methods, so the comparison is made on exactly the value the browser holds.
     */
    private function plainValue(string $name, string $value): ?string
    {
        try {
            $plain = $this->validateValue($name, $this->decryptCookie($name, $value));
        } catch (\Throwable) {
            // The cookie was not encrypted: the framework leaves some cookies
            // alone, and those are compared as they are.
            return $value;
        }

        return is_string($plain) ? $plain : null;
    }
}
