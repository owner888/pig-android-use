<?php

declare(strict_types=1);

namespace PigAndroidUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigAndroidUse\TreeQuality;
use PigAndroidUse\VisionOcr;

final class TreeQualityTest extends TestCase
{
    public function testATreeWithEnoughNamedElementsAndNoBlindAreaIsAdequate(): void
    {
        $rows = '';
        foreach (['聊天', '通讯录', '发现', '我', '搜索', '文件传输助手', '订阅号消息'] as $i => $name) {
            $top = 200 + $i * 150;
            $rows .= sprintf('<node text="%s" clickable="true" class="android.widget.TextView" bounds="[0,%d][1080,%d]" />', $name, $top, $top + 140);
        }

        $this->assertSame(['quality' => TreeQuality::ADEQUATE, 'reason' => null], TreeQuality::ofTree(self::screen($rows)));
    }

    public function testATreeWithNothingInItIsUnusable(): void
    {
        $this->assertSame('no_tree', TreeQuality::ofTree('')['reason']);
        $this->assertSame(TreeQuality::UNUSABLE, TreeQuality::ofTree('')['quality']);
        $this->assertSame(TreeQuality::UNUSABLE, TreeQuality::ofTree('not xml')['quality']);
    }

    public function testControlsWithNoLabelsMakeTheTreeUnusableOrPartial(): void
    {
        // A game: four tappable areas, not a word anywhere.
        $game = '';
        foreach ([0, 600, 1200, 1800] as $top) {
            $game .= sprintf('<node text="" content-desc="" clickable="true" class="android.view.View" bounds="[0,%d][1080,%d]" />', $top, $top + 500);
        }
        $this->assertSame(['quality' => TreeQuality::UNUSABLE, 'reason' => 'unlabeled_controls'], TreeQuality::ofTree(self::screen($game)));

        // The same with a title: something is named, most controls are not.
        $titled = '<node text="关卡 3" class="android.widget.TextView" bounds="[0,0][1080,100]" />' . $game;
        $this->assertSame(['quality' => TreeQuality::PARTIAL, 'reason' => 'unlabeled_controls'], TreeQuality::ofTree(self::screen($titled)));
    }

    public function testAClickableRowIsLabelledByTheTextInsideIt(): void
    {
        $rows = '';
        foreach (['张三', '李四', '王五', '赵六', '孙七', '周八'] as $i => $name) {
            $top = $i * 200;
            $rows .= sprintf(
                '<node text="" clickable="true" class="android.widget.LinearLayout" bounds="[0,%1$d][1080,%2$d]"><node text="%3$s" class="android.widget.TextView" bounds="[200,%1$d][800,%2$d]" /></node>',
                $top,
                $top + 190,
                $name,
            );
        }

        $this->assertSame(TreeQuality::ADEQUATE, TreeQuality::ofTree(self::screen($rows))['quality']);
    }

    public function testFewNamedElementsOrABlindAreaLeaveItToTheText(): void
    {
        $few = '<node text="确定" clickable="true" class="android.widget.Button" bounds="[0,0][1080,200]" />';
        $this->assertSame(['quality' => null, 'reason' => null], TreeQuality::ofTree(self::screen($few)));

        // Six names along the top, and a canvas filling the rest of the screen.
        $named = '';
        foreach (['返回', '标题', '分享', '收藏', '更多', '评论'] as $i => $name) {
            $named .= sprintf('<node text="%s" class="android.widget.TextView" bounds="[%d,0][%d,100]" />', $name, $i * 180, $i * 180 + 170);
        }
        $canvas = '<node text="" class="android.view.SurfaceView" bounds="[0,100][1080,2400]" />';
        $this->assertSame(['quality' => null, 'reason' => null], TreeQuality::ofTree(self::screen($named . $canvas)));
    }

    public function testTheScreenTextTheTreeLacksSettlesAnUncheckedTree(): void
    {
        $xml = self::screen('<node text="确定" clickable="true" class="android.widget.Button" bounds="[0,0][1080,200]" />');
        $lines = [
            self::line('确定', 0.99),
            self::line('第 3 关', 0.95),
            self::line('得分 1200', 0.9),
            self::line('开始游戏', 0.92),
            self::line('x', 0.99),          // too short to count
            self::line('模糊不清', 0.2),     // too uncertain to count
        ];

        $missing = TreeQuality::missingLines($lines, $xml);

        $this->assertSame(['第 3 关', '得分 1200', '开始游戏'], array_column($missing, 'text'));
        $this->assertSame(['quality' => TreeQuality::PARTIAL, 'reason' => 'text_outside_tree'], TreeQuality::withText(TreeQuality::ofTree($xml), $missing));
        $this->assertSame(TreeQuality::ADEQUATE, TreeQuality::withText(['quality' => null, 'reason' => null], array_slice($missing, 0, 2))['quality']);

        // A tree that was judged already stays as judged.
        $judged = ['quality' => TreeQuality::UNUSABLE, 'reason' => 'no_text'];
        $this->assertSame($judged, TreeQuality::withText($judged, []));
    }

    public function testOcrAndTheTreeAreComparedWithoutSpacesPunctuationOrCase(): void
    {
        $xml = self::screen('<node text="Sign in" class="android.widget.Button" bounds="[0,0][500,100]" /><node text="未读消息" class="android.widget.TextView" bounds="[0,100][500,200]" />');

        $missing = TreeQuality::missingLines([self::line('SIGN IN.', 0.9), self::line('未读消息(3)', 0.9), self::line('今天天气很好适合出门', 0.9)], $xml);

        $this->assertSame(['今天天气很好适合出门'], array_column($missing, 'text'));
    }

    public function testVisionBoxesBecomeTopLeftPixels(): void
    {
        $lines = VisionOcr::toLines([
            ['text' => ' 确定 ', 'confidence' => 0.987, 'x' => 0.25, 'y' => 0.75, 'w' => 0.5, 'h' => 0.1],
            ['text' => '', 'confidence' => 1.0, 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
            'not an observation',
        ], 1000, 2000);

        $this->assertSame([['text' => '确定', 'confidence' => 0.99, 'rect' => [250, 300, 500, 200]]], $lines);
    }

    /** @return array{text: string, confidence: float, rect: array{int, int, int, int}} */
    private static function line(string $text, float $confidence): array
    {
        return ['text' => $text, 'confidence' => $confidence, 'rect' => [0, 0, 100, 40]];
    }

    private static function screen(string $children): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><hierarchy rotation="0">'
            . '<node text="" class="android.widget.FrameLayout" bounds="[0,0][1080,2400]">' . $children . '</node>'
            . '</hierarchy>';
    }
}
