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
        $adultid = \local_oksigeniafamily\api::add_adult($familyid, ['firstname' => 'Lucía', 'phone' => $phone,
            'phoneverified' => true]);
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
     * WhatsApp proves the number: the code of a second student from the same number goes to the same adult, and an adult
     * the school has with that number is the one who joins.
     */
    public function test_claim_proves_the_number(): void {
        global $DB;
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$first->id);
        $this->assertSame('optin', consent::optin_from_message('34600000001', $code, 'wamid.A', 'Lucía'));
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$second->id);
        $this->assertSame('optin', consent::optin_from_message('34600000001', $code, 'wamid.B', 'Lucía'));

        $adults = \local_oksigeniafamily\api::adults_of_child((int)$first->id);
        $this->assertCount(1, $adults);
        $this->assertSame(array_keys($adults), array_keys(\local_oksigeniafamily\api::adults_of_child((int)$second->id)));
        $this->assertEquals(1, $DB->get_field('local_oksigeniafamily_adult', 'phoneverified', ['id' => key($adults)]));
        $this->assertSame(array_keys($adults), $this->linked('34600000001'));
        // The daily check keeps it: the number is theirs.
        $this->assertSame(0, family::check());

        [, $adultid] = $this->family('+34600000002');
        $third = $this->getDataGenerator()->create_user();
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$third->id);
        consent::optin_from_message('34600000002', $code, 'wamid.C');
        $this->assertSame([$adultid], array_keys(\local_oksigeniafamily\api::adults_of_child((int)$third->id)));
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_adult'));
    }

    /**
     * A chat of Telegram gives the records no phone, and so vouches for none.
     */
    public function test_claim_from_a_chat(): void {
        global $DB;
        $child = $this->getDataGenerator()->create_user();
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$child->id);
        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => family::claim('tg:555', $code, 'Ana')]);
        $this->assertEmpty($adult->phone);
        $this->assertEquals(0, $adult->phoneverified);
    }

    /**
     * The code of a second student from the same chat goes to the adult the chat stands for, in their family; another chat
     * is another adult. If that adult left the records, the chat starts again.
     */
    public function test_second_code_from_a_chat(): void {
        $children = [];
        $adults = [];
        foreach (['tg:555', 'tg:555', 'tg:556'] as $i => $chat) {
            $children[$i] = $this->getDataGenerator()->create_user();
            $code = \local_oksigeniafamily\api::issue_claim_code((int)$children[$i]->id);
            $this->assertSame('optin', consent::optin_from_message($chat, $code, 'wamid.T' . $i, 'Ana'));
            $adults[$i] = array_keys(\local_oksigeniafamily\api::adults_of_child((int)$children[$i]->id));
            $this->assertCount(1, $adults[$i]);
        }
        $this->assertSame($adults[0], $adults[1]);
        $this->assertNotSame($adults[0], $adults[2]);
        $this->assertSame($adults[0], $this->linked('tg:555'));

        \local_oksigeniafamily\api::remove_adult($adults[0][0]);
        $last = $this->getDataGenerator()->create_user();
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$last->id);
        consent::optin_from_message('tg:555', $code, 'wamid.T9', 'Ana');
        $again = array_keys(\local_oksigeniafamily\api::adults_of_child((int)$last->id));
        $this->assertCount(1, $again);
        $this->assertNotSame($adults[0], $again);
    }

    /**
     * A number that was only typed on the claim page proves nothing: writing from it does not make it stand for that adult.
     */
    public function test_number_only_typed(): void {
        $familyid = \local_oksigeniafamily\api::create_family('claim');
        \local_oksigeniafamily\api::add_adult($familyid, ['firstname' => 'Ana', 'phone' => '+34600000002']);
        $this->assertSame('optin', consent::optin_from_message('34600000002', null, 'wamid.B'));
        $this->assertSame([], $this->linked('34600000002'));
    }

    /**
     * What joins with the adult's own code stays, whatever number the records have, until the adult leaves.
     */
    public function test_own_code_stays(): void {
        // With another number in the records, and with one only typed, which the records do not count.
        [$child, $adultid] = $this->family('+34600000002');
        $familyid = \local_oksigeniafamily\api::create_family('claim');
        $typed = \local_oksigeniafamily\api::add_adult($familyid, ['firstname' => 'Ana', 'phone' => '+34600000003']);
        consent::optin_from_message('34600000008', api::invite(family::COMPONENT, $adultid), 'wamid.G');
        consent::optin_from_message('34600000003', api::invite(family::COMPONENT, $typed), 'wamid.H');
        $this->assertSame(0, family::check());
        $this->assertSame([$adultid], $this->linked('34600000008'));
        $this->assertSame([$typed], $this->linked('34600000003'));
        $this->assertSame(1, api::notify_child(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            (int)$child->id
        ));

        // Changing the records' number does not touch it; the adult leaving does.
        \local_oksigeniafamily\api::update_adult($adultid, ['phone' => '+34600000009']);
        $this->assertSame([$adultid], $this->linked('34600000008'));
        \local_oksigeniafamily\api::remove_adult($adultid);
        $this->assertSame([], $this->linked('34600000008'));
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
