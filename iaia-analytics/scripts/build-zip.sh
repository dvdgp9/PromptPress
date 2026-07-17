#!/bin/sh
# build-zip.sh — genera iaia-analytics.zip listo para subir a WordPress
# (Plugins → Añadir nuevo → Subir plugin).
#
# El zip contiene la carpeta iaia-analytics/ con el contenido de plugin/.
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/iaia-analytics.zip"
STAGE="$(mktemp -d)"

cp -R "$ROOT/plugin" "$STAGE/iaia-analytics"
rm -f "$OUT"
(cd "$STAGE" && zip -rq "$OUT" iaia-analytics -x '*.DS_Store')
rm -rf "$STAGE"

echo "Generado: $OUT"
unzip -l "$OUT" | tail -3
