<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrivacyController
{
    #[Route('/privacy', name: 'privacy_policy', methods: ['GET'])]
    public function __invoke(): Response
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loop 9 Privacy Policy</title>
    <style>
        :root { color-scheme: dark; font-family: system-ui, sans-serif; }
        body { margin: 0; background: #10110f; color: #e8e5dc; line-height: 1.65; }
        main { width: min(760px, calc(100% - 40px)); margin: 0 auto; padding: 56px 0 80px; }
        h1, h2 { color: #fff; line-height: 1.2; }
        h1 { margin-bottom: 4px; }
        h2 { margin-top: 36px; font-size: 1.25rem; }
        .meta { color: #aaa79f; margin-top: 0; }
        a { color: #ded27e; }
        li { margin: 8px 0; }
        code { color: #ded27e; }
    </style>
</head>
<body>
<main>
    <h1>Loop 9 Privacy Policy</h1>
    <p class="meta">Effective date: July 17, 2026</p>

    <p>This policy explains how Loop 9 processes information when you use its Steam authentication,
    live AI conversations, anonymous run telemetry, and optional Steam Cloud synchronization.</p>

    <h2>1. Data controller and contact</h2>
    <p>Loop 9 is independently developed and published by Andrija Stanisic. Privacy and support
    questions can be sent to <a href="mailto:andrijanstanisic321@gmail.com">andrijanstanisic321@gmail.com</a>.</p>

    <h2>2. Information processed</h2>
    <ul>
        <li><strong>Steam authentication:</strong> a Steam authentication ticket and SteamID64 are
        processed to verify that a request comes from a Steam user. The ticket is used for verification
        and is not stored by Loop 9. A signed session token normally expires within 12 hours.</li>
        <li><strong>AI conversations:</strong> the message you type, preferred language, and limited
        game-state context are processed to generate Dragojlo's reply. This context may include a
        bounded list of recent actions such as entering an area, inspecting an object, using a door,
        or toggling the flashlight; it excludes coordinates and movement paths. Loop 9 does not
        maintain a conversation-history database and does not write chat text to application logs.</li>
        <li><strong>Safety checks:</strong> messages are scanned locally for personal data
        (email/phone-like text) and requests to reproduce copyrighted works, then checked
        with a third-party moderation API for illegal and adult-sexual categories.
        Insults and dark in-fiction talk are allowed so the character can react.</li>
        <li><strong>Technical and security data:</strong> request identifiers, response status,
        timing information, hashed player/IP-derived identifiers, quota counters, and error categories
        are processed for security, abuse prevention, reliability, and cost control. Infrastructure
        providers may also process network addresses in their own access logs.</li>
        <li><strong>Anonymous run telemetry:</strong> ending type, reset count, AI interaction count,
        game build version, and aggregate AI-advice outcomes (whether a suggested area was checked,
        time-to-check, contradiction state, and lift-advice/follow counts) may be recorded for balancing
        and reliability analysis. This telemetry does not include chat content, coordinates, movement
        paths, or area names.</li>
        <li><strong>Steam Cloud:</strong> if enabled, Steam may synchronize local progression and
        settings files such as <code>Game.ini</code> and <code>GameUserSettings.ini</code>.</li>
    </ul>

    <h2>3. Why the information is used</h2>
    <p>Information is processed to provide requested game features, authenticate Steam sessions,
    generate and moderate AI dialogue, preserve optional cloud progress, prevent abuse, diagnose
    failures, balance the game, and comply with legal obligations. Where applicable, the legal bases
    are performance of the game service and legitimate interests in security and service reliability.</p>

    <h2>4. Service providers</h2>
    <ul>
        <li><strong>Valve/Steam:</strong> authentication, ownership platform, achievements, and optional Steam Cloud.</li>
        <li><strong>Groq:</strong> primary generation of short text dialogue.</li>
        <li><strong>OpenAI:</strong> fallback text generation and input/output safety moderation.</li>
        <li><strong>Render and its infrastructure services:</strong> backend hosting, operational logs, and rate-limit storage.</li>
    </ul>
    <p>These providers process information under their own terms and privacy policies. Information may
    be processed outside your country, subject to the safeguards offered by the relevant provider.</p>

    <h2>5. Retention</h2>
    <ul>
        <li>Loop 9 does not persist chat conversations in an application database.</li>
        <li>Session tokens expire automatically, normally within 12 hours.</li>
        <li>Rate-limit counters expire automatically, with the longest normal window being approximately 30 days.</li>
        <li>Operational and anonymous telemetry logs are retained only as long as reasonably necessary
        for security, debugging, balancing, and legal obligations, normally no longer than 30 days.</li>
        <li>Steam Cloud files remain subject to the player's Steam Cloud settings and Valve's retention practices.</li>
    </ul>

    <h2>6. Sharing and sale</h2>
    <p>Loop 9 does not sell personal information, serve targeted advertising, or share chat messages
    with other players. Information is shared only with the service providers needed to operate the
    features described above, or when legally required.</p>

    <h2>7. Your choices and rights</h2>
    <p>You may avoid AI processing by not using the in-game AI conversation feature. Steam Cloud can be
    controlled through Steam. Depending on your location, you may request access, correction, deletion,
    restriction, or objection by contacting the email above. Because Loop 9 intentionally stores little
    identifying information, some records may not be technically linkable to an individual request.</p>

    <h2>8. Children</h2>
    <p>Loop 9 is not directed to children under 13. Users must meet Steam's minimum age requirements.</p>

    <h2>9. Security and policy changes</h2>
    <p>Reasonable technical and organizational safeguards are used, including encrypted transport,
    short-lived signed sessions, rate limits, content moderation, and minimized logs. No system can
    guarantee absolute security. Material policy updates will be reflected on this page with a new
    effective date.</p>
</main>
</body>
</html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
