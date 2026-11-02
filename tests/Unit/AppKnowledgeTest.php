<?php

declare(strict_types=1);

namespace PigAndroidUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Utils\Yaml;

/**
 * The app notes under `skills/android-use/apps/` are files the skill points the model at, not
 * skills: the walk stops at the folder's `SKILL.md`, so `android-use` loads as one skill beside the
 * package's other skills, and no `APP.md` becomes one. And each note says which app it is for, which is how the skill tells the model to
 * find it.
 */
final class AppKnowledgeTest extends TestCase
{
    private const string SKILLS = __DIR__ . '/../../skills';

    public function testEachSkillFolderIsOneSkillAndTheAppNotesAreNotSkills(): void
    {
        [$skills, $warnings] = Skills::fromDirectories([self::SKILLS]);

        $this->assertSame([], $warnings);
        $this->assertSame(
            ['android-use', 'content-transcribe', 'info-research', 'messaging'],
            array_map(static fn ($s): string => $s->name, $skills),
        );
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
