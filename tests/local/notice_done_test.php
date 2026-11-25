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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/listening_plugin.php');
require_once(__DIR__ . '/../fixtures/listening_plugin_lib.php');

/**
 * What a plugin can know of its notices: whether they would reach, what became of them, and how to take them back.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\queue
 * @covers     \local_oksigeniamarandu\local\bulk
 * @covers     \local_oksigeniamarandu\api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(queue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(bulk::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class notice_done_test extends \advanced_testcase {
    /** The plugin. */
    private const PLUGIN = 'local_oksigeniamarandu';

    /**
     * A site with WhatsApp and Telegram, and a plugin with two notices.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $CFG->lang = 'en';
        listening_plugin::forget();
        notices::declare_for_tests([self::PLUGIN => [
            'act_invitation' => ['examples' => ['Jornada', 'jueves']],
            'answer_waiting' => ['examples' => ['Centro', 'Sí'], 'valuelimit' => 700],
            'too_long' => ['examples' => ['a'], 'valuelimit' => 5000],
        ]]);
        foreach (['whatsapp', 'telegram'] as $channel) {
            notices::set(self::PLUGIN . '/act_invitation', $channel, true);
            notices::set(self::PLUGIN . '/answer_waiting', $channel, true);
            notices::set(self::PLUGIN . '/notification', $channel, true);
        }
        foreach (['phonenumberid' => '1110000000', 'wabaid' => '2220000000', 'telegrambot' => 'test_bot'] as $name => $value) {
            set_config($name, $value, self::PLUGIN);
        }
        set_config('accesstoken', \core\encryption::encrypt('fake'), self::PLUGIN);
        set_config('telegramtoken', \core\encryption::encrypt('99:fake'), self::PLUGIN);
        set_config('capfamily', 0, self::PLUGIN);
        set_config('quietfrom', '', self::PLUGIN);
        set_config('quietdays', '', self::PLUGIN);
    }

    /**
     * The tests forget their notices.
     */
    protected function tearDown(): void {
        notices::declare_for_tests([]);
        listening_plugin::forget();
        parent::tearDown();
    }

    /**
     * Joins an address for an item.
     *
     * @param string $address The number or the chat.
     * @param int $itemid The item.
     * @return \stdClass The contact.
     */
    private function join(string $address, int $itemid): \stdClass {
        global $DB;
        consent::optin_from_message($address, api::invite(self::PLUGIN, $itemid), 'join');
        return $DB->get_record(consent::CONTACTS, ['phone' => $address]);
    }

    /**
     * Whether a notice would reach an item, by which channels.
     */
    public function test_item_reaches(): void {
        global $DB;
        $this->assertSame([], api::item_reaches(self::PLUGIN, 'notification', 1));
        $contact = $this->join('34600000001', 1);
        $this->assertSame(['whatsapp'], api::item_reaches(self::PLUGIN, 'notification', 1));
        $this->assertSame([], api::item_reaches(self::PLUGIN, 'notification', 2));
        $this->assertSame([], api::item_reaches(self::PLUGIN, 'nothing', 1));

        // Outside the 24 hours, only with its template approved; a marketing one, not to whoever wants none.
        $DB->set_field(consent::CONTACTS, 'lastinbound', time() - 3 * DAYSECS, ['id' => $contact->id]);
        $this->assertSame([], api::item_reaches(self::PLUGIN, 'notification', 1));
        $template = (int)$DB->insert_record(templates::TABLE, (object)['component' => self::PLUGIN, 'notice' => 'notification',
            'lang' => 'en', 'name' => 'mrd_test', 'metaid' => '900', 'status' => 'approved', 'category' => 'marketing',
            'body' => notices::text(notices::get(self::PLUGIN, 'notification'), 'template', 'en'), 'timesubmitted' => time(),
            'timemodified' => time()]);
        $this->assertSame(['whatsapp'], api::item_reaches(self::PLUGIN, 'notification', 1));
        consent::set_optout($contact, queue::MARKETING, true, 'meta');
        $this->assertSame([], api::item_reaches(self::PLUGIN, 'notification', 1));
        $DB->set_field(templates::TABLE, 'category', 'utility', ['id' => $template]);
        $this->assertSame(['whatsapp'], api::item_reaches(self::PLUGIN, 'notification', 1));

        // A no to the plugin, the notice off, or the channel with a problem.
        consent::set_optout($contact, self::PLUGIN, true, 'test');
        $this->assertSame([], api::item_reaches(self::PLUGIN, 'notification', 1));
        consent::set_optout($contact, self::PLUGIN, false, 'test');
        notices::set(self::PLUGIN . '/notification', 'whatsapp', false);
        $this->assertSame([], api::item_reaches(self::PLUGIN, 'notification', 1));
        $this->join('tg:555', 1);
        $this->assertSame(['telegram'], api::item_reaches(self::PLUGIN, 'notification', 1));
    }

    /**
     * How long the values of a notice may be.
     */
    public function test_value_limit(): void {
        global $DB;
        $this->assertSame([200, 700, 800, 0], array_map(function ($notice) {
            return api::value_limit(self::PLUGIN, $notice);
        }, ['act_invitation', 'answer_waiting', 'too_long', 'nothing']));
        $this->join('34600000001', 1);
        api::notify(self::PLUGIN, 'answer_waiting', ['Centro', str_repeat('a', 900)], null, 1);
        $params = json_decode($DB->get_field(queue::TABLE, 'params', ['notice' => 'answer_waiting']), true);
        $this->assertSame([6, 700], array_map('strlen', $params));
    }

    /**
     * The plugin hears what became of each notice: skipped, expired, cancelled, or failed after it went.
     */
    public function test_heard(): void {
        global $DB;
        $contact = $this->join('34600000001', 1);
        $now = time();
        $notify = function (int $validfor = DAYSECS) {
            api::notify(self::PLUGIN, 'act_invitation', ['Jornada', 'jueves'], null, 1, null, false, $validfor);
        };
        $notify();
        $this->assertSame(1, api::notices_cancel(self::PLUGIN, 1, ['act_invitation']));
        $this->assertSame(0, api::notices_cancel(self::PLUGIN, 1));
        $notify(10);
        queue::process(null, $now + 60);
        $notify();
        consent::set_optout($contact, self::PLUGIN, true, 'test');
        queue::process(null, $now + 60);
        consent::set_optout($contact, self::PLUGIN, false, 'test');
        $notify();
        api::unlink(self::PLUGIN, 1);
        $this->assertSame([
            ['act_invitation', 1, 'whatsapp', 'cancelled', 'cancelled'],
            ['act_invitation', 1, 'whatsapp', 'expired', 'expired'],
            ['act_invitation', 1, 'whatsapp', 'skipped', 'notactive'],
            ['act_invitation', 1, 'whatsapp', 'cancelled', 'cancelled'],
        ], listening_plugin::$heard);
        $this->assertFalse($DB->record_exists(queue::TABLE, ['status' => 'queued']));

        // Meta says later that one that went did not arrive: once.
        listening_plugin::forget();
        $DB->insert_record(queue::TABLE, (object)['contactid' => $contact->id, 'component' => self::PLUGIN, 'itemid' => 1,
            'area' => '', 'notice' => 'act_invitation', 'params' => '[]', 'token' => 'abcdefghij', 'urgent' => 0,
            'status' => 'sent', 'wamid' => 'wamid.S', 'timequeued' => $now, 'timeexpires' => $now + 5]);
        queue::status('wamid.S', 'failed', 'Undeliverable', 131026);
        queue::status('wamid.S', 'failed', 'Undeliverable', 131026);
        $this->assertSame([['act_invitation', 1, 'whatsapp', 'failed', 'Undeliverable']], listening_plugin::$heard);
    }

    /**
     * What waits in the queue goes with the number that leaves the item, or to the record that stays.
     */
    public function test_queue_of_an_item(): void {
        global $DB;
        $this->join('34600000001', 1);
        $this->join('34600000002', 1);
        api::notify(self::PLUGIN, 'act_invitation', ['Jornada', 'jueves'], null, 1);
        $this->assertSame(2, $DB->count_records(queue::TABLE, ['status' => 'queued', 'itemid' => 1]));
        api::unlink_number(self::PLUGIN, 1, '+34600000001');
        $this->assertSame(1, $DB->count_records(queue::TABLE, ['status' => 'queued', 'itemid' => 1]));
        api::relink(self::PLUGIN, 1, 2);
        $this->assertSame(1, $DB->count_records(queue::TABLE, ['status' => 'queued', 'itemid' => 2]));
    }

    /**
     * A send that waits for its approval: the plugin takes it back, or says of each item, when it goes, whether it still
     * does and with which values.
     */
    public function test_bulk(): void {
        global $DB;
        foreach ([1, 2, 3] as $itemid) {
            $this->join('3460000000' . $itemid, $itemid);
        }
        $DB->set_field(consent::CONTACTS, 'lastinbound', time() - 3 * DAYSECS);
        set_config('bulkthreshold', '0.01', self::PLUGIN);
        $asker = $this->getDataGenerator()->create_user();
        $boss = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniamarandu:bulkapprove', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $boss->id, \context_system::instance());
        $items = array_map(function ($itemid) {
            return ['itemid' => $itemid, 'params' => ['Jornada', 'jueves'], 'url' => null, 'lang' => ''];
        }, [1, 2, 3, 4]);
        $ask = function () use ($items, $asker) {
            $sink = $this->redirectMessages();
            $bulk = api::bulk_request(self::PLUGIN, 'act_invitation', $items, (int)$asker->id, 'Jornada');
            $sink->close();
            return $bulk;
        };
        $bulk = $ask();
        $this->assertSame('pending', $bulk['status']);
        $this->assertFalse(api::bulk_withdraw('local_other', $bulk['id'], 'x'));
        $this->assertTrue(api::bulk_withdraw(self::PLUGIN, $bulk['id'], 'The act was called off'));
        $this->assertFalse(api::bulk_withdraw(self::PLUGIN, $bulk['id']));
        $state = api::bulk_state($bulk['id']);
        $this->assertSame(['cancelled', []], [$state['status'], $state['released']]);
        $this->assertStringContainsString('The act was called off', $state['said']);
        $sink = $this->redirectMessages();
        $this->assertFalse(bulk::decide($bulk['id'], true, (int)$boss->id));

        $bulk = $ask();
        listening_plugin::$item = function (int $itemid) {
            return $itemid === 1 ? false : ($itemid === 2 ? ['params' => ['Jornada', 'viernes']] : true);
        };
        $this->assertTrue(bulk::decide($bulk['id'], true, (int)$boss->id));
        $sink->close();
        $this->assertSame([2, 3], api::bulk_state($bulk['id'])['released']);
        $params = array_map(function ($row) {
            return json_decode($row->params, true)[1];
        }, array_values($DB->get_records(queue::TABLE, ['notice' => 'act_invitation'], 'itemid ASC')));
        $this->assertSame(['viernes', 'jueves'], $params);
    }
}
