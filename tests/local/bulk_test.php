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
 * Tests of the sends to many and of what WhatsApp costs. Numbers and chats are made up; Meta is never called.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\bulk
 * @covers     \local_oksigeniamarandu\local\spend
 */
#[\PHPUnit\Framework\Attributes\CoversClass(bulk::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(spend::class)]
final class bulk_test extends \advanced_testcase {
    /**
     * A plugin with an invitation, on for both channels.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        notices::declare_for_tests(['local_oksigeniamarandu' => ['act_invitation' => ['examples' => ['Jornada', 'jueves']]]]);
        foreach (['whatsapp', 'telegram'] as $channel) {
            notices::set('local_oksigeniamarandu/act_invitation', $channel, true);
        }
    }

    /**
     * The tests forget their notices.
     */
    protected function tearDown(): void {
        notices::declare_for_tests([]);
        parent::tearDown();
    }

    /**
     * Joins an address for an item.
     *
     * @param string $address The number or the chat.
     * @param int $itemid The item.
     * @return \stdClass The contact.
     */
    private function join(string $address, int $itemid): \stdClass {
        global $DB;
        consent::optin_from_message($address, api::invite('local_oksigeniamarandu', $itemid), 'join');
        return $DB->get_record(consent::CONTACTS, ['phone' => $address]);
    }

    /**
     * The quote counts what costs, what is free and what nobody gets; a send under the threshold goes now, one over it
     * waits; approved, it goes; rejected or taken back, nothing goes; only whoever may approve decides.
     */
    public function test_quote_request_and_decide(): void {
        global $DB;
        $this->join('34600000001', 1);
        $inside = $this->join('34600000002', 2);
        $this->join('tg:555', 3);
        $out = $this->join('34600000004', 4);
        // One wrote in the last hours (free); the others did long ago (they cost); one said no to this plugin.
        $DB->set_field_select(consent::CONTACTS, 'lastinbound', time() - 3 * DAYSECS, 'id <> ?', [$inside->id]);
        consent::set_optout($out, 'local_oksigeniamarandu', true, 'test');

        $quote = api::bulk_quote('local_oksigeniamarandu', 'act_invitation', [1, 2, 3, 4, 5]);
        $this->assertSame([1, 1, 1, 2], [$quote['whatsapp'], $quote['whatsappfree'], $quote['telegram'], $quote['skipped']]);
        $this->assertSame('marketing', $quote['category']);
        $this->assertSame(0.06, $quote['cost']);
        $this->assertFalse($quote['approval']);

        $items = array_map(function ($itemid) {
            return ['itemid' => $itemid, 'params' => ['Jornada', 'jueves'], 'url' => null, 'lang' => ''];
        }, [1, 2, 3, 4, 5]);
        $asker = $this->getDataGenerator()->create_user();
        $sent = api::bulk_request('local_oksigeniamarandu', 'act_invitation', $items, (int)$asker->id, 'Jornada');
        $this->assertSame('queued', $sent['status']);
        $this->assertSame(3, api::bulk_state($sent['id'])['sent']);

        // From a threshold of 0.05, it waits; whoever asked can take it back.
        set_config('bulkthreshold', '0.05', 'local_oksigeniamarandu');
        $this->assertTrue(api::bulk_quote('local_oksigeniamarandu', 'act_invitation', [1])['approval']);
        $waiting = api::bulk_request('local_oksigeniamarandu', 'act_invitation', $items, (int)$asker->id, 'Jornada 2');
        $this->assertSame('pending', $waiting['status']);
        $this->assertSame(0, api::bulk_state($waiting['id'])['sent']);
        $this->assertTrue(api::bulk_cancel($waiting['id'], (int)$asker->id));
        $this->assertFalse(api::bulk_cancel($waiting['id'], (int)$asker->id));

        // Someone who may approve it: approved, it goes, and the plugin can read who did.
        $waiting = api::bulk_request('local_oksigeniamarandu', 'act_invitation', $items, (int)$asker->id, 'Jornada 3');
        $boss = $this->getDataGenerator()->create_user(['firstname' => 'Héctor', 'lastname' => 'Ruiz']);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniamarandu:bulkapprove', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $boss->id, \context_system::instance());
        $this->assertSame(['Héctor Ruiz'], array_values(array_map('fullname', bulk::approvers())));
        $this->assertTrue(bulk::decide($waiting['id'], true, (int)$boss->id));
        $state = api::bulk_state($waiting['id']);
        $this->assertSame(['queued', 'Héctor Ruiz', 3], [$state['status'], $state['decidedby'], $state['sent']]);
        $this->assertFalse(bulk::decide($waiting['id'], false, (int)$boss->id));

        // Rejected, nothing goes; and whoever may not approve cannot decide.
        $another = api::bulk_request('local_oksigeniamarandu', 'act_invitation', $items, (int)$asker->id, 'Jornada 4');
        $this->assertTrue(bulk::decide($another['id'], false, (int)$boss->id));
        $this->assertSame(['rejected', 0], [api::bulk_state($another['id'])['status'], api::bulk_state($another['id'])['sent']]);
        $this->expectException(\required_capability_exception::class);
        bulk::decide($waiting['id'], true, (int)$asker->id);
    }

