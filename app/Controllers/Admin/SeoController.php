<?php

namespace App\Controllers\Admin;

use App\Services\LinkAuditService;
use App\Services\PostMetaService;
use App\Services\Seo404Service;
use App\Services\SeoRedirectService;
use App\Services\SeoTechnicalAuditService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

final class SeoController
{
    public function index(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $tab = (string) Request::get('tab', 'summary');
        if (!in_array($tab, ['summary', 'redirects', '404', 'links', 'advanced'], true)) $tab = 'summary';

        $redirects = SeoRedirectService::list($siteId, 200);
        $notFound = Seo404Service::list($siteId, (string) Request::get('status', 'open'), 120);
        $linkIssues = LinkAuditService::audit($siteId);
        $metaIssues = self::metaIssues($siteId);
        $technicalIssues = SeoTechnicalAuditService::audit($siteId);

        $data = array_merge(DashboardController::getCommonData(), [
            'tab' => $tab,
            'csrf' => CSRF::token(),
            'redirects' => $redirects,
            'notFound' => $notFound,
            'notFoundStatus' => (string) Request::get('status', 'open'),
            'linkIssues' => $linkIssues,
            'linksByPage' => self::groupLinksByPage($linkIssues),
            'sectionTypes' => SectionController::SECTION_TYPES,
            'metaIssues' => $metaIssues,
            'technicalIssues' => $technicalIssues,
            'indexation' => self::indexationStatus($siteId),
            'kpis' => [
                'open404' => Seo404Service::countOpen($siteId),
                'activeRedirects' => self::activeRedirectCount($redirects),
                'linkIssues' => count($linkIssues),
                'metaIssues' => count($metaIssues),
                'technicalIssues' => count($technicalIssues),
            ],
        ]);

        View::send('admin/seo/index', $data);
    }

    public function storeRedirect(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $source = (string) Request::post('source_path', '');
        $target = (string) Request::post('target_path', '');
        $status = (int) Request::post('status_code', 301);
        $from404 = (int) Request::post('from_404_id', 0);

        try {
            $redirect = SeoRedirectService::createManual(
                $siteId,
                $source,
                $status === 410 ? null : $target,
                $status,
                Auth::id()
            );
            if ($from404 > 0) {
                Seo404Service::mark($siteId, $from404, 'resolved', (int) ($redirect['id'] ?? 0));
            }
            Session::flash('success', 'Redirección guardada.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }

        Response::redirect(base_url('admin/seo?tab=redirects'));
    }

    public function redirectAction(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $action = (string) Request::post('action', '');

        if ($action === 'deactivate') {
            SeoRedirectService::deactivate($siteId, $id);
            Session::flash('success', 'Redirección pausada.');
        } elseif ($action === 'activate') {
            SeoRedirectService::activate($siteId, $id);
            Session::flash('success', 'Redirección activada.');
        } elseif ($action === 'delete') {
            SeoRedirectService::delete($siteId, $id);
            Session::flash('success', 'Redirección eliminada.');
        }

        Response::redirect(base_url('admin/seo?tab=redirects'));
    }

    public function notFoundAction(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $action = (string) Request::post('action', '');

        if ($action === 'ignore') {
            Seo404Service::mark($siteId, $id, 'ignored');
            Session::flash('success', '404 ignorado.');
        } elseif ($action === 'open') {
            Seo404Service::mark($siteId, $id, 'open');
            Session::flash('success', '404 reabierto.');
        } elseif ($action === 'resolved') {
            Seo404Service::mark($siteId, $id, 'resolved');
            Session::flash('success', '404 marcado como resuelto.');
        }

        Response::redirect(base_url('admin/seo?tab=404'));
    }

    public function links(array $params = []): void
    {
        Response::redirect(base_url('admin/seo?tab=links'));
    }

    private static function metaIssues(int $siteId): array
    {
        PostMetaService::ensureSchema();

        $pages = Database::select(
            'SELECT p.id, p.title, p.slug, p.page_type, p.render_mode, p.meta_title, p.meta_description, p.status, p.seo_noindex,
                    pm.excerpt AS post_excerpt
             FROM pages p
             LEFT JOIN post_meta pm ON pm.page_id = p.id
             WHERE p.site_id = ? AND p.status = "published"
             ORDER BY p.updated_at DESC',
            [$siteId]
        );

        $issues = [];
        foreach ($pages as $page) {
            if ((int) ($page['seo_noindex'] ?? 0) === 1) continue;
            $desc = trim((string) ($page['meta_description'] ?? ''));
            if ($desc === '' && ($page['page_type'] ?? '') === 'article') {
                $desc = trim((string) ($page['post_excerpt'] ?? ''));
            }
            $title = trim((string) ($page['meta_title'] ?? ''));
            $notes = [];
            if ($desc === '') $notes[] = 'Sin meta descripción';
            if ($desc !== '' && mb_strlen($desc) < 70) $notes[] = 'Descripción muy corta';
            if (mb_strlen($desc) > 160) $notes[] = 'Descripción larga';
            if ($title === '') $notes[] = 'Sin meta título específico';
            if (mb_strlen($title) > 65) $notes[] = 'Título largo';

            if ($notes !== []) {
                $page['notes'] = $notes;
                $page['edit_url'] = self::editUrl($page);
                $issues[] = $page;
            }
        }
        return $issues;
    }

    private static function indexationStatus(int $siteId): array
    {
        $published = (int) (Database::selectOne(
            "SELECT COUNT(*) AS c FROM pages
             WHERE site_id = ? AND status = 'published'
               AND COALESCE(seo_noindex, 0) = 0
               AND COALESCE(seo_exclude_sitemap, 0) = 0",
            [$siteId]
        )['c'] ?? 0);
        $site = Database::selectOne('SELECT url FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?? [];
        $configured = rtrim(trim((string) ($site['url'] ?? '')), '/');
        $base = $configured !== '' && preg_match('#^https?://#i', $configured) ? $configured : rtrim(base_url(''), '/');

        return [
            'published_pages' => $published,
            'sitemap_url' => $base . '/sitemap.xml',
            'robots_url' => $base . '/robots.txt',
        ];
    }

    private static function editUrl(array $page): string
    {
        $id = (int) $page['id'];
        if (($page['page_type'] ?? '') === 'article') return base_url('admin/posts/' . $id . '/edit#pp-post-seo');
        if (($page['render_mode'] ?? '') === 'canvas') return base_url('admin/canvas/' . $id);
        return base_url('admin/pages/' . $id . '/edit');
    }

    private static function activeRedirectCount(array $redirects): int
    {
        $n = 0;
        foreach ($redirects as $r) {
            if ((int) ($r['is_active'] ?? 0) === 1) $n++;
        }
        return $n;
    }

    private static function groupLinksByPage(array $issues): array
    {
        $byPage = [];
        foreach ($issues as $i) {
            $byPage[$i['page_id']]['title'] = $i['page_title'];
            $byPage[$i['page_id']]['issues'][] = $i;
        }
        return $byPage;
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
