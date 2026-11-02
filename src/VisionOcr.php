<?php

declare(strict_types=1);

namespace PigAndroidUse;

use Pig\Tui\Process;
use RuntimeException;

/**
 * Text on a screenshot, read by Apple's Vision framework through `osascript -l JavaScript`
 * (`resources/vision-ocr.js`): nothing to install, Chinese and English, macOS only.
 *
 * Elsewhere there is no OCR: `available()` says so and the caller sends the screenshot to the
 * model instead, saying why.
 */
final class VisionOcr
{
    private const string OSASCRIPT = '/usr/bin/osascript';

    public static function available(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && is_executable(self::OSASCRIPT);
    }

    /**
     * The lines of text on an image, in its own pixels.
     *
     * @return list<array{text: string, confidence: float, rect: array{int, int, int, int}}> rect is
     *         x, y, width, height from the top left — the coordinates `android_tap` takes for that screenshot
     */
    public static function recognize(string $imagePath, int $width, int $height): array
    {
        if (!self::available()) {
            throw new RuntimeException('Vision OCR runs on macOS only.');
        }

        [$exit, $stdout, $stderr] = Process::run(
            [self::OSASCRIPT, '-l', 'JavaScript', dirname(__DIR__) . '/resources/vision-ocr.js', $imagePath],
            timeout: 20.0,
        );

        if ($exit !== 0) {
            throw new RuntimeException("Vision OCR failed (exit {$exit}): " . trim($stderr));
        }

        $decoded = json_decode(trim($stdout), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Vision OCR did not return JSON: ' . mb_substr(trim($stdout), 0, 200));
        }

        return self::toLines($decoded, $width, $height);
    }

    /**
     * Vision's observations to lines: its boxes are fractions of the image with the origin at the
     * bottom left; these are pixels from the top left.
     *
     * @param array<mixed> $observations each {text, confidence, x, y, w, h}
     * @return list<array{text: string, confidence: float, rect: array{int, int, int, int}}>
     */
    public static function toLines(array $observations, int $width, int $height): array
    {
        $lines = [];

        foreach ($observations as $o) {
            if (!is_array($o) || !is_string($o['text'] ?? null) || trim($o['text']) === '') {
                continue;
            }

            $x = (float) ($o['x'] ?? 0);
            $y = (float) ($o['y'] ?? 0);
            $w = (float) ($o['w'] ?? 0);
            $h = (float) ($o['h'] ?? 0);

            $lines[] = [
                'text' => trim($o['text']),
                'confidence' => round((float) ($o['confidence'] ?? 0), 2),
                'rect' => [
                    (int) round($x * $width),
                    (int) round((1.0 - $y - $h) * $height),
                    (int) round($w * $width),
                    (int) round($h * $height),
                ],
            ];
        }

        return $lines;
    }
}