    /**
     * The month as Meta counts it, by category and by day, against the budget: at 80 % and at 100 %, a word to whoever
     * watches, once each; over the budget, a send waits for approval though it is under the threshold.
     */
    public function test_spend_and_budget(): void {
        $api = new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array The points Meta gives. */
            public $points = [];

            /**
             * The points of the test.
             *
             * @param string $wabaid The account.
             * @param int $start From.
             * @param int $end To.
             * @return array
             */
            public function pricing(string $wabaid, int $start, int $end): array {
                return ['currency' => 'EUR', 'points' => $this->points];
            }
        };
        $now = time();
        $api->points = [
            ['start' => $now - 2 * HOURSECS, 'pricing_category' => 'UTILITY', 'volume' => 300, 'cost' => 6.0],
            ['start' => $now - 2 * HOURSECS, 'pricing_category' => 'MARKETING', 'volume' => 40, 'cost' => 2.4],
            ['start' => $now - 2 * HOURSECS, 'pricing_category' => 'SERVICE', 'volume' => 12, 'cost' => 0],
        ];
        // Whoever the site chose hears it; whoever sees every conversation, not.
        $watcher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniamarandu:chatall', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $other->id, \context_system::instance());
        spend::set_people([$watcher->id]);

        $sink = $this->redirectMessages();
        $month = spend::refresh($api, '999', $now);
        $this->assertSame([8.4, 352, 'EUR'], [$month['cost'], $month['count'], $month['currency']]);
        $this->assertSame(['count' => 40, 'cost' => 2.4], $month['categories']['marketing']);
        $this->assertSame(0, $sink->count());

        set_config('budgetmonth', '10', 'local_oksigeniamarandu');
        spend::refresh($api, '999', $now);
        $this->assertSame(1, $sink->count());
        spend::refresh($api, '999', $now);
        $this->assertSame(1, $sink->count());
        $this->assertTrue(bulk::needs_approval(2.0));
        $this->assertFalse(bulk::needs_approval(1.0));

        $api->points[] = ['start' => $now - HOURSECS, 'pricing_category' => 'MARKETING', 'volume' => 30, 'cost' => 1.8];
        $this->assertSame(102, spend::refresh($api, '999', $now)['share']);
        $this->assertSame(2, $sink->count());
        foreach ($sink->get_messages() as $message) {
            $this->assertSame((int)$watcher->id, (int)$message->useridto);
        }
        $sink->close();
    }

    /**
     * Only the people the site chose see the spend and approve; whoever administers chooses them; the threshold and
     * the budget are theirs.
     */
    public function test_who_sees_it(): void {
        $boss = $this->getDataGenerator()->create_user(['firstname' => 'Héctor']);
        // Until the site chooses, whoever has the capability through a role; with nobody, the administrators.
        $this->assertSame([(int)get_admin()->id], array_map(function ($user) {
            return (int)$user->id;
        }, spend::people()));
        spend::set_people([$boss->id]);
        $this->assertSame([(int)$boss->id], array_map(function ($user) {
            return (int)$user->id;
        }, spend::people()));
        $this->assertTrue(spend::is_person((int)$boss->id));
        $this->assertFalse(spend::is_person((int)get_admin()->id));
        spend::set_people([]);
        $this->assertFalse(spend::is_person((int)$boss->id));
    }
}
