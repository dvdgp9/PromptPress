<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Security\BotGuard;
use Core\Response;

/**
 * FEAT-4 AB3 — Endpoint público del reto proof-of-work.
 *
 * POST /_botguard/challenge → 200 JSON {challenge, salt, bits, expires}.
 * Stateless y sin CSRF (mismo criterio que /_analytics/collect): emitir un
 * reto cuesta un HMAC y no toca BD, así que no compensa un rate limit con
 * lookup en BD — sería más caro que lo que protege. El coste real está en
 * RESOLVER el reto (cliente) y el anti-replay ya impide reutilizar soluciones.
 */
final class BotGuardController
{
    public function challenge(array $params = []): void
    {
        Response::json(BotGuard::issueChallenge());
    }
}
