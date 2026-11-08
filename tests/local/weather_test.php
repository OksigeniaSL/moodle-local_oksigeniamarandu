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
 * Tests of the weather warnings of the channel. The warnings, their areas and their sources are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\weather
 */
#[\PHPUnit\Framework\Attributes\CoversClass(weather::class)]
final class weather_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid. */
    private const WEDNESDAY = 1793181600;

    /** The feed of the tests. */
    private const FEED = 'https://warnings.example.org/feed';

    /** @var array What each address answers. */
    private $sources = [];

    /** @var string[] The addresses asked for. */
    private $asked = [];

    /**
     * A site with its bot and its channel, Madrid time, and the sample source.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'example_school_bot', 'local_oksigeniamarandu');
        set_config('channelid', '-1001234567890', 'local_oksigeniamarandu');
        set_config('weatherfeed', self::FEED, 'local_oksigeniamarandu');
        $fixtures = __DIR__ . '/../fixtures/weather/';
        $this->sources = [
            self::FEED => file_get_contents($fixtures . 'feed.xml'),
            'https://warnings.example.org/cap/1' => file_get_contents($fixtures . 'alert1.xml'),
        ];
        weather::fake_for_tests(function (string $url) {
            $this->asked[] = $url;
            return $this->sources[$url] ?? null;
        });
        \cache::make('local_oksigeniamarandu', 'weather')->purge();
    }

    /**
     * The tests forget their source.
     */
    protected function tearDown(): void {
        weather::fake_for_tests(null);
        parent::tearDown();
    }

    /**
     * The posts of the warnings, by key.
     *
     * @return array
     */
    private function posts(): array {
        global $DB;
        $posts = [];
        foreach ($DB->get_records(channel::TABLE, ['kind' => weather::KIND], 'id') as $post) {
            $post->content = json_decode($post->content, true);
            $posts[] = $post;
        }
        return $posts;
    }

    /**
     * A feed of MeteoAlarm: each warning with its fields, its area, its code and the address of its whole alert.
     */
    public function test_parse(): void {
        $alerts = weather::parse($this->sources[self::FEED]);
        $this->assertCount(4, $alerts);
        $this->assertSame('2.49.0.0.999.0.XX.1', $alerts[0]['identifier']);
        $this->assertSame('https://warnings.example.org/cap/1', $alerts[0]['cap']);
        $this->assertSame('Severe', $alerts[0]['infos'][0]['severity']);
        $this->assertSame(['xx101'], $alerts[0]['infos'][0]['areas'][0]['codes']);
        $this->assertSame('Costa de Ejemplo', $alerts[0]['infos'][0]['areas'][0]['desc']);
        $this->assertSame(strtotime('2026-10-29T06:00:00Z'), $alerts[0]['infos'][0]['onset']);

        // A whole alert, in its two languages.
        [$alert] = weather::parse($this->sources['https://warnings.example.org/cap/1']);
        $this->assertSame(['es-ES', 'en-GB'], array_column($alert['infos'], 'language'));
        $this->assertSame('Servicio Meteorológico de Ejemplo', $alert['infos'][0]['sender']);

        // Nothing from what is not a source of warnings, nor from a document that names others.
        $this->assertSame([], weather::parse('<html><body>Not a feed</body></html>'));
        $this->assertSame([], weather::parse('<?xml version="1.0"?><!DOCTYPE alert [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"><identifier>&x;</identifier></alert>'));
    }

    /**
     * Only the warnings of the area of the site, from its level and still on, go to review; the severe as urgent, in
     * the language of the site when the whole alert brings it, with their times, area, source and link.
     */
    public function test_area_and_level(): void {
        global $CFG;
        set_config('weatherareas', 'XX101', 'local_oksigeniamarandu');
        $counts = weather::prepare(self::WEDNESDAY);
        $this->assertSame(['source' => 4, 'area' => 1, 'posted' => 1, 'withdrawn' => 0], $counts);
        [$post] = $this->posts();
        $this->assertSame('review', $post->status);
        $this->assertSame('1', (string)$post->urgent);
        $this->assertSame(strtotime('2026-10-29T23:59:59+01:00'), (int)$post->timeexpires);
        $this->assertSame('Orange wind warning. Costa de Ejemplo', $post->content['title']);
        $this->assertSame([
            'From Thursday 29 at 07:00 to Thursday 29 at 23:59',
            'Area: Costa de Ejemplo',
            'Source: Sample Weather Service',
        ], $post->content['lines']);
        $this->assertSame(['Maximum gusts of 90 km/h on the coast.',
            'Keep away from the seafront and secure anything the wind can carry.'], $post->content['text']);
        $this->assertSame('https://warnings.example.org/warnings', $post->content['url']);
        $this->assertFalse($post->content['utm']);
        $this->assertSame([self::FEED, 'https://warnings.example.org/cap/1'], $this->asked);

        // The whole alert is kept: the next reading does not ask for it again, and the post stays as it is.
        $this->asked = [];
        weather::prepare(self::WEDNESDAY + HOURSECS);
        $this->assertSame([self::FEED], $this->asked);
        $this->assertCount(1, $this->posts());

        // In the language of the site, if the alert brings it.
        \cache::make('local_oksigeniamarandu', 'weather')->purge();
        $CFG->lang = 'es';
        weather::prepare(self::WEDNESDAY + 2 * HOURSECS);
        [$post] = $this->posts();
        $this->assertSame('Aviso de viento de nivel naranja. Costa de Ejemplo', $post->content['title']);
        $this->assertSame('review', $post->status);

        // From the moderate level, the yellow one too, by the name of its area as well: its times touch those of the
        // orange one, so they go together, under the orange one; the post that had only the orange one does not go.
        $CFG->lang = 'en';
        set_config('weatherseverity', 'Moderate', 'local_oksigeniamarandu');
        set_config('weatherareas', 'costa de ejemplo', 'local_oksigeniamarandu');
        $counts = weather::prepare(self::WEDNESDAY);
        $this->assertSame(['source' => 4, 'area' => 2, 'posted' => 1, 'withdrawn' => 1], $counts);
        $posts = $this->posts();
        $this->assertSame(['cancelled', 'review'], array_column($posts, 'status'));
        $this->assertSame('Orange wind warning. Costa de Ejemplo', $posts[1]->content['title']);
        $this->assertSame([
            'Orange wind warning, from Thursday 29 at 07:00 to Thursday 29 at 23:59',
            'Moderate rain warning, from Thursday 29 at 10:00 to Thursday 29 at 23:59',
            'Area: Costa de Ejemplo',
            'Source: Sample Weather Service',
        ], $posts[1]->content['lines']);
        $this->assertSame('1', (string)$posts[1]->urgent);
        $this->assertSame(4, weather::last()->source);
    }

    /**
     * What the source no longer brings does not go, if it is still waiting; without an area, nothing goes; and a source
     * that does not answer changes nothing.
     */
    public function test_withdrawn(): void {
        set_config('weatherareas', 'XX101', 'local_oksigeniamarandu');
        weather::prepare(self::WEDNESDAY);
        $this->assertCount(1, $this->posts());

        $this->sources[self::FEED] = null;
        $this->assertSame(0, weather::prepare(self::WEDNESDAY)['withdrawn']);
        $this->assertNull(weather::last()->source);
        $this->assertSame('review', $this->posts()[0]->status);

        $this->sources[self::FEED] = '<feed xmlns="http://www.w3.org/2005/Atom"><id>x</id></feed>';
        $this->assertSame(1, weather::prepare(self::WEDNESDAY)['withdrawn']);
        $this->assertSame('cancelled', $this->posts()[0]->status);

        // Without an area or a place, no warning is of the site.
        unset_config('weatherareas', 'local_oksigeniamarandu');
        $this->sources[self::FEED] = file_get_contents(__DIR__ . '/../fixtures/weather/feed.xml');
        $this->assertSame(0, weather::prepare(self::WEDNESDAY)['area']);
    }

    /**
     * With the place of the site inside the outline of a warning, it is of the site; and an alert that cancels another
     * takes it back.
     */
    public function test_place_and_cancel(): void {
        set_config('latitude', '10.5', 'local_oksigeniamarandu');
        set_config('longitude', '20.5', 'local_oksigeniamarandu');
        $this->sources[self::FEED] = '<?xml version="1.0"?><alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">'
            . '<identifier>urn:oid:2.49.0.1.999.0.a0</identifier><status>Actual</status><msgType>Alert</msgType>'
            . '<info><event>Flood Watch</event><severity>Extreme</severity><expires>2026-10-29T08:00:00Z</expires>'
            . '<headline>Flood Watch for Example Valley</headline>'
            . '<area><areaDesc>Example Valley</areaDesc><circle>10.4,20.4 30</circle></area></info></alert>';
        $this->assertSame(1, weather::prepare(self::WEDNESDAY)['posted']);

        $this->sources[self::FEED] = file_get_contents(__DIR__ . '/../fixtures/weather/polygon.xml');
        $counts = weather::prepare(self::WEDNESDAY);
        $this->assertSame(1, $counts['area']);
        $this->assertSame(1, $counts['withdrawn']);
        $posts = $this->posts();
        $this->assertSame(['cancelled', 'review'], array_column($posts, 'status'));
        $this->assertSame('Flood Warning issued for Example Valley', $posts[1]->content['title']);
        $this->assertSame(['Flooding along the river of the valley.'], $posts[1]->content['text']);
        $this->assertTrue(weather::inside(10.5, 20.5, [[10, 20], [10, 21], [11, 21], [11, 20]]));
        $this->assertFalse(weather::inside(12.0, 20.5, [[10, 20], [10, 21], [11, 21], [11, 20]]));
    }
}
