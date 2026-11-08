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

use local_oksigeniamarandu\api;

/**
 * Tests of the card of the channel on the dashboard. Chats, channels and posts are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\work
 */
#[\PHPUnit\Framework\Attributes\CoversClass(work::class)]
final class work_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid: in the hours of review and of the channel. */
    private const WEDNESDAY = 1793181600;

    /**
     * A site with its bot and its channel, Madrid time, a made-up plugin that posts and the admin as reviewer.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'example_school_bot', 'local_oksigeniamarandu');
        set_config('channelid', '-1001234567890', 'local_oksigeniamarandu');
        set_config('channeltitle', 'Escuela Ejemplo', 'local_oksigeniamarandu');
        set_config('channelusername', 'escuelaejemplo', 'local_oksigeniamarandu');
        channel::declare_for_tests(['local_example' => ['open_day' => []]]);
        consent::optin_from_message('tg:555', api::invite('core', 0, (int)get_admin()->id), 'join');
    }

    /**
     * The tests forget their kinds.
     */
    protected function tearDown(): void {
        channel::declare_for_tests([]);
        parent::tearDown();
    }

    /**
     * A post to review.
     *
     * @param string $title Its title.
     * @param int $when Its time.
     * @param bool $urgent Whether it is urgent.
     * @return int Its id.
     */
    private function post(string $title, int $when, bool $urgent = false): int {
        return channel::post('local_example', 'open_day', ['title' => $title], $when, $urgent, '', null, self::WEDNESDAY);
    }

    /**
     * Only whoever may review sees the card; a site without a channel and nothing waiting shows none.
     */
    public function test_who_and_when(): void {
        global $DB;
        $admin = (int)get_admin()->id;
        $somebody = (int)$this->getDataGenerator()->create_user()->id;
        $manager = (int)$this->getDataGenerator()->create_user()->id;
        role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager, \context_system::instance());

        $this->assertSame([], work::for_user($somebody, self::WEDNESDAY));
        [$card] = work::for_user($admin, self::WEDNESDAY);
        $this->assertSame('channel', $card['key']);
        $this->assertSame('Telegram channel', $card['title']);
        $this->assertStringEndsWith('/local/oksigeniamarandu/channel.php', $card['url']);
        $this->assertSame('Nothing posted yet', $card['status']);
        $this->assertSame([], $card['alerts']);
        $this->assertSame([['label' => 'Open in Telegram', 'url' => 'https://t.me/escuelaejemplo']], $card['actions']);
        $this->assertCount(1, work::for_user($manager, self::WEDNESDAY));

        channel::disconnect();
        $this->assertSame([], work::for_user($admin, self::WEDNESDAY));
    }

    /**
     * Each post waiting for its review is something to do: urgent if only the review holds it back, for today if its
     * time has not come; the problems of the channel first.
     */
    public function test_alerts(): void {
        $admin = (int)get_admin()->id;
        $this->post('Open day', self::WEDNESDAY + DAYSECS);
        $this->post('School trip', self::WEDNESDAY - HOURSECS);

        [$card] = work::for_user($admin, self::WEDNESDAY);
        $this->assertSame('2 to review', $card['status']);
        $this->assertSame(['urgent', 'today'], array_column($card['alerts'], 'level'));
        $this->assertSame([
            '“School trip” is waiting for your review; it goes out as soon as you say yes.',
            '“Open day” is waiting for your review; it goes out tomorrow at 11:00.',
        ], array_column($card['alerts'], 'text'));
        $this->assertStringEndsWith('/local/oksigeniamarandu/channel.php#mrd-review', $card['alerts'][0]['url']);

        set_config('channelproblem', 'norights', 'local_oksigeniamarandu');
        [$card] = work::for_user($admin, self::WEDNESDAY);
        $problem = 'The bot can no longer post in the channel: give it “Manage messages” again.';
        $this->assertSame($problem, $card['alerts'][0]['text']);
        $this->assertSame('urgent', $card['alerts'][0]['level']);

        // More than three, in one line; without the channel, what waits is still told.
        $this->post('Book of the month', self::WEDNESDAY + 2 * DAYSECS);
        $this->post('Concert', self::WEDNESDAY + 3 * DAYSECS, true);
        channel::disconnect();
        [$card] = work::for_user($admin, self::WEDNESDAY);
        $this->assertSame([
            'The channel is not connected: what is waiting does not go out.',
            '4 posts are waiting for your review.',
        ], array_column($card['alerts'], 'text'));
        $this->assertSame(['urgent', 'urgent'], array_column($card['alerts'], 'level'));
        $this->assertSame([], $card['actions']);
    }

    /**
     * What was decided leaves the card, and what goes next is counted.
     */
    public function test_decided(): void {
        global $DB;
        $admin = (int)get_admin()->id;
        $id = $this->post('Open day', self::WEDNESDAY + DAYSECS);
        channel::decide($DB->get_record(channel::TABLE, ['id' => $id]), true, $admin, null, self::WEDNESDAY);

        [$card] = work::for_user($admin, self::WEDNESDAY);
        $this->assertSame('1 to go out', $card['status']);
        $this->assertSame([], $card['alerts']);
    }

    /**
     * The card is kept for a minute, and forgotten as soon as a post changes.
     */
    public function test_kept(): void {
        global $DB;
        $admin = (int)get_admin()->id;
        $this->assertSame([], work::for_user($admin)[0]['alerts']);

        // Written behind the back of the channel: the card kept stays.
        $DB->insert_record(channel::TABLE, (object)['component' => 'local_example', 'kind' => 'open_day', 'sourcekey' => '',
            'content' => json_encode(['title' => 'Open day']), 'urgent' => 0, 'status' => 'review', 'token' => 'abcdefghij',
            'timeplanned' => time(), 'timecreated' => time(), 'timemodified' => time()]);
        $this->assertSame([], work::for_user($admin)[0]['alerts']);

        // Any change of a post through the channel forgets it.
        $this->post('School trip', time() + WEEKSECS);
        $this->assertCount(2, work::for_user($admin)[0]['alerts']);
    }
}
