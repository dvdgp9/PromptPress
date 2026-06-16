<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ArticleTemplateService;
use App\Services\DesignSystem;
use Core\Database;

$failed = 0;
function check_article_template(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);
if ($siteId <= 0) {
    echo "SKIP no_site\n";
    exit(0);
}

$previous = Database::selectOne(
    'SELECT setting_value, is_encrypted FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
    [$siteId, ArticleTemplateService::SETTING_KEY]
);

Database::execute(
    'DELETE FROM settings WHERE site_id = ? AND setting_key = ?',
    [$siteId, ArticleTemplateService::SETTING_KEY]
);

check_article_template('default_without_setting', ArticleTemplateService::forSite($siteId) === 'classic');
check_article_template('normalize_unknown', ArticleTemplateService::normalize('unknown') === 'classic');
check_article_template('normalize_allowed', ArticleTemplateService::normalize('magazine') === 'magazine');
check_article_template('body_class', ArticleTemplateService::bodyClass('magazine') === 'pp-article-template--magazine');
check_article_template('options_include_four', count(ArticleTemplateService::options()) === 4);
$css = DesignSystem::renderSectionBaseCss();
check_article_template('css_has_visual_template', str_contains($css, '.pp-article-page.pp-article-template--visual .pp-article-hero'), 'visual CSS missing');
check_article_template('css_has_visual_mobile', str_contains($css, '@media (max-width: 780px)') && str_contains($css, 'aspect-ratio: 4 / 3'), 'visual mobile CSS missing');

Database::execute(
    'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
     VALUES (?, ?, ?, 0)',
    [$siteId, ArticleTemplateService::SETTING_KEY, 'visual']
);
check_article_template('setting_visual', ArticleTemplateService::forSite($siteId) === 'visual');

Database::execute(
    'UPDATE settings SET setting_value = ? WHERE site_id = ? AND setting_key = ?',
    ['bad-value', $siteId, ArticleTemplateService::SETTING_KEY]
);
check_article_template('setting_invalid_fallback', ArticleTemplateService::forSite($siteId) === 'classic');

Database::execute(
    'DELETE FROM settings WHERE site_id = ? AND setting_key = ?',
    [$siteId, ArticleTemplateService::SETTING_KEY]
);
if ($previous) {
    Database::execute(
        'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
         VALUES (?, ?, ?, ?)',
        [
            $siteId,
            ArticleTemplateService::SETTING_KEY,
            (string) $previous['setting_value'],
            (int) $previous['is_encrypted'],
        ]
    );
}

exit($failed > 0 ? 1 : 0);
