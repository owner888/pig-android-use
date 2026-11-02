<?php

declare(strict_types=1);

namespace PigAndroidUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Utils\Yaml;

/**
 * The app notes under `skills/android-use/apps/` are files the skill points the model at, not
 * skills: the walk stops at the folder's `SKILL.md`, so the package's skills directory loads as
 * the one skill. And each note says which app it is for, which is how the skill tells the model to
 * find it.
 */
final class AppKnowledgeTest extends TestCase
{
    private const string SKILLS = __DIR__ . '/../../skills';

    public function testTheSkillsDirectoryIsOneSkillAndTheAppNotesAreNotSkills(): void
    {
        [$skills, $warnings] = Skills::fromDirectories([self::SKILLS]);

        $this->assertSame([], $warnings);
        $this->assertSame(['android-use'], array_map(static fn ($s): string => $s->name, $skills));
    }

    public function testEveryAppNoteNamesItsAppAndPackages(): void
    {
        $notes = glob(self::SKILLS . '/android-use/apps/*/APP.md') ?: [];
        $this->assertNotSame([], $notes);

        foreach ($notes as $note) {
            $content = (string) file_get_contents($note);
            $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', $content, $m), "{$note} has frontmatter");

            $front = Yaml::parse($m[1]);
            $this->assertIsArray($front, $note);
            $this->assertSame(basename(dirname($note)), $front['name'] ?? null, "{$note} is named after its folder");
            $this->assertIsArray($front['packages'] ?? null, "{$note} lists its packages");
        }
    }
}
