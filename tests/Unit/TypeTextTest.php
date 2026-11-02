<?php

declare(strict_types=1);

namespace PigAndroidUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigAndroidUse\AdbClient;
use PigAndroidUse\InputManager;

/**
 * What android_type_text does without the helper, against a stand-in `adb` that writes down what
 * it is asked: the helper's own path (setting the field's text) needs a phone.
 */
final class TypeTextTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pig-type-' . getmypid();
        @mkdir($this->dir);
        file_put_contents("{$this->dir}/adb", "#!/bin/sh\necho \"\$@\" >> \"{$this->dir}/calls\"\n");
        chmod("{$this->dir}/adb", 0755);
    }

    protected function tearDown(): void
    {
        @unlink("{$this->dir}/adb");
        @unlink("{$this->dir}/calls");
        @rmdir($this->dir);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return file("{$this->dir}/calls", FILE_IGNORE_NEW_LINES) ?: [];
    }

    public function testAPlainWordGoesAsKeysToTheScreenAsked(): void
    {
        $typed = InputManager::typeText(new AdbClient("{$this->dir}/adb", 'S1'), 'hello', null, 17);

        $this->assertSame(['method' => 'keys', 'verified' => null], $typed);
        $this->assertSame(["-s S1 shell input -d 17 text 'hello'"], $this->calls());
    }

    public function testChineseWithoutTheHelperIsPastedAndSaidToBeUnchecked(): void
    {
        $typed = InputManager::typeText(new AdbClient("{$this->dir}/adb", 'S1'), '显示', null, 17);

        $this->assertSame('paste', $typed['method']);
        $this->assertSame(false, $typed['verified'], 'a paste on the background screen may put nothing there: never reported as done');
        $this->assertTrue(str_contains($typed['note'] ?? '', 'the helper is not available'), 'the note says why it fell back');
        $this->assertSame(["-s S1 shell cmd clipboard set text '显示'", '-s S1 shell input -d 17 keyevent 279'], $this->calls());
    }
}
