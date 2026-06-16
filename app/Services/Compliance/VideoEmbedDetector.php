<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * E-GDPR G4b — Detección de URLs de vídeo externo (YouTube, Vimeo) y
 * generación de HTML "click-to-load" que sustituye al iframe nativo
 * hasta que el visitante acepta la categoría `external_media`.
 *
 * El renderer público (SectionRenderer) llama a `isVideoUrl()` cuando va a
 * renderizar una URL en un campo de imagen / medio. Si devuelve true, en
 * lugar de un `<img>` se inyecta un placeholder con thumbnail (cuando se
 * pueda obtener) y un botón "Reproducir vídeo · cargar contenido externo".
 *
 * El JS del cookie banner escucha clicks en el placeholder, guarda consent
 * para `external_media` y sustituye el placeholder por el iframe real.
 */
final class VideoEmbedDetector
{
    /**
     * ¿Es una URL de YouTube o Vimeo?
     */
    public static function isVideoUrl(string $url): bool
    {
        return self::detectProvider($url) !== null;
    }

    /**
     * Devuelve 'youtube' | 'vimeo' | null.
     */
    public static function detectProvider(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        if (preg_match('#^https?://(?:www\.)?(?:youtube\.com|youtu\.be|youtube-nocookie\.com)/#i', $url)) {
            return 'youtube';
        }
        if (preg_match('#^https?://(?:www\.)?(?:vimeo\.com|player\.vimeo\.com)/#i', $url)) {
            return 'vimeo';
        }
        return null;
    }

    /**
     * Extrae el ID del vídeo cuando es posible. null si no se puede.
     */
    public static function videoId(string $url): ?string
    {
        $provider = self::detectProvider($url);
        if ($provider === 'youtube') {
            if (preg_match('#[?&]v=([A-Za-z0-9_-]{11})#', $url, $m)) return $m[1];
            if (preg_match('#youtu\.be/([A-Za-z0-9_-]{11})#', $url, $m)) return $m[1];
            if (preg_match('#/embed/([A-Za-z0-9_-]{11})#', $url, $m)) return $m[1];
            if (preg_match('#/shorts/([A-Za-z0-9_-]{11})#', $url, $m)) return $m[1];
        }
        if ($provider === 'vimeo') {
            if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m)) return $m[1];
            if (preg_match('#player\.vimeo\.com/video/(\d+)#', $url, $m)) return $m[1];
        }
        return null;
    }

    /**
     * URL de thumbnail si está disponible sin llamar a API externa.
     * YouTube: hqdefault es siempre accesible.
     * Vimeo: requiere oEmbed → null (el placeholder usa fondo neutro).
     */
    public static function thumbnailUrl(string $url): ?string
    {
        $provider = self::detectProvider($url);
        $id = self::videoId($url);
        if ($provider === 'youtube' && $id !== null) {
            return 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg';
        }
        return null;
    }

    /**
     * URL del iframe embebido que se cargará al hacer click.
     * Usamos siempre youtube-nocookie para reducir tracking adicional.
     */
    public static function embedUrl(string $url): ?string
    {
        $provider = self::detectProvider($url);
        $id = self::videoId($url);
        if ($provider === 'youtube' && $id !== null) {
            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id) . '?autoplay=1';
        }
        if ($provider === 'vimeo' && $id !== null) {
            return 'https://player.vimeo.com/video/' . rawurlencode($id) . '?autoplay=1';
        }
        return null;
    }

    /**
     * Genera el HTML del placeholder click-to-load para una URL de vídeo.
     * Si la URL no es de vídeo o no se puede extraer ID, devuelve null y el
     * llamador renderiza la imagen original.
     */
    public static function renderPlaceholder(string $url, string $altOrCaption = ''): ?string
    {
        $provider = self::detectProvider($url);
        $embedUrl = self::embedUrl($url);
        if ($provider === null || $embedUrl === null) return null;

        $thumb = self::thumbnailUrl($url);
        $providerLabel = $provider === 'youtube' ? 'YouTube' : 'Vimeo';
        $alt = htmlspecialchars($altOrCaption !== '' ? $altOrCaption : 'Vídeo de ' . $providerLabel, ENT_QUOTES, 'UTF-8');
        $embedSafe = htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8');

        $bg = $thumb
            ? 'background-image:url(\'' . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') . '\');'
            : 'background:#0f172a;';

        return '<div class="pp-video-cta" '
             . 'data-pp-video-provider="' . htmlspecialchars($provider, ENT_QUOTES, 'UTF-8') . '" '
             . 'data-pp-video-embed="' . $embedSafe . '" '
             . 'aria-label="' . $alt . '" '
             . 'style="' . $bg . '">'
             . '<div class="pp-video-cta__overlay"></div>'
             . '<button type="button" class="pp-video-cta__play" aria-label="Reproducir vídeo y cargar contenido de ' . htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') . '">'
             .   '<svg viewBox="0 0 24 24" width="32" height="32" aria-hidden="true"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>'
             . '</button>'
             . '<div class="pp-video-cta__notice">'
             .   '<strong>' . htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') . '</strong>'
             .   '<span>Al reproducir aceptas cargar contenido externo y cookies de ' . htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') . '.</span>'
             . '</div>'
             . '</div>';
    }
}
