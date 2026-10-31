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
 * Tests for the family records of Oksigenia Family, when the site has them. Made-up numbers and people.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\family
 */
#[\PHPUnit\Framework\Attributes\CoversClass(family::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(consent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class family_test extends \advanced_testcase {
    /**
     * A site with its account and the general notice switched on; the records of families, or the test is skipped.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_oksigeniafamily\api')) {
            $this->markTestSkipped('local_oksigeniafamily is not installed');
        }
        $this->resetAfterTest();
        $this->redirectMessages();
        $this->redirectEmails();
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('enablednotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
    }

    /**
     * A family of the records, as a school's list brings it: an adult with a phone, and a student.
     *
     * @param string $phone The adult's number, in E.164.
     * @return array The student and the adult.
     */
    private function family(string $phone): array {
        $child = $this->getDataGenerator()->create_user();
        $familyid = \local_oksigeniafamily\api::create_family('csv');
        $adultid = \local_oksigeniafamily\api::add_adult($familyid, ['firstname' => 'Lucía', 'phone' => $phone]);
        \local_oksigeniafamily\api::add_child($familyid, (int)$child->id);
        return [$child, $adultid];
    }

    /**
     * The links of a number to adults of the records.
     *
     * @param string $phone Digits with the country code.
     * @return int[] The adults.
     */
    private function linked(string $phone): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql('SELECT l.itemid FROM {' . consent::LINKS . '} l
                                                           JOIN {' . consent::CONTACTS . '} c ON c.id = l.contactid
                                                          WHERE c.phone = ? AND l.component = ?', [$phone, family::COMPONENT]));
    }

    /**
     * ALTA with a student's code of the records joins the number to the student's family, also with the code in halves.
     */
    public function test_claim_with_the_students_code(): void {
        $child = $this->getDataGenerator()->create_user();
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$child->id);
        $match = keywords::match('Alta ' . substr($code, 0, 4) . '-' . substr($code, 4));
        $this->assertSame($code, $match['code']);

        $this->assertSame('optin', consent::optin_from_message('34600000001', $match['code'], 'wamid.A', 'Lucía'));

        $adults = \local_oksigeniafamily\api::adults_of_child((int)$child->id);
        $this->assertCount(1, $adults);
        $this->assertSame('+34600000001', reset($adults)->phone);
        $this->assertSame(array_keys($adults), $this->linked('34600000001'));
    }

    /**
     * ALTA without a code, from the number the school has for an adult, joins it to that adult.
     */
    public function test_number_the_school_has(): void {
        [, $adultid] = $this->family('+34600000002');
        $this->assertSame('optin', consent::optin_from_message('34600000002', null, 'wamid.B'));
        $this->assertSame([$adultid], $this->linked('34600000002'));
        // A number the records do not have joins as always, standing for nobody.
        $this->assertSame('optin', consent::optin_from_message('34600000003', null, 'wamid.C'));
        $this->assertSame([], $this->linked('34600000003'));
    }

    /**
     * A notice about a student reaches the numbers of the student's family that opted in, and no other.
     */
    public function test_notify_child(): void {
        global $DB;
        [$child] = $this->family('+34600000002');
        [$other] = $this->family('+34600000004');
        consent::optin_from_message('34600000002', null, 'wamid.B');
        consent::optin_from_message('34600000004', null, 'wamid.D');

        $this->assertSame(1, api::notify_child(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            (int)$child->id
        ));
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000002']);
        $this->assertSame([(int)$contact->id], array_map('intval', $DB->get_fieldset_select(queue::TABLE, 'contactid', '1 = 1')));

        consent::optout('34600000002', 'keyword', 'wamid.E');
        $this->assertSame(0, api::notify_child(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            (int)$child->id
        ));
        $this->assertSame(0, api::notify_child(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            (int)$this->getDataGenerator()->create_user()->id
        ));
        $this->assertNotEmpty($other);
    }

    /**
     * When the phone of an adult changes, or the adult leaves, the old number stops standing for them at once; the daily
     * check finds what an event missed.
     */
    public function test_adult_changes(): void {
        global $DB;
        [, $adultid] = $this->family('+34600000002');
        consent::optin_from_message('34600000002', null, 'wamid.B');
        \local_oksigeniafamily\api::update_adult($adultid, ['phone' => '+34600000009']);
        $this->assertSame([], $this->linked('34600000002'));

        [, $adultid] = $this->family('+34600000005');
        consent::optin_from_message('34600000005', null, 'wamid.F');
        \local_oksigeniafamily\api::remove_adult($adultid);
        $this->assertSame([], $this->linked('34600000005'));

        // A link the events did not see, to an adult that is not that number's.
        [, $adultid] = $this->family('+34600000006');
        $contact = consent::get_or_create('34600000007');
        family::link((int)$contact->id, [$adultid]);
        $this->assertSame(1, family::check());
        $this->assertSame(0, $DB->count_records(consent::LINKS, ['component' => family::COMPONENT]));
    }

    /**
     * A number that writes wrong codes over and over has to wait, even with a good one; others do not.
     */
    public function test_wrong_codes_slow_down(): void {
        $child = $this->getDataGenerator()->create_user();
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$child->id);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('unknowncode', consent::optin_from_message('34600000001', 'ZZZZ2222', 'wamid.W' . $i));
        }
        $this->assertSame('unknowncode', consent::optin_from_message('34600000001', $code, 'wamid.X'));
        $this->assertSame('optin', consent::optin_from_message('34600000002', $code, 'wamid.Y'));
    }

    /**
     * A plugin stops a number standing for its item.
     */
    public function test_unlink(): void {
        consent::optin_from_message('34600000001', api::invite('local_example', 42), 'wamid.A');
        $this->assertTrue(api::has_active_contact('local_example', 42));
        $this->assertSame(1, api::unlink('local_example', 42));
        $this->assertFalse(api::has_active_contact('local_example', 42));
        $this->assertSame(0, api::unlink('local_example', 42));
    }
}
