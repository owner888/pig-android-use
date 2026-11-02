<?php

declare(strict_types=1);

namespace PigAndroidUse;

use RuntimeException;

/**
 * pig's side of the phone helper (`helper/`): pushes the .dex, starts it with app_process as the
 * shell user, reaches it through `adb forward`, and keeps pig's background screen on it.
 *
 * The background screen is a virtual display the person never sees: an app launched there runs
 * while the phone is in use. The helper holds its picture and takes its screenshots; it also reads
 * the view tree, of that screen and of the main one. Taps, keys and launches go to the background
 * screen with adb (`input -d`, `am start --display`).
 *
 * Each request is one connection, one JSON line each way, with the token pig made when it started
 * the helper. The helper exits by itself after 15 minutes without a request.
 */
final class Helper
{
    public const string REMOTE_DEX = '/data/local/tmp/pig-android-helper.dex';
    public const string MAIN_CLASS = 'pig.androiduse.helper.Main';
    /** Main.VERSION in the helper this package ships. */
    public const int VERSION = 6;
    /** Display.DEFAULT_DISPLAY, the phone's own screen. */
    public const int MAIN_DISPLAY = 0;
    /** Android 12: the per-display user-activity call and the display flags the helper relies on. */
    public const int MIN_SDK = 31;

    /** Display.STATE_ON. */
    private const int STATE_ON = 2;
    /** KEYCODE_WAKEUP: turns a screen on without unlocking it. */
    private const int KEYCODE_WAKEUP = 224;
    private const float WAKE_TIMEOUT = 1.5;

    private const string SOCKET = 'pig-android-helper';
    private const string LOG = '/data/local/tmp/pig-android-helper.log';
    private const float START_TIMEOUT = 8.0;

    /** @var array<string, array{port: int, token: string}> by device */
    private array $sessions = [];

    /** @var array<string, int> the background screen's display id, by device */
    private array $screens = [];

    /**
     * Devices whose background screen went away — the helper exited after 15 minutes unused, or
     * was restarted — taking the app on it along. The next screen there is a new, empty one, and
     * the model is told so when it looks at it before opening an app again.
     *
     * @var array<string, true>
     */
    private array $replaced = [];

    public function __construct(private readonly AdbClient $adb)
    {
    }

    public static function localDex(): string
    {
        return dirname(__DIR__) . '/helper/pig-android-helper.dex';
    }

    /**
     * The background screen's display id, made on first use with the phone's own size and density.
     * A screen the helper already has (pig was restarted, the helper was not) is used again.
     */
    public function backgroundScreen(?string $serial): int
    {
        $key = $this->key($serial);
        $displays = array_map('intval', (array) ($this->request($serial, 'ping')['displays'] ?? []));

        if (isset($this->screens[$key]) && in_array($this->screens[$key], $displays, true)) {
            return $this->awake($serial, $this->screens[$key]);
        }

        if (isset($this->screens[$key])) {
            $this->replaced[$key] = true;
            unset($this->screens[$key]);
        }

        if ($displays !== []) {
            return $this->awake($serial, $this->screens[$key] = $displays[0]);
        }

        $size = $this->adb->screenSize($serial) ?? throw new RuntimeException('Could not read the screen size (`wm size`) for the background screen.');
        $dpi = $this->adb->density($serial) ?? throw new RuntimeException('Could not read the screen density (`wm density`) for the background screen.');

        $reply = $this->request($serial, 'create', ['width' => $size['width'], 'height' => $size['height'], 'dpi' => $dpi]);

        return $this->awake($serial, $this->screens[$key] = (int) $reply['id']);
    }

