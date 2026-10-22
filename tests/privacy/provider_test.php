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

namespace local_oksigeniamarandu\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_oksigeniamarandu\api;
use local_oksigeniamarandu\local\consent;

/**
 * Tests of the privacy provider. Numbers are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\privacy\provider
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /**
     * A user with a number that joined, linked to them.
     *
     * @param string $phone Digits with the country code.
     * @return \stdClass The user.
     */
    private function user_with_number(string $phone): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = api::invite('core', 0, $user->id);
        consent::optin_from_message($phone, $code, 'wamid.FAKE');
        $this->assertTrue($DB->record_exists(consent::LINKS, ['code' => $code, 'userid' => $user->id]));
        return $user;
    }

    /**
     * The tables and WhatsApp are declared.
     */
    public function test_get_metadata(): void {
        $items = provider::get_metadata(new \core_privacy\local\metadata\collection('local_oksigeniamarandu'))
            ->get_collection();
        $names = array_map(function ($item) {
            return $item->get_name();
        }, $items);
        $this->assertEqualsCanonicalizing([consent::CONTACTS, consent::LINKS, consent::LOG, 'whatsapp'], $names);
    }

    /**
     * Only users with a linked number have data, in the system context, and it can be exported.
     */
    public function test_contexts_users_and_export(): void {
        $this->resetAfterTest();
        $user = $this->user_with_number('34600000001');
        $other = $this->getDataGenerator()->create_user();
        $system = \context_system::instance();

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($user->id)->get_contextids());
        $this->assertSame([], provider::get_contexts_for_userid($other->id)->get_contextids());

        $userlist = new userlist($system, 'local_oksigeniamarandu');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int)$user->id], array_map('intval', $userlist->get_userids()));

        provider::export_user_data(new approved_contextlist($user, 'local_oksigeniamarandu', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_oksigeniamarandu')]);
        $this->assertSame('+34600000001', $data->numbers[0]->phone);
        $this->assertSame('active', $data->numbers[0]->status);
    }

    /**
     * Deleting a user deletes their link and the number, unless another link still uses it.
     */
    public function test_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $system = \context_system::instance();
        $user = $this->user_with_number('34600000001');
        $kept = $this->user_with_number('34600000002');
        $family = api::invite('local_oksigeniameals', 42);
        consent::optin_from_message('34600000002', $family, 'wamid.FAKE');
        $first = $DB->get_field(consent::CONTACTS, 'id', ['phone' => '34600000001']);

        provider::delete_data_for_user(new approved_contextlist($user, 'local_oksigeniamarandu', [$system->id]));
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => '34600000001']));
        $this->assertFalse($DB->record_exists(consent::LINKS, ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists(consent::LOG, ['contactid' => $first]));

        provider::delete_data_for_users(new approved_userlist($system, 'local_oksigeniamarandu', [$kept->id]));
        $this->assertFalse($DB->record_exists(consent::LINKS, ['userid' => $kept->id]));
        $this->assertTrue($DB->record_exists(consent::CONTACTS, ['phone' => '34600000002']));

        $last = $this->user_with_number('34600000003');
        provider::delete_data_for_all_users_in_context($system);
        $this->assertFalse($DB->record_exists(consent::LINKS, ['userid' => $last->id]));
        $this->assertFalse($DB->record_exists(consent::CONTACTS, ['phone' => '34600000003']));
    }
}
