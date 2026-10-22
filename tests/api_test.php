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
}
