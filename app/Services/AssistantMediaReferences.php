<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Resuelve referencias media_id del composer contra el sitio autenticado.
 * Nunca confía en la ruta enviada por el navegador.
 */
final class AssistantMediaReferences
{
    /** @param array<string,mixed> $bundle @return array<string,mixed> */
    public static function resolve(array $bundle, int $siteId): array
    {
        $ids = [];
        foreach (($bundle['media'] ?? []) as $media) {
            $id = (int) ($media['media_id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));

        $owned = [];
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::select(
                'SELECT id, path, alt_text, mime_type, file_size, width, height
                 FROM media WHERE site_id = ? AND id IN (' . $placeholders . ')',
                array_merge([$siteId], $ids)
            );
            foreach ($rows as $row) {
                $path = ltrim((string) ($row['path'] ?? ''), '/');
                if (self::safeOwnedPath($path, $siteId)) {
                    $owned[(int) $row['id']] = $row;
                }
            }
        }

        $resolvedRefs = [];
        foreach ($bundle['media'] as &$media) {
            $id = (int) ($media['media_id'] ?? 0);
            if ($id <= 0) continue;
            $row = $owned[$id] ?? null;
            if ($row === null) {
                unset($media['media_id']);
                $media['status'] = 'needs_review';
                $media['source_kind'] = 'unresolved';
                $media['source'] = '';
                self::warn($bundle, 'invalid_media_reference', (string) ($media['ref'] ?? ''));
                continue;
            }
            $media['media_id'] = $id;
            $media['status'] = 'stored';
            $media['source_kind'] = 'media';
            $media['source'] = '/' . ltrim((string) $row['path'], '/');
            $media['mime'] = (string) $row['mime_type'];
            $media['bytes'] = (int) $row['file_size'];
            $media['width'] = $row['width'] !== null ? (int) $row['width'] : null;
            $media['height'] = $row['height'] !== null ? (int) $row['height'] : null;
            if (trim((string) ($row['alt_text'] ?? '')) !== '') {
                $media['alt'] = trim((string) $row['alt_text']);
            }
            $resolvedRefs[] = (string) ($media['ref'] ?? '');
        }
        unset($media);

        if ($resolvedRefs !== []) {
            $bundle['warnings'] = array_values(array_filter(
                $bundle['warnings'] ?? [],
                static fn (array $warning): bool => !(
                    ($warning['code'] ?? '') === 'unresolved_image'
                    && in_array((string) ($warning['media_ref'] ?? ''), $resolvedRefs, true)
                )
            ));
        }
        $bundle['status'] = ($bundle['blocks'] ?? []) === []
            ? 'rejected'
            : (($bundle['warnings'] ?? []) === [] ? 'ready' : 'partial');
        $bundle['prompt_text'] = self::renderPromptText($bundle);
        return $bundle;
    }

    private static function safeOwnedPath(string $path, int $siteId): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) return false;
        return preg_match('#^storage/uploads/' . preg_quote((string) $siteId, '#') . '/[a-zA-Z0-9._/-]+$#', $path) === 1;
    }

    /** @param array<string,mixed> $bundle */
    private static function warn(array &$bundle, string $code, string $mediaRef): void
    {
        foreach (($bundle['warnings'] ?? []) as $warning) {
            if (($warning['code'] ?? '') === $code && ($warning['media_ref'] ?? '') === $mediaRef) return;
        }
        $bundle['warnings'][] = ['code' => $code, 'media_ref' => $mediaRef];
    }

    /** @param array<string,mixed> $bundle */
    private static function renderPromptText(array $bundle): string
    {
        $mediaByRef = [];
        foreach (($bundle['media'] ?? []) as $media) $mediaByRef[(string) $media['ref']] = $media;
        $lines = [];
        foreach (($bundle['blocks'] ?? []) as $block) {
            $meta = (string) $block['type'];
            if ($meta === 'heading') {
                $meta .= ' level=' . (int) $block['level'];
            } elseif ($meta === 'list_item') {
                $meta .= ' depth=' . (int) $block['depth'] . ' list=' . (string) $block['list_kind'];
            } elseif ($meta === 'image') {
                $ref = (string) $block['media_ref'];
                $media = $mediaByRef[$ref] ?? [];
                $meta .= ' ' . $ref . ' status=' . ($media['status'] ?? 'rejected');
                if (isset($media['media_id'])) $meta .= ' media_id=' . (int) $media['media_id'];
                if (($media['source_kind'] ?? '') === 'media') $meta .= ' path=' . (string) $media['source'];
            }
            $text = (string) ($block['text'] ?? '');
            $lines[] = '[' . $block['id'] . ' ' . $meta . ']' . ($text !== '' ? ' ' . $text : '');
        }
        return implode("\n", $lines);
    }
}
