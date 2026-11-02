<?php

declare(strict_types=1);

namespace PigAndroidUse;

use RuntimeException;

/**
 * Android touch, gesture, hardware buttons and Unicode typing manager.
 * 100% pure PHP using native ADB commands.
 */
final class InputManager
{
    public const array BUTTON_KEYCODES = [
        'home' => 3,
        'back' => 4,
        'menu' => 82,
        'power' => 26,
        'volumeup' => 24,
        'volumedown' => 25,
        'enter' => 66,
        'app_switch' => 187,
        'recent' => 187,
    ];

    /**
     * Tap coordinate with automatic resolution conversion and optional anti-ban jitter.
     *
     * @param array{float, float} $pixelToPoint
     * @return array{real_x: int, real_y: int}
     */
    public static function tap(
        AdbClient $adb,
        float $x,
        float $y,
        array $pixelToPoint = [1.0, 1.0],
        ?string $serial = null,
        bool $humanJitter = true,
        ?int $display = null
    ): array {
        $scaleX = $pixelToPoint[0] ?? 1.0;
        $scaleY = $pixelToPoint[1] ?? 1.0;

        $jitterX = $humanJitter ? random_int(-2, 2) : 0;
        $jitterY = $humanJitter ? random_int(-2, 2) : 0;

        $realX = max(0, (int) round($x * $scaleX) + $jitterX);
        $realY = max(0, (int) round($y * $scaleY) + $jitterY);

        $adb->executeShell(self::input($display) . " tap {$realX} {$realY}", $serial);

        return ['real_x' => $realX, 'real_y' => $realY];
    }

