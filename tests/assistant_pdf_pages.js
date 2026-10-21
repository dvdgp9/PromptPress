'use strict';

/**
 * ASST-CONV AC3 — cuentas del render de un PDF de diseño a imágenes.
 * Solo las funciones puras: el render real necesita navegador (pdf.js + canvas).
 */
const assert = require('node:assert/strict');
const Pdf = require('../admin/assets/js/assistant-pdf-pages.js');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed += 1;
        process.stdout.write('PASS ' + name + '\n');
    } catch (error) {
        process.stderr.write('FAIL ' + name + '\n  -> ' + error.message + '\n');
        process.exitCode = 1;
    }
}

test('a4_page_is_one_tile_at_target_width', () => {
    const plan = Pdf.pageRenderPlan(595, 842, { targetWidth: 1280, maxTiles: 4 });
    assert.equal(plan.width, 1280);
    assert.equal(plan.height, Math.round(842 * 1280 / 595));
    assert.equal(plan.tiles.length, 1);
    assert.deepEqual(plan.tiles[0], { y: 0, height: plan.height });
});

test('landscape_slide_is_one_tile', () => {
    const plan = Pdf.pageRenderPlan(1920, 1080, { targetWidth: 1280, maxTiles: 4 });
    assert.equal(plan.width, 1280);
    assert.equal(plan.height, 720);
    assert.equal(plan.tiles.length, 1);
});

test('long_web_screenshot_is_cut_into_readable_tiles', () => {
    const plan = Pdf.pageRenderPlan(1440, 7200, { targetWidth: 1280, maxTiles: 4 });
    assert.equal(plan.width, 1280);
    assert.equal(plan.tiles.length, 4);
    const total = plan.tiles.reduce((sum, tile) => sum + tile.height, 0);
    assert.equal(total, plan.height, 'los trozos cubren la página entera sin huecos');
    plan.tiles.forEach((tile, index) => {
        if (index > 0) assert.equal(tile.y, plan.tiles[index - 1].y + plan.tiles[index - 1].height);
    });
});

test('tile_count_respects_the_per_page_limit', () => {
    const plan = Pdf.pageRenderPlan(1440, 20000, { targetWidth: 1280, maxTiles: 2 });
    assert.equal(plan.tiles.length, 2);
});

test('huge_page_is_scaled_down_to_fit_canvas_limits', () => {
    const plan = Pdf.pageRenderPlan(1440, 40000, { targetWidth: 1280, maxTiles: 4, maxCanvasHeight: 12000 });
    assert.ok(plan.height <= 12000, 'alto ' + plan.height);
    assert.ok(plan.width < 1280);
});

test('tiny_page_is_not_upscaled_beyond_limit', () => {
    const plan = Pdf.pageRenderPlan(100, 100, { targetWidth: 1280, maxTiles: 4, maxScale: 3 });
    assert.equal(plan.width, 300);
});

test('image_budget_is_spread_across_pages', () => {
    assert.deepEqual(Pdf.tileBudget(3, 8), { pages: 3, perPage: 2 });
    assert.deepEqual(Pdf.tileBudget(12, 8), { pages: 8, perPage: 1 });
    assert.deepEqual(Pdf.tileBudget(1, 8), { pages: 1, perPage: 4 });
    assert.deepEqual(Pdf.tileBudget(5, 0), { pages: 0, perPage: 0 });
});

test('page_file_names_are_readable_and_safe', () => {
    assert.equal(Pdf.pageFileName('Diseño web cliente.pdf', 2, 0, 1), 'diseno-web-cliente-p2.jpg');
    assert.equal(Pdf.pageFileName('home.PDF', 1, 1, 3), 'home-p1-2.jpg');
    assert.equal(Pdf.pageFileName('../../x.pdf', 1, 0, 1), 'x-p1.jpg');
});

if (!process.exitCode) {
    process.stdout.write('ALL PASS (' + passed + ')\n');
}
