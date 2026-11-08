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
 * Tests of the sky of the channel. The elements of the station are the public ones of 8 November 2026; the reference
 * values come from the library sgp4 2.27 and from Skyfield 1.55 with the ephemeris DE421.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\sky
 * @covers     \local_oksigeniamarandu\local\sgp4
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sky::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sgp4::class)]
final class sky_test extends \advanced_testcase {
    /** A place: Madrid. */
    private const PLACE = [40.4168, -3.7038];

    /** @var string[] The addresses asked for. */
    private $asked = [];

    /**
     * A site with its bot and its channel in Madrid, and the elements of the station of the fixture.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'example_school_bot', 'local_oksigeniamarandu');
        set_config('channelid', '-1001234567890', 'local_oksigeniamarandu');
        set_config('latitude', (string)self::PLACE[0], 'local_oksigeniamarandu');
        set_config('longitude', (string)self::PLACE[1], 'local_oksigeniamarandu');
        sky::fake_for_tests(function (string $url) {
            $this->asked[] = $url;
            return file_get_contents(__DIR__ . '/../fixtures/sky/iss.tle');
        });
    }

    /**
     * The tests forget their source.
     */
    protected function tearDown(): void {
        sky::fake_for_tests(null);
        parent::tearDown();
    }

    /**
     * The station of the fixture.
     *
     * @return sgp4
     */
    private function station(): sgp4 {
        $lines = file(__DIR__ . '/../fixtures/sky/iss.tle', FILE_IGNORE_NEW_LINES);
        return new sgp4($lines[1], $lines[2]);
    }

    /**
     * The model gives the positions of the reference implementation, to the metre.
     */
    public function test_sgp4(): void {
        $station = $this->station();
        $this->assertEqualsWithDelta(2461352.67541007, $station->epoch, 1e-8);
        $epoch = ($station->epoch - 2440587.5) * DAYSECS;
        $reference = [
            0 => [4141.077718, -5384.660738, 0.002052],
            360 => [462.739849, -5648.753786, -3752.916888],
            4320 => [-2869.953401, 6158.273347, -341.279297],
            10080 => [-1042.597884, 6663.355852, -889.398029],
        ];
        foreach ($reference as $minutes => $position) {
            $this->assertEqualsWithDelta($position, $station->position($epoch + $minutes * MINSECS), 0.001);
        }
        $this->expectException(\moodle_exception::class);
        new sgp4('1 25544U', '2 25544');
    }

    /**
     * The passes seen from a place, as Skyfield sees them: to the step of the search.
     */
    public function test_passes(): void {
        $from = gmmktime(15, 0, 0, 11, 10, 2026);
        $passes = sky::passes($this->station(), self::PLACE, $from, $from + DAYSECS);
        $this->assertCount(1, $passes);
        // Skyfield: seen from 18:17:52 to 18:20:32 UTC, up to 21.2°, from the azimuth 184 to 130.
        $this->assertEqualsWithDelta(gmmktime(18, 17, 52, 11, 10, 2026), $passes[0]['start'], 40);
        $this->assertEqualsWithDelta(gmmktime(18, 20, 32, 11, 10, 2026), $passes[0]['end'], 40);
        $this->assertEqualsWithDelta(21.2, $passes[0]['maxelevation'], 0.5);
        $this->assertEqualsWithDelta(184, $passes[0]['from'], 5);
        $this->assertEqualsWithDelta(130, $passes[0]['to'], 8);
    }

    /**
     * The Moon: its full moon and its rise, as Skyfield gives them, to a few minutes.
     */
    public function test_moon(): void {
        // Skyfield: full moon on 24 November 2026 at 14:54 UTC; it rises over Madrid at 16:27 UTC, azimuth 56.
        $full = sky::next_full_moon(gmmktime(0, 0, 0, 11, 8, 2026));
        $this->assertEqualsWithDelta(gmmktime(14, 54, 0, 11, 24, 2026), $full, 30 * MINSECS);
        $rise = sky::moonrise(self::PLACE, gmmktime(11, 0, 0, 11, 24, 2026), gmmktime(23, 0, 0, 11, 24, 2026));
        $this->assertEqualsWithDelta(gmmktime(16, 27, 0, 11, 24, 2026), $rise['time'], 4 * MINSECS);
        $this->assertEqualsWithDelta(56, $rise['azimuth'], 2);
        $this->assertSame(4, sky::moon_phase($full)['phase']);
        $this->assertSame(100, sky::moon_phase($full)['lit']);
        $this->assertSame(0, sky::moon_phase(gmmktime(12, 0, 0, 11, 9, 2026))['phase']);
    }

