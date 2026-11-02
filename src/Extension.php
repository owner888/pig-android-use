<?php

declare(strict_types=1);

namespace PigAndroidUse;

use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;

/**
 * Android use for pig: drives a real or emulated Android phone over ADB, in pure PHP.
 *
 * Registers the `/android` command, the `android_*` tools and the `/api/android` screen viewer.
 * Zero runtime dependencies beyond PHP itself and the `adb` binary.
 */
final class Extension
{
    public static function register(ExtensionApi $pi): void
    {
        $adb = new AdbClient();
        ScreenCapture::useDirectory($pi->artifactsDirectory(...));
        /**
         * Each screen's pixel-to-point ratio, by display id: the coordinates the model sees on it
         * (screenshots, tree rects) times this are the device's. Set by every screenshot and every
         * tree read of that screen, so a tap goes where the last look at that screen said.
         *
         * @var array<int, array{float, float}>
         */
        $scales = [];

        /** A screen's ratio; never looked at yet, the one a screenshot of its `wm size` would have. */
        $scaleOf = static function (?int $display, ?string $serial) use ($adb, &$scales): array {
            $key = $display ?? Helper::MAIN_DISPLAY;
            if (!isset($scales[$key])) {
                $size = $adb->screenSize($serial) ?? throw new \RuntimeException('Could not read the screen size (`wm size`) to place the coordinates.');
                $scales[$key] = ScreenCapture::spaceFor($size['width'], $size['height'])['pixel_to_point'];
            }

            return $scales[$key];
        };
        $helper = new Helper($adb);

        /** The `screen` parameter the screen tools share. */
        $screenParam = [
            'type' => 'string',
            'enum' => ['main', 'background'],
            'description' => 'Which screen: "main" (default) is the phone\'s own; "background" is pig\'s own screen, which the person does not see, '
                . 'so they can keep using the phone while you work. Open the app there first with android_launch_app(screen="background").',
        ];

        /** The display id for a tool call's `screen`: null for the main screen. */
        $screenOf = static function (array $params, ?string $serial) use ($helper): ?int {
            return match ($params['screen'] ?? 'main') {
                'main' => null,
                'background' => $helper->backgroundScreen($serial),
                default => throw new \RuntimeException('screen must be "main" or "background".'),
            };
        };

        // -------------------------------------------------------------------------
        // Slash Command: /android
        // -------------------------------------------------------------------------
        $pi->registerCommand(
            name: 'android',
            description: 'Android device controller: /android status | connect <ip:port> | screenshot | background [off]',
            handler: static function (string $args, $ctx) use ($adb, $helper, &$scales): void {
                $parts = preg_split('/\s+/', trim($args), 2, PREG_SPLIT_NO_EMPTY);
                $sub = strtolower($parts[0] ?? 'status');
                $param = trim($parts[1] ?? '');

                if ($sub === 'status' || $sub === '') {
                    if (!$adb->isAvailable()) {
                        $ctx->ui->notify("❌ ADB binary not found. Please install Android platform-tools or set ADB_PATH.", 'error');
                        return;
                    }

                    $ver = $adb->version() ?? 'unknown';
                    $devices = $adb->devices();

                    if ($devices === []) {
                        $ctx->ui->notify("📱 ADB ready ({$ver}), but no Android devices attached.\nConnect your device via USB (enable USB debugging) or run: /android connect <ip:5555>");
                        return;
                    }

                    $lines = ["📱 Android Devices ({$ver}):"];
                    foreach ($devices as $idx => $d) {
                        $star = ($idx === 0) ? ' (active)' : '';
                        $lines[] = sprintf(
                            "  • [%s] %s | %s | %s%s",
                            $d['serial'],
                            $d['model'] ?? 'Device',
                            $d['state'],
                            $d['transport'],
                            $star
                        );
                    }

                    $screen = $adb->screenSize();
                    if ($screen !== null) {
                        $lines[] = "  📐 Screen Resolution: {$screen['width']}x{$screen['height']}";
                    }
                    $focus = $adb->currentFocus();
                    if ($focus !== null) {
                        $lines[] = "  🎯 Foreground: {$focus['package']}/{$focus['activity']}";
                    }

                    $ctx->ui->notify(implode("\n", $lines));
                    return;
                }

                if ($sub === 'connect') {
                    if ($param === '') {
                        $ctx->ui->notify("Usage: /android connect <ip:port> (e.g. 192.168.1.100:5555)");
                        return;
                    }
                    $res = $adb->connect($param);
                    $ctx->ui->notify($res['ok'] ? "✅ {$res['message']}" : "❌ {$res['message']}", $res['ok'] ? 'info' : 'error');
                    return;
                }

                if ($sub === 'disconnect') {
                    $res = $adb->disconnect($param !== '' ? $param : null);
                    $ctx->ui->notify($res['ok'] ? "✅ {$res['message']}" : "❌ {$res['message']}", $res['ok'] ? 'info' : 'error');
                    return;
                }

                if ($sub === 'screenshot') {
                    $processed = $adb->captureScreen($param !== '' ? $param : null);
                    if ($processed === null) {
                        $ctx->ui->notify("❌ Failed to capture screenshot. Make sure the device is connected, awake and unlocked.", 'error');
                        return;
                    }

                    $scales[Helper::MAIN_DISPLAY] = $processed['pixel_to_point'];
                    $methodTag = ($processed['method'] ?? '') === 'hardware_h264' ? ' (⚡ hardware accelerated)' : '';
                    $ctx->ui->notify(
                        "📸 Screenshot captured{$methodTag}: {$processed['width']}x{$processed['height']} " .
                        "(raw {$processed['raw_width']}x{$processed['raw_height']}, " .
                        "ratio {$processed['pixel_to_point'][0]}x{$processed['pixel_to_point'][1]})\n" .
                        "Saved to: {$processed['path']}"
                    );
                    return;
                }

                if ($sub === 'background') {
                if ($param === 'off') {
                    $released = $helper->releaseBackground(null);
                    $ctx->ui->notify($released ? 'Closed the background screen and the app on it.' : 'There is no background screen.');
                    return;
                }

                $id = $helper->existingScreen(null);
                $ctx->ui->notify($id === null
                    ? 'No background screen. The model opens one with android_launch_app(screen="background").'
                    : "Background screen: display {$id}. `/android background off` closes it; the helper also exits after 15 minutes unused.");
                return;
            }

            $ctx->ui->notify("Unknown command '/android {$sub}'. Available: /android status | connect <ip:port> | screenshot | background [off]", 'warning');
            }
        );

        // -------------------------------------------------------------------------
        // Helper: take observation
        // -------------------------------------------------------------------------
        $observeScreen = static function (?string $serial = null, ?int $display = null) use ($adb, $helper, &$scales): array {
            $processed = $display === null ? $adb->captureScreen($serial) : $helper->screenshot($serial, $display);
            if ($processed === null) {
                throw new \RuntimeException('Failed to capture screen from Android device via ADB.');
            }

            $scales[$display ?? Helper::MAIN_DISPLAY] = $processed['pixel_to_point'];
            $focus = $display === null ? $adb->currentFocus($serial) : $adb->displayFocus($display, $serial);

            return [
                'app' => $focus['package'] ?? 'unknown',
                'activity' => $focus['activity'] ?? 'unknown',
                'screen_width' => $processed['raw_width'],
                'screen_height' => $processed['raw_height'],
                'viewport_width' => $processed['width'],
                'viewport_height' => $processed['height'],
                'pixel_to_point' => $processed['pixel_to_point'],
                'image' => [
                    'path' => $processed['path'],
                    'mimeType' => $processed['mimeType'],
                    'width' => $processed['width'],
                    'height' => $processed['height'],
                    'data' => $processed['data'],
                ],
            ];
        };

        // -------------------------------------------------------------------------
        // Tool 1: android_doctor
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_doctor',
            label: 'Android Doctor',
            description: 'Diagnose local ADB environment, connected Android devices, screen resolution and battery/focus state.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
            ],
            execute: static function (string $id, array $params) use ($adb): AgentToolResult {
                $serial = $params['serial'] ?? null;
                $available = $adb->isAvailable();

                $info = [
                    'adb_available' => $available,
                    'adb_path' => $available ? $adb->binary() : null,
                    'adb_version' => $available ? $adb->version() : null,
                    'ffmpeg_available' => $available ? ($adb->ffmpegBinary() !== null) : false,
                    'hardware_accelerated' => $available ? ($adb->ffmpegBinary() !== null) : false,
                    'devices' => $available ? $adb->devices() : [],
                    'screen_size' => $available ? $adb->screenSize($serial) : null,
                    'foreground' => $available ? $adb->currentFocus($serial) : null,
                    'screen_on' => $available ? $adb->isScreenOn($serial) : null,
                ];

                return new AgentToolResult([new TextContent((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))]);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 2: android_ready
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_ready',
            label: 'Android Ready Check',
            description: 'Check if an Android device is connected, wake it up, and return initial viewport dimensions and screenshot.',
            // Same rule as computer use: the screen is the last resort, after the command line.
            promptGuidelines: [
                'Tapping the phone screen is a last resort. When adb from bash can do it (adb shell am start, input, settings, pm, dumpsys; adb push/pull/install), do that first; use the android_* screen tools only when the person asks for them or adb cannot do the job.',
            ],
            parameters: [
                'type' => 'object',
                'properties' => [
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                    'screenshot' => ['type' => 'boolean', 'description' => 'Whether to include screenshot. Default true.'],
                ],
            ],
            execute: static function (string $id, array $params) use ($adb, $observeScreen): AgentToolResult {
                $serial = $params['serial'] ?? null;
                if (!$adb->isAvailable()) {
                    throw new \RuntimeException('ADB binary not found on this machine. Install Android platform-tools or set ADB_PATH.');
                }

                $devices = $adb->devices();
                if ($devices === []) {
                    throw new \RuntimeException('No Android devices connected. Enable USB debugging on your device, or connect via WiFi.');
                }

                $wantScreenshot = (bool) ($params['screenshot'] ?? true);
                $obs = $observeScreen($serial);

                $contents = [];
                $meta = [
                    'ready' => true,
                    'device' => $devices[0]['serial'],
                    'model' => $devices[0]['model'] ?? 'Android',
                    'app' => $obs['app'],
                    'activity' => $obs['activity'],
                    'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                    'screen_size' => ['width' => $obs['screen_width'], 'height' => $obs['screen_height']],
                    'pixel_to_point' => $obs['pixel_to_point'],
                ];

                $contents[] = new TextContent((string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if ($wantScreenshot) {
                    $contents[] = new ImageContent(
                        base64_encode($obs['image']['data']),
                        $obs['image']['mimeType']
                    );
                }

                return new AgentToolResult($contents);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 3: android_observe
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_observe',
            label: 'Android Observe Screen',
            description: 'Capture the current screen screenshot and foreground app info from the Android device.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['screenshot', 'info', 'both'],
                        'description' => 'Observation mode. Default both.',
                    ],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
            ],
            execute: static function (string $id, array $params) use ($observeScreen, $screenOf): AgentToolResult {
                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);
                $mode = (string) ($params['mode'] ?? 'both');
                $obs = $observeScreen($serial, $display);

                $contents = [];
                $meta = [
                    'app' => $obs['app'],
                    'activity' => $obs['activity'],
                    'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                    'screen_size' => ['width' => $obs['screen_width'], 'height' => $obs['screen_height']],
                    'pixel_to_point' => $obs['pixel_to_point'],
                    'image_path' => $obs['image']['path'],
                ];

                $contents[] = new TextContent((string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if ($mode === 'screenshot' || $mode === 'both') {
                    $contents[] = new ImageContent(
                        base64_encode($obs['image']['data']),
                        $obs['image']['mimeType']
                    );
                }

                return new AgentToolResult($contents);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 4: android_tap
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_tap',
            label: 'Android Tap',
            description: 'Tap on screen coordinates (x, y). Coordinates can match the scaled screenshot and will automatically convert to physical pixels with anti-ban jitter.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'x' => ['type' => 'number', 'description' => 'X coordinate to tap.'],
                    'y' => ['type' => 'number', 'description' => 'Y coordinate to tap.'],
                    'observe' => [
                        'type' => 'string',
                        'enum' => ['none', 'screenshot', 'both'],
                        'description' => 'Post-action observation. Default none.',
                    ],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
                'required' => ['x', 'y'],
            ],
            execute: static function (string $id, array $params) use ($adb, $observeScreen, $scaleOf, $screenOf): AgentToolResult {
                $x = (float) $params['x'];
                $y = (float) $params['y'];
                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);
                $observe = (string) ($params['observe'] ?? 'none');

                $tapped = InputManager::tap($adb, $x, $y, $scaleOf($display, $serial), $serial, humanJitter: true, display: $display);

                $contents = [];
                $res = [
                    'action_executed' => true,
                    'tapped' => $tapped,
                ];

                if ($observe === 'screenshot' || $observe === 'both') {
                    $obs = $observeScreen($serial, $display);
                    $res['observation'] = [
                        'app' => $obs['app'],
                        'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                        'pixel_to_point' => $obs['pixel_to_point'],
                    ];
                    $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $contents[] = new ImageContent(base64_encode($obs['image']['data']), $obs['image']['mimeType']);
                } else {
                    $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }

                return new AgentToolResult($contents);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 5: android_swipe
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_swipe',
            label: 'Android Swipe Gesture',
            description: 'Swipe on Android screen by direction ("up", "down", "left", "right") or by explicit from/to coordinates.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'direction' => [
                        'type' => 'string',
                        'enum' => ['up', 'down', 'left', 'right'],
                        'description' => 'Finger movement direction. "up" usually scrolls down.',
                    ],
                    'from_x' => ['type' => 'number', 'description' => 'Optional explicit start X.'],
                    'from_y' => ['type' => 'number', 'description' => 'Optional explicit start Y.'],
                    'to_x' => ['type' => 'number', 'description' => 'Optional explicit end X.'],
                    'to_y' => ['type' => 'number', 'description' => 'Optional explicit end Y.'],
                    'duration_ms' => ['type' => 'integer', 'description' => 'Gesture duration in milliseconds. Default 300.'],
                    'observe' => [
                        'type' => 'string',
                        'enum' => ['none', 'screenshot', 'both'],
                        'description' => 'Post-action observation. Default none.',
                    ],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
            ],
            execute: static function (string $id, array $params) use ($adb, $observeScreen, $scaleOf, $screenOf): AgentToolResult {
                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);
                $duration = (int) ($params['duration_ms'] ?? 300);
                $observe = (string) ($params['observe'] ?? 'none');

                $info = [];
                if (isset($params['from_x'], $params['from_y'], $params['to_x'], $params['to_y'])) {
                    $info = InputManager::swipe(
                        $adb,
                        (float) $params['from_x'],
                        (float) $params['from_y'],
                        (float) $params['to_x'],
                        (float) $params['to_y'],
                        $duration,
                        $scaleOf($display, $serial),
                        $serial,
                        $display
                    );
                } elseif (!empty($params['direction'])) {
                    $screen = $adb->screenSize($serial) ?? ['width' => 1080, 'height' => 2400];
                    $info = InputManager::swipeDirection($adb, (string) $params['direction'], $screen, $duration, $serial, $display);
                } else {
                    throw new \RuntimeException('Must provide either direction ("up", "down", "left", "right") or (from_x, from_y, to_x, to_y).');
                }

                $contents = [];
                $res = [
                    'action_executed' => true,
                    'gesture' => $info,
                ];

                if ($observe === 'screenshot' || $observe === 'both') {
                    $obs = $observeScreen($serial, $display);
                    $res['observation'] = [
                        'app' => $obs['app'],
                        'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                        'pixel_to_point' => $obs['pixel_to_point'],
                    ];
                    $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $contents[] = new ImageContent(base64_encode($obs['image']['data']), $obs['image']['mimeType']);
                } else {
                    $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }

                return new AgentToolResult($contents);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 6: android_type_text
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_type_text',
            label: 'Android Type Text',
            description: 'Input text on the focused Android input field, with full support for Chinese, Unicode, and multiline text.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'description' => 'The full text to enter.'],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
                'required' => ['text'],
            ],
            execute: static function (string $id, array $params) use ($adb, $screenOf): AgentToolResult {
                $text = (string) ($params['text'] ?? '');
                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);