    /**
     * Make sure the background screen is on before pig acts on it, and restart its group's sleep
     * timer. A screen in a group of its own is woken by itself; one the system put in the main
     * screen's group (Android 17 on some phones) sleeps when the phone does, so the phone is woken —
     * to its lock screen, not unlocked: the background screen shows anyway (ALWAYS_UNLOCKED). Called
     * on every action there, so the phone stays awake while pig works on it, and sleeps on its own
     * timer once pig stops.
     */
    private function awake(?string $serial, int $display): int
    {
        $screen = (array) ($this->request($serial, 'activity', ['id' => $display])['screen'] ?? []);

        if (($screen['state'] ?? null) === self::STATE_ON) {
            return $display;
        }

        $target = ($screen['group'] ?? 0) === 0 ? '' : " -d {$display}";
        $this->adb->executeShell('input' . $target . ' keyevent ' . self::KEYCODE_WAKEUP, $serial);

        $deadline = microtime(true) + self::WAKE_TIMEOUT;
        do {
            usleep(150_000);
            $screen = (array) ($this->request($serial, 'activity', ['id' => $display])['screen'] ?? []);
            if (($screen['state'] ?? null) === self::STATE_ON) {
                return $display;
            }
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The background screen is off (display state ' . json_encode($screen['state'] ?? null)
            . ', group ' . json_encode($screen['group'] ?? null) . ') and did not come on when woken.');
    }

    /** The background screen if there is one now, without starting the helper to find out. */
    public function existingScreen(?string $serial): ?int
    {
        return $this->screens[$this->key($serial)] ?? null;
    }

    /**
     * Close the background screen, and whatever app is on it.
     *
     * @return bool whether there was one
     */
    public function releaseBackground(?string $serial): bool
    {
        $key = $this->key($serial);

        if (!isset($this->sessions[$key])) {
            return false;
        }

        $released = false;
        foreach ((array) ($this->request($serial, 'ping')['displays'] ?? []) as $id) {
            $released = (bool) ($this->request($serial, 'release', ['id' => (int) $id])['released'] ?? false) || $released;
        }
        unset($this->screens[$key]);

        return $released;
    }

    /**
     * A screenshot of the background screen, in the shape `AdbClient::captureScreen()` returns.
     *
     * @return array{path: string, mimeType: string, width: int, height: int, raw_width: int, raw_height: int, pixel_to_point: array{float, float}, data: string, method: string}
     */
    public function screenshot(?string $serial, int $display): array
    {
        $key = $this->key($serial);

        try {
            $reply = $this->request($serial, 'screenshot', [
                'id' => $display,
                'max_short' => ScreenCapture::MAX_SHORT_SIDE,
                'max_long' => ScreenCapture::MAX_LONG_SIDE,
                'quality' => ScreenCapture::JPEG_QUALITY,
            ]);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'nothing has been drawn')) {
                throw $e;
            }

            throw new RuntimeException(self::emptyScreen(isset($this->replaced[$key])), 0, $e);
        }

        unset($this->replaced[$key]);

        $data = base64_decode((string) ($reply['jpeg'] ?? ''), true);
        if ($data === false || $data === '') {
            throw new RuntimeException('The helper sent a screenshot that is not base64 JPEG.');
        }

        $path = ScreenCapture::createArtifactPath()['jpg'];
        file_put_contents($path, $data);

        $width = (int) $reply['width'];
        $height = (int) $reply['height'];

