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
 * Asking a number to join with one touch.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\joinask
 */
final class joinask_test extends \advanced_testcase {
    /** A number. */
    private const PHONE = '+34 600 111 222';

    /** The same, as it is kept. */
    private const NUMBER = '34600111222';

    /**
     * A site with WhatsApp, the template approved and the question switched on.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        $CFG->lang = 'en';
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('accesstoken', \core\encryption::encrypt('fake'), 'local_oksigeniamarandu');
        set_config('wabaid', '2220000000', 'local_oksigeniamarandu');
        set_config('displayphone', '+34 600 000 000', 'local_oksigeniamarandu');
        set_config('cardorgof', 'del Centro Ejemplo', 'local_oksigeniamarandu');
        set_config('enablednotices', 'local_oksigeniamarandu/join', 'local_oksigeniamarandu');
        $notice = notices::get('local_oksigeniamarandu', 'join');
        $DB->insert_record(templates::TABLE, (object)['component' => 'local_oksigeniamarandu', 'notice' => 'join', 'lang' => 'en',
            'name' => 'mrd_local_oksigeniamarandu_join', 'metaid' => '900', 'status' => 'approved', 'category' => 'utility',
            'body' => notices::text($notice, 'template', 'en'), 'timesubmitted' => time(), 'timemodified' => time()]);
    }

    /**
     * A client that keeps what it would send, or fails.
     *
     * @param bool $fails Whether Meta says no.
     * @return cloud_api
     */
    private function api(bool $fails = false): cloud_api {
        $api = new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was sent. */
            public $sent = [];

            /** @var bool Whether it fails. */
            public $fails = false;

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
                if ($this->fails) {
                    throw new api_exception('error:templatenotfound', null, 131026);
                }
                $this->sent[] = [$to, $template, $language, $components];
                return 'wamid.J' . count($this->sent);
            }
        };
        $api->fails = $fails;
        return $api;
    }

    /**
     * Asks, with a client.
     *
     * @param cloud_api $api The client.
     * @param array $more What changes: item, phone, now.
     * @return string
     */
    private function ask(cloud_api $api, array $more = []): string {
        return joinask::ask(
            'local_example',
            $more['item'] ?? 7,
            $more['phone'] ?? self::PHONE,
            1795000000,
            'example:family',
            '',
            $api,
            $more['now'] ?? time()
        );
    }

    /**
     * A touch of a button, as Meta sends it.
     *
     * @param int $id The question.
     * @param string $answer y or n.
     * @param string $from Who touches.
     * @param int $after Seconds from now.
     * @return array The message.
     */
    private function touch(int $id, string $answer, string $from = self::NUMBER, int $after = 0): array {
        return ['id' => 'wamid.IN', 'from' => $from, 'type' => 'button', 'timestamp' => (string)(time() + $after),
            'button' => ['payload' => "mrdj:{$id}:{$answer}", 'text' => $answer === 'y' ? 'Sí' : 'No']];
    }

    /**
     * Asked once: it goes to that number with the name of the centre and two buttons; asked again it waits.
     */
    public function test_ask(): void {
        global $DB;
        $api = $this->api();
        $this->assertSame('asked', $this->ask($api));
        [$to, $name, $lang, $components] = $api->sent[0];
        $this->assertSame([self::NUMBER, 'mrd_local_oksigeniamarandu_join', 'en'], [$to, $name, $lang]);
        $this->assertSame('del Centro Ejemplo', $components[0]['parameters'][0]['text']);
        $row = $DB->get_record(joinask::TABLE, []);
        $this->assertSame(['mrdj:' . $row->id . ':y', 'mrdj:' . $row->id . ':n'], [$components[1]['parameters'][0]['payload'],
            $components[2]['parameters'][0]['payload']]);
        $this->assertSame(['quick_reply', 'quick_reply'], [$components[1]['sub_type'], $components[2]['sub_type']]);
        $this->assertSame(['asked', 'wamid.J1', 'example:family', '1795000000'], [$row->status, $row->wamid,
            $row->consentsource, (string)$row->consentat]);
        // Nobody joined yet: the number has no contact and no notice would reach it.
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => self::NUMBER]));
        $this->assertSame('waiting', $this->ask($api));
        $this->assertSame('waiting', $this->ask($api, ['item' => 8]));
        $this->assertCount(1, $api->sent);
        $state = api::item_channels('local_example', 7)['whatsapp'];
        $this->assertGreaterThan(0, $state['asked']);
        $this->assertSame([0, false], [$state['declined'], $state['joined']]);

        // Another plugin may ask the same number, and an odd number is said so.
        $this->assertSame('invalid', $this->ask($api, ['phone' => '600111222']));
        $this->assertSame('invalid', $this->ask($api, ['phone' => '']));
    }

    /**
     * Yes: the number joins for the item, with the proof of the permission, its welcome and a 24-hour window; nothing
     * older changes it, and it is not asked again.
     */
    public function test_yes(): void {
        global $DB;
        $api = $this->api();
        $this->ask($api);
        $id = (int)$DB->get_field(joinask::TABLE, 'id', []);
        $this->assertTrue(joinask::from_whatsapp($this->touch($id, 'y'), self::NUMBER, 'Laura'));
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => self::NUMBER]);
        $this->assertSame(['active', 'Laura'], [$contact->status, $contact->name]);
        $this->assertSame('yes', $DB->get_field(joinask::TABLE, 'status', ['id' => $id]));
        $link = $DB->get_record(consent::LINKS, ['component' => 'local_example', 'itemid' => 7]);
        $this->assertSame((int)$contact->id, (int)$link->contactid);
        $actions = $DB->get_fieldset_select(consent::LOG, 'action', 'contactid = ?', [$contact->id]);
        sort($actions);
        $this->assertSame(['optin', 'permission'], $actions);
        $log = $DB->get_record(consent::LOG, ['contactid' => $contact->id, 'action' => 'permission']);
        $this->assertSame(['example:family', '1795000000', 'wamid.IN'], [$log->source, (string)$log->timecreated, $log->detail]);
        $this->assertSame('joined', $this->ask($api));
        $this->assertTrue(joinask::from_whatsapp($this->touch($id, 'n', self::NUMBER, -60), self::NUMBER, null));
        $this->assertSame('active', $DB->get_field(consent::CONTACTS, 'status', ['phone' => self::NUMBER]));
        $this->assertTrue(api::item_channels('local_example', 7)['whatsapp']['joined']);
        $tasks = \core\task\manager::get_adhoc_tasks('\\local_oksigeniamarandu\\task\\send_reply');
        $this->assertCount(1, $tasks);
    }

    /**
     * No: nothing of that plugin goes to the number, its plugin is told in a trail, and it is not asked again; other
     * plugins are not affected.
     */
    public function test_no(): void {
        global $DB;
        $api = $this->api();
        $this->ask($api);
        $id = (int)$DB->get_field(joinask::TABLE, 'id', []);
        $this->assertTrue(joinask::from_whatsapp($this->touch($id, 'n'), self::NUMBER, null));
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => self::NUMBER]);
        $this->assertSame('pending', $contact->status);
        $this->assertTrue(consent::out_of($contact, 'local_example'));
        $this->assertFalse(consent::out_of($contact, 'local_other'));
        $this->assertFalse($DB->record_exists(consent::LINKS, ['contactid' => $contact->id]));
        $this->assertSame('declined', $this->ask($api));
        $this->assertGreaterThan(0, api::item_channels('local_example', 7)['whatsapp']['declined']);
        $this->assertSame('asked', joinask::ask('local_other', 3, self::PHONE, 1795000000, 'other', '', $api));
    }

    /**
     * Someone else cannot answer for the number, and a payload that is not a question is nothing of this.
     */
    public function test_whose(): void {
        global $DB;
        $api = $this->api();
        $this->ask($api);
        $id = (int)$DB->get_field(joinask::TABLE, 'id', []);
        $this->assertTrue(joinask::from_whatsapp($this->touch($id, 'y', '34699999999'), '34699999999', null));
        $this->assertSame('asked', $DB->get_field(joinask::TABLE, 'status', ['id' => $id]));
        $this->assertFalse(joinask::from_whatsapp(['type' => 'text', 'text' => ['body' => 'mrdj:1:y']], self::NUMBER, null));
    }

    /**
     * What does not go: no template, not switched on, refused by Meta, the day full or a number that said no to everything.
     */
    public function test_not_now(): void {
        global $DB;
        $bad = $this->api(true);
        $this->assertSame('notready', $this->ask($bad));
        $this->assertDebuggingCalled();
        $this->assertSame('failed', $DB->get_field(joinask::TABLE, 'status', []));
        // A failure is asked again; the day full is not.
        $api = $this->api();
        $this->assertSame('asked', $this->ask($api));
        set_config('joinaskday', 1, 'local_oksigeniamarandu');
        $this->assertSame('limit', $this->ask($api, ['phone' => '+34600222333']));
        set_config('joinaskday', '', 'local_oksigeniamarandu');
        set_config('enablednotices', '', 'local_oksigeniamarandu');
        $this->assertSame('notready', $this->ask($api, ['phone' => '+34600222333']));
        set_config('enablednotices', 'local_oksigeniamarandu/join', 'local_oksigeniamarandu');
        $DB->set_field(templates::TABLE, 'status', 'pending', []);
        $this->assertSame('notready', $this->ask($api, ['phone' => '+34600222333']));
        $DB->set_field(templates::TABLE, 'status', 'approved', []);

        // Not delivered: it may be asked again.
        joinask::status('wamid.J1', 'failed', 'Undeliverable');
        $row = $DB->get_records(joinask::TABLE, ['status' => 'failed'], 'id DESC');
        $this->assertSame('Undeliverable', reset($row)->error);
        $this->assertSame('asked', $this->ask($api));
        joinask::status('wamid.J2', 'delivered', '');
        $this->assertSame('asked', $DB->get_field(joinask::TABLE, 'status', ['wamid' => 'wamid.J2']));

        $contact = consent::get_or_create('34600444555');
        $DB->set_field(consent::CONTACTS, 'status', 'optedout', ['id' => $contact->id]);
        $this->assertSame('declined', $this->ask($api, ['phone' => '+34600444555']));
    }

    /**
     * The replies that wait to go, in their order.
     *
     * @return string[]
     */
    private function replies(): array {
        $tasks = \core\task\manager::get_adhoc_tasks('\\local_oksigeniamarandu\\task\\send_reply');
        ksort($tasks);
        return array_values(array_map(function ($task) {
            $data = $task->get_custom_data();
            return $data->reply ?? (isset($data->back) ? 'back' : 'text');
        }, $tasks));
    }

    /**
     * The number that touches is proved: it joins even if somebody else claimed the code of the item in the meantime, and
     * two numbers of one item join both.
     */
    public function test_code_taken(): void {
        global $DB;
        $api = $this->api();
        $code = api::invite('local_example', 7);
        $this->ask($api);
        $this->ask($api, ['phone' => '+34600222333']);
        [$first, $second] = array_values($DB->get_records(joinask::TABLE, null, 'id ASC'));
        $this->assertSame('optin', consent::optin_from_message('tg:555', $code, 'tg.1', 'Leo'));
        joinask::from_whatsapp($this->touch((int)$first->id, 'y'), self::NUMBER, 'Laura');
        joinask::from_whatsapp($this->touch((int)$second->id, 'y', '34600222333'), '34600222333', 'Pablo');
        $this->assertSame(3, $DB->count_records_select(
            consent::LINKS,
            "component = 'local_example' AND itemid = 7 AND contactid IS NOT NULL"
        ));
        $this->assertSame(['optin', 'optin'], $this->replies());
        $numbers = api::item_channels('local_example', 7)['whatsapp']['numbers'];
        sort($numbers);
        $this->assertSame(['+34600111222', '+34600222333'], $numbers);
    }

    /**
     * The last touch counts: whoever touched the wrong button touches the other one, and a webhook that comes again does not.
     */
    public function test_change_of_mind(): void {
        global $DB;
        $api = $this->api();
        $this->ask($api);
        $id = (int)$DB->get_field(joinask::TABLE, 'id', []);
        $no = $this->touch($id, 'n');
        joinask::from_whatsapp($no, self::NUMBER, null);
        joinask::from_whatsapp($this->touch($id, 'y', self::NUMBER, 5), self::NUMBER, null);
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => self::NUMBER]);
        $this->assertSame('active', $contact->status);
        $this->assertFalse(consent::out_of($contact, 'local_example'));
        $this->assertTrue(api::item_channels('local_example', 7)['whatsapp']['joined']);
        joinask::from_whatsapp($no, self::NUMBER, null);
        $this->assertFalse(consent::out_of($contact, 'local_example'));
        joinask::from_whatsapp($this->touch($id, 'n', self::NUMBER, 9), self::NUMBER, null);
        $this->assertTrue(consent::out_of($contact, 'local_example'));
        $this->assertSame('no', $DB->get_field(joinask::TABLE, 'status', ['id' => $id]));
        $this->assertSame(['joinno', 'optin', 'joinno'], $this->replies());
    }

    /**
     * Asked again: after a no, only with a permission given later; after no answer, a week later; and three times at most.
     */
    public function test_again(): void {
        global $DB;
        $api = $this->api();
        $now = time();
        $ask = function (int $when, int $at) use ($api) {
            return joinask::ask('local_example', 7, self::PHONE, $when, 'example:family', '', $api, $at);
        };
        $this->assertSame('asked', $ask($now - 5, $now));
        $this->assertSame('waiting', $ask($now + DAYSECS, $now + DAYSECS));
        $this->assertSame('waiting', $ask($now - 5, $now + 8 * DAYSECS));
        $this->assertSame('asked', $ask($now + 8 * DAYSECS, $now + 8 * DAYSECS));
        $id = (int)$DB->get_field_sql('SELECT MAX(id) FROM {' . joinask::TABLE . '}');
        joinask::from_whatsapp($this->touch($id, 'n'), self::NUMBER, null);
        $this->assertSame('declined', $ask($now - 5, $now + 9 * DAYSECS));
        $this->assertSame('asked', $ask($now + 9 * DAYSECS, $now + 9 * DAYSECS));
        $id = (int)$DB->get_field_sql('SELECT MAX(id) FROM {' . joinask::TABLE . '}');
        joinask::from_whatsapp($this->touch($id, 'n'), self::NUMBER, null);
        $this->assertSame('declined', $ask($now + 10 * DAYSECS, $now + 10 * DAYSECS));
        $this->assertCount(3, $api->sent);
    }

    /**
     * Two items with the same number: one question, and its answer is for both.
     */
    public function test_two_items(): void {
        global $DB;
        $api = $this->api();
        $this->ask($api);
        $this->assertSame('waiting', $this->ask($api, ['item' => 8]));
        $this->assertSame('waiting', $this->ask($api, ['item' => 8]));
        $this->assertSame(2, $DB->count_records(joinask::TABLE));
        $this->assertGreaterThan(0, api::item_channels('local_example', 8)['whatsapp']['asked']);
        $this->assertSame(1, joinask::sent_since(0));
        // Not delivered: neither waits any more, and the next question is for the two of them again.
        joinask::status('wamid.J1', 'failed', 'Undeliverable');
        $this->assertSame(2, $DB->count_records(joinask::TABLE, ['status' => 'failed']));
        $DB->delete_records(joinask::TABLE);
        $this->assertSame('asked', $this->ask($api));
        $this->assertSame('waiting', $this->ask($api, ['item' => 8]));
        $id = (int)$DB->get_field(joinask::TABLE, 'id', ['itemid' => 7]);
        joinask::from_whatsapp($this->touch($id, 'y'), self::NUMBER, null);
        $this->assertTrue(api::item_channels('local_example', 7)['whatsapp']['joined']);
        $this->assertTrue(api::item_channels('local_example', 8)['whatsapp']['joined']);
        $this->assertSame(['optin'], $this->replies());
        $this->assertFalse(consent::rejoined(self::NUMBER));
        joinask::from_whatsapp($this->touch($id, 'n', self::NUMBER, 5), self::NUMBER, null);
        $this->assertGreaterThan(0, api::item_channels('local_example', 8)['whatsapp']['declined']);
    }

    /**
     * An item that goes closes its question, two that were the same keep what joined, and an old number leaves.
     */
    public function test_over(): void {
        global $DB;
        $api = $this->api();
        $this->ask($api);
        $id = (int)$DB->get_field(joinask::TABLE, 'id', []);
        api::unlink('local_example', 7);
        $this->assertSame('over', $DB->get_field(joinask::TABLE, 'status', ['id' => $id]));
        joinask::from_whatsapp($this->touch($id, 'y'), self::NUMBER, null);
        joinask::from_whatsapp($this->touch($id, 'y', self::NUMBER, 5), self::NUMBER, null);
        $this->assertSame(['joinover', 'joinover'], $this->replies());
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => self::NUMBER]));

        // Merged before its answer: the touch joins the one that stays.
        $this->ask($api, ['phone' => '+34600222333', 'item' => 20]);
        $code = api::invite('local_example', 20);
        consent::optin_from_message('34600777888', api::invite('local_example', 20), 'wamid.X');
        $this->assertSame(1, api::relink('local_example', 20, 21));
        $id = (int)$DB->get_field(joinask::TABLE, 'id', ['phone' => '34600222333']);
        joinask::from_whatsapp($this->touch($id, 'y', '34600222333'), '34600222333', null);
        $numbers = api::item_channels('local_example', 21)['whatsapp']['numbers'];
        sort($numbers);
        $this->assertSame(['+34600222333', '+34600777888'], $numbers);
        $this->assertSame(0, api::relink('local_example', 20, 21));
        $this->assertSame(1, api::unlink_number('local_example', 21, '+34600777888'));
        $this->assertSame(['+34600222333'], api::item_channels('local_example', 21)['whatsapp']['numbers']);
        $this->assertSame(0, api::unlink_number('local_example', 21, '+34600777888'));
        $this->assertNotSame('', $code);
    }

    /**
     * «Sí» written instead of touched is the answer, unless something else was said since or a person of the team has the
     * conversation.
     */
    public function test_typed(): void {
        global $DB;
        $api = $this->api();
        $this->assertFalse(joinask::typed(self::NUMBER, 'yes', 'wamid.T', null));
        $this->ask($api);
        $this->assertFalse(joinask::typed(self::NUMBER, 'Yes, but when?', 'wamid.T', null));
        $conv = (int)$DB->insert_record('local_oksigeniamarandu_conv', (object)['cwid' => 5, 'phone' => self::NUMBER, 'name' => 'L',
            'kind' => '', 'preview' => '', 'previewkind' => '', 'cwupdated' => '1', 'status' => 'open', 'lastincoming' => 1]);
        $this->assertFalse(joinask::typed(self::NUMBER, 'yes', 'wamid.T', null));
        $DB->update_record('local_oksigeniamarandu_conv', (object)['id' => $conv, 'status' => 'pending',
            'lastincoming' => time() + 5]);
        $this->assertFalse(joinask::typed(self::NUMBER, 'yes', 'wamid.T', null));
        $DB->update_record('local_oksigeniamarandu_conv', (object)['id' => $conv, 'status' => 'resolved', 'lastincoming' => 1]);
        $this->assertTrue(joinask::typed(self::NUMBER, ' Yes! ', 'wamid.T', 'Laura'));
        $this->assertTrue(api::item_channels('local_example', 7)['whatsapp']['joined']);
        $this->assertFalse(joinask::typed(self::NUMBER, 'yes', 'wamid.T', null));
        $this->ask($api, ['phone' => '+34600222333']);
        $this->assertTrue(joinask::typed('34600222333', 'No.', 'wamid.T', null));
        $this->assertSame(['optin', 'joinno'], $this->replies());
    }

    /**
     * Whoever said no and writes the opt-in word, as the answer tells them, joins for what they were asked about.
     */
    public function test_word(): void {
        global $DB;
        $api = $this->api();
        $this->ask($api);
        $id = (int)$DB->get_field(joinask::TABLE, 'id', []);
        joinask::from_whatsapp($this->touch($id, 'n'), self::NUMBER, null);
        $this->assertSame('optin', consent::optin_from_message(self::NUMBER, null, 'wamid.W', null));
        $info = api::item_channels('local_example', 7)['whatsapp'];
        $this->assertTrue($info['joined']);
        $this->assertSame(0, $info['declined']);
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => self::NUMBER]);
        $this->assertFalse(consent::out_of($contact, 'local_example'));
        $this->assertSame(['permission', 'optin', 'optout'], array_values(array_unique($DB->get_fieldset_sql(
            'SELECT action FROM {' . consent::LOG . '} WHERE contactid = ? ORDER BY id DESC',
            [$contact->id]
        ))));
    }

    /**
     * What the shared inbox and the card know of the question: whoever answers it with words goes to the plugin that asked.
     */
    public function test_known(): void {
        global $DB;
        $api = $this->api();
        $this->assertArrayNotHasKey('lastnotice', inbox::context(self::PHONE));
        $this->ask($api);
        $known = inbox::context(self::PHONE);
        $this->assertSame(['none', 'local_example', 'join'], [$known['status'], $known['lastnotice']['component'],
            $known['lastnotice']['notice']]);
        $this->assertArrayNotHasKey('lastnotice', inbox::context(self::PHONE, '', 1, time() + 3 * DAYSECS));
        $this->assertSame([['asked', 'asked', 'local_example']], array_map(function ($step) {
            return [$step['action'], $step['source'], $step['scope']];
        }, joinask::asked_to(self::NUMBER)));
        joinask::status('wamid.J1', 'failed', 'Undeliverable');
        $this->assertGreaterThan(0, api::item_channels('local_example', 7)['whatsapp']['failed']);
    }

    /**
     * Its template has the question and the two answers, without a link, and is a utility one.
     */
    public function test_template(): void {
        $payload = templates::payload(notices::get('local_oksigeniamarandu', 'join'), 'en');
        $this->assertSame('UTILITY', $payload['category']);
        $this->assertNull(templates::problem(notices::text(notices::get('local_oksigeniamarandu', 'join'), 'template', 'en')));
        $buttons = $payload['components'][1]['buttons'];
        $this->assertSame([['QUICK_REPLY', 'Yes'], ['QUICK_REPLY', 'No']], array_map(function ($one) {
            return [$one['type'], $one['text']];
        }, $buttons));
        $this->assertSame([['del Colegio Ejemplo']], $payload['components'][0]['example']['body_text']);
    }
}
