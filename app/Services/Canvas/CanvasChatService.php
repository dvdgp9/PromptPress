<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\ImageBankService;
use Core\Database;

/**
 * FEAT-5 F5-T4 — Pipeline de edición conversacional de una página canvas,
 * extraído de CanvasController::chat() para poder reutilizarlo desde el
 * asistente central (jobs multi-página) sin duplicar lógica.
 *
 * Entrada: página + instrucción (+sección opcional). Salida: cambio aplicado
 * y GUARDADO como versión draft (CanvasService::save), o un error de negocio
 * como array (['ok'=>false,'error'=>...,'http'=>...]).
 *
 * Los errores del proveedor de IA suben como AIException: cada caller decide
 * su mensaje de cara al usuario.
 */
final class CanvasChatService
{
    /**
     * Aplica una instrucción de chat sobre una página canvas y guarda versión.
     *
     * @param array<string,mixed> $page Fila de `pages` (id, title, ...)
     * @return array{ok:bool, error?:string, http?:int, reply?:string, summary?:string, saved?:array{html:string,css:string,warnings:array<int,string>}}
     * @throws \App\Services\AI\AIException
     */
    public static function applyInstruction(
        int $siteId,
        array $page,
        string $instruction,
        string $sectionId = '',
        string $elementContext = '',
        string $origin = 'chat',
        string $summaryPrefix = ''
    ): array {
        $pageId = (int) $page['id'];

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            return ['ok' => false, 'error' => 'Esta página aún no tiene contenido canvas.', 'http' => 404];
        }

        $requiresImages = self::requestsImages($instruction);
        if ($requiresImages) {
            $prepared = self::prepareRequestedImages($siteId, (string) $page['title'], $instruction);
            // Si Unsplash falla pero el sitio YA tiene imágenes en su biblioteca,
            // no bloqueamos: la IA puede usar esas (van en `available_images`).
            // Solo bloqueamos si no hay ninguna imagen utilizable en absoluto.
            if (!$prepared['ok'] && !self::hasLibraryImages($siteId)) {
                return ['ok' => false, 'error' => (string) $prepared['error'], 'http' => 503];
            }
        }

        // Añadir/insertar una sección NUEVA es un cambio estructural de PÁGINA,
        // no de una sección: el editor de sección solo reemplaza la sección
        // elegida (descartaría la nueva → "no creó nada"). Se enruta a página,
        // usando la sección seleccionada como referencia de posición.
        $wantsNewSection = self::requestsNewSection($instruction);

        $effectiveInstruction = $instruction;
        if (!$wantsNewSection && $sectionId !== '' && $elementContext !== '') {
            $effectiveInstruction .= "\n\nElemento concreto seleccionado por el usuario: " . mb_substr($elementContext, 0, 240) . '. Aplica el cambio a ese elemento, no al conjunto de la sección.';
        }
        if ($wantsNewSection && $sectionId !== '') {
            $effectiveInstruction .= "\n\nUbica el cambio respecto a la sección de referencia \"" . self::sectionLabel($sectionId) . "\" (data-pp-section=\"" . $sectionId . "\"). A la sección NUEVA dale un data-pp-section único y descriptivo; conserva intactas todas las demás secciones.";
        }
        // Peticiones de imagen: distinguimos fondo (CSS, sin reescribir HTML) de
        // contenido (<img> en el HTML). Reescribir secciones con ilustraciones SVG
        // grandes solo para una imagen de FONDO es lento y trunca: con CSS es
        // instantáneo. La verificación posterior cuenta imágenes en HTML y en CSS.
        if ($requiresImages) {
            $effectiveInstruction .= "\n\nHay imágenes disponibles para esta petición. Si es una imagen de FONDO, aplícala con CSS (`background-image: url(...)` apuntando a una ruta de las imágenes disponibles) sobre la sección o el elemento, y deja \"html\":\"\" (NO reescribas el HTML, sobre todo si hay ilustraciones o SVG). Si la imagen forma parte del CONTENIDO (una foto dentro del texto), devuelve el HTML con la etiqueta <img>.";
        }

