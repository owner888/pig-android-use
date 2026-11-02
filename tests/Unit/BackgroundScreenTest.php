<?php

declare(strict_types=1);

namespace PigAndroidUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigAndroidUse\AdbClient;
use PigAndroidUse\Helper;
use PigAndroidUse\InputManager;

/**
 * The adb-side pieces of the background screen. The helper itself runs on a phone and is tried
 * there; what is checked here is everything pig reads from or writes to adb around it.
 */
final class BackgroundScreenTest extends TestCase
{
    public function testInputGoesToTheDisplayAskedFor(): void
    {
        $this->assertSame('input', InputManager::input(null));
        $this->assertSame('input -d 7', InputManager::input(7));
    }

    public function testTheHelperStartsDetachedWithItsSocketAndToken(): void
    {
        $command = Helper::startCommand('abc123');

        $this->assertSame(
            'CLASSPATH=/data/local/tmp/pig-android-helper.dex setsid nohup app_process / pig.androiduse.helper.Main pig-android-helper abc123'
            . ' > /data/local/tmp/pig-android-helper.log 2>&1 < /dev/null &',
            $command,
        );
    }

    public function testTheForwardedPortIsWhatAdbPrints(): void
    {
        $this->assertSame(41237, Helper::forwardedPort("41237\n"));
        $this->assertNull(Helper::forwardedPort(''));
        $this->assertNull(Helper::forwardedPort("adb: error: cannot bind listener\n"));
    }

    public function testTheDensityIsTheOverrideWhenThereIsOne(): void
    {
        $this->assertSame(480, AdbClient::parseDensity("Physical density: 480\n"));
        $this->assertSame(420, AdbClient::parseDensity("Physical density: 480\nOverride density: 420\n"));
        $this->assertNull(AdbClient::parseDensity(''));
    }

    public function testTheFocusOfADisplayIsReadFromItsOwnSection(): void
    {
        $dumpsys = <<<TXT
ACTIVITY MANAGER ACTIVITIES (dumpsys activity activities)
Display #0 (activities from top to bottom):
  * Task{1 #12 type=standard A=10123:com.tencent.mm}
    topResumedActivity=ActivityRecord{1a2b3c u0 com.tencent.mm/.ui.LauncherUI t12}
  ResumedActivity: ActivityRecord{1a2b3c u0 com.tencent.mm/.ui.LauncherUI t12}
Display #7 (activities from top to bottom):
  * Task{2 #31 type=standard A=10200:com.xingin.xhs}
  ResumedActivity: ActivityRecord{4d5e6f u0 com.xingin.xhs/com.xingin.xhs.index.v2.IndexActivityV2 t31}
TXT;

        $this->assertSame(['package' => 'com.tencent.mm', 'activity' => 'com.tencent.mm.ui.LauncherUI'], AdbClient::parseDisplayFocus($dumpsys, 0));
        $this->assertSame(['package' => 'com.xingin.xhs', 'activity' => 'com.xingin.xhs.index.v2.IndexActivityV2'], AdbClient::parseDisplayFocus($dumpsys, 7));
        $this->assertNull(AdbClient::parseDisplayFocus($dumpsys, 3));
    }

    public function testTheLauncherActivityIsTheLastLineResolveActivityPrints(): void
    {
        $this->assertSame('com.tencent.mm/.ui.LauncherUI', AdbClient::launcherComponent("priority=0 preferredOrder=0 match=0x108000 specificIndex=-1 isDefault=false\ncom.tencent.mm/.ui.LauncherUI\n"));
        $this->assertNull(AdbClient::launcherComponent("No activity found\n"));
        $this->assertNull(AdbClient::launcherComponent(''));
    }

    public function testThePackageShipsTheHelperItTalksTo(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/helper/src/pig/androiduse/helper/Main.java');

        $this->assertSame(1, preg_match('/static final int VERSION = (\d+);/', $source, $m));
        $this->assertSame(Helper::VERSION, (int) $m[1], 'Helper::VERSION follows Main.VERSION');
        $this->assertTrue(is_file(Helper::localDex()), 'helper/pig-android-helper.dex is built (helper/build.sh) and committed');
    }
}