        return [
            'path' => $path,
            'mimeType' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'raw_width' => (int) $reply['raw_width'],
            'raw_height' => (int) $reply['raw_height'],
            'pixel_to_point' => [(int) $reply['raw_width'] / $width, (int) $reply['raw_height'] / $height],
            'data' => $data,
            'method' => 'background_screen',
        ];
    }

    /**
     * A screen's view tree — the main screen's (MAIN_DISPLAY) or the background one's — in the
     * XML `uiautomator dump` writes. Read by the helper through UiAutomation, without the file
     * `uiautomator dump` writes on the phone and reads back for every call.
     */
    /**
     * @return array{xml: string, width: int, height: int} the tree, and the screen's own size now
     *         (rotation included) — the nodes need not cover it, so their extent is not its size
     */
    public function tree(?string $serial, int $display): array
    {
        $reply = $this->request($serial, 'tree', ['id' => $display], 20.0);
        $xml = (string) ($reply['xml'] ?? '');

        if (!str_contains($xml, '<hierarchy') || (int) ($reply['width'] ?? 0) <= 0 || (int) ($reply['height'] ?? 0) <= 0) {
            throw new RuntimeException('The helper sent a view tree without uiautomator XML or the screen size.');
        }

        return ['xml' => $xml, 'width' => (int) $reply['width'], 'height' => (int) $reply['height']];
    }

    /** What to tell the model about a background screen nothing has been opened on. */
    public static function emptyScreen(bool $replaced): string
    {
        return 'The background screen is empty: no app has been opened on it'
            . ($replaced ? ' since it was made again — the one before closed, with the app on it, after 15 minutes without use (or the helper restarted)' : ' yet')
            . '. Open the app with android_launch_app(screen="background"), then look again.';
    }

    /**
     * One request to the helper, started first if it is not running.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed> the reply, `ok` true
     */
    public function request(?string $serial, string $cmd, array $args = [], float $timeout = 15.0): array
    {
        $session = $this->session($serial);
        $reply = self::send($session['port'], ['token' => $session['token'], 'cmd' => $cmd] + $args, $timeout);

        if (($reply['ok'] ?? false) !== true) {
            throw new RuntimeException("Android helper ({$cmd}): " . (string) ($reply['error'] ?? 'no answer'));
        }

        return $reply;
    }

    /** @return array{port: int, token: string} */
    private function session(?string $serial): array
    {
        $key = $this->key($serial);

        if (isset($this->sessions[$key])) {
            $session = $this->sessions[$key];
            $reply = self::trySend($session['port'], ['token' => $session['token'], 'cmd' => 'ping'], 3.0);

            if (($reply['ok'] ?? false) === true && ($reply['version'] ?? null) === self::VERSION) {
                return $session;
            }

            // The helper may be fine and only the forward gone: a restarted adb server (Android
            // Studio restarts it) drops every forward. A new one keeps the helper and its screen.
            if ($reply === null) {
                $port = $this->forward($serial);
                $again = self::trySend($port, ['token' => $session['token'], 'cmd' => 'ping'], 3.0);

                if (($again['ok'] ?? false) === true && ($again['version'] ?? null) === self::VERSION) {
                    return $this->sessions[$key] = ['port' => $port, 'token' => $session['token']];
                }
            }

            if (isset($this->screens[$key])) {
                $this->replaced[$key] = true;
            }
            unset($this->sessions[$key], $this->screens[$key]);
        }

        return $this->sessions[$key] = $this->start($serial);
    }

    /**
     * Push the .dex when the phone's copy differs, stop any helper already running (its token is
     * not this pig's), start a new one and wait for it to answer.
     *
     * @return array{port: int, token: string}
     */
    private function start(?string $serial): array
    {
        $dex = self::localDex();
        if (!is_file($dex)) {
            throw new RuntimeException("The phone helper is not built: {$dex} is missing. Build it with helper/build.sh (needs the Android SDK).");
        }

        $sdk = $this->sdk($serial);
        if ($sdk < self::MIN_SDK) {
            throw new RuntimeException("The background screen needs Android 12 (API " . self::MIN_SDK . ") or newer; this phone is API {$sdk}.");
        }

        $remoteSum = $this->adb->executeShell('md5sum ' . self::REMOTE_DEX . ' 2>/dev/null', $serial)['stdout'];
        if (!str_starts_with(trim($remoteSum), (string) md5_file($dex))) {
            $push = $this->adb->executeAdb(['push', $dex, self::REMOTE_DEX], $serial, 30);
            if ($push['exit'] !== 0) {
                throw new RuntimeException('Could not push the phone helper: ' . trim($push['stderr'] . ' ' . $push['stdout']));
            }
        }

        $this->adb->executeShell('pkill -f ' . self::MAIN_CLASS, $serial);

        $token = bin2hex(random_bytes(16));
        $start = $this->adb->executeShell(self::startCommand($token), $serial);
        if ($start['exit'] !== 0) {
            throw new RuntimeException('Could not start the phone helper: ' . trim($start['stderr'] . ' ' . $start['stdout']));
        }

        $port = $this->forward($serial);

        $deadline = microtime(true) + self::START_TIMEOUT;
        do {
            $reply = self::trySend($port, ['token' => $token, 'cmd' => 'ping'], 1.0);
            if (($reply['ok'] ?? false) === true) {
                if (($reply['version'] ?? null) !== self::VERSION) {
                    throw new RuntimeException('The phone helper answered with version ' . json_encode($reply['version'] ?? null) . ', this package needs ' . self::VERSION . '.');
                }

                return ['port' => $port, 'token' => $token];
            }
            usleep(150_000);
        } while (microtime(true) < $deadline);

        $log = trim($this->adb->executeShell('tail -n 20 ' . self::LOG, $serial)['stdout']);
        throw new RuntimeException('The phone helper did not answer within ' . self::START_TIMEOUT . " s. Its log:\n" . ($log !== '' ? $log : '(empty)'));
    }

    /**
     * The phone's API level. adb loses the phone now and then for a moment ("no devices/emulators
     * found"), which also drops every forward: then wait for it once and ask again. An answer that
     * is still not a number is adb's failure, said as such — not an API level of 0.
     */
    private function sdk(?string $serial): int
    {
        $read = fn (): array => $this->adb->executeShell('getprop ro.build.version.sdk', $serial);
        $res = $read();

        if (preg_match('/^\d+$/', trim($res['stdout'])) !== 1) {
            $this->adb->executeAdb(['wait-for-device'], $serial, 10);
            $res = $read();
        }

        $value = trim($res['stdout']);
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException('adb could not read the phone\'s Android version: ' . trim($res['stderr'] . ' ' . $value) . ' (exit ' . $res['exit'] . ')');
        }

        return (int) $value;
    }

    /** A local port forwarded to the helper's socket; adb picks the port. */
    private function forward(?string $serial): int
    {
        $forward = $this->adb->executeAdb(['forward', 'tcp:0', 'localabstract:' . self::SOCKET], $serial);
        $port = self::forwardedPort($forward['stdout']);

        if ($forward['exit'] !== 0 || $port === null) {
            throw new RuntimeException('Could not forward a port to the phone helper: ' . trim($forward['stderr'] . ' ' . $forward['stdout']));
        }

        return $port;
    }

    /** The shell command that starts the helper detached from the adb shell that runs it. */
    public static function startCommand(string $token): string
    {
        return 'CLASSPATH=' . self::REMOTE_DEX . ' setsid nohup app_process / ' . self::MAIN_CLASS
            . ' ' . self::SOCKET . ' ' . $token . ' > ' . self::LOG . ' 2>&1 < /dev/null &';
    }

    /** `adb forward tcp:0 …` prints the port it picked. */
    public static function forwardedPort(string $stdout): ?int
    {
        return preg_match('/^\s*(\d{2,5})\s*$/', $stdout, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function send(int $port, array $request, float $timeout): array
    {
        $reply = self::trySend($port, $request, $timeout, $error);

        return $reply ?? throw new RuntimeException("Android helper on port {$port}: {$error}");
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null null when the helper could not be reached or said nothing
     */
    private static function trySend(int $port, array $request, float $timeout, ?string &$error = null): ?array
    {
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, $timeout);
            if ($stream === false) {
                $error = "connect failed: {$errstr}";

                return null;
            }

            stream_set_timeout($stream, (int) ceil($timeout));
            fwrite($stream, json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $line = fgets($stream);
            fclose($stream);
        } finally {
            restore_error_handler();
        }

        if ($line === false || trim($line) === '') {
            $error ??= 'no answer';

            return null;
        }

        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            $error = 'answer is not JSON: ' . mb_substr($line, 0, 200);

            return null;
        }

        return $decoded;
    }

    private function key(?string $serial): string
    {
        return $serial ?? $this->adb->defaultSerial() ?? '';
    }
}
