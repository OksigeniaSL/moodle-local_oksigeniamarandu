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
 * Tests of the work of a class sent to the channel. Courses, people and photos are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\classwork
 */
#[\PHPUnit\Framework\Attributes\CoversClass(classwork::class)]
final class classwork_test extends \advanced_testcase {
    /**
     * A site with its bot and its channel.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'example_school_bot', 'local_oksigeniamarandu');
        set_config('channelid', '-1001234567890', 'local_oksigeniamarandu');
    }

    /**
     * A made-up photo: a wide PNG with a transparent half.
     *
     * @param int $width Its width.
     * @param int $height Its height.
     * @return string
     */
    private function png(int $width = 2000, int $height = 1000): string {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, imagecolorallocate($image, 200, 40, 40));
        ob_start();
        imagepng($image);
        return (string)ob_get_clean();
    }

    /**
     * The photo is drawn again: a JPEG no larger than it needs, on white where it was transparent; what is not a photo,
     * nothing.
     */
    public function test_photo(): void {
        $jpeg = classwork::photo($this->png());
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
        [$width, $height] = getimagesizefromstring($jpeg);
        $this->assertSame([1600, 800], [$width, $height]);
        $image = imagecreatefromstring($jpeg);
        $white = imagecolorsforindex($image, imagecolorat($image, 1500, 400));
        $this->assertGreaterThan(240, min($white['red'], $white['green'], $white['blue']));

        $small = classwork::photo($this->png(300, 200));
        $this->assertSame([300, 200], array_slice(getimagesizefromstring($small), 0, 2));
        $this->assertNull(classwork::photo('Not a photo'));
        $this->assertNull(classwork::photo(''));
        $this->assertNull(classwork::photo('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
    }

    /**
     * Whoever teaches the course sends it; it waits for a review, always, with its photo on the site for the channel; the
     * log says who sent it, the post does not.
     */
    public function test_send(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Taller de cerámica']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $context = \context_course::instance($course->id);
        $this->assertTrue(classwork::can_send($context, (int)$teacher->id));
        $this->assertFalse(classwork::can_send($context, (int)$student->id));

        // The site cannot make it go by itself.
        channel::set_policies(['local_oksigeniamarandu/classwork' => 'auto']);
        $this->assertSame('review', channel::policy('local_oksigeniamarandu/classwork'));

        $this->setUser($teacher);
        $sink = $this->redirectEvents();
        $id = classwork::send($course, $this->png(), '  Cuencos de <b>barro</b> ', 'Taller de cerámica', 'Hechos a mano.');
        $events = $sink->get_events();
        $sink->close();

        $post = $DB->get_record(channel::TABLE, ['id' => $id]);
        $this->assertSame('review', $post->status);
        $content = json_decode($post->content, true);
        $this->assertSame('Cuencos de barro', $content['title']);
        $this->assertSame(['Taller de cerámica'], $content['lines']);
        $this->assertSame(['Hechos a mano.'], $content['text']);
        $this->assertMatchesRegularExpression(
            '~/pluginfile\.php/\d+/local_oksigeniamarandu/classwork/\d+/work\.jpg$~',
            $content['image']
        );
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer(rich::image($content['image'])));
        $this->assertStringNotContainsString($teacher->lastname, $post->sourcekey . $post->content);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_oksigeniamarandu\event\classwork_sent::class, $events[0]);
        $this->assertSame((int)$teacher->id, (int)$events[0]->userid);
        $this->assertSame($id, (int)$events[0]->objectid);

        // Without a title, or without a photo, it does not go.
        foreach ([[$this->png(), ''], ['Not a photo', 'Cuencos']] as [$photo, $title]) {
            try {
                classwork::send($course, $photo, $title, '');
                $this->fail('It should not go');
            } catch (\moodle_exception $e) {
                $this->assertStringStartsWith('classwork:', $e->errorcode);
            }
        }

        // Switched off, or without a channel, it cannot be sent; and nothing is kept.
        channel::set_policies(['local_oksigeniamarandu/classwork' => 'off']);
        $this->assertFalse(classwork::can_send($context, (int)$teacher->id));
        channel::set_policies(['local_oksigeniamarandu/classwork' => 'review']);
        channel::disconnect();
        $this->assertFalse(classwork::can_send($context, (int)$teacher->id));
        $area = ['component' => 'local_oksigeniamarandu', 'filearea' => 'classwork'];
        $files = $DB->count_records('files', $area);
        $this->assertSame(0, classwork::send($course, $this->png(), 'Otra', ''));
        $this->assertSame($files, $DB->count_records('files', $area));
    }
}