        if ($sectionId !== '' && !$wantsNewSection) {
            $result = self::applySectionEdit($siteId, $page, $canvas, $sectionId, $effectiveInstruction);
        } else {
            $result = self::applyPageEdit($siteId, $page, $canvas, $effectiveInstruction);
        }

        if ($requiresImages) {
            // Rechazamos solo si el resultado se queda SIN ninguna imagen (la IA
            // ignoró la petición). No exigimos que aumente el número: mover una
            // imagen de contenido a fondo, o reemplazarla, mantiene el total y es
            // un cambio válido. Contamos en HTML y en CSS (background-image).
            $resultScope = $sectionId !== '' ? CanvasService::extractSection($result['html'], $sectionId) : $result['html'];
            $afterImageCount = self::imageCount((string) $resultScope) + self::imageCount((string) $result['css']);
            if ($afterImageCount === 0) {
                error_log('[canvas chat] page=' . $pageId . ' image_request_not_applied section=' . ($sectionId !== '' ? $sectionId : 'page'));
                return [
                    'ok' => false,
                    'error' => 'No he podido incorporar ninguna imagen, así que no he guardado el cambio. Prueba de nuevo cuando el servicio de imágenes esté disponible.',
                    'http' => 422,
                ];
            }
        }

        $scope = $sectionId !== '' ? self::sectionLabel($sectionId) : 'Toda la página';
        $summary = ($summaryPrefix !== '' ? $summaryPrefix . ' — ' : '') . $scope . ' — ' . mb_substr($instruction, 0, 90);
        $saved = CanvasService::save($pageId, $result['html'], $result['css'], $origin, $summary);

