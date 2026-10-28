<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ASST-CONV AC7 — memoria de la conversación del Asistente.
 *
 * La conversación vive en el navegador mientras la pestaña está abierta (sin
 * tablas): en cada mensaje llegan los turnos anteriores, el plan pendiente y
 * el token firmado del material ya adjuntado. Aquí se acota lo que llega del
 * navegador y se fusiona el material de varios turnos sin cambiar los ids
 * (B#, IMG-#) que el plan vigente ya cita.
 */
final class AssistantConversation
{
    private const MAX_TURNS = 10;
    private const MAX_TURN_CHARS = 1500;
    private const MAX_PLAN_ITEMS = 20;
    private const MAX_INSTRUCTION_CHARS = 1500;
    // Los mismos topes que aplica AssistantSourceEnvelope al firmar.
    private const MAX_MEDIA = 8;
    private const MAX_BLOCKS = 600;
    private const MAX_CHARS = 60000;

    /** @return array<int,array{role:string,text:string}> */
    public static function parseHistory(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [];
        }
        $turns = [];
        foreach ($raw as $turn) {
            if (!is_array($turn) || !in_array($turn['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $turns[] = ['role' => (string) $turn['role'], 'text' => mb_substr($text, 0, self::MAX_TURN_CHARS)];
        }
        return array_slice($turns, -self::MAX_TURNS);
    }

    /** @return array<int,array<string,mixed>> */
    public static function parseCurrentPlan(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [];
        }
        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $instruction = trim((string) ($item['instruction'] ?? ''));
            if ($instruction === '') {
                continue;
            }
            $mediaIds = [];
            foreach (is_array($item['media_ids'] ?? null) ? $item['media_ids'] : [] as $id) {
                if ((is_int($id) || ctype_digit((string) $id)) && (int) $id > 0 && !in_array((int) $id, $mediaIds, true)) {
                    $mediaIds[] = (int) $id;
                }
            }
            $blockIds = [];
            foreach (is_array($item['source_block_ids'] ?? null) ? $item['source_block_ids'] : [] as $id) {
                if (preg_match('/^B[1-9][0-9]*$/', (string) $id) === 1 && !in_array((string) $id, $blockIds, true)) {
                    $blockIds[] = (string) $id;
                }
            }
            $entry = [
                'page_id' => (int) ($item['page_id'] ?? 0),
                'page_title' => mb_substr(trim((string) ($item['page_title'] ?? '')), 0, 120),
                'section' => mb_substr(trim((string) ($item['section'] ?? '')), 0, 80),
                'capability_id' => mb_substr(trim((string) ($item['capability_id'] ?? '')), 0, 60),
                'category' => mb_substr(trim((string) ($item['category'] ?? '')), 0, 40),
                'instruction' => mb_substr($instruction, 0, self::MAX_INSTRUCTION_CHARS),
                'media_ids' => array_slice($mediaIds, 0, self::MAX_MEDIA),
                'source_block_ids' => array_slice($blockIds, 0, 120),
            ];
            // ASST-FORMS — sin form_id el plan vigente olvidaba a qué formulario
            // iba un cambio en cuanto el usuario seguía hablando.
            if ((int) ($item['form_id'] ?? 0) > 0) {
                $entry['form_id'] = (int) $item['form_id'];
            }
            $items[] = $entry;
            if (count($items) >= self::MAX_PLAN_ITEMS) {
                break;
            }
        }
        return $items;
    }

    /**
     * Bloque para el prompt del planificador. Vacío en el primer mensaje.
     *
     * @param array<int,array{role:string,text:string}> $history
     * @param array<int,array<string,mixed>> $plan
     */
    public static function renderForPrompt(array $history, array $plan): string
    {
        if ($history === [] && $plan === []) {
            return '';
        }
        // i18n-ignore-start: bloque que viaja dentro del prompt, no interfaz.
        $out = '';
        if ($history !== []) {
            $lines = [];
            foreach ($history as $turn) {
                $lines[] = ($turn['role'] === 'user' ? 'Operador: ' : 'Asistente: ') . $turn['text'];
            }
            $out .= "CONVERSACIÓN PREVIA (contexto; lo que pide el último mensaje manda):\n" . implode("\n", $lines) . "\n\n";
        }
        if ($plan !== []) {
            $out .= "PLAN VIGENTE (propuesto y aún sin aplicar):\n"
                . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }
        // i18n-ignore-end
        return $out;
    }

    /**
     * Fusiona el material de turnos anteriores con el nuevo. Lo anterior
     * conserva sus ids; lo nuevo se numera a continuación. Si no cabe todo, se
     * descarta primero lo más antiguo: el mensaje nuevo siempre entra.
     *
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed>|null $new
     * @return array<string,mixed>|null
     */
    public static function mergeBundles(?array $previous, ?array $new): ?array
    {
        if ($previous === null || ((array) ($previous['blocks'] ?? [])) === []) {
            return $new;
        }
        if ($new === null) {
            return $previous;
        }

        $media = array_values(array_filter((array) ($previous['media'] ?? []), 'is_array'));
        $refByMediaId = [];
        $maxRef = 0;
        foreach ($media as $entry) {
            $maxRef = max($maxRef, self::number((string) ($entry['ref'] ?? ''), 'IMG-'));
            if ((int) ($entry['media_id'] ?? 0) > 0) {
                $refByMediaId[(int) $entry['media_id']] = (string) $entry['ref'];
            }
        }
        $newRefMap = [];
        foreach ((array) ($new['media'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $oldRef = (string) ($entry['ref'] ?? '');
            $mediaId = (int) ($entry['media_id'] ?? 0);
            if ($mediaId > 0 && isset($refByMediaId[$mediaId])) {
                $newRefMap[$oldRef] = $refByMediaId[$mediaId];
                continue;
            }
            $maxRef++;
            $entry['ref'] = 'IMG-' . $maxRef;
            $newRefMap[$oldRef] = $entry['ref'];
            if ($mediaId > 0) {
                $refByMediaId[$mediaId] = $entry['ref'];
            }
            $media[] = $entry;
        }
        // Tope de imágenes: fuera las más antiguas.
        $media = array_slice($media, -self::MAX_MEDIA);
        $keptRefs = array_fill_keys(array_map(static fn (array $m): string => (string) ($m['ref'] ?? ''), $media), true);

        $previousBlocks = array_values(array_filter((array) ($previous['blocks'] ?? []), 'is_array'));
        $maxBlock = 0;
        foreach ($previousBlocks as $block) {
            $maxBlock = max($maxBlock, self::number((string) ($block['id'] ?? ''), 'B'));
        }
        $newBlocks = [];
        foreach ((array) ($new['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $maxBlock++;
            $block['id'] = 'B' . $maxBlock;
            if (isset($block['media_ref'])) {
                $block['media_ref'] = $newRefMap[(string) $block['media_ref']] ?? (string) $block['media_ref'];
            }
            $newBlocks[] = $block;
        }

        // Lo nuevo entra entero; de lo anterior, lo más reciente que quepa.
        $budgetChars = self::MAX_CHARS - self::chars($newBlocks);
        $budgetBlocks = self::MAX_BLOCKS - count($newBlocks);
        $keptPrevious = [];
        for ($i = count($previousBlocks) - 1; $i >= 0; $i--) {
            $length = mb_strlen((string) ($previousBlocks[$i]['text'] ?? ''));
            if ($length > $budgetChars || $budgetBlocks <= 0) {
                break;
            }
            $budgetChars -= $length;
            $budgetBlocks--;
            array_unshift($keptPrevious, $previousBlocks[$i]);
        }

        $blocks = [];
        foreach (array_merge($keptPrevious, $newBlocks) as $block) {
            if (isset($block['media_ref']) && !isset($keptRefs[(string) $block['media_ref']])) {
                unset($block['media_ref']);
            }
            $blocks[] = $block;
        }

        return [
            'status' => ($new['status'] ?? '') === 'ready' && ($previous['status'] ?? '') === 'ready' ? 'ready' : 'partial',
            'blocks' => $blocks,
            'media' => $media,
            'warnings' => array_values(array_merge((array) ($previous['warnings'] ?? []), (array) ($new['warnings'] ?? []))),
        ];
    }

    private static function number(string $id, string $prefix): int
    {
        return str_starts_with($id, $prefix) && ctype_digit(substr($id, strlen($prefix))) ? (int) substr($id, strlen($prefix)) : 0;
    }

    /** @param array<int,array<string,mixed>> $blocks */
    private static function chars(array $blocks): int
    {
        $total = 0;
        foreach ($blocks as $block) {
            $total += mb_strlen((string) ($block['text'] ?? ''));
        }
        return $total;
    }
}
