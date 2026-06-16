<?php

namespace App\Services\Renderer;

/**
 * Set mínimo de iconos SVG inline (estilo lucide) para secciones públicas.
 *
 * Acepta nombres tolerantes: "shield-check", "icon-shield-check",
 * "lucide-shield-check", "ShieldCheck", "shield_check", etc.
 *
 * Si no hay match, devuelve un fallback discreto (un círculo) en lugar de
 * pintar el string crudo. Para "ningún icono", pasar string vacío.
 */
final class Icons
{
    /**
     * Lista de nombres canónicos disponibles (sin `_fallback`). Útil para
     * pasarlos como contexto al prompt de la IA y que no invente otros.
     *
     * @return string[]
     */
    public static function names(): array
    {
        $names = array_keys(self::SVGS);
        return array_values(array_filter($names, fn($n) => $n !== '' && $n[0] !== '_'));
    }

    /** True si el nombre (tolerante) resuelve a un icono real de la librería. */
    public static function exists(string $name): bool
    {
        $key = self::normalize($name);
        if ($key === '' || $key[0] === '_') return false;
        return isset(self::SVGS[$key]) || isset(self::ALIASES[$key]);
    }

    /** Nombre canónico (resuelve aliases y normaliza); '' si no existe. */
    public static function canonicalName(string $name): string
    {
        $key = self::normalize($name);
        if (isset(self::SVGS[$key]) && $key[0] !== '_') return $key;
        $alias = self::ALIASES[$key] ?? '';
        return is_string($alias) && isset(self::SVGS[$alias]) ? $alias : '';
    }

    /** Devuelve el SVG inline para el nombre dado, o un fallback si no se reconoce. */
    public static function render(string $name, string $extraClass = ''): string
    {
        $key = self::normalize($name);
        if ($key === '') {
            return '';
        }

        $svg = self::SVGS[$key] ?? self::ALIASES[$key] ?? null;
        if ($svg === null) {
            $svg = self::SVGS['_fallback'];
        } elseif (is_string($svg) && isset(self::SVGS[$svg])) {
            $svg = self::SVGS[$svg];
        }

        $cls = 'pp-icon' . ($extraClass !== '' ? ' ' . $extraClass : '');
        return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" '
             . 'viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
             . 'aria-hidden="true" focusable="false">'
             . $svg
             . '</svg>';
    }

