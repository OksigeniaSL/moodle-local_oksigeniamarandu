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
use local_oksigeniamarandu\task\send_reply;

/**
 * Tests of what reaches the webhook: opt-ins, opt-outs and the proof of consent. Numbers and names are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\inbound
 * @covers     \local_oksigeniamarandu\local\consent
 * @covers     \local_oksigeniamarandu\local\test_message
 */
#[\PHPUnit\Framework\Attributes\CoversClass(inbound::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(consent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(test_message::class)]
final class inbound_test extends \advanced_testcase {
    /** The id Meta gives to the made-up number of the site. */
    private const SITE_NUMBER_ID = '1110000000';

    /**
     * The site is connected to its made-up number.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('phonenumberid', self::SITE_NUMBER_ID, 'local_oksigeniamarandu');
    }

    /**
     * A body as Meta sends it, with one text message.
     *
     * @param string $from The number that writes.
     * @param string $text What it writes.
     * @param string $numberid The number of the site it writes to.
     * @return array
     */
    private function payload(string $from, string $text, string $numberid = self::SITE_NUMBER_ID): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '2220000000',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '34900000000', 'phone_number_id' => $numberid],
                        'contacts' => [['profile' => ['name' => 'Ana Prueba'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => 'wamid.FAKE' . random_int(1, 99999),
                            'timestamp' => (string)time(),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * The replies queued, as phone => reply.
     *
     * @return array
     */
    private function queued_replies(): array {
        $replies = [];
        foreach (\core\task\manager::get_adhoc_tasks(send_reply::class) as $task) {
            $data = $task->get_custom_data();
            $replies[] = [$data->phone, $data->reply];
        }
        return $replies;
    }

    /**
     * Only a body signed with the app secret is taken.
     */
    public function test_verify_signature(): void {
        $raw = '{"object":"whatsapp_business_account"}';
        $good = 'sha256=' . hash_hmac('sha256', $raw, 'fake-secret');

        $this->assertTrue(inbound::verify_signature($raw, $good, 'fake-secret'));
        $this->assertFalse(inbound::verify_signature($raw . ' ', $good, 'fake-secret'));
        $this->assertFalse(inbound::verify_signature($raw, $good, 'other-secret'));
        $this->assertFalse(inbound::verify_signature($raw, substr($good, 7), 'fake-secret'));
        $this->assertFalse(inbound::verify_signature($raw, $good, ''));
    }

    /**
     * A family with a code joins and gets linked to what the code stands for.
     */
    public function test_optin_with_code(): void {
        global $DB;
        $code = api::invite('local_oksigeniameals', 42);

        $this->assertSame(1, inbound::handle($this->payload('34600000001', 'ALTA ' . strtolower($code))));

        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000001'], '*', MUST_EXIST);
        $this->assertSame('active', $contact->status);
        $this->assertSame('keyword', $contact->consentsource);
        $this->assertSame('Ana Prueba', $contact->name);
        $this->assertNotEmpty($contact->lastinbound);
        $link = $DB->get_record(consent::LINKS, ['code' => $code], '*', MUST_EXIST);
        $this->assertEquals($contact->id, $link->contactid);
        $this->assertTrue(api::has_active_contact('local_oksigeniameals', 42));
        $this->assertSame(1, $DB->count_records(consent::LOG, ['contactid' => $contact->id, 'action' => 'optin']));
        $this->assertSame([['34600000001', 'optin']], $this->queued_replies());
    }

    /**
     * A code that does not exist, or that another number used, links nothing and nobody joins.
     */
    public function test_optin_with_unknown_or_used_code(): void {
        global $DB;
        $code = api::invite('local_oksigeniameals', 42);
        inbound::handle($this->payload('34600000001', 'ALTA ' . $code));

        inbound::handle($this->payload('34600000002', 'ALTA ' . $code));
        inbound::handle($this->payload('34600000003', 'ALTA ZZZZ2222'));

        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => '34600000002']));
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => '34600000003']));
        $this->assertEqualsCanonicalizing([
            ['34600000001', 'optin'],
            ['34600000002', 'unknowncode'],
            ['34600000003', 'unknowncode'],
        ], $this->queued_replies());
    }

    /**
     * Writing the same code again from the same number is fine.
     */
    public function test_same_code_again(): void {
        $code = api::invite('local_oksigeniameals', 42);
        inbound::handle($this->payload('34600000001', 'ALTA ' . $code));
        inbound::handle($this->payload('34600000001', 'ALTA ' . $code));

        $this->assertSame([['34600000001', 'optin'], ['34600000001', 'optin']], $this->queued_replies());
    }

    /**
     * The opt-out word stops a number; an unknown number is kept as opted out.
     */
    public function test_optout(): void {
        global $DB;
        inbound::handle($this->payload('34600000001', 'ALTA'));
        inbound::handle($this->payload('34600000001', 'Baja'));
        inbound::handle($this->payload('34600000009', 'STOP'));

        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000001'], '*', MUST_EXIST);
        $this->assertSame('optedout', $contact->status);
        $this->assertNotEmpty($contact->optouttime);
        $this->assertSame(['optin', 'optout'], array_values($DB->get_fieldset_select(
            consent::LOG,
            'action',
            'contactid = ? ORDER BY id',
            [$contact->id]
        )));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000009']));
    }

    /**
     * Any other message only notes the 24-hour window of a known number, and creates nothing.
     */
    public function test_other_messages(): void {
        global $DB;
        inbound::handle($this->payload('34600000001', 'ALTA'));
        $DB->set_field(consent::CONTACTS, 'lastinbound', 1, ['phone' => '34600000001']);

        inbound::handle($this->payload('34600000001', '¿Mañana hay comedor?'));
        inbound::handle($this->payload('34600000005', 'Hola'));

        $this->assertGreaterThan(1, $DB->get_field(consent::CONTACTS, 'lastinbound', ['phone' => '34600000001']));
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => '34600000005']));
        $this->assertCount(1, $this->queued_replies());
    }

    /**
     * The statuses of the test message and an answer from its phone reach the wizard.
     */
    public function test_test_message(): void {
        test_message::start('34600000001', 'wamid.TEST', ' +34 600 00 00 01 ');
        $this->assertSame('+34 600 00 00 01', test_message::get()['typed']);
        $statuses = $this->payload('34600000001', 'Recibido');
        $value = &$statuses['entry'][0]['changes'][0]['value'];
        $value['statuses'] = [
            ['id' => 'wamid.OTHER', 'status' => 'failed', 'errors' => [['title' => 'Fake']]],
            ['id' => 'wamid.TEST', 'status' => 'delivered', 'recipient_id' => '34600000001'],
        ];
        unset($value);

        inbound::handle($statuses);

        $state = test_message::get();
        $this->assertNotEmpty($state['delivered']);
        $this->assertNotEmpty($state['replied']);
        $this->assertSame('', $state['failed']);

        test_message::start('34600000002', 'wamid.TEST2');
        test_message::status('wamid.TEST2', 'failed', 'Fake reason');
        test_message::inbound('34600000001');
        $state = test_message::get();
        $this->assertSame('+34600000002', $state['typed']);
        $this->assertSame('Fake reason', $state['failed']);
        $this->assertEmpty($state['delivered']);
        $this->assertEmpty($state['replied']);
    }

    /**
     * The webhook of templates updates what Meta says of them, for the whole account.
     */
    public function test_template_updates(): void {
        global $DB, $CFG;
        $CFG->lang = 'es';
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $DB->insert_record(templates::TABLE, (object)['component' => $notice->component, 'notice' => $notice->notice,
            'lang' => 'es', 'name' => notices::template_name($notice), 'status' => 'pending', 'timesubmitted' => 1,
            'timemodified' => 1]);
        $update = function (string $field, array $value): array {
            return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '2220000000',
                'changes' => [['field' => $field, 'value' => $value]]]]];
        };

        inbound::handle($update('message_template_status_update', ['event' => 'APPROVED',
            'message_template_name' => 'mrd_local_oksigeniamarandu_notification', 'message_template_language' => 'es',
            'reason' => 'NONE']));
        inbound::handle($update('template_category_update', ['new_category' => 'MARKETING',
            'message_template_name' => 'mrd_local_oksigeniamarandu_notification', 'message_template_language' => 'es']));

        $template = templates::get($notice, 'es');
        $this->assertSame(['approved', 'marketing', null], [$template->status, $template->category, $template->reason]);
    }

    /**
     * What goes to another number of the same account, or is not a message, is left alone.
     */
    public function test_other_numbers_and_fields(): void {
        global $DB;
        $this->assertSame(0, inbound::handle($this->payload('34600000001', 'ALTA', '9990000000')));

        $statuses = $this->payload('34600000001', 'ALTA');
        $statuses['entry'][0]['changes'][0]['field'] = 'message_template_status_update';
        $this->assertSame(0, inbound::handle($statuses));
        $this->assertSame(0, inbound::handle(['object' => 'page', 'entry' => []]));

        set_config('phonenumberid', '', 'local_oksigeniamarandu');
        $this->assertSame(0, inbound::handle($this->payload('34600000001', 'ALTA')));

        $this->assertSame(0, $DB->count_records(consent::CONTACTS));
    }
}
