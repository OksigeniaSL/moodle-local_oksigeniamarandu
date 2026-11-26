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
 * Tests of writing first to numbers that did not join, with the permission of their plugin: the send that carries them,
 * their «no», what they write, the people a day of the line and its quality. Numbers are made up; Meta is never called.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\implied
 * @covers     \local_oksigeniamarandu\local\bulk
 */
#[\PHPUnit\Framework\Attributes\CoversClass(implied::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(bulk::class)]
final class implied_test extends \advanced_testcase {
    /** The plugin of the tests. */
    private const C = 'local_oksigeniamarandu';

    /** The notice that invites. */
    private const N = 'notification';

    /** Its permission. */
    private const WHY = 'example:invite';

    /**
     * A site with WhatsApp, no quiet hours, and an invitation with its template approved as marketing.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        $CFG->lang = 'en';
        set_config('phonenumberid', '1110000000', self::C);
        set_config('accesstoken', \core\encryption::encrypt('fake'), self::C);
        set_config('wabaid', '2220000000', self::C);
        set_config('displayphone', '+34 600 000 000', self::C);
        set_config('quietfrom', '', self::C);
        set_config('quietto', '', self::C);
        set_config('quietdays', '', self::C);
        // The general notice stands for an invitation: its texts exist, and its template is approved as marketing.
        foreach (['whatsapp', 'telegram'] as $channel) {
            notices::set(self::C . '/' . self::N, $channel, true);
        }
        $notice = notices::get(self::C, self::N);
        $DB->insert_record(templates::TABLE, (object)['component' => self::C, 'notice' => self::N, 'lang' => 'en',
            'name' => 'mrd_notification', 'metaid' => '901', 'status' => 'approved', 'category' => 'marketing',
            'body' => notices::text($notice, 'template', 'en'), 'timesubmitted' => time(), 'timemodified' => time()]);
    }

    /**
     * A client that keeps what it would send and says how the line is.
     *
     * @return cloud_api
     */
    private function api(): cloud_api {
        return new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was sent. */
            public $sent = [];

            /** @var array What Meta says of the line. */
            public $line = ['quality_rating' => 'GREEN', 'whatsapp_business_manager_messaging_limit' => 'TIER_2K'];

            /**
             * Keeps a template message.
             *
             * @param string $to Number.
             * @param string $template Name.
             * @param string $language Language.
             * @param array $components Values.
             * @return string
             */
            public function send_template(string $to, string $template, string $language, array $components = []): string {
                $this->sent[] = $to;
                return 'wamid.I' . count($this->sent);
            }

            /**
             * Keeps a text message.
             *
             * @param string $to Number.
             * @param string $text Text.
             * @return string
             */
            public function send_text(string $to, string $text): string {
                $this->sent[] = $to;
                return 'wamid.I' . count($this->sent);
            }

            /**
             * How the line is.
             *
             * @return array
             */
            public function get_line(): array {
                return $this->line;
            }
        };
    }

    /**
     * An item of a send, with its number when it has one.
     *
     * @param int $itemid The item.
     * @param string $phone Its number, or empty.
     * @return array
     */
    private function item(int $itemid, string $phone = ''): array {
        return ['itemid' => $itemid, 'params' => ['Jornada', 'jueves'], 'url' => null, 'lang' => '']
            + ($phone === '' ? [] : ['phone' => $phone, 'implied' => self::WHY]);
    }

    /**
     * A message of a number to the webhook.
     *
     * @param string $from The number.
     * @param string $text What it writes.
     * @return array
     */
    private function payload(string $from, string $text): array {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '2220000000', 'changes' => [[
            'field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '1110000000'],
                'messages' => [['id' => 'wamid.IN' . random_string(4), 'from' => $from, 'type' => 'text',
                    'timestamp' => (string)time(), 'text' => ['body' => $text]]]]]]]]];
    }

    /**
     * The quote counts each new number once and leaves out those that said no or are not numbers; the send joins the
     * new number as implied, without any welcome, and the queue writes to it; each item says what happened.
     */
    public function test_send_with_numbers(): void {
        global $DB;
        consent::optin_from_message('34600000001', api::invite(self::C, 1), 'join');
        consent::optout('34600000003', 'keyword', 'test');
        $declined = consent::get_or_create('34600000005');
        $DB->insert_record(consent::OPTOUTS, (object)['contactid' => $declined->id, 'scope' => self::C, 'source' => 'asked',
            'timecreated' => time()]);
        $DB->set_field(consent::CONTACTS, 'lastinbound', time() - 3 * DAYSECS, []);
        $this->assertTrue(api::bulk_phones());

        $phones = [2 => '+34600000002', 3 => '+34600000003', 4 => '600 abc', 5 => '+34600000005', 6 => '+34600000002'];
        $quote = api::bulk_quote(self::C, self::N, [1, 2, 3, 4, 5, 6], '', $phones);
        $this->assertSame([2, 0, 3], [$quote['whatsapp'], $quote['telegram'], $quote['skipped']]);
        $this->assertSame(0.12, $quote['cost']);

        $items = [$this->item(1)];
        foreach ($phones as $itemid => $phone) {
            $items[] = $this->item($itemid, $phone);
        }
        $asker = $this->getDataGenerator()->create_user();
        $send = api::bulk_request(self::C, self::N, $items, (int)$asker->id, 'Jornada');
        $this->assertSame('queued', $send['status']);

        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000002']);
        $this->assertSame(['active', self::WHY, self::WHY], [$contact->status, $contact->implied, $contact->consentsource]);
        $this->assertSame('implied', $DB->get_field(consent::LOG, 'action', ['contactid' => $contact->id]));
        $this->assertSame(2, $DB->count_records(consent::LINKS, ['contactid' => $contact->id, 'component' => self::C]));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000003']));
        $this->assertSame(self::WHY, api::item_channels(self::C, 2)['whatsapp']['implied']);
        $this->assertSame('', api::item_channels(self::C, 1)['whatsapp']['implied']);
        // Nothing of the welcome of who joins by themselves.
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks('\\local_oksigeniamarandu\\task\\send_reply'));

        $api = $this->api();
        queue::process($api);
        $this->assertSame(['34600000001', '34600000002'], array_values(array_unique($api->sent)));

        $said = [];
        foreach (api::bulk_items($send['id']) as $one) {
            $said[$one['itemid']] = [$one['status'], $one['why'], $one['implied']];
        }
        $this->assertSame(['sent', '', false], $said[1]);
        $this->assertSame(['sent', '', true], $said[2]);
        $this->assertSame(
            [['skipped', 'optout', false], ['skipped', 'invalid', false], ['skipped', 'declined', false]],
            [$said[3], $said[4], $said[5]]
        );

        // What WhatsApp says afterwards: read by one; the other number has no WhatsApp.
        $wamids = $DB->get_records_menu(queue::TABLE, ['status' => 'sent'], 'id ASC', 'itemid, wamid');
        queue::status($wamids[2], 'read');
        queue::status($wamids[1], 'failed', 'Message undeliverable', 131026);
        $said = [];
        foreach (api::bulk_items($send['id']) as $one) {
            $said[$one['itemid']] = $one;
        }
        $this->assertSame('read', $said[2]['status']);
        $this->assertGreaterThan(0, $said[2]['time']);
        $this->assertSame(['nowhatsapp', '131026', 'whatsapp'], [$said[1]['status'], $said[1]['why'], $said[1]['channel']]);
    }

    /**
     * A «no» to an invitation is «we cannot come»: its plugin hears it with the send and the item, thanks, and the
     * number keeps getting messages. Only the first thing after the send counts, from whoever joined or not; a «no» to
     * something else is a message; only the words of leaving stop the messages.
     */
    public function test_no_is_an_answer(): void {
        global $DB;
        require_once(__DIR__ . '/../fixtures/listening_plugin.php');
        require_once(__DIR__ . '/../fixtures/listening_plugin_lib.php');
        listening_plugin::forget();
        consent::optin_from_message('34600000001', api::invite(self::C, 1), 'join');
        $DB->set_field(consent::CONTACTS, 'lastinbound', time() - 3 * DAYSECS, []);
        $asker = $this->getDataGenerator()->create_user();
        $items = [$this->item(1), $this->item(2, '+34600000002')];
        $send = api::bulk_request(self::C, self::N, $items, (int)$asker->id, 'Charla');
        queue::process($this->api());

        inbound::handle($this->payload('34600000002', 'No'));
        inbound::handle($this->payload('34600000001', 'We cannot come!'));
        $this->assertSame([[$send['id'], 2, 'no'], [$send['id'], 1, 'no']], listening_plugin::$answers);
        $this->assertFalse($DB->record_exists(consent::OPTOUTS, []));
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000002']);
        $this->assertSame('active', $contact->status);
        $this->assertEmpty($contact->implied);
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks('\\local_oksigeniamarandu\\task\\send_reply'));

        // A second «no» is a message like any other.
        inbound::handle($this->payload('34600000002', 'no'));
        $this->assertCount(2, listening_plugin::$answers);

        // A «no» after a notice that was not a send to many, neither.
        $this->assertSame('', implied::join('+34600000007', self::C, '', 7, self::WHY));
        api::notify(self::C, self::N, ['Centro', 'Aviso'], null, 7);
        queue::process($this->api());
        inbound::handle($this->payload('34600000007', 'No'));
        $this->assertCount(2, listening_plugin::$answers);

        // BAJA does stop them.
        inbound::handle($this->payload('34600000002', 'BAJA'));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000002']));
        listening_plugin::forget();
    }

    /**
     * A sample of one item goes at once, also in the quiet hours; the rest waits for them; a sample is one item.
     */
    public function test_sample(): void {
        global $DB;
        require_once(__DIR__ . '/../fixtures/listening_plugin.php');
        require_once(__DIR__ . '/../fixtures/listening_plugin_lib.php');
        listening_plugin::forget();
        set_config('quietdays', '0,1,2,3,4,5,6', self::C);
        $asker = $this->getDataGenerator()->create_user();
        // A send that goes at once does not ask its plugin whether each item still goes: the plugin gave them just now and
        // may not have kept the send yet, as a plugin that looks for it by its id would.
        listening_plugin::$item = function () {
            return false;
        };
        $sample = api::bulk_request(self::C, self::N, [$this->item(2, '+34600000002')], (int)$asker->id, 'Muestra', '', true);
        $rest = api::bulk_request(self::C, self::N, [$this->item(3, '+34600000003')], (int)$asker->id, 'Charla');
        $api = $this->api();
        queue::process($api);
        $this->assertSame(['34600000002'], $api->sent);
        $this->assertSame('sent', api::bulk_items($sample['id'])[0]['status']);
        $waits = api::bulk_items($rest['id'])[0];
        $this->assertSame(['waiting', 'quiet'], [$waits['status'], $waits['why']]);

        // One that waited for its approval asks: an item its plugin drops is not written to, and its number is not joined.
        set_config('bulkthreshold', '0.01', self::C);
        $boss = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniamarandu:bulkapprove', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $boss->id, \context_system::instance());
        $sink = $this->redirectMessages();
        $later = api::bulk_request(self::C, self::N, [$this->item(4, '+34600000004')], (int)$asker->id, 'Charla 2');
        $this->assertSame('pending', $later['status']);
        $this->assertTrue(bulk::decide($later['id'], true, (int)$boss->id));
        $sink->close();
        $said = api::bulk_items($later['id'])[0];
        $this->assertSame(['skipped', 'plugin'], [$said['status'], $said['why']]);
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => '34600000004', 'status' => 'active']));
        listening_plugin::forget();

        $this->expectException(\coding_exception::class);
        api::bulk_request(self::C, self::N, [$this->item(4), $this->item(5)], (int)$asker->id, 'Muestra', '', true);
    }

    /**
     * The line writes first to as many people a day as Meta lets it, with room for the team; the rest waits and is not
     * lost. While its quality is not green, what goes to whoever did not join waits too, and whoever approves hears it
     * once.
     */
    public function test_line(): void {
        global $DB;
        $api = $this->api();
        $api->line = ['quality_rating' => 'GREEN', 'whatsapp_business_manager_messaging_limit' => 'TIER_250'];
        $this->assertSame(250, implied::refresh($api)['limit']);
        // Room for one a day: 2 × 0.8.
        set_config('linelimit', 2, self::C);
        $items = [$this->item(2, '+34600000002'), $this->item(3, '+34600000003')];
        $asker = $this->getDataGenerator()->create_user();
        $send = api::bulk_request(self::C, self::N, $items, (int)$asker->id, 'Jornada');
        queue::process($api);
        $this->assertCount(1, $api->sent);
        $said = array_column(api::bulk_items($send['id']), null, 'itemid');
        $this->assertSame(['sent', 'waiting', 'limit'], [$said[2]['status'], $said[3]['status'], $said[3]['why']]);
        $waiting = $DB->get_record(queue::TABLE, ['itemid' => 3]);
        $this->assertGreaterThanOrEqual(time() + 6 * DAYSECS, (int)$waiting->timeexpires);

        // The quality goes down: whoever approves hears it once, and the implied wait; who joined by themselves does not.
        set_config('linelimit', 0, self::C);
        $boss = $this->getDataGenerator()->create_user();
        spend::set_people([$boss->id]);
        $sink = $this->redirectMessages();
        $api->line = ['quality_rating' => 'YELLOW', 'whatsapp_business_manager_messaging_limit' => 'TIER_2K'];
        $this->assertSame(['YELLOW', 2000], array_values(array_slice(implied::refresh($api), 0, 2)));
        implied::refresh($api);
        $this->assertSame(1, $sink->count());
        $sink->close();
        consent::optin_from_message('34600000004', api::invite(self::C, 4), 'join');
        $DB->set_field(queue::TABLE, 'timeretry', null, ['itemid' => 3]);
        $other = api::bulk_request(self::C, self::N, [$this->item(4)], (int)$asker->id, 'Jornada 2');
        queue::process($api);
        $this->assertSame(['34600000002', '34600000004'], $api->sent);
        $said = array_column(api::bulk_items($send['id']), null, 'itemid');
        $this->assertSame(['waiting', 'quality'], [$said[3]['status'], $said[3]['why']]);
        $this->assertSame('sent', api::bulk_items($other['id'])[0]['status']);
    }
}
