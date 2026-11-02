<?php

declare(strict_types=1);

namespace PigAndroidUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigAndroidUse\ScreenCapture;
use PigAndroidUse\TreeParser;

/**
 * Tree rects and screenshots of one screen share one coordinate space — the one its own size gives
 * (the helper reports it with the tree; the nodes need not cover the screen) — so a target read
 * from the tree is tapped where it is whether or not a screenshot came in between.
 */
final class CoordinatesTest extends TestCase
{
    public function testTheSpaceOfAScreenIsTheOneItsScreenshotHas(): void
    {
        $space = ScreenCapture::spaceFor(1440, 3200);

        $this->assertSame(['width' => 706, 'height' => 1568], $space['viewport']);
        $this->assertSame([2.0397, 2.0408], $space['pixel_to_point']);

        $fitted = ScreenCapture::fitDimensions(1440, 3200);
        $this->assertSame([$fitted['width'], $fitted['height']], [$space['viewport']['width'], $space['viewport']['height']]);

        $this->assertSame(['width' => 1568, 'height' => 706], ScreenCapture::spaceFor(3200, 1440)['viewport']);
    }

    public function testATreeRectInThatSpaceMapsBackToTheDevice(): void
    {
        $xml = '<hierarchy><node bounds="[0,0][1440,3200]"><node text="Display" clickable="true" class="android.widget.TextView" bounds="[0,2300][1440,2420]" /></node></hierarchy>';
        $space = ScreenCapture::spaceFor(1440, 3200);

        $node = TreeParser::parse($xml, $space['pixel_to_point'])[0];
        $centreY = $node['tap'][1] * $space['pixel_to_point'][1];

        $this->assertLessThan(3.0, abs($centreY - 2360), 'the centre of the row, back on the device');
    }
}
