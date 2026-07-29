<?php

/**
 * Works around a GLPI core bug: KnowbaseItem::getVisibilityCriteriaKB()
 * OR-adds an unconditional "no entity restriction" clause, so any KB article
 * restricted only by Group/Profile/User (with no explicit Entity target)
 * becomes visible to every user with plain READ/READFAQ right, regardless of
 * the configured restriction.
 *
 * GLPI does not expose a hook inside the affected methods (KnowbaseItem::
 * getListRequest(), ::showList(), ::showRecentPopular()), so the leaked rows
 * cannot be excluded from the SQL itself without patching core. Instead, this
 * guard buffers the HTML of the few endpoints that render KB items, re-checks
 * every referenced KnowbaseItem with the real (per-item, non-buggy)
 * KnowbaseItem::canViewItem(), and strips the rows the current user should
 * not see before the response ever reaches the browser.
 *
 * Known limitation: list/pager counts computed by core before this filter
 * runs (e.g. "10 results") are not adjusted; only the leaked rows themselves
 * are removed.
 */
class PluginTregopluginsKbVisibilityGuard
{
    /**
     * Front controllers that render KnowbaseItem rows outside of the
     * (safe) per-item canViewItem() path.
     */
    private const GUARDED_SCRIPTS = [
        '/front/knowbaseitem.php',
        '/front/helpdesk.faq.php',
        '/front/helpdesk.public.php',
        '/front/report.dynamic.php',
        '/ajax/knowbase.php',
    ];

    public static function boot(): void
    {
        if (PHP_SAPI === 'cli' || !class_exists(DOMDocument::class)) {
            return;
        }

        if (!PluginTregopluginsKbVisibilityConfig::isEnabled()) {
            return;
        }

        if (!self::isGuardedScript()) {
            return;
        }

        ob_start([self::class, 'filterOutput']);
    }

    private static function isGuardedScript(): bool
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        foreach (self::GUARDED_SCRIPTS as $guarded) {
            if (str_ends_with($script, $guarded)) {
                return true;
            }
        }
        return false;
    }

    public static function filterOutput(string $html): string
    {
        if (
            !str_contains($html, 'knowbaseitem.php')
            && !str_contains($html, 'helpdesk.faq.php')
        ) {
            return $html;
        }

        if (
            !preg_match_all(
                '/(?:knowbaseitem\.php|helpdesk\.faq\.php)\?(?:[^"\'<>]*[&])?id=(\d+)/',
                $html,
                $matches
            )
        ) {
            return $html;
        }

        $ids = array_unique(array_map('intval', $matches[1]));

        $forbidden_ids = [];
        $kb            = new KnowbaseItem();
        foreach ($ids as $id) {
            if (!$kb->getFromDB($id)) {
                continue;
            }

            // An article with zero configured targets (no User/Group/Profile/
            // Entity restriction at all) is meant to be open to anyone with
            // KB rights in a reachable entity - that is what the core SQL
            // criteria already (correctly, by design) grants, and
            // CommonDBVisible::haveVisibilityAccess() does not special-case
            // it (it only matches explicit rows), so canViewItem() would
            // wrongly reject it here. Only re-check articles that actually
            // have at least one restriction configured; that's exactly the
            // case the buggy core SQL gets wrong.
            if ($kb->countVisibilities() > 0 && !$kb->canViewItem()) {
                $forbidden_ids[] = $id;
            }
        }

        if (!$forbidden_ids) {
            return $html;
        }

        return self::stripForbiddenRows($html, $forbidden_ids);
    }

    /**
     * Handles both full pages (front/*.php, already carrying their own
     * <!doctype>/<html>/<body>) and bare HTML fragments (ajax/knowbase.php)
     * without needing a synthetic wrapper: NOIMPLIED/NODEFDTD only affect
     * fragments, which are then serialized back out as bare fragments too.
     *
     * @param int[] $forbidden_ids
     */
    private static function stripForbiddenRows(string $html, array $forbidden_ids): string
    {
        $dom               = new DOMDocument();
        $previous_setting  = libxml_use_internal_errors(true);
        $loaded            = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_setting);

        if (!$loaded) {
            // Could not safely parse the response; leave it unfiltered
            // rather than risk mangling or blanking the page.
            return $html;
        }

        $xpath = new DOMXPath($dom);
        $links = $xpath->query(
            "//a[contains(@href, 'knowbaseitem.php?') or contains(@href, 'helpdesk.faq.php?')]"
        );

        $forbidden_lookup = array_flip($forbidden_ids);
        $removed_any      = false;
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (
                !preg_match('/[?&]id=(\d+)(?:&|#|$)/', $href, $href_matches)
                || !isset($forbidden_lookup[(int) $href_matches[1]])
            ) {
                continue;
            }

            $row = self::findRemovableAncestor($link);
            if ($row !== null && $row->parentNode !== null) {
                $row->parentNode->removeChild($row);
                $removed_any = true;
            }
        }

        if (!$removed_any) {
            return $html;
        }

        $out = $dom->saveHTML();
        return $out !== false ? $out : $html;
    }

    private static function findRemovableAncestor(DOMNode $node): ?DOMNode
    {
        $removable_tags = ['tr', 'li'];
        $current        = $node->parentNode;
        while ($current !== null) {
            if (in_array(strtolower($current->nodeName), $removable_tags, true)) {
                return $current;
            }
            $current = $current->parentNode;
        }
        return null;
    }
}
