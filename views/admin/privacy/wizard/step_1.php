<?php
/** Paso 1 — Datos de tu empresa. Reutiliza el partial tab_legal.php. */
$formAction  = base_url('admin/privacy/wizard/step1');
$submitLabel = __('privacy.wizard.next_cookies') . ' →';
$hideSubmit  = false;
include __DIR__ . '/../tab_legal.php';