    /** Normaliza un nombre eliminando prefijos comunes y convirtiendo a kebab-case. */
    private static function normalize(string $name): string
    {
        $n = trim($name);
        if ($n === '') return '';

        // CamelCase → kebab
        $n = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $n) ?? $n;
        $n = strtolower($n);
        // separadores → guiones
        $n = preg_replace('/[\s_]+/', '-', $n) ?? $n;
        // colapsar guiones múltiples
        $n = preg_replace('/-+/', '-', $n) ?? $n;
        // quitar prefijos comunes
        $n = preg_replace('/^(icon|lucide|i|fa|fas|far|feather)-/', '', $n) ?? $n;
        return trim($n, '-');
    }

    // Aliases → nombre canónico en SVGS.
    private const ALIASES = [
        'mobile'         => 'smartphone',
        'phone-mobile'   => 'smartphone',
        'device-mobile'  => 'smartphone',
        'tick'           => 'check',
        'checkmark'      => 'check',
        'circle-check'   => 'check-circle',
        'envelope'       => 'mail',
        'email'          => 'mail',
        'location'       => 'map-pin',
        'pin'            => 'map-pin',
        'time'           => 'clock',
        'schedule'       => 'calendar',
        'graph'          => 'chart-bar',
        'analytics'      => 'chart-bar',
        'bolt'           => 'zap',
        'flash'          => 'zap',
        'lightning'      => 'zap',
        'security'       => 'shield',
        'protection'     => 'shield',
        'verified'       => 'shield-check',
        'profile'        => 'user',
        'person'         => 'user',
        'people'         => 'users',
        'team'           => 'users',
        'group'          => 'users',
        'add-user'       => 'user-plus',
        'stack'          => 'layers',
        'sparkle'        => 'sparkles',
        'magic'          => 'sparkles',
        'rocket-launch'  => 'rocket',
        'medal'          => 'award',
        'trophy'         => 'award',
        'pulse'          => 'activity',
        'heartbeat'      => 'activity',
        'medical'        => 'stethoscope',
        'doctor'         => 'stethoscope',
        'clinic'         => 'stethoscope',
        'rating'         => 'star',
        'favorite'       => 'heart',
        'love'           => 'heart',
        'price'          => 'tag',
        'document'       => 'file-text',
        'docs'           => 'file-text',
        'arrow'          => 'arrow-right',
        'next'           => 'arrow-right',
        'growth'         => 'trending-up',
        'increase'       => 'trending-up',
        'gear'           => 'settings',
        'cog'            => 'settings',
        'config'         => 'settings',
        'padlock'        => 'lock',
        'secure'         => 'lock',
        'find'           => 'search',
        'magnify'        => 'search',
    ];

    // Set lucide-style. Solo el contenido interno del <svg>.
    private const SVGS = [
        '_fallback' => '<circle cx="12" cy="12" r="9"/>',

        'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'shield-check'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'user-plus'     => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M19 8v6"/><path d="M16 11h6"/>',
        'users'         => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8"/><path d="M22 21a7 7 0 0 0-6-6.93"/>',
        'layers'        => '<path d="m12 2 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'smartphone'    => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
        'check'         => '<path d="M5 12l5 5L20 7"/>',
        'check-circle'  => '<circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/>',
        'star'          => '<polygon points="12 2 15 9 22 9.5 17 14.5 18.5 22 12 18 5.5 22 7 14.5 2 9.5 9 9 12 2"/>',
        'heart'         => '<path d="M12 21s-7-4.5-9-9.5C1.5 7 5 3 8.5 5 10.5 6.2 12 8 12 8s1.5-1.8 3.5-3c3.5-2 7 2 5.5 6.5C19 16.5 12 21 12 21z"/>',
        'zap'           => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'clock'         => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'calendar'      => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M3 10h18"/>',
        'mail'          => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 6 10 7L22 6"/>',
        'phone'         => '<path d="M22 16.92V21a1 1 0 0 1-1.1 1A19 19 0 0 1 2 4.1 1 1 0 0 1 3 3h4.09a1 1 0 0 1 1 .75l1 4a1 1 0 0 1-.27 1L7.21 10.21a16 16 0 0 0 6.58 6.58l1.46-1.61a1 1 0 0 1 1-.27l4 1a1 1 0 0 1 .75 1z"/>',
        'map-pin'       => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
        'lock'          => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'search'        => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'sparkles'      => '<path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/>',
        'award'         => '<circle cx="12" cy="9" r="6"/><path d="m8.5 13.5-1.5 8 5-3 5 3-1.5-8"/>',
        'trending-up'   => '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/>',
        'activity'      => '<polyline points="3 12 7 12 10 4 14 20 17 12 21 12"/>',
        'stethoscope'   => '<path d="M6 3v6a4 4 0 0 0 8 0V3"/><path d="M6 3h2"/><path d="M12 3h2"/><path d="M10 13a6 6 0 0 0 6 6 4 4 0 0 0 4-4v-2"/><circle cx="20" cy="11" r="2"/>',
        'chart-bar'     => '<path d="M3 21h18"/><rect x="6" y="11" width="3" height="9"/><rect x="11" y="6" width="3" height="14"/><rect x="16" y="14" width="3" height="6"/>',
        'rocket'        => '<path d="M5 14a4 4 0 0 0 5 5l3-3-5-5-3 3z"/><path d="M14 6c5-2 5 5 5 5l-2 2-5-5 2-2z"/><path d="M9 15l-2 5"/><path d="M15 9l5-2"/>',
        'tag'           => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/>',
        'file-text'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="14" y2="17"/>',
        'arrow-right'   => '<line x1="4" y1="12" x2="20" y2="12"/><polyline points="14 6 20 12 14 18"/>',
        'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'thumbs-up'     => '<path d="M7 10v11h11l3-7v-3h-7l1-5a2 2 0 0 0-4-1l-4 5z"/><path d="M3 10h4v11H3z"/>',
        'message'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'globe'         => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
        'briefcase'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'home'          => '<path d="m3 11 9-8 9 8v9a2 2 0 0 1-2 2h-4v-7h-6v7H5a2 2 0 0 1-2-2z"/>',
        'play'          => '<polygon points="6 4 20 12 6 20 6 4"/>',
        'eye'           => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'gift'          => '<rect x="3" y="8" width="18" height="13" rx="1"/><path d="M3 12h18"/><path d="M12 8v13"/><path d="M12 8s-3-5-6-3 1 3 6 3z"/><path d="M12 8s3-5 6-3-1 3-6 3z"/>',
        'lightbulb'     => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12c1 1 1.5 2 1.5 3h5c0-1 .5-2 1.5-3A7 7 0 0 0 12 2z"/>',
    ];
}
