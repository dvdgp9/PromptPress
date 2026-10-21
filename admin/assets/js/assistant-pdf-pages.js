/**
 * ASST-CONV AC3 — un PDF de diseño se convierte en imágenes en el navegador.
 *
 * Un PDF con capturas de la web que le gusta al cliente apenas trae texto: lo
 * que importa es cómo se ve. Se renderiza cada página con pdf.js (vendorizado
 * en admin/assets/vendor/pdfjs) y las imágenes resultantes entran en el
 * composer como cualquier imagen adjunta. Así no hace falta Imagick ni
 * Ghostscript en el hosting.
 *
 * Las páginas muy largas (una web entera exportada) se trocean para que la IA
 * las lea con detalle, repartiendo el cupo de imágenes entre las páginas.
 */
(function (root, factory) {
    'use strict';
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.PPAPdfPages = api;
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    const DEFAULTS = {
        targetWidth: 1280,
        maxTiles: 4,
        maxScale: 3,
        maxCanvasHeight: 12000,
        tileAspect: 1.5,
        quality: 0.85
    };

    /**
     * Escala y trozos de una página (medidas en puntos PDF a escala 1).
     * @return {{scale:number,width:number,height:number,tiles:Array<{y:number,height:number}>}}
     */
    function pageRenderPlan(widthPt, heightPt, options) {
        const opts = Object.assign({}, DEFAULTS, options || {});
        const w = Math.max(1, Number(widthPt) || 1);
        const h = Math.max(1, Number(heightPt) || 1);
        let scale = Math.min(opts.targetWidth / w, opts.maxScale);
        if (h * scale > opts.maxCanvasHeight) scale = opts.maxCanvasHeight / h;
        const width = Math.round(w * scale);
        const height = Math.round(h * scale);

        const tileMax = Math.round(width * opts.tileAspect);
        let count = height <= tileMax * 1.15 ? 1 : Math.ceil(height / tileMax);
        count = Math.max(1, Math.min(count, Math.max(1, opts.maxTiles)));
        const tiles = [];
        const base = Math.floor(height / count);
        let y = 0;
        for (let index = 0; index < count; index += 1) {
            const tileHeight = index === count - 1 ? height - y : base;
            tiles.push({ y: y, height: tileHeight });
            y += tileHeight;
        }
        return { scale: scale, width: width, height: height, tiles: tiles };
    }

    /** Cuántas páginas caben y cuántos trozos por página con `budget` imágenes. */
    function tileBudget(numPages, budget) {
        const available = Math.max(0, Math.floor(Number(budget) || 0));
        const pages = Math.min(Math.max(0, Math.floor(Number(numPages) || 0)), available);
        if (pages === 0) return { pages: 0, perPage: 0 };
        return { pages: pages, perPage: Math.max(1, Math.min(DEFAULTS.maxTiles, Math.floor(available / pages))) };
    }

    function pageFileName(pdfName, pageNumber, tileIndex, tileCount) {
        const base = String(pdfName || 'documento')
            .replace(/\.pdf$/i, '')
            .split(/[\\/]/).pop()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 60) || 'documento';
        return base + '-p' + pageNumber + (tileCount > 1 ? '-' + (tileIndex + 1) : '') + '.jpg';
    }

    let libraryPromise = null;

    function loadLibrary(libUrl, workerUrl) {
        if (!libraryPromise) {
            libraryPromise = import(libUrl).then((lib) => {
                lib.GlobalWorkerOptions.workerSrc = workerUrl;
                return lib;
            }).catch((error) => {
                libraryPromise = null;
                throw error;
            });
        }
        return libraryPromise;
    }

    function canvasToFile(canvas, name, quality) {
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (!blob) {
                    reject(new Error('toBlob'));
                    return;
                }
                resolve(new File([blob], name, { type: 'image/jpeg' }));
            }, 'image/jpeg', quality);
        });
    }

    /**
     * Renderiza el PDF y devuelve las imágenes en orden.
     * @param {File} file
     * @param {{libUrl:string, workerUrl:string, budget:number, onProgress?:function(number,number):void}} options
     * @return {Promise<{files:File[], totalPages:number, renderedPages:number, pageOf:Map<string,{page:number,part:number,parts:number}>}>}
     */
    async function renderPdf(file, options) {
        const opts = Object.assign({}, DEFAULTS, options || {});
        const lib = await loadLibrary(opts.libUrl, opts.workerUrl);
        const data = new Uint8Array(await file.arrayBuffer());
        // Un PDF del cliente es contenido no confiable: sin eval en las fuentes
        // y sin formularios XFA.
        const task = lib.getDocument({ data: data, isEvalSupported: false, enableXfa: false });
        const pdf = await task.promise;
        const files = [];
        const pageOf = new Map();
        try {
            const plan = tileBudget(pdf.numPages, opts.budget);
            for (let pageNumber = 1; pageNumber <= plan.pages; pageNumber += 1) {
                if (typeof opts.onProgress === 'function') opts.onProgress(pageNumber, plan.pages);
                const page = await pdf.getPage(pageNumber);
                const base = page.getViewport({ scale: 1 });
                const layout = pageRenderPlan(base.width, base.height, Object.assign({}, opts, { maxTiles: plan.perPage }));
                const viewport = page.getViewport({ scale: layout.scale });
                const full = document.createElement('canvas');
                full.width = layout.width;
                full.height = layout.height;
                // intent 'print': el modo 'display' avanza con requestAnimationFrame,
                // que se para si la pestaña no está a la vista (y el render se
                // queda colgado). Para sacar imágenes no hace falta pintar en pantalla.
                await page.render({ canvas: full, viewport: viewport, intent: 'print' }).promise;

                for (let index = 0; index < layout.tiles.length; index += 1) {
                    const tile = layout.tiles[index];
                    const piece = document.createElement('canvas');
                    piece.width = layout.width;
                    piece.height = tile.height;
                    const ctx = piece.getContext('2d');
                    // JPEG no tiene transparencia: sin fondo, lo transparente sale negro.
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, piece.width, piece.height);
                    ctx.drawImage(full, 0, tile.y, layout.width, tile.height, 0, 0, layout.width, tile.height);
                    const name = pageFileName(file.name, pageNumber, index, layout.tiles.length);
                    const out = await canvasToFile(piece, name, opts.quality);
                    pageOf.set(name, { page: pageNumber, part: index + 1, parts: layout.tiles.length });
                    files.push(out);
                    piece.width = 0;
                    piece.height = 0;
                }
                full.width = 0;
                full.height = 0;
                page.cleanup();
            }
            return { files: files, totalPages: pdf.numPages, renderedPages: plan.pages, pageOf: pageOf };
        } finally {
            // En pdf.js 6 se destruye la tarea de carga (libera el worker).
            task.destroy();
        }
    }

    return {
        pageRenderPlan: pageRenderPlan,
        tileBudget: tileBudget,
        pageFileName: pageFileName,
        renderPdf: renderPdf
    };
}));
