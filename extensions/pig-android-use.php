<?php

declare(strict_types=1);

use Pig\CodingAgent\Extensions\ExtensionApi;
use PigAndroidUse\Extension;

/*
 * pig-android-use — drive an Android phone over ADB: taps, swipes, text, the view tree and
 * screenshots, plus the `/android` command and a live screen page for the web UI.
 *
 * The file is named after the extension because pig names an extension by its file: settings
 * that say `"disabledExtensions": ["pig-android-use"]` from the days it was bundled with pig
 * still reach it.
 *
 * A package installed from git is not run through Composer, so nothing autoloads its classes;
 * this registers the loader for the package's own namespace, once.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PigAndroidUse\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 14)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

return static function (ExtensionApi $pi): void {
    Extension::register($pi);
};