    /**
     * Drag/swipe from (x1, y1) to (x2, y2).
     *
     * @param array{float, float} $pixelToPoint
     * @return array{from_x: int, from_y: int, to_x: int, to_y: int, duration_ms: int}
     */
    public static function swipe(
        AdbClient $adb,
        float $fromX,
        float $fromY,
        float $toX,
        float $toY,
        int $durationMs = 300,
        array $pixelToPoint = [1.0, 1.0],
        ?string $serial = null,
        ?int $display = null
    ): array {
        $scaleX = $pixelToPoint[0] ?? 1.0;
        $scaleY = $pixelToPoint[1] ?? 1.0;

        $x1 = max(0, (int) round($fromX * $scaleX));
        $y1 = max(0, (int) round($fromY * $scaleY));
        $x2 = max(0, (int) round($toX * $scaleX));
        $y2 = max(0, (int) round($toY * $scaleY));
        $duration = max(50, $durationMs);

        $adb->executeShell(self::input($display) . " swipe {$x1} {$y1} {$x2} {$y2} {$duration}", $serial);

        return [
            'from_x' => $x1,
            'from_y' => $y1,
            'to_x' => $x2,
            'to_y' => $y2,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Swipe in a cardinal direction relative to the screen size.
     *
     * @param array{width: int, height: int} $screenSize
     * @return array{direction: string, from_x: int, from_y: int, to_x: int, to_y: int}
     */
    public static function swipeDirection(
        AdbClient $adb,
        string $direction,
        array $screenSize,
        int $durationMs = 300,
        ?string $serial = null,
        ?int $display = null
    ): array {
        $w = $screenSize['width'];
        $h = $screenSize['height'];
        $midX = (int) round($w / 2.0);
        $midY = (int) round($h / 2.0);
        $distX = (int) round($w * 0.35);
        $distY = (int) round($h * 0.35);

        [$fromX, $fromY, $toX, $toY] = match (strtolower($direction)) {
            'up' => [$midX, $midY + $distY, $midX, $midY - $distY],
            'down' => [$midX, $midY - $distY, $midX, $midY + $distY],
            'left' => [$midX + $distX, $midY, $midX - $distX, $midY],
            'right' => [$midX - $distX, $midY, $midX + $distX, $midY],
            default => throw new RuntimeException("Invalid swipe direction '{$direction}'. Use up, down, left, or right."),
        };

        self::swipe($adb, (float) $fromX, (float) $fromY, (float) $toX, (float) $toY, $durationMs, [1.0, 1.0], $serial, $display);

        return [
            'direction' => $direction,
            'from_x' => $fromX,
            'from_y' => $fromY,
            'to_x' => $toX,
            'to_y' => $toY,
        ];
    }

    /**
     * Types text into the focused field.
     *
     * Plain ASCII words go as key events (`input text`), as a keyboard would send them. Anything
     * else — Chinese, emoji, spaces, several lines — the helper sets into the field directly and
     * reads back: key events carry ASCII only, and pasting reads the clipboard, which Android 10
     * and later keeps from an app without the focus (an app on the background screen, always).
     * Only where the helper finds no field (a game draws its own) or cannot run does it fall back
     * to the clipboard, and then the result says it could not check.
     *
     * @return array{method: string, verified: bool|null, field?: string, text?: string, note?: string}
     */
    public static function typeText(AdbClient $adb, string $text, ?string $serial = null, ?int $display = null, ?Helper $helper = null): array
    {
        if ($text === '') {
            return ['method' => 'none', 'verified' => true];
        }

        if (preg_match('/^[a-zA-Z0-9._\-]+$/', $text) === 1) {
            $adb->executeShell(self::input($display) . " text '" . addcslashes($text, "'\\") . "'", $serial);

            return ['method' => 'keys', 'verified' => null];
        }

        $why = 'the helper is not available';
        if ($helper !== null) {
            try {
                $reply = $helper->type($serial, $display ?? Helper::MAIN_DISPLAY, $text);
                if ($reply['typed'] ?? false) {
                    $result = ['method' => 'set_text', 'verified' => $reply['verified'] ?? null];
                    foreach (['field', 'text'] as $key) {
                        if (isset($reply[$key])) {
                            $result[$key] = $reply[$key];
                        }
                    }
                    if ($result['verified'] === false) {
                        $result['note'] = 'The field took the text but does not show it: the app may have changed or limited it. Look before going on.';
                    }

                    return $result;
                }
                $why = (string) ($reply['reason'] ?? 'the field did not take it');
            } catch (\Throwable $e) {
                $why = $e->getMessage();
            }
        }

        // Last resort: the clipboard, and a paste.
        $adb->executeShell('cmd clipboard set text ' . escapeshellarg($text), $serial);
        $adb->executeShell(self::input($display) . ' keyevent 279', $serial);

        return [
            'method' => 'paste',
            'verified' => false,
            'note' => "Pasted, unchecked ({$why}). An app on the background screen cannot read the clipboard, so the paste may have put nothing there: tap the field and look with android_tree or a screenshot.",
        ];
    }

    /**
     * Press a hardware or navigation button.
     */
    public static function pressButton(AdbClient $adb, string $button, ?string $serial = null, ?int $display = null): void
    {
        $normalized = strtolower(trim($button));
        $keycode = self::BUTTON_KEYCODES[$normalized] ?? null;

        if ($keycode === null) {
            $known = implode(', ', array_keys(self::BUTTON_KEYCODES));
            throw new RuntimeException("Unknown button '{$button}'. Available buttons: {$known}");
        }

        $adb->executeShell(self::input($display) . " keyevent {$keycode}", $serial);
    }

    /**
     * Execute a sequence of operations with humanized anti-ban micro-delays between steps.
     *
     * @param list<array{op: string, args?: array<string, mixed>}> $steps
     * @param array{float, float} $pixelToPoint
     * @return array{completed: int, results: list<array<string, mixed>>}
     */
    /** `input`, or `input -d N` for a display other than the main screen. */
    public static function input(?int $display): string
    {
        return $display === null ? 'input' : "input -d {$display}";
    }

    public static function batch(
        AdbClient $adb,
        array $steps,
        array $pixelToPoint = [1.0, 1.0],
        ?string $serial = null,
        ?int $display = null,
        ?Helper $helper = null
    ): array {
        $results = [];
        $completed = 0;

        foreach ($steps as $step) {
            $op = (string) ($step['op'] ?? '');
            $args = is_array($step['args'] ?? null) ? $step['args'] : [];

            switch ($op) {
                case 'tap':
                    $x = (float) ($args['x'] ?? 0);
                    $y = (float) ($args['y'] ?? 0);
                    $res = self::tap($adb, $x, $y, $pixelToPoint, $serial, humanJitter: true, display: $display);
                    $results[] = ['op' => 'tap', 'ok' => true, 'tapped' => $res];
                    break;

                case 'swipe':
                    if (isset($args['from_x'], $args['from_y'], $args['to_x'], $args['to_y'])) {
                        $res = self::swipe(
                            $adb,
                            (float) $args['from_x'],
                            (float) $args['from_y'],
                            (float) $args['to_x'],
                            (float) $args['to_y'],
                            (int) ($args['duration_ms'] ?? 300),
                            $pixelToPoint,
                            $serial,
                            $display
                        );
                        $results[] = ['op' => 'swipe', 'ok' => true, 'gesture' => $res];
                    } elseif (!empty($args['direction'])) {
                        $screen = $adb->screenSize($serial) ?? ['width' => 1080, 'height' => 2400];
                        $res = self::swipeDirection($adb, (string) $args['direction'], $screen, (int) ($args['duration_ms'] ?? 300), $serial, $display);
                        $results[] = ['op' => 'swipe', 'ok' => true, 'gesture' => $res];
                    }
                    break;

                case 'type_text':
                    $text = (string) ($args['text'] ?? '');
                    $typed = self::typeText($adb, $text, $serial, $display, $helper);
                    $results[] = ['op' => 'type_text', 'ok' => $typed['verified'] !== false, 'length' => mb_strlen($text)] + $typed;
                    break;

                case 'press_button':
                    $btn = (string) ($args['name'] ?? 'back');
                    self::pressButton($adb, $btn, $serial, $display);
                    $results[] = ['op' => 'press_button', 'ok' => true, 'button' => $btn];
                    break;

                case 'wait':
                    $sec = max(0.1, min(10.0, (float) ($args['seconds'] ?? 1.0)));
                    usleep((int) round($sec * 1000000));
                    $results[] = ['op' => 'wait', 'ok' => true, 'seconds' => $sec];
                    break;

                default:
                    throw new RuntimeException("Unsupported batch operation '{$op}'.");
            }

            $completed++;

            // Inject humanized anti-ban micro-jitter delay between actions (150ms ~ 350ms)
            if ($completed < count($steps)) {
                usleep(random_int(150000, 350000));
            }
        }

        return ['completed' => $completed, 'results' => $results];
    }
}
