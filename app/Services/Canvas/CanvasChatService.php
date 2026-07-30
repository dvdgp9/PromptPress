<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\ImageBankService;
use App\Services\LanguageService;
use App\Services\MediaLibraryService;

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
        string $summaryPrefix = '',
        string $requestId = '',
        array $context = []
    ): array {
        $pageId = (int) $page['id'];
        // STUDIO-2 B1/B2 — contexto opcional del chat: turnos anteriores y el
        // camino exacto del elemento seleccionado. Los otros llamadores (el
        // asistente central) no los mandan y siguen funcionando igual.
        $history = is_array($context['history'] ?? null) ? $context['history'] : [];
        $elementPath = trim((string) ($context['element_path'] ?? ''));

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            return ['ok' => false, 'error' => 'Esta página aún no tiene contenido canvas.', 'http' => 404];
        }

        $requiresImages = self::requestsImages($instruction);
        if ($requiresImages) {
            // STUDIO-2 C2 — Las fotos del negocio son la PRIMERA opción. Antes se
            // llamaba a Unsplash en cuanto la petición mencionaba una foto, así
            // que el material propio del cliente quedaba enterrado bajo stock
            // recién importado. Ahora solo se recurre al banco si no hay fotos
            // propias o si el usuario lo pide explícitamente.
            if (self::requestsImageBank($instruction) || !MediaLibraryService::hasOwnImages($siteId)) {
                $prepared = self::prepareRequestedImages($siteId, (string) $page['title'], $instruction);
                // Si Unsplash falla pero el sitio YA tiene imágenes en su biblioteca,
                // no bloqueamos: la IA puede usar esas (van en `available_images`).
                // Solo bloqueamos si no hay ninguna imagen utilizable en absoluto.
                if (!$prepared['ok'] && !MediaLibraryService::hasAnyImages($siteId)) {
                    return ['ok' => false, 'error' => (string) $prepared['error'], 'http' => 503];
                }
            }
        }

        // Añadir/insertar una sección NUEVA es un cambio estructural de PÁGINA,
        // no de una sección: el editor de sección solo reemplaza la sección
        // elegida (descartaría la nueva → "no creó nada"). Se enruta a página,
        // usando la sección seleccionada como referencia de posición.
        $wantsNewSection = self::requestsNewSection($instruction);

        $effectiveInstruction = $instruction;
        // B1 — Los turnos anteriores van DELANTE de la petición: son contexto
        // para entender referencias ("ahora un poco más grande", "ese botón"),
        // no peticiones pendientes.
        $effectiveInstruction = self::historyBlock($history) . $effectiveInstruction;
        $crossPageReference = CanvasCrossPageReference::resolve($siteId, $page, $instruction);
        if ($crossPageReference !== null) {
            $effectiveInstruction .= CanvasCrossPageReference::promptBlock($crossPageReference);
        }
        // B2 — El elemento elegido se marca en el propio HTML (data-pp-target).
        // La descripción en prosa se mantiene como apoyo: si el camino no se
        // puede resolver, el modelo todavía sabe de qué elemento se habla.
        $markTarget = !$wantsNewSection && $sectionId !== '' && $elementPath !== '';
        if (!$wantsNewSection && $sectionId !== '' && $elementContext !== '') {
            $effectiveInstruction .= "\n\nElemento concreto seleccionado por el usuario: " . mb_substr($elementContext, 0, 240) . '. Aplica el cambio a ese elemento, no al conjunto de la sección.';
        }
        if ($markTarget) {
            $effectiveInstruction .= "\n\nESE ELEMENTO VIENE MARCADO en el HTML con el atributo `data-pp-target=\"1\"`: es exactamente el que debes cambiar (si hay varios elementos parecidos, no te equivoques de uno). Quita ese atributo en el HTML que devuelvas.";
        }
        if ($wantsNewSection && $sectionId !== '') {
            $effectiveInstruction .= "\n\nUbica el cambio respecto a la sección de referencia \"" . self::sectionLabel($sectionId) . "\" (data-pp-section=\"" . $sectionId . "\"). A la sección NUEVA dale un data-pp-section único y descriptivo; conserva intactas todas las demás secciones.";
        }
        // Peticiones de imagen: distinguimos fondo (CSS, sin reescribir HTML) de
        // contenido (<img> en el HTML). Reescribir secciones con ilustraciones SVG
        // grandes solo para una imagen de FONDO es lento y trunca: con CSS es
        // instantáneo. La verificación posterior cuenta imágenes en HTML y en CSS.
        if ($requiresImages) {
            $effectiveInstruction .= MediaLibraryService::hasOwnImages($siteId)
                ? "\n\nPRIORIDAD DE IMÁGENES: este negocio tiene fotos propias en su biblioteca. Usa una de ellas siempre que encaje razonablemente, aunque no sea perfecta; una foto real del negocio vale más que una de banco. Recurre al banco solo si ninguna propia tiene sentido para lo que se pide."
                : '';
            $effectiveInstruction .= "\n\nHay imágenes disponibles para esta petición. Si es una imagen de FONDO, aplícala con CSS (`background-image: url(...)` apuntando a una ruta de las imágenes disponibles) sobre la sección o el elemento, y deja \"html\":\"\" (NO reescribas el HTML, sobre todo si hay ilustraciones o SVG). Si la imagen forma parte del CONTENIDO (una foto dentro del texto), devuelve el HTML con la etiqueta <img>.";
        }

        if ($sectionId !== '' && !$wantsNewSection) {
            $result = self::applySectionEdit($siteId, $page, $canvas, $sectionId, $effectiveInstruction, $markTarget ? $elementPath : '');
        } else {
            $result = self::applyPageEdit($siteId, $page, $canvas, $effectiveInstruction);
        }

        // Por si el modelo no quitó la marca: nunca debe llegar a la página.
        $result['html'] = self::stripTargetMarks($result['html']);

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

        // CANCEL — Último control antes de tocar la página: si el usuario pulsó
        // "Parar" mientras la IA trabajaba, se descarta el resultado. Aquí es el
        // único sitio honesto para mirarlo: es la línea que modifica la página.
        if ($requestId !== '' && CanvasCancelToken::isCancelled($siteId, $requestId)) {
            return [
                'ok'        => false,
                'cancelled' => true,
                'error'     => 'Cambio cancelado. Tu página no se ha tocado.',
                'http'      => 409,
            ];
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
    private static function applySectionEdit(int $siteId, array $page, array $canvas, string $sectionId, string $instruction, string $elementPath = ''): array
    {
        $sectionHtml = CanvasService::extractSection($canvas['html'], $sectionId);
        if ($sectionHtml === null) {
            throw new \RuntimeException('Sección no encontrada: ' . $sectionId);
        }
        $promptHtml = $elementPath !== '' ? self::markTarget($sectionHtml, $elementPath) : $sectionHtml;

        $data = self::runEdit(Actions::EDIT_CANVAS_SECTION, [
            'instruction' => $instruction,
            'section_html' => $promptHtml,
            'page_css' => mb_substr($canvas['css'], 0, 14000),
            'page_title' => (string) $page['title'],
            // El idioma lo manda la PÁGINA: en una web bilingüe, pedirle un
            // cambio a una página francesa debe devolver francés aunque el
            // idioma principal del sitio sea otro.
            'language' => LanguageService::promptLabel(LanguageService::forPage($page, $siteId)),
            'available_images' => self::availableImages($siteId),
            'modules_hint' => CanvasService::modulesHint($siteId),
        ], $siteId);

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
        $data = self::runEdit(Actions::EDIT_CANVAS_PAGE, [
            'instruction' => $instruction,
            'page_html' => $canvas['html'],
            'page_css' => $canvas['css'],
            'page_title' => (string) $page['title'],
            // El idioma lo manda la PÁGINA: en una web bilingüe, pedirle un
            // cambio a una página francesa debe devolver francés aunque el
            // idioma principal del sitio sea otro.
            'language' => LanguageService::promptLabel(LanguageService::forPage($page, $siteId)),
            'available_images' => self::availableImages($siteId),
            'modules_hint' => CanvasService::modulesHint($siteId),
        ], $siteId);

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
     * STUDIO-2 B4 — Ejecuta una acción de edición y parsea el sobre, con UN
     * reintento si el sobre viene mal. Es el fallo más común y el más tonto:
     * el modelo escribe el cambio bien pero se come una etiqueta, y el usuario
     * veía "la IA no devolvió un cambio válido" teniendo que repetirlo a mano.
     *
     * @param array<string,mixed> $input
     * @return array{html:string,css:string,reply:string}
     * @throws AIException
     */
    private static function runEdit(string $action, array $input, int $siteId): array
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $payload = $input;
            if ($attempt > 1) {
                $payload['instruction'] .= "\n\nAVISO: tu respuesta anterior no traía el sobre completo. Devuelve OBLIGATORIAMENTE los tres bloques <pp-html>…</pp-html>, <pp-css>…</pp-css> y <pp-reply>…</pp-reply>, sin markdown ni texto fuera de las etiquetas.";
            }
            $result = AIActionRunner::run($action, $payload, $siteId);
            try {
                return self::parseEditEnvelope((string) ($result['data'] ?? ''));
            } catch (AIException $e) {
                $lastError = $e;
                error_log('[canvas chat] envelope_retry action=' . $action . ' attempt=' . $attempt . ': ' . $e->getMessage());
            }
        }
        throw $lastError ?? new AIException('La edición no devolvió un sobre válido.');
    }

    /**
     * STUDIO-2 B1 — Bloque de conversación reciente. Sin esto, cada mensaje
     * viajaba solo y "ahora un poco más grande" no tenía a qué referirse.
     *
     * @param array<int,array{q?:string,a?:string,scope?:string}> $turns
     */
    private static function historyBlock(array $turns): string
    {
        $lines = [];
        foreach (array_slice($turns, -4) as $turn) {
            $q = trim((string) ($turn['q'] ?? ''));
            if ($q === '') continue;
            $a = trim((string) ($turn['a'] ?? ''));
            $scope = trim((string) ($turn['scope'] ?? ''));
            $lines[] = '- Pidió: "' . mb_substr($q, 0, 200) . '"'
                . ($scope !== '' ? ' (en "' . mb_substr($scope, 0, 60) . '")' : '')
                . ($a !== '' ? ' → hiciste: "' . mb_substr($a, 0, 200) . '"' : '');
        }
        if ($lines === []) return '';

        return "CONVERSACIÓN RECIENTE con este cliente sobre esta página, de lo más antiguo a lo más nuevo. Es CONTEXTO para entender a qué se refiere ahora (\"un poco más grande\", \"ese botón\", \"déjalo como antes\"), NO son peticiones pendientes: no vuelvas a aplicarlas.\n"
            . implode("\n", $lines)
            . "\n\nPETICIÓN ACTUAL (la única que debes aplicar):\n";
    }

    /**
     * STUDIO-2 B2 — Marca con `data-pp-target="1"` el elemento que el usuario
     * tenía seleccionado. El camino son índices de hijos desde la sección, tal
     * como los calcula el overlay del preview ("2.0.1").
     *
     * Antes el elemento viajaba solo como prosa ("texto con texto ...") y con
     * dos titulares parecidos el modelo podía cambiar el que no era.
     */
    public static function markTarget(string $sectionHtml, string $path): string
    {
        // El formato se valida ANTES de convertir: `intval('')` es 0, así que un
        // camino vacío o con basura habría marcado el primer hijo — peor que no
        // marcar nada, porque el modelo cambiaría el elemento equivocado.
        $path = trim($path);
        if ($path === '' || !preg_match('/^\d+(\.\d+)*$/', $path)) return $sectionHtml;
        $steps = array_map('intval', explode('.', $path));
        if (count($steps) > 12) return $sectionHtml;

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $doc->loadHTML(
            '<!doctype html><meta charset="utf-8"><div id="pp-root">' . $sectionHtml . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return $sectionHtml;

        $root = $doc->getElementById('pp-root');
        if (!$root) return $sectionHtml;

        // El primer hijo elemento de la raíz es la propia <section>.
        $node = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement) { $node = $child; break; }
        }
        if ($node === null) return $sectionHtml;

        foreach ($steps as $index) {
            $children = [];
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) $children[] = $child;
            }
            if (!isset($children[$index])) return $sectionHtml;   // camino obsoleto: sin marca
            $node = $children[$index];
        }
        $node->setAttribute('data-pp-target', '1');

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out) !== '' ? $out : $sectionHtml;
    }

    /** Quita cualquier marca de objetivo que el modelo se haya dejado puesta. */
    public static function stripTargetMarks(string $html): string
    {
        if (stripos($html, 'data-pp-target') === false) return $html;
        return (string) preg_replace('/\s*data-pp-target\s*=\s*(["\'])[^"\']*\1/i', '', $html);
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

    /**
     * ¿El usuario pide EXPLÍCITAMENTE buscar fuera (banco de imágenes)? Solo
     * entonces se va a Unsplash teniendo fotos propias: "pon una foto de fondo"
     * debe resolverse con el material del negocio.
     */
    public static function requestsImageBank(string $instruction): bool
    {
        $t = ' ' . mb_strtolower($instruction) . ' ';
        return preg_match('/\b(unsplash|banco de im[áa]genes|imagen de banco|de internet|en internet|stock|gratuita?s?)\b/u', $t) === 1;
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

    /**
     * Imágenes de la biblioteca para "cambia la foto", con las propias del
     * negocio SIEMPRE por delante (STUDIO-2 C1).
     */
    private static function availableImages(int $siteId): string
    {
        $library = MediaLibraryService::forAi($siteId);

        // LOGO2 — Los logos de marca viajan con las imágenes disponibles: así el
        // chat puede atender "pon el logo aquí" y elegir la variante que
        // contrasta con el fondo de esa sección.
        $logoHint = \App\Services\BrandService::logoHintForAi($siteId);

        return $logoHint !== '' ? $library . "\n\n" . $logoHint : $library;
    }
}
