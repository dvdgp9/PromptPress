<?php
/**
 * @var array $kpiToday
 * @var array $kpiMonth
 * @var array $kpiAll
 * @var array $byModel
 * @var array $byAction
 * @var array $logs
 * @var int   $page
 * @var int   $totalPages
 * @var int   $total
 * @var array $actionLabels
 */
\Core\View::extend('admin/layout');

$fmtCost = fn(float $c) => '$' . number_format($c, $c < 0.01 ? 6 : 4, '.', ',');
$fmtNum  = fn(int $n)   => number_format($n, 0, ',', '.');
?>

<?php \Core\View::start('title'); ?>Uso de IA<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Uso de IA</h2>
    <div class="pp-page-header__actions">
        <a href="<?= e(base_url('admin/ai/test')) ?>" class="pp-btn pp-btn--secondary">Test manual →</a>
        <a href="<?= e(base_url('admin/ai/prompts')) ?>" class="pp-btn pp-btn--secondary">Explorador de prompts →</a>
        <a href="<?= e(base_url('admin/settings/ai')) ?>" class="pp-btn pp-btn--secondary">Ajustes IA →</a>
    </div>
</div>

<p class="pp-page-intro">
    Historial de llamadas al proveedor de IA con tokens, latencia y coste estimado.
</p>

<div class="pp-ai-kpi-grid">
    <?php foreach ([
        ['label' => 'Hoy',              'data' => $kpiToday],
        ['label' => 'Últimos 30 días',  'data' => $kpiMonth],
        ['label' => 'Acumulado',        'data' => $kpiAll],
    ] as $kpi): $d = $kpi['data']; ?>
        <div class="pp-ai-kpi">
            <div class="pp-ai-kpi__label"><?= e($kpi['label']) ?></div>
            <div class="pp-ai-kpi__value"><?= $fmtNum($d['calls']) ?><small> llamadas</small></div>
            <div class="pp-ai-kpi__row">
                <span><?= $fmtNum($d['tokens_in']) ?> in / <?= $fmtNum($d['tokens_out']) ?> out tokens</span>
            </div>
            <div class="pp-ai-kpi__row pp-ai-kpi__cost"><?= $fmtCost($d['cost']) ?></div>
            <?php if ($d['errors'] > 0): ?>
                <div class="pp-ai-kpi__errors"><?= $d['errors'] ?> error<?= $d['errors'] !== 1 ? 'es' : '' ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($byModel !== [] || $byAction !== []): ?>
<div class="pp-ai-summary-grid">
    <?php if ($byAction !== []): ?>
        <div class="pp-ai-summary">
            <h3>Por acción (30 días)</h3>
            <table class="pp-table pp-table--compact">
                <thead>
                    <tr>
                        <th>Acción</th>
                        <th class="right">Llamadas</th>
                        <th class="right">Tokens</th>
                        <th class="right">Coste</th>
                        <th class="right">Errores</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($byAction as $r): ?>
                    <tr>
                        <td><code><?= e($r['action_type']) ?></code></td>
                        <td class="right"><?= $fmtNum((int) $r['calls']) ?></td>
                        <td class="right"><?= $fmtNum((int) $r['tokens']) ?></td>
                        <td class="right"><?= $fmtCost((float) $r['cost']) ?></td>
                        <td class="right"><?= (int) $r['errors'] ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($byModel !== []): ?>
        <div class="pp-ai-summary">
            <h3>Por modelo (30 días)</h3>
            <table class="pp-table pp-table--compact">
                <thead>
                    <tr>
                        <th>Modelo</th>
                        <th class="right">Llamadas</th>
                        <th class="right">Tokens in/out</th>
                        <th class="right">Coste</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($byModel as $r): ?>
                    <tr>
                        <td>
                            <span class="pp-ai-provider-badge"><?= e($r['provider']) ?></span>
                            <code><?= e($r['model']) ?></code>
                        </td>
                        <td class="right"><?= $fmtNum((int) $r['calls']) ?></td>
                        <td class="right"><?= $fmtNum((int) $r['tokens_in']) ?> / <?= $fmtNum((int) $r['tokens_out']) ?></td>
                        <td class="right"><?= $fmtCost((float) $r['cost']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="pp-ai-logs">
    <h3>Llamadas recientes <small>(<?= $fmtNum($total) ?> total)</small></h3>

    <?php if ($logs === []): ?>
        <div class="pp-empty-state">
            <p>Aún no se ha registrado ninguna llamada a IA para este sitio.</p>
            <a href="<?= e(base_url('admin/ai/test')) ?>" class="pp-btn pp-btn--primary">Hacer una llamada de test →</a>
        </div>
    <?php else: ?>
        <table class="pp-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Modelo</th>
                    <th class="right">Tokens</th>
                    <th class="right">Latencia</th>
                    <th class="right">Coste</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr class="<?= $log['status'] === 'error' ? 'is-error' : '' ?>">
                    <td class="mono"><?= e(date('Y-m-d H:i:s', strtotime((string) $log['created_at']))) ?></td>
                    <td><?= e($log['username'] ?? '—') ?></td>
                    <td><code><?= e((string) $log['action_type']) ?></code></td>
                    <td>
                        <span class="pp-ai-provider-badge"><?= e((string) $log['provider']) ?></span>
                        <code><?= e((string) $log['model']) ?></code>
                    </td>
                    <td class="right mono"><?= $fmtNum((int) $log['tokens_input']) ?>/<?= $fmtNum((int) $log['tokens_output']) ?></td>
                    <td class="right mono"><?= $log['duration_ms'] !== null ? $fmtNum((int) $log['duration_ms']) . ' ms' : '—' ?></td>
                    <td class="right mono"><?= $fmtCost((float) $log['estimated_cost']) ?></td>
                    <td>
                        <?php if ($log['status'] === 'error'): ?>
                            <span class="pp-status-badge pp-status-badge--error" title="<?= e((string) ($log['error_message'] ?? '')) ?>">error</span>
                        <?php else: ?>
                            <span class="pp-status-badge pp-status-badge--success">ok</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <nav class="pp-pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="pp-btn pp-btn--secondary">← Anterior</a>
                <?php endif; ?>
                <span class="pp-pagination__info">Página <?= $page ?> de <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="pp-btn pp-btn--secondary">Siguiente →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
