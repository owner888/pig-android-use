<?php

declare(strict_types=1);

namespace PigAndroidUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigAndroidUse\Helper;
use PigAndroidUse\TreeParser;

final class TreeParserTest extends TestCase
{
    public function testNodesPastTheLimitAreCountedSoTheToolCanSaySomeWereLeftOut(): void
    {
        $rows = '';
        for ($i = 0; $i < 12; $i++) {
            $rows .= sprintf('<node text="row %d" clickable="true" class="android.widget.TextView" bounds="[0,%d][1080,%d]" />', $i, $i * 100, $i * 100 + 90);
        }
        // A container with no text and not clickable is not a useful node, and is not counted.
        $xml = '<hierarchy><node class="android.widget.FrameLayout" bounds="[0,0][1080,2400]">' . $rows . '</node></hierarchy>';

        $nodes = TreeParser::parse($xml, [1.0, 1.0], 5, $total);
        $this->assertSame(5, count($nodes));
        $this->assertSame(12, $total);
        $this->assertSame('row 4', $nodes[4]['text'], 'the first ones, in the order of the tree');

        TreeParser::parse($xml, [1.0, 1.0], 60, $all);
        $this->assertSame(12, $all);

        TreeParser::parse('', [1.0, 1.0], 60, $none);
        $this->assertSame(0, $none);
    }

    public function testAnEmptyBackgroundScreenSaysWhyAndWhatToDo(): void
    {
        $this->assertStringContainsString('no app has been opened on it yet', Helper::emptyScreen(false));
        $this->assertStringContainsString('closed, with the app on it, after 15 minutes', Helper::emptyScreen(true));
        $this->assertStringContainsString('android_launch_app(screen="background")', Helper::emptyScreen(true));
    }
}
