<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;

/**
 * FORMS-LANG T5/T7 — Traduce los textos de un formulario.
 *
 * Dos modos, y la diferencia importa:
 *
 *  - `toBase()`: **reescribe el idioma base**. Es el arreglo de un sitio de un
 *    solo idioma cuyo formulario nació en castellano (webs anteriores a esta
 *    fase). No hay dos idiomas que convivir: el texto de antes estaba mal y se
 *    sustituye.
 *  - `toLanguage()`: **añade una traducción** al bloque `i18n` sin tocar la
 *    base. Es lo que necesita una web multi-idioma, donde `/contact` (fr) y
 *    `/contacto` (es) comparten el MISMO formulario.
 *
 * Ninguno de los dos toca los `name` de los campos ni la configuración RGPD:
 * el saneado de `FormI18n` descarta cualquier cosa que devuelva el modelo que
 * no sea texto visible de un campo que ya existía.
 */
final class FormTranslator
{
    /**
     * Añade (o rehace) la traducción de un formulario a un idioma.
     *
     * @return array{ok:bool, content?:array<string,mixed>, message?:string}
     */
    public static function toLanguage(int $siteId, array $content, string $targetLang): array
    {
        $result = self::translateTexts($siteId, $content, $targetLang);
        if (!$result['ok']) {
            return $result;
        }
        return ['ok' => true, 'content' => FormI18n::withTranslation($content, $targetLang, $result['texts'])];
    }

    /**
     * Reescribe los textos base del formulario en otro idioma.
     *
     * @return array{ok:bool, content?:array<string,mixed>, message?:string}
     */
    public static function toBase(int $siteId, array $content, string $targetLang): array
    {
        $result = self::translateTexts($siteId, $content, $targetLang);
        if (!$result['ok']) {
            return $result;
        }
        return ['ok' => true, 'content' => FormI18n::withBaseTexts($content, $targetLang, $result['texts'])];
    }

    /**
     * @param array<string,mixed> $content
     * @return array{ok:bool, texts?:array<string,mixed>, message?:string}
     */
    private static function translateTexts(int $siteId, array $content, string $targetLang): array
    {
        $targetLang = LanguageService::normalize($targetLang);
        $texts = FormI18n::extractTexts($content);
        if ($texts === []) {
            return ['ok' => false, 'message' => __('tr.err.empty_form')];
        }

        try {
            $result = AIActionRunner::run(Actions::TRANSLATE_FORM, [
                'language'  => LanguageService::promptLabel($targetLang),
                'form_json' => json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $siteId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('tr.err.form_ai', ['detalle' => $e->getMessage()])];
        }

        $data = $result['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return ['ok' => false, 'message' => __('tr.err.form_empty')];
        }

        return ['ok' => true, 'texts' => $data];
    }
}
