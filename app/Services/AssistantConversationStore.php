<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * ASST-V2 F1 — Conversaciones guardadas del asistente.
 *
 * El navegador sigue llevando la conversación (AssistantConversation acota lo
 * que viaja al planner); aquí solo se guarda una foto para poder recargar o
 * retomarla otro día. Cada conversación es de UNA persona en UN sitio: nadie
 * ve las conversaciones de otro.
 *
 * Lo que se guarda se vuelve a pintar con textContent y lo que se vuelve a
 * aplicar pasa otra vez por SiteAssistantJobs::createJob (registro, huella y
 * página), así que una foto manipulada no da más poder que el navegador.
 */
final class AssistantConversationStore
{
    public const RETENTION_DAYS = 30;
    /** Conversación que se reabre sola al entrar al asistente. */
    public const RESUME_DAYS = 7;

    private const MAX_LOG_ENTRIES = 80;
    private const MAX_TEXT = 4000;
    private const MAX_PLAN_BYTES = 120000;
    private const MAX_LOG_BYTES = 1500000;
    private const MAX_TOKEN_BYTES = 180000;
    private const LOG_TYPES = ['user', 'assistant', 'plan', 'note', 'error'];

    /**
     * Guarda (crea o actualiza) la foto de una conversación. Devuelve su id, o
     * null si el id no es de esta persona en este sitio.
     *
     * @param array<string,mixed> $snapshot {state:{turns,plan,source_token}, log:[]}
     */
    public static function save(int $siteId, ?int $userId, ?int $id, array $snapshot): ?int
    {
        $clean = self::sanitize($snapshot);
        $state = json_encode($clean['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $log = json_encode($clean['log'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($state === false || $log === false) {
            return null;
        }

        if ($id !== null && $id > 0) {
            if (self::find($siteId, $userId, $id) === null) {
                return null;
            }
            Database::execute(
                'UPDATE assistant_conversations SET title = ?, state_json = ?, log_json = ?, updated_at = NOW()
                 WHERE id = ? AND site_id = ? AND user_id <=> ?',
                [$clean['title'], $state, $log, $id, $siteId, $userId]
            );
            return $id;
        }

        self::prune($siteId);
        Database::execute(
            'INSERT INTO assistant_conversations (site_id, user_id, title, state_json, log_json) VALUES (?, ?, ?, ?, ?)',
            [$siteId, $userId, $clean['title'], $state, $log]
        );
        return (int) Database::lastInsertId();
    }

    /** @return array{id:int,title:string,state:array<string,mixed>,log:array<int,mixed>,updated_at:string}|null */
    public static function find(int $siteId, ?int $userId, int $id): ?array
    {
        $row = Database::selectOne(
            'SELECT id, title, state_json, log_json, updated_at FROM assistant_conversations
             WHERE id = ? AND site_id = ? AND user_id <=> ? LIMIT 1',
            [$id, $siteId, $userId]
        );
        return $row === null ? null : self::hydrate($row);
    }

    /** Última conversación tocada en los últimos días (la que se reabre sola). */
    public static function latest(int $siteId, ?int $userId): ?array
    {
        $row = Database::selectOne(
            'SELECT id, title, state_json, log_json, updated_at FROM assistant_conversations
             WHERE site_id = ? AND user_id <=> ? AND updated_at >= NOW() - INTERVAL ' . self::RESUME_DAYS . ' DAY
             ORDER BY updated_at DESC, id DESC LIMIT 1',
            [$siteId, $userId]
        );
        return $row === null ? null : self::hydrate($row);
    }

    /** @return array<int,array{id:int,title:string,updated_at:string}> */
    public static function recent(int $siteId, ?int $userId, int $limit = 12): array
    {
        $rows = Database::select(
            'SELECT id, title, updated_at FROM assistant_conversations
             WHERE site_id = ? AND user_id <=> ?
             ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)),
            [$siteId, $userId]
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'updated_at' => (string) $r['updated_at'],
        ], $rows);
    }

    public static function delete(int $siteId, ?int $userId, int $id): bool
    {
        return Database::execute(
            'DELETE FROM assistant_conversations WHERE id = ? AND site_id = ? AND user_id <=> ?',
            [$id, $siteId, $userId]
        ) > 0;
    }

    /** Borra lo que pasó de la retención. Se llama al crear una conversación. */
    public static function prune(int $siteId): void
    {
        try {
            Database::execute(
                'DELETE FROM assistant_conversations WHERE site_id = ? AND updated_at < NOW() - INTERVAL ' . self::RETENTION_DAYS . ' DAY',
                [$siteId]
            );
        } catch (\Throwable $e) {
            error_log('[AssistantConversationStore] prune: ' . $e->getMessage());
        }
    }

    /**
     * Acota la foto que manda el navegador. Pura: se prueba sin BD.
     *
     * @param array<string,mixed> $snapshot
     * @return array{title:string,state:array<string,mixed>,log:array<int,array<string,mixed>>}
     */
    public static function sanitize(array $snapshot): array
    {
        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];
        $turns = AssistantConversation::parseHistory(json_encode($state['turns'] ?? []) ?: '[]');
        $plan = is_array($state['plan'] ?? null) ? self::cleanPlan($state['plan']) : null;
        $token = is_string($state['source_token'] ?? null) && strlen($state['source_token']) <= self::MAX_TOKEN_BYTES
            && preg_match('/^[A-Za-z0-9._-]*$/', $state['source_token']) === 1
            ? $state['source_token'] : '';

        $log = [];
        $bytes = 0;
        $rawLog = is_array($snapshot['log'] ?? null) ? array_values($snapshot['log']) : [];
        foreach (array_slice($rawLog, -self::MAX_LOG_ENTRIES) as $entry) {
            if (!is_array($entry) || !in_array($entry['type'] ?? null, self::LOG_TYPES, true)) {
                continue;
            }
            $clean = ['type' => (string) $entry['type']];
            if ($clean['type'] === 'plan') {
                $entryPlan = is_array($entry['plan'] ?? null) ? self::cleanPlan($entry['plan']) : null;
                if ($entryPlan === null) continue;
                $clean['plan'] = $entryPlan;
            } else {
                $text = trim((string) ($entry['text'] ?? ''));
                if ($text === '') continue;
                $clean['text'] = mb_substr($text, 0, self::MAX_TEXT);
            }
            $bytes += strlen((string) json_encode($clean));
            if ($bytes > self::MAX_LOG_BYTES) break;
            $log[] = $clean;
        }

        $title = '';
        foreach ($log as $entry) {
            if ($entry['type'] === 'user') {
                $title = trim((string) preg_replace('/\s+/u', ' ', $entry['text']));
                break;
            }
        }

        return [
            'title' => mb_substr($title, 0, 120),
            'state' => ['turns' => $turns, 'plan' => $plan, 'source_token' => $token],
            'log' => $log,
        ];
    }

    /** Un plan tal como lo devolvió /plan, sin las claves internas del navegador. */
    private static function cleanPlan(array $plan): ?array
    {
        unset($plan['_thumbs']);
        $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_PLAN_BYTES || !is_array($plan['items'] ?? [])) {
            return null;
        }
        return $plan;
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $state = json_decode((string) ($row['state_json'] ?? ''), true);
        $log = json_decode((string) ($row['log_json'] ?? ''), true);
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'state' => is_array($state) ? $state : [],
            'log' => is_array($log) ? $log : [],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