    /**
     * The best pass of the next days goes to review, with its card, the afternoon of its evening; one a week at most.
     */
    public function test_iss_post(): void {
        global $DB;
        // Tuesday 10 November at 10:00 in Madrid: the best of the next three evenings is that of Thursday 12.
        $counts = sky::prepare(gmmktime(9, 0, 0, 11, 10, 2026));
        $this->assertSame(1, $counts['iss']);
        $post = $DB->get_record(channel::TABLE, ['kind' => sky::ISS]);
        $this->assertSame('sky_iss-20261112', $post->sourcekey);
        $this->assertSame('review', $post->status);
        $this->assertSame(gmmktime(16, 0, 0, 11, 12, 2026), (int)$post->timeplanned);
        $content = json_decode($post->content, true);
        $this->assertSame('The Space Station passes tonight', $content['title']);
        $this->assertSame('At 19:18', $content['lines'][0]);
        $this->assertSame(
            'It appears in the southwest and goes away in the east. It is seen for 4 minutes, without binoculars.',
            $content['lines'][1]
        );
        $this->assertSame('sky', $content['card']['type']);
        $this->assertSame('19:18', $content['card']['zones']['time']);
        $this->assertSame(['phase' => 1], $content['card']['zones']['moon']);
        $this->assertSame([sky::DEFAULTTLE], $this->asked);

        // The next day, the same evening is still the best: the same post; the elements are not downloaded again.
        sky::prepare(gmmktime(9, 0, 0, 11, 11, 2026));
        $this->assertSame(1, $DB->count_records(channel::TABLE, ['kind' => sky::ISS]));
        $this->assertCount(1, $this->asked);

        // Friday 13, the best is that of Saturday 14, but one went a few days before.
        $this->assertSame(0, sky::prepare(gmmktime(9, 0, 0, 11, 13, 2026))['iss']);

        // Switched off, nothing; without the place, nothing.
        channel::set_policies(['local_oksigeniamarandu/sky_iss' => 'off']);
        $DB->delete_records(channel::TABLE);
        $this->assertSame(0, sky::prepare(gmmktime(9, 0, 0, 11, 10, 2026))['iss']);
        channel::set_policies(['local_oksigeniamarandu/sky_iss' => 'review']);
        unset_config('latitude', 'local_oksigeniamarandu');
        $this->assertSame(0, sky::prepare(gmmktime(9, 0, 0, 11, 10, 2026))['iss']);
    }

    /**
     * The evening of the full moon, with the time it rises; and the night of a meteor shower of the hemisphere.
     */
    public function test_moon_and_meteors(): void {
        global $DB;
        $this->assertSame(0, sky::prepare(gmmktime(9, 0, 0, 11, 23, 2026))['moon']);
        $this->assertSame(1, sky::prepare(gmmktime(9, 0, 0, 11, 24, 2026))['moon']);
        $post = $DB->get_record(channel::TABLE, ['kind' => sky::MOON]);
        $this->assertSame('sky_moon-20261124', $post->sourcekey);
        $content = json_decode($post->content, true);
        $this->assertSame('Full moon', $content['title']);
        $this->assertMatchesRegularExpression('/^It rises in the northeast at 17:2[3-9]\./', $content['lines'][0]);
        $this->assertSame(['phase' => 4], $content['card']['zones']['moon']);
        $this->assertSame('Full · 100 %', $content['card']['zones']['moon_label']);

        // The Geminids, seen from both hemispheres; the Perseids, not from the south.
        $this->assertSame(1, sky::prepare(gmmktime(9, 0, 0, 12, 13, 2026))['meteors']);
        $content = json_decode($DB->get_field(channel::TABLE, 'content', ['kind' => sky::METEORS]), true);
        $this->assertSame('Meteor shower: the Geminids', $content['title']);
        $this->assertSame('150/h', $content['card']['zones']['time']);
        $this->assertSame(0, sky::prepare(gmmktime(9, 0, 0, 12, 14, 2026))['meteors']);
        set_config('latitude', '-33.4489', 'local_oksigeniamarandu');
        $this->assertSame(0, sky::prepare(gmmktime(9, 0, 0, 8, 12, 2027))['meteors']);
    }
}
