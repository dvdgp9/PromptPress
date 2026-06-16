# PromptPress (PPress)

CMS ligero tipo WordPress enfocado en la creación de páginas web asistidas por IA.

> **Estado actual:** En desarrollo (Fase 0 — Bootstrap). Ver `.cursor/scratchpad.md` para el plan completo y el progreso.

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
- Permisos de escritura en `/config/`, `/storage/`

---

## 🚀 Instalación

### 1. Subir archivos al servidor
Sube todos los archivos al directorio público de tu hosting (`public_html`, `www`, `htdocs`…).

### 2. Asegurar permisos
```bash
chmod -R 755 .
chmod -R 775 storage config
```

### 3. Ejecutar el instalador
Abre en el navegador: `https://tudominio.com/install/`

El instalador te guiará por:
1. Verificación de requisitos
2. Configuración de la base de datos
3. Creación del usuario administrador
4. Configuración inicial del sitio
5. Configuración del proveedor de IA (API Key)

### 4. Listo
Al finalizar, accede al panel: `https://tudominio.com/admin/`

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
