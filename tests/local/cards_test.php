<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_oksigeniamarandu\local;

/**
 * Tests of the cards of the channel, with the templates of the plugin and sample texts.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\cards
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cards::class)]
final class cards_test extends \advanced_testcase {
    /**
     * Every template has its files and its zones.
     */
    public function test_templates(): void {
        foreach (cards::TYPES as $type) {
            $template = cards::template($type);
            foreach ($template['files'] as $file) {
                $this->assertFileExists($template['dir'] . '/' . $file);
            }
            foreach ($template['fonts'] as $font) {
                $this->assertFileExists(dirname(__DIR__, 2) . '/fonts/' . $font);
            }
            $this->assertNotEmpty($template['zones']);
        }
        $this->expectException(\coding_exception::class);
        cards::template('poster');
    }

    /**
     * The colour of the organization as it is if it meets the contrast of the template, or the nearest that does, with
     * the text over it that reads best, as the house measured them.
     */
    public function test_colours(): void {
        $template = cards::template('open_day');
        $green = cards::colours($template, '#2f8f4e');
        $this->assertSame([44, 134, 73], $green['accent']);
        $this->assertSame([255, 255, 255], $green['on_accent']);
        $yellow = cards::colours($template, '#f2d45c');
        $this->assertSame([242, 212, 92], $yellow['accent']);
        $this->assertGreaterThanOrEqual(4.5, cards::contrast($yellow['accent'], $yellow['on_accent']));

        $this->resetAfterTest();
        $this->assertSame(cards::DEFAULTCOLOR, cards::brand());
        set_config('brandcolor', '#e83e8c', 'theme_boost');
        $this->assertSame('#e83e8c', cards::brand());
        set_config('cardcolor', '#1b3a6b', 'local_oksigeniamarandu');
        $this->assertSame('#1b3a6b', cards::brand());
        set_config('cardorg', 'Escuela Ejemplo', 'local_oksigeniamarandu');
        $this->assertSame('Escuela Ejemplo', cards::org());
    }

    /**
     * A card knows its type and its zones; the items of a list, as label and text; a cover, only of this site.
     */
    public function test_clean(): void {
        $card = cards::clean(['type' => 'this_week', 'zones' => ['title' => '<b>Esta semana</b>',
            'items' => [['label' => 'Lun 9', 'text' => 'Reunión de familias', 'extra' => 'x']]]]);
        $this->assertSame(['type' => 'this_week', 'zones' => ['title' => 'Esta semana',
            'items' => [['label' => 'Lun 9', 'text' => 'Reunión de familias']]]], $card);
        $this->assertSame(['phase' => 7], cards::clean(['type' => 'sky', 'zones' => ['moon' => ['phase' => -1]]])['zones']['moon']);
        $this->assertSame('Día de la Infancia', cards::clean(['type' => 'open_day',
            'zones' => ['title' => 'Día de la Infancia 🧒👧 ☀️']])['zones']['title']);
        foreach (
            [['type' => 'poster'], ['type' => 'open_day', 'zones' => ['price' => '3 €']],
                ['type' => 'book', 'zones' => ['cover' => 'https://example.org/cover.jpg']]] as $bad
        ) {
            try {
                cards::clean($bad);
                $this->fail('Not a valid card');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('card', $e->getMessage());
            }
        }
    }

    /**
     * The still card is a JPEG of the size of its template, for every type, also with long texts.
     */
    public function test_still(): void {
        if (!cards::can_draw()) {
            $this->markTestSkipped('GD with FreeType is needed');
        }
        $this->resetAfterTest();
        foreach (cards::TYPES as $type) {
            $sample = cards::template($type)['sample'];
            unset($sample['cover']);
            $jpeg = cards::still(cards::clean(['type' => $type, 'zones' => $sample]), '#2f8f4e');
            $size = getimagesizefromstring($jpeg);
            $this->assertSame([1280, 720, 'image/jpeg'], [$size[0], $size[1], $size['mime']], $type);
        }
        $long = cards::clean(['type' => 'open_day', 'zones' => ['title' => str_repeat('Jornada de puertas abiertas ', 12),
            'detail' => str_repeat('Con visita guiada y café. ', 30)]]);
        $this->assertNotEmpty(cards::still($long));
    }

    /**
     * The animated card is an MP4, if the server has an ffmpeg that can make it.
     */
    public function test_animated(): void {
        $path = trim((string)shell_exec('command -v ffmpeg 2>/dev/null'));
        $this->resetAfterTest();
        set_config('ffmpeg', $path, 'local_oksigeniamarandu');
        if (!cards::can_draw() || $path === '' || cards::ffmpeg() === null) {
            $this->markTestSkipped('ffmpeg 6.1 or later and GD with FreeType are needed');
        }
        $card = cards::clean(['type' => 'no_class', 'zones' => cards::template('no_class')['sample']]);
        $video = cards::animated($card, cards::ffmpeg(), '#1b3a6b');
        $this->assertSame('video/mp4', (new \finfo(FILEINFO_MIME_TYPE))->buffer((string)$video));
    }
}
