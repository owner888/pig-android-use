<?php

declare(strict_types=1);

namespace PigAndroidUse;

/**
 * Android Accessibility XML tree parser.
 * Compresses raw hierarchy XML (often 200KB+) into a lightweight, token-saving compact JSON tree.
 * 100% pure PHP without external dependencies.
 */
final class TreeParser
{
    /**
     * Parse raw uiautomator XML string into compact nodes.
     *
     * @param array{float, float} $pixelToPoint
     * @return list<array{
     *     type: string,
     *     tap: array{int, int},
     *     text?: string,
     *     desc?: string,
     *     id?: string,
     *     clickable?: bool,
     *     rect: array{int, int, int, int}
     * }>
     *
     * @param int|null $total set to how many useful nodes the tree has, those past $maxNodes included
     */
    public static function parse(string $xmlContent, array $pixelToPoint = [1.0, 1.0], int $maxNodes = 60, ?int &$total = null): array
    {
        $total = 0;

        if (trim($xmlContent) === '') {
            return [];
        }

        set_error_handler(static fn (): bool => true);
        try {
            $xml = simplexml_load_string($xmlContent);
        } finally {
            restore_error_handler();
        }

        if ($xml === false) {
            return [];
        }

        $nodes = [];
        $scaleX = $pixelToPoint[0] > 0 ? (1.0 / $pixelToPoint[0]) : 1.0;
        $scaleY = $pixelToPoint[1] > 0 ? (1.0 / $pixelToPoint[1]) : 1.0;

        self::walk($xml, $nodes, $scaleX, $scaleY, $maxNodes, $total);

        return $nodes;
    }

    /**
     * Recursively walk XML nodes and extract meaningful interactive or textual elements.
     *
     * Every useful node is counted in $total; only the first $maxNodes are kept. The walk goes on
     * past the limit to count the rest, so the caller can say how many were left out.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private static function walk(
        \SimpleXMLElement $element,
        array &$nodes,
        float $scaleX,
        float $scaleY,
        int $maxNodes,
        int &$total
    ): void {

        $attrs = $element->attributes();
        if ($attrs !== null && isset($attrs['bounds'])) {
            $boundsStr = (string) $attrs['bounds'];
            $rect = self::parseBounds($boundsStr, $scaleX, $scaleY);

            $text = trim((string) ($attrs['text'] ?? ''));
            $desc = trim((string) ($attrs['content-desc'] ?? ''));
            $resId = trim((string) ($attrs['resource-id'] ?? ''));
            $class = (string) ($attrs['class'] ?? '');
            $clickable = ((string) ($attrs['clickable'] ?? 'false')) === 'true';

            // Filter: only keep if visible area > 0 and has text/desc or is clickable/interactive
            $isInteresting = ($text !== '' || $desc !== '' || $clickable);

            if ($rect !== null && $rect[2] > 0 && $rect[3] > 0 && $isInteresting) {
                // Shorten class name: e.g. "android.widget.TextView" -> "TextView"
                $type = $class !== '' ? basename(str_replace('.', '/', $class)) : 'View';

                // Where to tap it, worked out here: a model left to compute x + w / 2 from the rect
                // guesses instead — and divides the screen by the number of tabs.
                $item = [
                    'type' => $type,
                    'tap' => [$rect[0] + intdiv($rect[2], 2), $rect[1] + intdiv($rect[3], 2)],
                    'rect' => $rect,
                ];

                if ($text !== '') {
                    $item['text'] = mb_substr($text, 0, 80);
                }
                if ($desc !== '') {
                    $item['desc'] = mb_substr($desc, 0, 80);
                }
                if ($resId !== '') {
                    // Shorten resource-id: "com.tencent.mm:id/title" -> "id/title"
                    $shortId = str_contains($resId, ':') ? substr($resId, strpos($resId, ':') + 1) : $resId;
                    $item['id'] = $shortId;
                }
                if ($clickable) {
                    $item['clickable'] = true;
                }

                $total++;
                if (count($nodes) < $maxNodes) {
                    $nodes[] = $item;
                }
            }
        }

        foreach ($element->children() as $child) {
            self::walk($child, $nodes, $scaleX, $scaleY, $maxNodes, $total);
        }
    }

    /**
     * Parse bounds string like "[0,120][1080,2400]" into [x, y, width, height].
     *
     * @return array{int, int, int, int}|null
     */
    public static function parseBounds(string $bounds, float $scaleX = 1.0, float $scaleY = 1.0): ?array
    {
        if (preg_match('/\[(\d+),(\d+)\]\[(\d+),(\d+)\]/', $bounds, $m)) {
            $x1 = (int) $m[1];
            $y1 = (int) $m[2];
            $x2 = (int) $m[3];
            $y2 = (int) $m[4];

            $x = (int) round($x1 * $scaleX);
            $y = (int) round($y1 * $scaleY);
            $w = (int) round(($x2 - $x1) * $scaleX);
            $h = (int) round(($y2 - $y1) * $scaleY);

            return [$x, $y, max(0, $w), max(0, $h)];
        }

        return null;
    }
}
