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

use local_oksigeniamarandu\local\chat\access;
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\directory;

/**
 * Who opens the conversations: only the people of the account, by a role of the site.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\chat\access
 */
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class access_test extends \advanced_testcase {
    /**
     * A made-up account with these people.
     *
     * @param array $agents Each with id, name and email.
     */
    private function account(array $agents): void {
        $client = new class ('https://chat.example.com', 7, 'token') extends chatwoot {
            /** @var array The people of the account. */
            public $agents = [];

            /**
             * Answers as the account would.
             *
             * @param string $method The method.
             * @param string $path The path.
             * @param array $data The data.
             * @return array
             */
            public function call(string $method, string $path, array $data = []): array {
                if ($path === '/agents') {
                    return $this->agents;
                }
                return $path === '/inboxes' ? ['payload' => []] : [];
            }
        };
        $client->agents = array_map(function ($agent) {
            return $agent + ['role' => 'agent'];
        }, $agents);
        directory::use_client($client);
    }

    /**
     * Whether a user opens the chat now, with what the roles say at this moment.
     *
     * @param \stdClass $user The user.
     * @return bool
     */
    private function can(\stdClass $user): bool {
        accesslib_clear_all_caches_for_unit_testing();
        return has_capability(access::CAPABILITY, \context_system::instance(), $user);
    }

    /**
     * Ends the made-up account.
     */
    protected function tearDown(): void {
        directory::use_client(null);
        parent::tearDown();
    }

    /**
     * Nobody has it by default; the role goes to the people of the account, also to whoever joins, and leaves with them.
     */
    public function test_role_follows_the_account(): void {
        global $DB;
        $this->resetAfterTest();
        $system = \context_system::instance();
        $ana = $this->getDataGenerator()->create_user(['email' => 'ana@example.com']);
        $luis = $this->getDataGenerator()->create_user(['email' => 'luis@example.com']);
        $other = $this->getDataGenerator()->create_user(['email' => 'otro@example.com']);
        $this->assertFalse($this->can($ana));
        $this->assertSame([], access::open_roles());

        $this->account([['id' => 1, 'name' => 'Ana Pérez', 'email' => 'ana@example.com'],
            ['id' => 2, 'name' => 'Nadie Aquí', 'email' => 'nadie@example.com']]);
        // Without a role chosen, nothing is given: only said.
        $this->assertSame(['given' => 0, 'taken' => 0], access::sync());
        $this->assertCount(1, access::missing());
        $this->assertSame('nadie@example.com', access::unmatched()[0]['email']);

        $role = access::choose();
        $this->assertSame(access::SHORTNAME, $role->shortname);
        $this->assertSame(['given' => 1, 'taken' => 0], access::sync());
        $this->assertTrue($this->can($ana));
        $this->assertFalse($this->can($other));
        $this->assertSame([], access::missing());

        // Luis joins, Ana leaves.
        $this->account([['id' => 2, 'name' => 'Luis Gómez', 'email' => 'luis@example.com']]);
        $this->assertSame(['given' => 1, 'taken' => 1], access::sync());
        $this->assertTrue($this->can($luis));
        $this->assertFalse($this->can($ana));

        // An account that answers with nobody takes nobody's away.
        $this->account([]);
        $this->assertSame(['given' => 0, 'taken' => 0], access::sync());
        $this->assertTrue($this->can($luis));

        // The role the organisation gave by hand is not taken away: only the one given here.
        $this->account([]);
        role_assign($role->id, $other->id, $system->id);
        $this->account([['id' => 2, 'name' => 'Luis Gómez', 'email' => 'luis@example.com']]);
        access::sync();
        $this->assertTrue($this->can($other));
        $this->assertSame(1, $DB->count_records('role', ['shortname' => access::SHORTNAME]));
    }

    /**
     * Whoever opens the chat by another role gets the role of the chat too.
     */
    public function test_role_also_to_whoever_opens_it_otherwise(): void {
        global $CFG;
        $this->resetAfterTest();
        $system = \context_system::instance();
        $ana = $this->getDataGenerator()->create_user(['email' => 'ana@example.com']);
        assign_capability(access::CAPABILITY, CAP_ALLOW, $CFG->defaultuserroleid, $system->id, true);
        $this->account([['id' => 1, 'name' => 'Ana Pérez', 'email' => 'ana@example.com']]);
        access::choose();
        // Ana opens it as every account does; she gets the role all the same, so closing it to every account keeps her in.
        $this->assertSame(['given' => 1, 'taken' => 0], access::sync());
        access::close();
        $this->assertTrue($this->can($ana));
        $this->assertSame(['given' => 0, 'taken' => 0], access::sync());
    }

    /**
     * A role of the organisation, by its short name; and the capability taken from every account.
     */
    public function test_own_role_and_closing(): void {
        global $CFG;
        $this->resetAfterTest();
        $system = \context_system::instance();
        $user = $this->getDataGenerator()->create_user();
        $id = create_role('Chat', 'mychat', '');
        $this->assertSame((int)$id, (int)access::choose('mychat')->id);
        $this->assertSame((int)$id, (int)access::role()->id);
        try {
            access::choose('missing');
            $this->fail('A role that is not there');
        } catch (\moodle_exception $e) {
            $this->assertSame('chat:access:norole', $e->errorcode);
        }

        // A site that gave it to every account, as before.
        assign_capability(access::CAPABILITY, CAP_ALLOW, $CFG->defaultuserroleid, $system->id, true);
        $system->mark_dirty();
        $this->assertTrue($this->can($user));
        $this->assertArrayHasKey((int)$CFG->defaultuserroleid, access::open_roles());
        $this->assertSame(1, access::close());
        $this->assertSame([], access::open_roles());
        $this->assertFalse($this->can($user));
    }
}
