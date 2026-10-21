<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
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
 *   2. the login page's own `GET /api/auth/me` is still in flight, carrying S1;
 *   3. the sign in regenerates: S2 replaces S1, and S1 is destroyed;
 *   4. the in-flight request answers, and its response re-sends the cookie
 *      with **S1**;
 *   5. the browser takes S1 back — the session the sign in just created is
 *      gone, and the browser is the person it was before.
 *
 * The user sees "I signed in and it threw me back to the login screen", and
 * nobody can say why: the server's answer was a 200 and the session worked.
 * The A06 journey saw it as a portal client being refused by the portal's own
 * endpoint, which is what a stale session looks like from the outside.
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
 */
final class PreserveSessionCookies
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
    private function guardedCookies(Request $request): array
    {
        return array_values(array_filter([
            $request->session()->getName(),
            (string) config('sanctum.csrf_cookie', 'XSRF-TOKEN'),
        ]));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guarded = $this->guardedCookies($request);

        // What the browser sent, and therefore what it already holds.
        $arrived = [];

        foreach ($guarded as $name) {
            $value = $request->cookies->get($name);

            if (is_string($value) && $value !== '') {
                $arrived[$name] = $value;
            }
        }

        $response = $next($request);

        foreach ($arrived as $name => $value) {
            $this->removeUnchangedCookie($response, $name, $value);
        }

        return $response;
    }

    /**
     * Drop the cookie from the response when it repeats what the browser sent.
     */
    private function removeUnchangedCookie(Response $response, string $name, string $value): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie instanceof Cookie) {
                continue;
            }

            if ($cookie->getName() !== $name || $cookie->getValue() !== $value) {
                continue;
            }

            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());

            return;
        }
    }
}
