# IAIA Analytics — plugin de WordPress

Analítica web propia, autocontenida y sin terceros: los datos se recogen,
almacenan y consultan **dentro del propio WordPress**. Sin Google Analytics,
sin cookies, sin banner.

Origen: port del módulo Analytics de PromptPress (FEAT-3).

## Instalación

1. Generar el zip: `scripts/build-zip.sh` → `iaia-analytics.zip`.
2. En wp-admin: **Plugins → Añadir nuevo → Subir plugin**, elegir el zip y **Activar**.
3. Listo. No hay nada que configurar: el tracking empieza al activar y el
   dashboard aparece en el menú lateral, entrada **Analítica**.

Requisitos: WordPress ≥ 6.0, PHP ≥ 8.1, MySQL/MariaDB.

## Qué hace

- Inyecta un script de ~1 KB en el frontend que envía un pageview por visita
  (vía `navigator.sendBeacon`, no bloquea la navegación).
- Guarda los eventos en tablas propias (`{prefijo}iaia_events`, `_daily`, `_salts`).
- Dashboard en wp-admin: visitantes, páginas vistas, evolución diaria,
  páginas top, fuentes de tráfico, dispositivos, navegadores y conversiones,
  con rangos de 7/30/90 días y comparativa con el periodo anterior.
- No se trackea a los usuarios logueados que pueden editar contenido
  (administradores, editores, autores…).
- Filtra bots por User-Agent.

## Eventos personalizados (conversiones)

Desde cualquier JS de tu web:

```js
ppTrack('form_submit');   // o 'compra', 'llamada', 'descarga_pdf'…
```

Aparecen en la tarjeta **Conversiones** del dashboard.

## Privacidad y GDPR

Diseño privacy-first, el mismo criterio que la analítica tipo Plausible/Fathom:

- **Sin cookies ni localStorage**: no se escribe nada en el navegador.
- **No se almacena ni la IP ni el User-Agent.** Solo se usan en memoria para
  derivar dispositivo/navegador y calcular el identificador del visitante.
- **La IP se trunca antes incluso de calcular ese identificador** (IPv4 a /24,
  IPv6 a /48): la IP exacta no participa en ningún cálculo persistido.
- El visitante se identifica con un **hash irreversible que cambia cada día**:
  la sal aleatoria diaria se destruye en cuanto empieza el día siguiente, así
  que los hashes pasados no pueden recomputarse ni verificarse. No es posible
  seguir a un visitante entre días ni re-identificarlo a posteriori.
- Los eventos detallados (sin datos personales) se conservan **90 días**; los
  agregados diarios, indefinidamente.
- Los datos **nunca salen de tu servidor**: no hay transferencia a terceros.

Con este diseño no se tratan datos personales persistidos, que es el argumento
estándar del sector para operar sin banner de consentimiento. **Esto no es
asesoramiento legal**: si la web es de un cliente o de riesgo, validadlo con
vuestro asesor.

## Datos y desinstalación

- **Desactivar** el plugin detiene el tracking pero conserva los datos.
- **Borrar** el plugin desde wp-admin elimina las tablas y todos los datos
  históricos (uninstall.php).

## Desarrollo

- Código del plugin en `plugin/`; el zip se genera con `scripts/build-zip.sh`.
- Entorno de pruebas local (desechable, ignorado en git) en `dev/`:
  WordPress + wp-cli sobre el MySQL local. `dev/wp.sh <comando>` ejecuta wp-cli.
- Tests de integración: `dev/wp.sh eval-file scripts/test-rollup.php`
  (rollup, purgas y stats) y `scripts/seed-demo.php` para poblar el dashboard.
