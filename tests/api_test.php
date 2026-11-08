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

namespace local_oksigeniamarandu;

use local_oksigeniamarandu\local\consent;

/**
 * Tests of what other plugins use. Numbers are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class api_test extends \advanced_testcase {
    /**
     * A code is reused until somebody claims it; then the next call gives a new one, for a second person.
     */
    public function test_invite(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $family = api::invite('local_oksigeniameals', 42);
        $this->assertSame($family, api::invite('local_oksigeniameals', 42));
        $this->assertNotSame($family, api::invite('local_oksigeniameals', 43));
        $this->assertNotSame($family, api::invite('local_oksigeniameals', 42, $user->id));

        $contact = consent::get_or_create('34600000001');
        $DB->set_field(consent::LINKS, 'contactid', $contact->id, ['code' => $family]);
        $this->assertNotSame($family, api::invite('local_oksigeniameals', 42));
    }

    /**
     * A plugin must say who it is.
     */
    public function test_invite_needs_a_component(): void {
        $this->expectException(\coding_exception::class);
        api::invite('not a component');
    }

    /**
     * The link opens WhatsApp with the first opt-in word and the code, once the site has set its number.
     */
    public function test_optin_url(): void {
        $this->resetAfterTest();
        $this->assertNull(api::optin_url('K7M2Q9XA'));

        set_config('displayphone', '+34 900 000 000', 'local_oksigeniamarandu');
        $this->assertSame('https://wa.me/34900000000?text=ALTA%20K7M2Q9XA', api::optin_url('K7M2Q9XA'));

        set_config('optinkeywords', 'Avisos, ALTA', 'local_oksigeniamarandu');
        $this->assertSame('https://wa.me/34900000000?text=AVISOS%20K7M2Q9XA', api::optin_url('K7M2Q9XA'));
    }

    /**
     * The private page of a code.
     */
    public function test_signup_url(): void {
        $this->assertSame(
            '/local/oksigeniamarandu/alta.php?code=K7M2Q9XA',
            api::signup_url('K7M2Q9XA')->out_as_local_url(false)
        );
    }

    /**
     * Only a linked number that receives notices counts.
     */
    public function test_has_active_contact(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(api::has_active_contact('core', 0, $user->id));

        $code = api::invite('core', 0, $user->id);
        $contact = consent::get_or_create('34600000001');
        $DB->set_field(consent::LINKS, 'contactid', $contact->id, ['code' => $code]);
        $this->assertFalse(api::has_active_contact('core', 0, $user->id));

        $DB->set_field(consent::CONTACTS, 'status', 'active', ['id' => $contact->id]);
        $this->assertTrue(api::has_active_contact('core', 0, $user->id));
        $this->assertFalse(api::has_active_contact('core', 0));

        consent::optout('34600000001', 'admin', 'test');
        $this->assertFalse(api::has_active_contact('core', 0, $user->id));
    }

    /**
     * A plugin with two kinds of item, such as the diners of a canteen and its kitchen: each kind has its own codes and
     * its own numbers, and the notices of one do not reach the other.
     */
    public function test_areas(): void {
        global $DB;
        $this->resetAfterTest();
        $diners = api::invite('local_oksigeniamarandu', 7);
        $kitchen = api::invite('local_oksigeniamarandu', 7, null, 'kitchen');
        $this->assertNotSame($diners, $kitchen);
        $this->assertSame($kitchen, api::invite('local_oksigeniamarandu', 7, null, 'kitchen'));
        $this->assertSame('kitchen', $DB->get_field(consent::LINKS, 'area', ['code' => $kitchen]));
        $this->assertSame('', $DB->get_field(consent::LINKS, 'area', ['code' => $diners]));

        consent::optin_from_message('34600000001', $diners, 'join');
        consent::optin_from_message('34600000002', $kitchen, 'join');
        $this->assertTrue(api::has_active_contact('local_oksigeniamarandu', 7));
        $this->assertTrue(api::has_active_contact('local_oksigeniamarandu', 7, null, 'kitchen'));
        $this->assertFalse(api::has_active_contact('local_oksigeniamarandu', 8, null, 'kitchen'));

        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'marandu_example_bot', 'local_oksigeniamarandu');
        $channels = api::item_channels('local_oksigeniamarandu', 8, 'kitchen');
        $this->assertSame(['telegram'], array_keys($channels));
        $this->assertFalse($channels['telegram']['joined']);
        $this->assertStringEndsWith(
            'start=' . api::invite('local_oksigeniamarandu', 8, null, 'kitchen'),
            $channels['telegram']['url']
        );

        $count = api::notify(
            'local_oksigeniamarandu',
            'notification',
            ['Comedor Ejemplo', 'el resumen de cocina de hoy'],
            null,
            7,
            area: 'kitchen'
        );
        $this->assertSame(1, $count);
        $contactid = $DB->get_field(consent::CONTACTS, 'id', ['phone' => '34600000002']);
        $this->assertEquals($contactid, $DB->get_field(local\queue::TABLE, 'contactid', []));

        $this->assertSame(1, api::unlink('local_oksigeniamarandu', 7, null, 'kitchen'));
        $this->assertFalse(api::has_active_contact('local_oksigeniamarandu', 7, null, 'kitchen'));
        $this->assertTrue(api::has_active_contact('local_oksigeniamarandu', 7));
    }

    /**
     * An area is a short name, as a plugin would write it in its code.
     */
    public function test_area_is_a_short_name(): void {
        $this->expectException(\coding_exception::class);
        api::invite('local_oksigeniamarandu', 7, null, 'Kitchen of the north');
    }

    /**
     * A plugin asks for the language of a notice: it is kept if the site has its language pack, and Marandu says which
     * one it will write it in, so that the plugin writes its values in the same.
     */
    public function test_language(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->assertSame('en', api::language('en'));
        $this->assertSame($CFG->lang, api::language('ar'));
        $this->assertSame($CFG->lang, api::language('../en'));
        $this->assertSame($CFG->lang, api::language(''));

        consent::optin_from_message('34600000001', api::invite('local_oksigeniamarandu', 7), 'join');
        api::notify('local_oksigeniamarandu', 'notification', ['Centro Ejemplo', 'Reunión'], null, 7, lang: 'en');
        api::notify('local_oksigeniamarandu', 'notification', ['Centro Ejemplo', 'Reunión'], null, 7, lang: 'ar');
        $this->assertSame(['en', null], array_values($DB->get_fieldset_select(local\queue::TABLE, 'lang', '1 = 1 ORDER BY id')));
    }

    /**
     * The notices of a plugin, summed by notice, channel and day.
     */
    public function test_notice_stats(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        consent::optin_from_message('34600000001', api::invite('local_oksigeniamarandu', 7), 'join');
        consent::optin_from_message('tg:555', api::invite('local_oksigeniamarandu', 7), 'join');
        $day = 1793181600;
        api::notify('local_oksigeniamarandu', 'notification', ['Centro Ejemplo', 'Reunión'], null, 7);
        $rows = $DB->get_records(local\queue::TABLE, null, 'id');
        $statuses = ['read', 'sent'];
        foreach (array_values($rows) as $i => $row) {
            $DB->update_record(local\queue::TABLE, (object)['id' => $row->id, 'status' => $statuses[$i], 'timequeued' => $day,
                'timeclicked' => $i === 0 ? $day + 60 : null]);
        }
        $stats = api::notice_stats('local_oksigeniamarandu', $day - DAYSECS, $day + DAYSECS);
        $this->assertSame([
            ['notice' => 'notification', 'channel' => 'telegram', 'day' => '2026-10-28', 'queued' => 1, 'sent' => 1,
                'delivered' => 0, 'read' => 0, 'clicked' => 0, 'failed' => 0],
            ['notice' => 'notification', 'channel' => 'whatsapp', 'day' => '2026-10-28', 'queued' => 1, 'sent' => 1,
                'delivered' => 1, 'read' => 1, 'clicked' => 1, 'failed' => 0],
        ], $stats);
        $this->assertSame([], api::notice_stats('local_oksigeniamarandu', $day + DAYSECS, $day + 2 * DAYSECS));

        // The notices of one item: the queue keeps it.
        $this->assertEquals([7, 7], array_values($DB->get_fieldset_select(local\queue::TABLE, 'itemid', '1 = 1 ORDER BY id')));
        $this->assertCount(2, api::notice_stats('local_oksigeniamarandu', $day - DAYSECS, $day + DAYSECS, '', [7, 8]));
        $this->assertSame([], api::notice_stats('local_oksigeniamarandu', $day - DAYSECS, $day + DAYSECS, '', [8]));
    }
}