                InputManager::typeText($adb, $text, $serial, $display);

                return new AgentToolResult([new TextContent((string) json_encode([
                    'action_executed' => true,
                    'typed_length' => mb_strlen($text),
                ]))]);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 7: android_press_button
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_press_button',
            label: 'Android Press Button',
            description: 'Simulate physical hardware or navigation button presses (home, back, power, volumeup, volumedown, enter, app_switch).',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'enum' => ['home', 'back', 'menu', 'power', 'volumeup', 'volumedown', 'enter', 'app_switch', 'recent'],
                        'description' => 'Button to press.',
                    ],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
                'required' => ['name'],
            ],
            execute: static function (string $id, array $params) use ($adb, $screenOf): AgentToolResult {
                $button = (string) $params['name'];
                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);

                InputManager::pressButton($adb, $button, $serial, $display);

                return new AgentToolResult([new TextContent((string) json_encode([
                    'action_executed' => true,
                    'button' => $button,
                ]))]);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 8: android_launch_app
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_launch_app',
            label: 'Android Launch App',
            description: 'Launch an Android application by package name (e.g. com.tencent.mm, com.android.settings).',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'package' => ['type' => 'string', 'description' => 'Target application package name.'],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
                'required' => ['package'],
            ],
            execute: static function (string $id, array $params) use ($adb, $screenOf): AgentToolResult {
                $pkg = trim((string) $params['package']);
                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);

                if ($pkg === '' || !preg_match('/^[a-zA-Z0-9._]+$/', $pkg)) {
                    throw new \RuntimeException('Invalid package name format.');
                }

                // The main screen through monkey's launcher intent; another display by its launcher activity's name.
                $res = $display === null
                    ? $adb->executeShell("monkey -p {$pkg} -c android.intent.category.LAUNCHER 1", $serial)
                    : $adb->launchOnDisplay($pkg, $display, $serial);

                // `am start` says "Error: …" and still exits 0 when the activity did not start.
                $failed = $res['exit'] !== 0 || str_contains($res['stdout'] . $res['stderr'], 'Error');
                $reply = ['action_executed' => true, 'package' => $pkg, 'success' => !$failed];
                if ($display !== null) {
                    $reply['screen'] = 'background';
                }
                if ($failed) {
                    $reply['error'] = trim($res['stdout'] . ' ' . $res['stderr']);
                }

                return new AgentToolResult([new TextContent((string) json_encode($reply, JSON_UNESCAPED_SLASHES))]);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 9: android_connect
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_connect',
            label: 'Android Connect Wireless',
            description: 'Connect to an Android device over WiFi/TCP (requires device to have Wireless Debugging enabled).',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'address' => ['type' => 'string', 'description' => 'Device address in IP:port format (e.g. 192.168.1.100:5555).'],
                ],
                'required' => ['address'],
            ],
            execute: static function (string $id, array $params) use ($adb): AgentToolResult {
                $addr = trim((string) $params['address']);
                $res = $adb->connect($addr);

                return new AgentToolResult([new TextContent((string) json_encode([
                    'success' => $res['ok'],
                    'message' => $res['message'],
                ]))]);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 10: android_tree (Compact Accessibility Hierarchy)
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_tree',
            label: 'Android Compact Hierarchy Tree',
            description: 'Dump native Android View hierarchy tree and return compact, token-saving JSON nodes with labels, IDs and bounds. '
                . 'When the tree is not enough to work from (an app that draws its own UI), it also returns the screen text the tree lacks (OCR, macOS) or the screenshot.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'max_nodes' => ['type' => 'integer', 'description' => 'Max number of interactive nodes to return. Default 60.'],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
            ],
            execute: static function (string $id, array $params) use ($adb, $observeScreen, $scaleOf, &$scales, $screenOf, $helper): AgentToolResult {
                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);
                $maxNodes = max(1, min(200, (int) ($params['max_nodes'] ?? 60)));

                // The helper reads either screen. A secure or custom-drawn surface comes back as a
                // tree with nothing in it, which TreeQuality calls unusable.
                $tree = $helper->tree($serial, $display ?? Helper::MAIN_DISPLAY);
                $rawXml = $tree['xml'];
                $check = TreeQuality::ofTree($rawXml);
                $focus = $display === null ? $adb->currentFocus($serial) : $adb->displayFocus($display, $serial);
                $result = [
                    'app' => $focus['package'] ?? 'unknown',
                    'activity' => $focus['activity'] ?? 'unknown',
                ];
                $image = null;

                if ($check['quality'] !== TreeQuality::ADEQUATE) {
                    // The screenshot is taken before the nodes are scaled, so the tree, the OCR
                    // lines and the image all share its coordinates.
                    $obs = $observeScreen($serial, $display);

                    if (VisionOcr::available()) {
                        $lines = VisionOcr::recognize($obs['image']['path'], $obs['viewport_width'], $obs['viewport_height']);
                        $missing = TreeQuality::missingLines($lines, $rawXml);
                        $check = TreeQuality::withText($check, $missing);
                        $result['ocr_lines'] = $missing;

                        // An unusable tree has next to nothing in it (a game, a canvas): OCR gives its
                        // words, but its icons — a game's tab bar — are only in the picture, and
                        // without it the model guesses where they are. A partial tree has most of
                        // the screen already; its missing text is enough.
                        if ($check['quality'] === TreeQuality::UNUSABLE) {
                            $image = $obs['image'];
                        }
                    } elseif ($check['quality'] !== null) {
                        $result['ocr'] = 'unavailable: Vision OCR runs on macOS only, so the screenshot is attached instead';
                        $image = $obs['image'];
                    } else {
                        $result['ocr'] = 'unavailable: Vision OCR runs on macOS only; the tree could not be checked against the screen, use android_observe if a target is missing';
                    }

                    $result['viewport'] = ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']];
                }

                // The helper says how big the screen is now (rotation included): the tree's rects go
                // into the coordinates a screenshot of it has, and that screen's taps use the same ratio.
                $space = ScreenCapture::spaceFor($tree['width'], $tree['height']);
                $scales[$display ?? Helper::MAIN_DISPLAY] = $space['pixel_to_point'];
                $result['viewport'] = $space['viewport'];

                $nodes = TreeParser::parse($rawXml, $scaleOf($display, $serial), $maxNodes);
                $result['tree_quality'] = $check['quality'] ?? 'unchecked';
                if ($check['reason'] !== null) {
                    $result['tree_quality_reason'] = $check['reason'];
                }
                $result['total_nodes'] = count($nodes);
                $result['nodes'] = $nodes;

                $contents = [new TextContent((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
                if ($image !== null) {
                    $contents[] = new ImageContent(base64_encode($image['data']), $image['mimeType']);
                }

                return new AgentToolResult($contents);
            }
        ));

        // -------------------------------------------------------------------------
        // Tool 11: android_batch (Humanized Anti-Ban Sequence)
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'android_batch',
            label: 'Android Anti-Ban Batch Steps',
            description: 'Execute up to 15 sequential actions with anti-ban random coordinate jitter and humanized micro-delays (150-350ms).',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'steps' => [
                        'type' => 'array',
                        'description' => 'List of action objects: {op: "tap"|"swipe"|"type_text"|"press_button"|"wait", args: {...}}',
                        'items' => ['type' => 'object'],
                    ],
                    'observe' => [
                        'type' => 'string',
                        'enum' => ['none', 'screenshot', 'both'],
                        'description' => 'Observe screen after the batch completes. Default screenshot.',
                    ],
                    'screen' => $screenParam,
                    'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                ],
                'required' => ['steps'],
            ],
            execute: static function (string $id, array $params) use ($adb, $observeScreen, $scaleOf, $screenOf): AgentToolResult {
                $steps = (array) ($params['steps'] ?? []);
                if (count($steps) > 15) {
                    throw new \RuntimeException('Batch steps exceed maximum budget of 15 operations.');
                }

                $serial = $params['serial'] ?? null;
                $display = $screenOf($params, $serial);
                $observe = (string) ($params['observe'] ?? 'screenshot');

                $batchRes = InputManager::batch($adb, $steps, $scaleOf($display, $serial), $serial, $display);

                $contents = [];
                $res = [
                    'action_executed' => true,
                    'completed' => $batchRes['completed'],
                    'results' => $batchRes['results'],
                ];

                if ($observe === 'screenshot' || $observe === 'both') {
                    $obs = $observeScreen($serial, $display);
                    $res['observation'] = [
                        'app' => $obs['app'],
                        'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                        'pixel_to_point' => $obs['pixel_to_point'],
                    ];
                    $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $contents[] = new ImageContent(base64_encode($obs['image']['data']), $obs['image']['mimeType']);
                } else {
                    $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }

                return new AgentToolResult($contents);
            }
        ));

        // -------------------------------------------------------------------------
        // Web HTTP Route: /api/android (Screen Monitoring & Inspection)
        // -------------------------------------------------------------------------
        $pi->registerHttpRoute('/api/android', static function (string $path, array $req) use ($adb, $observeScreen): ?array {
            if ($path === '/api/android/screen.jpg' && $req['method'] === 'GET') {
                $raw = $adb->screencapRaw();
                if ($raw === null) {
                    return ['status' => 503, 'body' => 'Device screen unavailable.'];
                }
                // For the person watching: the model's coordinates stay as its own last look set them.
                $processed = ScreenCapture::process($raw);

                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'image/jpeg',
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    ],
                    'body' => $processed['data'],
                ];
            }

            if ($path === '/api/android/screen' && $req['method'] === 'GET') {
                try {
                    $obs = $observeScreen();
                    return [
                        'status' => 200,
                        'body' => [
                            'ok' => true,
                            'app' => $obs['app'],
                            'activity' => $obs['activity'],
                            'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                            'pixel_to_point' => $obs['pixel_to_point'],
                            'image_url' => '/api/android/screen.jpg?t=' . microtime(true),
                        ],
                    ];
                } catch (\Throwable $e) {
                    return ['status' => 503, 'body' => ['ok' => false, 'error' => $e->getMessage()]];
                }
            }

            if ($path === '/api/android/stream' && $req['method'] === 'GET') {
                $html = <<<HTML
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Android Live Screen - pig</title>
        <style>
            body { margin: 0; background: #0f1117; color: #fff; font-family: system-ui, sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; }
            .frame { border: 12px solid #2a2d3d; border-radius: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); overflow: hidden; background: #000; position: relative; }
            img { display: block; max-height: 80vh; max-width: 90vw; object-fit: contain; }
            .bar { margin-top: 15px; display: flex; gap: 10px; }
            button { background: #3b82f6; border: none; color: #fff; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 500; }
            button:hover { background: #2563eb; }
            .info { margin-top: 8px; font-size: 13px; color: #94a3b8; }
        </style>
    </head>
    <body>
        <div class="frame">
            <img id="screen" src="/api/android/screen.jpg" alt="Android Screen" />
        </div>
        <div class="bar">
            <button onclick="refresh()">Refresh</button>
            <button id="toggleBtn" onclick="toggleStream()">Auto refresh: on</button>
        </div>
        <div class="info" id="status">Reading the screen over ADB...</div>
        <script>
            let live = true;
            let timer = null;
            function refresh() {
                const img = document.getElementById('screen');
                img.src = '/api/android/screen.jpg?t=' + Date.now();
            }
            function toggleStream() {
                live = !live;
                document.getElementById('toggleBtn').innerText = 'Auto refresh: ' + (live ? 'on' : 'off');
                if (live) start(); else clearInterval(timer);
            }
            function start() {
                clearInterval(timer);
                timer = setInterval(refresh, 800);
            }
            start();
        </script>
    </body>
    </html>
    HTML;
                return [
                    'status' => 200,
                    'headers' => ['Content-Type' => 'text/html; charset=utf-8'],
                    'body' => $html,
                ];
            }

            return null;
        });
    }
}
