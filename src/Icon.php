<?php

/**
 * Renders a locally-bundled Lucide SVG icon (public/img/lucide/*.svg) as an
 * <img> tag. No remote/CDN requests, per plugin convention.
 */
class PluginTregopluginsIcon
{
    public static function html(string $name, int $size = 20, string $class = ''): string
    {
        $url = Plugin::getWebDir('tregoplugins') . '/public/img/lucide/' . $name . '.svg';

        return sprintf(
            "<img src='%s' width='%d' height='%d' alt='' aria-hidden='true' class='%s' style='vertical-align:text-bottom'>",
            Html::entities_deep($url),
            $size,
            $size,
            Html::entities_deep($class)
        );
    }
}
