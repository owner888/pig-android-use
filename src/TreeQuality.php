<?php

declare(strict_types=1);

namespace PigAndroidUse;

/**
 * Whether a screen's view tree is enough to work from, or the model needs the screen's text or
 * the screenshot as well — an app that draws its own UI (a game, a canvas, a map, a WebView the
 * dump cannot see into) gives a tree with few names or none.
 *
 * The tree is judged first, cheaply. Only a tree that is clearly enough is let through on its own:
 * enough named elements, most controls named, and no large area of the window with no text in it.
 * Anything less certain is `null` — read the text on the screen (OCR) and compare: three lines or
 * more that the tree does not have make it `partial`.
 *
 * The rules and thresholds follow Mika's `TreeQuality.kt` (Apache-2.0), rewritten for the
 * `uiautomator dump` XML this package reads — plus one of this package's own: a canvas covering
 * most of the screen (a game's SurfaceView, a blind area of half the screen) makes the tree
 * unusable outright, however little text OCR then finds on it.
 */
final class TreeQuality
{
    public const string ADEQUATE = 'adequate';
    public const string PARTIAL = 'partial';
    public const string UNUSABLE = 'unusable';

    /** Fewer named elements than this and the tree cannot tell: a simple page looks like a broken one. */
    private const int ENOUGH_NAMED = 6;

    /** A subtree with no text that covers this much of the screen (0–1) is a blind area. */
    private const float BLIND_AREA = 0.2;

    /** A blind area or a drawing surface this large (0–1) is the screen: the tree has nothing of it. */
    private const float CANVAS_AREA = 0.5;

    /** Views an app draws into itself, which the tree knows only as one box. */
    private const string SURFACE_CLASSES = '/(SurfaceView|TextureView)$/';

    /** With this many named elements inside it, a textless node is a backdrop under them, not a blind area. */
    private const int NAMED_INSIDE = 3;

    /** OCR lines the tree does not have, from which on the tree is missing a part. */
    private const int MISSING_LINES = 3;

    /** An OCR line below this confidence, or shorter than this many characters, is not counted. */
    private const float MIN_CONFIDENCE = 0.5;
    private const int MIN_CHARS = 2;

    /**
     * Judge the tree alone.
     *
     * @return array{quality: string|null, reason: string|null} null quality: read the screen's text to decide
     */
    public static function ofTree(string $xml): array
    {
        $root = self::load($xml);

        if ($root === null) {
            return ['quality' => self::UNUSABLE, 'reason' => 'no_tree'];
        }

        $elements = [];
        self::flatten($root, $elements);

        if ($elements === []) {
            return ['quality' => self::UNUSABLE, 'reason' => 'empty_elements'];
        }

        $named = array_values(array_filter($elements, static fn (array $e): bool => $e['name'] !== ''));
        $controls = array_values(array_filter($elements, static fn (array $e): bool => $e['control']));
        $namedControls = count(array_filter($controls, static fn (array $e): bool => $e['labelled']));

        if ($controls !== [] && ($namedControls === 0 || (count($controls) >= 4 && $namedControls * 3 <= count($controls)))) {
            return ['quality' => $named === [] ? self::UNUSABLE : self::PARTIAL, 'reason' => 'unlabeled_controls'];
        }

        if ($named === []) {
            return ['quality' => self::UNUSABLE, 'reason' => 'no_text'];
        }

        // A game or a map: one surface the app draws, or half the screen with no text in it. OCR
        // finding little on its loading screen does not make the tree any better.
        if (self::surfaceArea($root) >= self::CANVAS_AREA || self::blindArea($root, $named) >= self::CANVAS_AREA) {
            return ['quality' => self::UNUSABLE, 'reason' => 'canvas'];
        }

        if (count($named) < self::ENOUGH_NAMED || self::blindArea($root, $named) >= self::BLIND_AREA) {
            return ['quality' => null, 'reason' => null];
        }

        return ['quality' => self::ADEQUATE, 'reason' => null];
    }

    /**
     * Settle a tree the tree alone could not judge, by the screen text it lacks; a judged tree is
     * returned as it is.
     *
     * @param array{quality: string|null, reason: string|null} $tree
     * @param list<array{text: string, confidence: float, rect: array{int, int, int, int}}> $missing from `missingLines()`
     * @return array{quality: string|null, reason: string|null}
     */
    public static function withText(array $tree, array $missing): array
    {
        if ($tree['quality'] !== null) {
            return $tree;
        }

        return count($missing) >= self::MISSING_LINES
            ? ['quality' => self::PARTIAL, 'reason' => 'text_outside_tree']
            : ['quality' => self::ADEQUATE, 'reason' => null];
    }

    /**
     * The OCR lines whose text is nowhere in the tree — what the model would not see from the tree
     * alone. Lines too uncertain or too short to mean anything are dropped first.
     *
     * @param list<array{text: string, confidence: float, rect: array{int, int, int, int}}> $lines
     * @return list<array{text: string, confidence: float, rect: array{int, int, int, int}}>
     */
    public static function missingLines(array $lines, string $xml): array
    {
        $root = self::load($xml);
        $texts = [];

        if ($root !== null) {
            $elements = [];
            self::flatten($root, $elements);

            foreach ($elements as $element) {
                if ($element['name'] !== '') {
                    $texts[] = self::normalize($element['name']);
                }
            }
        }

        $haystack = implode("\u{1}", $texts);
        $missing = [];

        foreach ($lines as $line) {
            $text = self::normalize($line['text']);

            if ($line['confidence'] < self::MIN_CONFIDENCE || mb_strlen($text) < self::MIN_CHARS) {
                continue;
            }

            if (!str_contains($haystack, $text) && !self::containsAnyOf($text, $texts)) {
                $missing[] = $line;
            }
        }

        return $missing;
    }

