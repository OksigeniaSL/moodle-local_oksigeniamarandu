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
 * Who writes when WhatsApp does not say their number, and who changes it.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\waid
 * @covers     \local_oksigeniamarandu\local\consent
 */
#[\PHPUnit\Framework\Attributes\CoversClass(waid::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(consent::class)]
final class waid_test extends \advanced_testcase {
    /** The number of the site. */
    private const MINE = '1110000000';

    /** An identifier, as WhatsApp gives it. */
    private const USER = 'ES.13491208655302741918';

    /**
     * A site with its number.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('phonenumberid', self::MINE, 'local_oksigeniamarandu');
    }

    /**
     * A webhook.
     *
     * @param array $value What it has inside.
     * @param string $number The number it is about.
     * @return \stdClass
     */
    private function body(array $value, string $number = self::MINE): \stdClass {
        return json_decode(json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '222', 'changes' => [[
            'field' => 'messages', 'value' => $value + ['metadata' => ['phone_number_id' => $number]]]]]]]));
    }

    /**
     * A message.
     *
     * @param string|null $from The number; null if WhatsApp does not give it.
     * @param string $text What it says.
     * @return array
     */
    private function message(?string $from, string $text = 'Hola'): array {
        $who = ['user_id' => self::USER, 'profile' => ['name' => 'Laura']] + ($from === null ? [] : ['wa_id' => $from]);
        return ['contacts' => [$who], 'messages' => [['id' => 'wamid.' . $text, 'from_user_id' => self::USER, 'type' => 'text',
            'timestamp' => (string)time(), 'text' => ['body' => $text]] + ($from === null ? [] : ['from' => $from])]];
    }

    /**
     * The number comes with the identifier until it does not: then it is put back; what is not known, or is of another
     * number, stays as it came.
     */
    public function test_complete(): void {
        $unknown = $this->body($this->message(null));
        $this->assertFalse(waid::complete($unknown, self::MINE));
        $this->assertFalse(isset($unknown->entry[0]->changes[0]->value->messages[0]->from));

        $this->assertFalse(waid::complete($this->body($this->message('34600000001'), '999'), self::MINE));
        $this->assertNull(waid::phone(self::USER));
        $this->assertFalse(waid::complete($this->body($this->message('34600000001')), self::MINE));
        $this->assertSame('34600000001', waid::phone(self::USER));

        $bare = $this->body($this->message(null) + ['statuses' => [['id' => 'wamid.S', 'status' => 'delivered',
            'recipient_user_id' => self::USER]]]);
        $this->assertTrue(waid::complete($bare, self::MINE));
        $value = $bare->entry[0]->changes[0]->value;
        $this->assertSame(['34600000001', '34600000001', '34600000001'], [$value->messages[0]->from, $value->contacts[0]->wa_id,
            $value->statuses[0]->recipient_id]);
        $this->assertFalse(waid::complete($this->body($this->message(null), '999'), self::MINE));

        waid::learn('not an identifier', '34600000009');
        $this->assertNull(waid::phone('not an identifier'));
        waid::learn(self::USER, '+34 600 000 002');
        $this->assertSame('34600000002', waid::phone(self::USER));
        waid::forget(self::USER);
        $this->assertNull(waid::phone(self::USER));
    }

    /**
     * Somebody who writes without their number is who they are: they join, and say no.
     */
    public function test_without_number(): void {
        global $DB;
        waid::learn(self::USER, '34600000001');
        $write = function (string $text) {
            $body = $this->body($this->message(null, $text));
            waid::complete($body, self::MINE);
            return inbound::handle(json_decode(json_encode($body), true));
        };
        $this->assertSame(1, $write('ALTA ' . api::invite('local_example', 7)));
        $this->assertSame('active', $DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000001']));
        $write('BAJA');
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000001']));
    }

    /**
     * Whoever changes their number takes what they joined and what they said no to; the old one stands for nobody.
     */
    public function test_moved(): void {
        global $DB;
        consent::optin_from_message('34600000001', api::invite('local_example', 7), 'join');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000001']);
        consent::set_optout($contact, 'local_other', true, 'test');
        $system = function (string $type, array $more) {
            $body = $this->body(['messages' => [['id' => 'wamid.C', 'type' => 'system', 'timestamp' => (string)time(),
                'system' => ['type' => $type, 'body' => 'x'] + $more]]]);
            waid::complete($body, self::MINE);
            return $body;
        };
        waid::learn(self::USER, '34600000001');
        $body = $system('user_changed_number', ['wa_id' => '34600000002', 'user_id' => 'ES.999',
            'previous_user_id' => self::USER]);
        $moved = $DB->get_record(consent::CONTACTS, ['phone' => '34600000002']);
        $this->assertSame('active', $moved->status);
        $this->assertTrue(consent::out_of($moved, 'local_other'));
        $this->assertTrue($DB->record_exists(consent::LINKS, ['component' => 'local_example', 'itemid' => 7,
            'contactid' => $moved->id]));
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => '34600000001']));
        $this->assertSame(['34600000002', null], [waid::phone('ES.999'), waid::phone(self::USER)]);
        $this->assertSame('moved', consent::trail((int)$moved->id)[0]['action']);
        // It is nobody's message: the 24 hours do not open.
        $DB->set_field(consent::CONTACTS, 'lastinbound', 5, ['id' => $moved->id]);
        inbound::handle(json_decode(json_encode($body), true));
        $this->assertSame('5', (string)$DB->get_field(consent::CONTACTS, 'lastinbound', ['id' => $moved->id]));

        // To a number that had already written: one link for each thing, and the last word counts.
        consent::optin_from_message('34600000003', api::invite('local_example', 7), 'join');
        consent::optin_from_message('34600000003', api::invite('local_example', 8), 'join');
        $DB->set_field(consent::CONTACTS, 'consenttime', time() - DAYSECS, ['phone' => '34600000003']);
        consent::optout('34600000002', 'keyword', 'no');
        $this->assertTrue(consent::moved('34600000002', '34600000003', 'wamid.D'));
        $this->assertSame(['optedout', 'pending'], [$DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000003']),
            $DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000002'])]);
        $this->assertSame(2, $DB->count_records_select(
            consent::LINKS,
            "component = 'local_example' AND contactid IS NOT NULL"
        ));
        $this->assertFalse(consent::moved('34600000099', '34600000003', 'wamid.E'));

        // A new identifier without a number: it is still the same person.
        waid::learn('ES.111', '34600000003');
        $system('user_changed_user_id', ['user_id' => 'ES.222', 'previous_user_id' => 'ES.111']);
        $this->assertSame(['34600000003', null], [waid::phone('ES.222'), waid::phone('ES.111')]);
    }
}
