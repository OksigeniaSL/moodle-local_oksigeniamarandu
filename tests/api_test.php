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
     * The link opens WhatsApp with a greeting and the code between brackets, once the site has set its number; the
     * plugin can give the greeting, in the words of the person, and the bot reads the code from it.
     */
    public function test_optin_url(): void {
        $this->resetAfterTest();
        $this->assertNull(api::optin_url('K7M2Q9XA'));

        set_config('displayphone', '+34 900 000 000', 'local_oksigeniamarandu');
        $this->assertSame('https://wa.me/34900000000?text=' . rawurlencode(
            'Hello, we would like to get the notices of PHPUnit test site here. (K7M2Q9XA)'
        ), api::optin_url('K7M2Q9XA'));

        $url = api::join_url('whatsapp', 'K7M2Q9XA', 'Hola, somos la familia de Leo.');
        $this->assertSame('https://wa.me/34900000000?text=' . rawurlencode('Hola, somos la familia de Leo. (K7M2Q9XA)'), $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(
            ['action' => 'optin', 'code' => 'K7M2Q9XA'],
            \local_oksigeniamarandu\local\keywords::match($query['text'])
        );
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
     * One decision of not wanting messages, wherever it is taken: a plugin says it for its item, with a scope (only its
     * notices) or for everything; writing again takes it back on that channel; the trail keeps it all, with dates.
     */
    public function test_optout_single(): void {
        global $DB;
        $this->resetAfterTest();
        $code = api::invite('local_oksigeniamarandu', 7);
        consent::optin_from_message('34600000001', $code, 'join');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000001']);

        // Only what admissions sends.
        $this->assertSame(1, api::set_optout('local_oksigeniamarandu', 7, 'local_oksigeniaeguahe', true, 'eguahe:family'));
        $this->assertSame(0, api::set_optout('local_oksigeniamarandu', 7, 'local_oksigeniaeguahe', true, 'eguahe:family'));
        $contact = $DB->get_record(consent::CONTACTS, ['id' => $contact->id]);
        $this->assertTrue(consent::out_of($contact, 'local_oksigeniaeguahe'));
        $this->assertFalse(consent::out_of($contact, 'local_oksigeniameals'));
        $state = api::optout_state('local_oksigeniamarandu', 7)[0];
        $this->assertSame(
            ['whatsapp', 0, ['local_oksigeniaeguahe']],
            [$state['channel'], $state['all'], array_keys($state['scopes'])]
        );
        $this->assertSame(
            ['optout', 'eguahe:family', 'local_oksigeniaeguahe'],
            [$state['trail'][0]['action'], $state['trail'][0]['source'], $state['trail'][0]['scope']]
        );

        // Writing again never takes it back by itself, that day or another: the number is also the one of the office.
        \local_oksigeniamarandu\local\conversation::received('34600000001', 'text', 'Gracias', time(), 'wamid.W', null);
        $this->assertSame(['local_oksigeniaeguahe'], array_keys(api::optout_state('local_oksigeniamarandu', 7)[0]['scopes']));
        $DB->set_field(consent::OPTOUTS, 'timecreated', time() - 2 * DAYSECS, ['contactid' => $contact->id]);
        \local_oksigeniamarandu\local\conversation::received('34600000001', 'text', 'Hola de nuevo', time(), 'wamid.X', null);
        $this->assertSame(['local_oksigeniaeguahe'], array_keys(api::optout_state('local_oksigeniamarandu', 7)[0]['scopes']));

        // Nothing of the centre: whoever writes again is asked, once in a while and not the same day, whether they want
        // the notices again; the «yes» of the button brings them back.
        api::set_optout('local_oksigeniamarandu', 7, 'all', true, 'chat');
        $contact = $DB->get_record(consent::CONTACTS, ['id' => $contact->id]);
        $this->assertTrue(consent::out_of($contact, 'local_oksigeniameals'));
        $this->assertFalse(api::has_active_contact('local_oksigeniamarandu', 7));
        $this->assertFalse(consent::offer_back('34600000001'));
        $DB->set_field(consent::CONTACTS, 'optouttime', time() - 2 * DAYSECS, ['id' => $contact->id]);
        $this->assertTrue(consent::offer_back('34600000001'));
        $this->assertFalse(consent::offer_back('34600000001'));
        $this->assertFalse(api::has_active_contact('local_oksigeniamarandu', 7));
        $this->assertTrue(consent::come_back('34600000001', 'wamid.Y'));
        $this->assertTrue(api::has_active_contact('local_oksigeniamarandu', 7));
        $this->assertFalse(consent::come_back('34600000001', 'wamid.Z'));
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
     * Values given as a function of the language: each person gets them in the language their notice goes in, the one
     * they chose first, and the function is called once for each language. Values given as they are go in the language
     * the plugin asked for, also to whoever chose another.
     */
    public function test_values_by_language(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        foreach (['34600000001', '34600000002', 'tg:555'] as $address) {
            consent::optin_from_message($address, api::invite('local_oksigeniamarandu', 7), 'join');
        }
        $DB->set_field(consent::CONTACTS, 'lang', 'pt', ['phone' => 'tg:555']);

        $calls = [];
        $values = function (string $lang) use (&$calls): array {
            $calls[] = $lang;
            return ['Centro Ejemplo', "Reunión ($lang)"];
        };
        $this->assertSame(3, api::notify('local_oksigeniamarandu', 'notification', $values, null, 7));
        $this->assertSame([$CFG->lang, 'pt'], $calls);
        $rows = $DB->get_records_sql('SELECT q.id, c.phone, q.lang, q.params
                                        FROM {' . local\queue::TABLE . '} q
                                        JOIN {' . consent::CONTACTS . '} c ON c.id = q.contactid
                                    ORDER BY q.id');
        $this->assertSame([
            ['34600000001', $CFG->lang, ['Centro Ejemplo', 'Reunión (' . $CFG->lang . ')']],
            ['34600000002', $CFG->lang, ['Centro Ejemplo', 'Reunión (' . $CFG->lang . ')']],
            ['tg:555', 'pt', ['Centro Ejemplo', 'Reunión (pt)']],
        ], array_map(fn($row) => [$row->phone, $row->lang, json_decode($row->params, true)], array_values($rows)));

        // As they are, with a language: the whole notice in it.
        $DB->delete_records(local\queue::TABLE);
        api::notify('local_oksigeniamarandu', 'notification', ['Centro Ejemplo', 'Reunión'], null, 7, lang: 'en');
        $this->assertSame(['en', 'en', 'en'], array_values($DB->get_fieldset_select(local\queue::TABLE, 'lang', '1 = 1')));

        // A function that gives another number of values.
        $this->expectException(\coding_exception::class);
        api::notify('local_oksigeniamarandu', 'notification', fn(string $lang) => ['Centro Ejemplo'], null, 7);
    }

    /**
     * The codes nobody wrote stop working; what joined with them stays.
     */
    public function test_close_codes(): void {
        global $DB;
        $this->resetAfterTest();
        consent::optin_from_message('34600000001', api::invite('local_oksigeniameals', 7, null, 'familylink'), 'join');
        $open = api::invite('local_oksigeniameals', 7, null, 'familylink');
        api::invite('local_oksigeniameals', 8, null, 'familylink');

        $this->assertSame(1, api::close_codes('local_oksigeniameals', 7, null, 'familylink'));
        $this->assertFalse($DB->record_exists(consent::LINKS, ['code' => $open]));
        $this->assertTrue(api::has_active_contact('local_oksigeniameals', 7, null, 'familylink'));
        $this->assertSame(1, $DB->count_records(consent::LINKS, ['itemid' => 8, 'area' => 'familylink']));
        $this->assertSame(0, api::close_codes('local_oksigeniameals', 7, null, 'familylink'));
    }

    /**
     * An adult who leaves the records: the numbers that stood only for that adult stop getting the notices of the
     * items of the plugin; a number shared with another adult keeps them.
     */
    public function test_unlink_adult(): void {
        global $DB;
        $this->resetAfterTest();
        foreach (['34600000001', '34600000002'] as $phone) {
            consent::optin_from_message($phone, api::invite('local_oksigeniameals', 7, null, 'familylink'), 'join');
            consent::optin_from_message($phone, api::invite('local_oksigeniameals', 8, null, 'familylink'), 'join');
        }
        $alone = (int)$DB->get_field(consent::CONTACTS, 'id', ['phone' => '34600000001']);
        $shared = (int)$DB->get_field(consent::CONTACTS, 'id', ['phone' => '34600000002']);
        local\family::link($alone, [11]);
        local\family::link($shared, [11, 12]);

        $this->assertSame(0, api::unlink_adult('local_oksigeniameals', 11, 'familylink', []));
        $this->assertSame(1, api::unlink_adult('local_oksigeniameals', 11, 'familylink', [7]));
        $this->assertSame(1, api::unlink_adult('local_oksigeniameals', 11, 'familylink'));
        $this->assertSame(0, api::unlink_adult('local_oksigeniameals', 11, 'familylink'));
        $this->assertSame(0, api::unlink_adult('local_oksigeniameals', 11));
        $left = $DB->get_records_select(
            consent::LINKS,
            "component = 'local_oksigeniameals'",
            null,
            'itemid',
            'id, itemid, contactid'
        );
        $this->assertSame([[7, $shared], [8, $shared]], array_map(
            fn($link) => [(int)$link->itemid, (int)$link->contactid],
            array_values($left)
        ));
    }

    /**
     * Who already gets the notices of an item on the same number or chat, through their account or their record; a
     * number that left does not count.
     */
    public function test_same_contact(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        consent::optin_from_message('34600000001', api::invite('local_oksigeniameals', 7), 'join');
        consent::optin_from_message('34600000001', api::invite('core', 0, $user->id), 'join');
        local\family::link((int)$DB->get_field(consent::CONTACTS, 'id', ['phone' => '34600000001']), [11]);
        consent::optin_from_message('34600000002', api::invite('local_oksigeniameals', 8), 'join');
        consent::optin_from_message('34600000002', api::invite('core', 0, $other->id), 'join');
        consent::optout('34600000002', 'admin', 'test');

        $this->assertSame(
            [7 => ['users' => [(int)$user->id], 'adults' => [11]]],
            api::same_contact('local_oksigeniameals', [7, 8, 9], [$user->id, $other->id], [11, 12])
        );
        $this->assertSame(
            [7 => ['users' => [], 'adults' => [11]]],
            api::same_contact('local_oksigeniameals', [7], [], [11])
        );
        $this->assertSame([], api::same_contact('local_oksigeniameals', [7], [$other->id], [12]));
        $this->assertSame([], api::same_contact('local_oksigeniameals', [7], [$user->id], [], 'kitchen'));
    }

    /**
     * The links of a plugin that was uninstalled are forgotten; those of the site, of an installed plugin and of the
     * accounts stay.
     */
    public function test_forget_uninstalled(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        api::invite('local_oksigeniamarandu', 7);
        api::invite('mod_forum', 7);
        api::invite('core', 0, $user->id);
        consent::optin_from_message('34600000001', api::invite('local_oksigeniagoneexample', 7), 'join');
        api::invite('local_oksigeniagoneexample', 8);

        $this->assertSame(2, consent::forget_uninstalled());
        $this->assertSame(0, $DB->count_records(consent::LINKS, ['component' => 'local_oksigeniagoneexample']));
        $this->assertSame(3, $DB->count_records(consent::LINKS));
        $this->assertSame('active', $DB->get_field(consent::CONTACTS, 'status', ['phone' => '34600000001']));
        $this->assertSame(0, consent::forget_uninstalled());
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
