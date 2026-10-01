# PromptPress (PPress)

CMS ligero tipo WordPress enfocado en la creación de páginas web asistidas por IA.

> **Estado actual:** en producción, con versiones publicadas en [GitHub Releases](https://github.com/dvdgp9/PromptPress/releases). Ver `.cursor/scratchpad.md` para el plan y el progreso.

---

## ✨ Características

- 🧩 **Páginas por secciones**: cada página se compone de bloques tipados (hero, beneficios, FAQ, CTA, formulario…)
- 🤖 **IA integrada multi-proveedor**: OpenRouter, OpenAI, Anthropic
- 🎨 **Design System propio**: colores, tipografías, espaciados, botones — coherentes en toda la web
- 📚 **Documentos base como contexto**: sube PDFs/DOCX/TXT y la IA los usa para generar contenido coherente
- 🧠 **Memoria del sitio**: la IA conoce tu empresa, tono y servicios
- 📊 **Control de coste IA**: logs detallados de uso, tokens y coste estimado
- ⚡ **Sin frameworks pesados**: PHP puro, JS vanilla, compatible con hosting compartido

---

## 📋 Requisitos

- **PHP 8.0 o superior**
- **MySQL 5.7+** o **MariaDB 10.3+**
- Extensiones PHP: `pdo_mysql`, `json`, `mbstring`, `fileinfo`, `curl`, `zip`, `openssl`
- **Apache** con `mod_rewrite` activado, o **Nginx** (ver `nginx.conf.example`)
- Una base de datos vacía y un usuario con acceso a ella (en hosting compartido se crean desde el panel del proveedor)

---

## 🚀 Instalación

### 1. Poner los archivos en el servidor

**Opción rápida (recomendada): un solo archivo**

1. Descarga **https://github.com/dvdgp9/PromptPress/releases/latest/download/instalar.php**
2. Súbelo al directorio público del dominio (`public_html`, `www`, `htdocs`…).
3. Abre `https://tudominio.com/instalar.php` y pulsa «Descargar e instalar».

Él descarga la última versión, comprueba su checksum, la descomprime ahí, se borra solo y te lleva al instalador del paso 2. Si la carpeta tiene archivos que no son del hosting, te los enseña y pide confirmación. Conserva la versión de PHP elegida en cPanel (`.htaccess`) y aparta la página de bienvenida del hosting (`index.html` → `index.html.antes-de-promptpress`).

**Opción manual: el zip**

Descarga **https://github.com/dvdgp9/PromptPress/releases/latest/download/promptpress.zip** (siempre la última versión) y descomprímelo en el directorio público del dominio. Los archivos van directamente ahí: el zip no trae una carpeta envolvente.

Lo más rápido es subir el zip tal cual y descomprimirlo desde el administrador de archivos del hosting. Si lo subes por FTP ya descomprimido, asegúrate de que se suben también los archivos ocultos (`.htaccess`): algunos clientes FTP no los muestran.

### 2. Ejecutar el instalador
Abre en el navegador: `https://tudominio.com/install/`

El instalador te guiará por:
1. Comprobación de requisitos (incluye permisos de escritura en `config/` y `storage/`)
2. Base de datos (crea las tablas)
3. Usuario administrador
4. Proveedor de IA (API Key) y, opcionalmente, Unsplash
5. Fin

Normalmente los permisos ya vienen bien. Solo si el paso 1 marca `config/` o `storage/` como no escribibles:
```bash
chmod -R 775 storage config
```

### 3. Listo
Al finalizar, accede al panel: `https://tudominio.com/admin/`. Ahí empieza el onboarding del sitio (identidad, materiales y primeras páginas).

### Actualizar
Desde el propio panel: **Ajustes → Actualizaciones → «Comprobar ahora» → «Aplicar actualización»**. Hace copia de seguridad, verifica el paquete y migra la base de datos solo.

---

## 🗂️ Estructura del proyecto

```
/
├── index.php              # Front controller
├── .htaccess              # URL rewriting Apache
├── nginx.conf.example     # Config Nginx de referencia
├── config/                # Configuración (generada por instalador)
├── core/                  # Micro-kernel (router, DB, auth, sesiones)
├── app/
│   ├── Controllers/       # Controladores admin y públicos
│   ├── Models/            # Modelos de datos
│   └── Services/          # AI, Renderer, Media, Documentos
├── install/               # Instalador
├── admin/                 # Assets del panel de administración
├── views/                 # Plantillas (admin + público + secciones)
├── storage/               # Uploads, documentos, cache, logs
└── public/                # CSS/JS público + assets estáticos
```

Detalle completo: ver `.cursor/scratchpad.md`.

---

## 🔌 Proveedores IA soportados

| Proveedor | Estado |
|-----------|--------|
| OpenRouter | ✅ Obligatorio en MVP |
| OpenAI | ✅ Soportado |
| Anthropic | ✅ Soportado |

Cada instalación usa **su propia API Key**, configurable desde el panel.

---

## 🛡️ Seguridad

- API keys encriptadas en base de datos (`openssl_encrypt`)
- Prepared statements en todas las queries (PDO)
- CSRF tokens en todos los formularios
- Cabeceras de seguridad (X-Frame-Options, CSP, etc.)
- Directorios sensibles bloqueados vía `.htaccess` / Nginx
- Validación y sanitización en cada entrada de usuario

---

## 📜 Licencia

Por definir.

---

## 🛠️ Desarrollo

Consulta `.cursor/scratchpad.md` para:
- Plan de tareas (`Project Status Board`)
- Arquitectura detallada
- Esquema de base de datos
- Ejemplos de JSON y rendering

Convención: trabajo coordinado por roles **Planner** / **Executor** documentado en el scratchpad.