        return [
            'ok'      => true,
            'reply'   => $result['reply'],
            'summary' => $summary,
            'saved'   => $saved,
        ];
    }

    /** Etiqueta legible de una sección ("cta-final" → "Cta final"). */
    public static function sectionLabel(string $id): string
    {
        $s = trim(str_replace(['-', '_'], ' ', $id));
        return $s !== '' ? mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1) : 'Sección';
    }

    // ==================================================================
    // Internals (movidos tal cual de CanvasController — FH3/FH7)
    // ==================================================================

    /** @return array{html:string,css:string,reply:string} */
    private static function applySectionEdit(int $siteId, array $page, array $canvas, string $sectionId, string $instruction): array
    {
        $sectionHtml = CanvasService::extractSection($canvas['html'], $sectionId);
        if ($sectionHtml === null) {
            throw new \RuntimeException('Sección no encontrada: ' . $sectionId);
        }

        $result = AIActionRunner::run(Actions::EDIT_CANVAS_SECTION, [
            'instruction' => $instruction,
            'section_html' => $sectionHtml,
            'page_css' => mb_substr($canvas['css'], 0, 14000),
            'page_title' => (string) $page['title'],
            'language' => 'es',
            'available_images' => self::availableImages($siteId),
            'modules_hint' => CanvasService::modulesHint($siteId),
        ], $siteId);

        $data = self::parseEditEnvelope((string) ($result['data'] ?? ''));
        // Cambio solo de estilo: el modelo deja "html" vacío y manda únicamente
        // css_append. Conservamos la sección original intacta (no reescribir el
        // HTML protege ilustraciones SVG y evita truncados en secciones grandes).
        $newSectionHtml = trim((string) ($data['html'] ?? ''));
        if ($newSectionHtml === '') {
            $newHtml = $canvas['html'];
        } else {
            $newHtml = CanvasService::replaceSection($canvas['html'], $sectionId, $newSectionHtml);
            if ($newHtml === null) {
                throw new \RuntimeException('No se pudo integrar la sección editada.');
            }
        }

        $cssAppend = trim((string) ($data['css'] ?? ''));
        $css = $canvas['css'] . ($cssAppend !== '' ? "\n/* chat */\n" . $cssAppend : '');

        return ['html' => $newHtml, 'css' => $css, 'reply' => self::reply($data)];
    }

    /** @return array{html:string,css:string,reply:string} */
    private static function applyPageEdit(int $siteId, array $page, array $canvas, string $instruction): array
    {
        $result = AIActionRunner::run(Actions::EDIT_CANVAS_PAGE, [
            'instruction' => $instruction,
            'page_html' => $canvas['html'],
            'page_css' => $canvas['css'],
            'page_title' => (string) $page['title'],
            'language' => 'es',
            'available_images' => self::availableImages($siteId),
            'modules_hint' => CanvasService::modulesHint($siteId),
        ], $siteId);

        $data = self::parseEditEnvelope((string) ($result['data'] ?? ''));
        // Cambio global solo de estilo: si el modelo deja "html" vacío, conservamos
        // el HTML actual de la página y aplicamos únicamente el CSS devuelto.
        $newPageHtml = trim((string) ($data['html'] ?? ''));
        return [
            'html' => $newPageHtml !== '' ? (string) $data['html'] : $canvas['html'],
            'css' => trim((string) ($data['css'] ?? '')) !== '' ? (string) $data['css'] : $canvas['css'],
            'reply' => self::reply($data),
        ];
    }

    private static function reply(array $data): string
    {
        $reply = trim((string) ($data['reply'] ?? ''));
        return $reply !== '' ? mb_substr($reply, 0, 400) : 'Hecho, cambio aplicado.';
    }

    /**
     * Extrae una edición Canvas del sobre de texto usado por las acciones de
     * chat. El HTML deja de viajar dentro de JSON: atributos con comillas,
     * saltos de línea y CSS complejo ya no pueden invalidar la respuesta.
     *
     * @return array{html:string,css:string,reply:string}
     * @throws AIException si faltan bloques o la edición está vacía
     */
    public static function parseEditEnvelope(string $raw): array
    {
        $raw = trim($raw);
        // Algunos modelos añaden un fence pese a la instrucción; se tolera
        // siempre que dentro exista el sobre completo.
        if (preg_match('/^```(?:html|text)?\s*(.*?)\s*```$/is', $raw, $fence)) {
            $raw = trim((string) $fence[1]);
        }

        $extract = static function (string $tag) use ($raw): ?string {
            if (!preg_match('~<' . preg_quote($tag, '~') . '>\s*(.*?)\s*</' . preg_quote($tag, '~') . '>~is', $raw, $match)) {
                return null;
            }
            return trim((string) $match[1]);
        };

        $html = $extract('pp-html');
        $css = $extract('pp-css');
        $reply = $extract('pp-reply');
        if ($html === null || $css === null) {
            throw new AIException(
                'La edición no contiene el sobre de texto esperado. Respuesta: ' . mb_substr($raw, 0, 300)
            );
        }
        if ($html === '' && $css === '') {
            throw new AIException('La edición no contiene ni HTML ni estilos.');
        }

        return [
            'html' => $html,
            'css' => $css,
            'reply' => $reply ?? '',
        ];
    }

    /**
     * ¿La petición pide AÑADIR/INSERTAR una sección NUEVA? Es un cambio de
     * estructura de página (no de una sección): hay que enrutarlo al editor de
     * página, porque la edición de sección solo reemplaza la sección elegida y
     * descartaría la nueva. Distingue "mete una sección nueva" de "añade un
     * botón a esta sección" (eso es editar la sección actual): exige que el
     * sustantivo de sección sea el OBJETO nuevo (una/otra/nueva sección…).
     */
    public static function requestsNewSection(string $instruction): bool
    {
        $t = ' ' . mb_strtolower($instruction) . ' ';
        $noun = 'secci[óo]n|secciones|franja|banda|apartado|bloque';
        // Duplicar una sección también crea una nueva (cambio estructural).
        if (preg_match('/\bduplic\w*\b[^.]{0,20}?\b(?:' . $noun . ')\b/u', $t)) {
            return true;
        }
        $verb = 'añad\w*|agreg\w*|met[eaéo]\w*|insert\w*|cre[ae]\w*|incorpor\w*';
        if (!preg_match('/\b(?:' . $verb . ')\b/u', $t)) {
            return false;
        }
        return preg_match('/\b(?:una|otra|un|nueva|nuevo)\s+(?:' . $noun . ')\b/u', $t) === 1
            || preg_match('/\b(?:' . $noun . ')\s+nuevas?\b/u', $t) === 1;
    }

    /**
     * ¿La petición pide AÑADIR/CAMBIAR una imagen (y por tanto conviene buscar
     * en el banco de imágenes)? Debe distinguir una petición real ("pon una
     * foto", "imagen de fondo") de una simple MENCIÓN de un elemento que
     * contiene imágenes ("dale menos ancho a la caja de foto+texto") o de un
     * cambio de layout que no toca la imagen. Un falso positivo aquí lanzaba
     * Unsplash y el control de "no se añadió ninguna imagen" sin motivo.
     */
    public static function requestsImages(string $instruction): bool
    {
        $t = ' ' . mb_strtolower($instruction) . ' ';
        $img = 'imagen|imagenes|imágenes|foto|fotos|fotograf[íi]a|fotograf[íi]as';

        // Quitar/ocultar una imagen no requiere buscar una nueva.
        if (preg_match('/\b(?:quita\w*|elimina\w*|borra\w*|oculta\w*|sin)\b[^.]{0,30}?\b(?:' . $img . '|fondo)\b/u', $t)) {
            return false;
        }
        // "imagen/foto de fondo" → siempre es una petición de imagen.
        if (preg_match('/\b(?:' . $img . ')\s+de\s+fondo\b/u', $t)) {
            return true;
        }
        // Referencia descriptiva "… de (la) imagen/foto" (p. ej. "caja de
        // foto+texto", "bloque de imágenes"): no se pide cambiar la imagen.
        $t = preg_replace('/\bde\s+(?:la|el|las|los|una|un)?\s*(?:' . $img . ')\b/u', ' ', $t) ?? $t;
        // Si AÚN queda una palabra de imagen, es el objeto de la acción → petición.
        return preg_match('/\b(?:' . $img . ')\b/u', $t) === 1;
    }

    /** @return array{ok:bool,error:?string} */
    private static function prepareRequestedImages(int $siteId, string $pageTitle, string $instruction): array
    {
        if (!ImageBankService::isAvailable()) {
            return ['ok' => false, 'error' => 'No se pueden añadir imágenes porque Unsplash no está configurado.'];
        }

        ImageBankService::resetDiagnostics();
        $query = trim($pageTitle . ' ' . preg_replace('/\s+/', ' ', mb_substr($instruction, 0, 100)));
        $search = ImageBankService::searchDetailed($query, 6, 'landscape');
        if (!$search['ok']) {
            return ['ok' => false, 'error' => (string) ($search['message'] ?? 'Unsplash no está disponible temporalmente.')];
        }
        if ($search['items'] === []) {
            return ['ok' => false, 'error' => 'Unsplash no encontró imágenes adecuadas para esta petición. Prueba a describir el tipo de foto que necesitas.'];
        }

        $imported = 0;
        foreach (array_slice($search['items'], 0, 3) as $item) {
            try {
                ImageBankService::downloadToMedia($item, $siteId, \Core\Auth::id(), $pageTitle);
                $imported++;
            } catch (\Throwable $e) {
                error_log('[canvas chat] provider=unsplash operation=download site=' . $siteId . ' error=' . get_class($e) . ': ' . $e->getMessage());
            }
        }
        return $imported > 0
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'Unsplash respondió, pero no se pudieron descargar las imágenes. No se ha modificado la página.'];
    }

    private static function imageCount(string $html): int
    {
        preg_match_all('/<img\b|background-image\s*:|<picture\b/iu', $html, $matches);
        return count($matches[0]);
    }

    /** ¿El sitio tiene al menos una imagen en su biblioteca de medios? */
    private static function hasLibraryImages(int $siteId): bool
    {
        $row = Database::selectOne(
            "SELECT 1 FROM media WHERE site_id = ? AND mime_type LIKE 'image/%' LIMIT 1",
            [$siteId]
        );
        return $row !== null;
    }

    /** Últimas imágenes de la biblioteca del sitio, para "cambia la foto". */
    private static function availableImages(int $siteId): string
    {
        $rows = Database::select(
            "SELECT path, alt_text FROM media
             WHERE site_id = ? AND mime_type LIKE 'image/%'
             ORDER BY id DESC LIMIT 12",
            [$siteId]
        );
        if ($rows === []) return '(ninguna en la biblioteca)';
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- /' . ltrim((string) $r['path'], '/') . ' — ' . trim((string) ($r['alt_text'] ?? ''));
        }
        return implode("\n", $lines);
    }
}