    /**
     * A line OCR read as one may hold a tree text with a little around it (a label and its count);
     * it is in the tree when that text is at least half of it.
     *
     * @param list<string> $texts
     */
    private static function containsAnyOf(string $text, array $texts): bool
    {
        foreach ($texts as $t) {
            if (mb_strlen($t) >= self::MIN_CHARS && mb_strlen($t) * 2 >= mb_strlen($text) && str_contains($text, $t)) {
                return true;
            }
        }

        return false;
    }

    /** Lower case, no whitespace or punctuation: OCR and the tree disagree on those more than on the words. */
    private static function normalize(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $text));
    }

    private static function load(string $xml): ?\SimpleXMLElement
    {
        if (trim($xml) === '') {
            return null;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $root = simplexml_load_string($xml);
        } finally {
            restore_error_handler();
        }

        return $root === false ? null : $root;
    }

    /**
     * Every node with a visible area, with its own name, whether it is a control, and whether a
     * control has a label — its own text, or text in a child, as a clickable row's title is.
     *
     * @param list<array{name: string, control: bool, labelled: bool, bounds: array{int, int, int, int}}> $elements
     * @return bool whether this subtree has any text
     */
    private static function flatten(\SimpleXMLElement $node, array &$elements): bool
    {
        $attrs = $node->attributes();
        $bounds = $attrs !== null && isset($attrs['bounds']) ? self::bounds((string) $attrs['bounds']) : null;
        $name = $attrs === null ? '' : (trim((string) ($attrs['text'] ?? '')) ?: trim((string) ($attrs['content-desc'] ?? '')));
        $control = $attrs !== null && (((string) ($attrs['clickable'] ?? '')) === 'true' || str_contains((string) ($attrs['class'] ?? ''), 'EditText'));

        $index = null;
        if ($bounds !== null && $bounds[2] > $bounds[0] && $bounds[3] > $bounds[1]) {
            $index = count($elements);
            $elements[] = ['name' => $name, 'control' => $control, 'labelled' => $name !== '', 'bounds' => $bounds];
        }

        $text = $name !== '';
        foreach ($node->children() as $child) {
            if (self::flatten($child, $elements)) {
                $text = true;
            }
        }

        if ($index !== null && $text) {
            $elements[$index]['labelled'] = true;
        }

        return $text;
    }

    /**
     * The largest node with no text anywhere under it that is not a backdrop under named elements,
     * as a share of the screen (0–1).
     *
     * @param list<array{name: string, control: bool, labelled: bool, bounds: array{int, int, int, int}}> $named
     */
    private static function blindArea(\SimpleXMLElement $root, array $named): float
    {
        $screen = self::screen($root);

        if ($screen <= 0) {
            return 0.0;
        }

        $largest = 0.0;
        $visit = static function (\SimpleXMLElement $node) use (&$visit, &$largest, $screen, $named): bool {
            $attrs = $node->attributes();
            $text = $attrs !== null && (trim((string) ($attrs['text'] ?? '')) !== '' || trim((string) ($attrs['content-desc'] ?? '')) !== '');

            foreach ($node->children() as $child) {
                if ($visit($child)) {
                    $text = true;
                }
            }

            $bounds = $attrs !== null && isset($attrs['bounds']) ? self::bounds((string) $attrs['bounds']) : null;

            if (!$text && $bounds !== null && self::area($bounds) / $screen > $largest
                && count(array_filter($named, static fn (array $e): bool => self::inside($e['bounds'], $bounds))) < self::NAMED_INSIDE) {
                $largest = self::area($bounds) / $screen;
            }

            return $text;
        };

        $visit($root);

        return $largest;
    }

    /** The largest view the app draws into itself (SurfaceView, TextureView), as a share of the screen. */
    private static function surfaceArea(\SimpleXMLElement $root): float
    {
        $screen = self::screen($root);
        $largest = 0.0;

        foreach ($screen > 0 ? ($root->xpath('//node[@bounds]') ?: []) : [] as $node) {
            $bounds = self::bounds((string) $node['bounds']);
            if ($bounds !== null && preg_match(self::SURFACE_CLASSES, (string) $node['class']) === 1) {
                $largest = max($largest, self::area($bounds) / $screen);
            }
        }

        return $largest;
    }

    /** The screen's area: the extent of every node's bounds. */
    private static function screen(\SimpleXMLElement $root): int
    {
        $width = 0;
        $height = 0;

        foreach ($root->xpath('//node[@bounds]') ?: [] as $node) {
            $bounds = self::bounds((string) $node['bounds']);
            if ($bounds !== null) {
                $width = max($width, $bounds[2]);
                $height = max($height, $bounds[3]);
            }
        }

        return $width * $height;
    }

    /** @param array{int, int, int, int} $b */
    private static function area(array $b): int
    {
        return max(0, $b[2] - $b[0]) * max(0, $b[3] - $b[1]);
    }

    /**
     * Whether an element's centre is inside the bounds.
     *
     * @param array{int, int, int, int} $e
     * @param array{int, int, int, int} $b
     */
    private static function inside(array $e, array $b): bool
    {
        $x = intdiv($e[0] + $e[2], 2);
        $y = intdiv($e[1] + $e[3], 2);

        return $x >= $b[0] && $x <= $b[2] && $y >= $b[1] && $y <= $b[3];
    }

    /** @return array{int, int, int, int}|null x1, y1, x2, y2 as the dump writes them */
    private static function bounds(string $bounds): ?array
    {
        return preg_match('/\[(-?\d+),(-?\d+)\]\[(-?\d+),(-?\d+)\]/', $bounds, $m) === 1
            ? [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]]
            : null;
    }
}
