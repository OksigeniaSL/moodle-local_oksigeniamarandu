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

namespace local_oksigeniamarandu\external;

use core_external\external_api;
use local_oksigeniamarandu\api;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\inbox;
use local_oksigeniamarandu\local\notices;
use local_oksigeniamarandu\local\queue;

/**
 * Tests for what the site tells a shared inbox of a WhatsApp number: a made-up plugin, numbers and notices.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\external\whatsapp_context
 * @covers     \local_oksigeniamarandu\local\inbox
 */
#[\PHPUnit\Framework\Attributes\CoversClass(whatsapp_context::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(inbox::class)]
final class whatsapp_context_test extends \advanced_testcase {
    /** A Wednesday at noon. */
    private const NOW = 1793181600;

    /**
     * A site with a made-up plugin that declares a notice.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        notices::declare_for_tests(['local_canteen' => ['menu_missing' => ['examples' => ['Ana', '3 días']]]]);
    }

    /**
     * The tests forget their notices.
     */
    protected function tearDown(): void {
        notices::declare_for_tests([]);
        parent::tearDown();
    }

    /**
     * A notice that left the site for a number.
     *
     * @param int $contactid The number.
     * @param string $status Its status.
     * @param int $timesent When it left.
     */
    private function notice(int $contactid, string $status, int $timesent): void {
        global $DB;
        $DB->insert_record(queue::TABLE, (object)['contactid' => $contactid, 'component' => 'local_canteen',
            'notice' => 'menu_missing', 'params' => '[]', 'itemid' => 7, 'area' => '', 'token' => random_string(16),
            'status' => $status, 'timequeued' => $timesent, 'timeexpires' => $timesent + DAYSECS, 'timesent' => $timesent]);
    }

    /**
     * A number the site does not know; one joined with a plugin, with the last notice it got in the days asked.
     */
    public function test_context(): void {
        global $DB;
        $this->assertSame(
            ['phone' => '34600000009', 'status' => 'none', 'joined' => [], 'takenby' => '', 'accounts' => []],
            inbox::context('+34 600 00 00 09')
        );

        consent::optin_from_message('34600000001', api::invite('local_canteen', 7), 'join');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => '34600000001'], '*', MUST_EXIST);
        $result = inbox::context('+34600000001', '', 7, self::NOW);
        $this->assertSame('active', $result['status']);
        $this->assertSame([['component' => 'local_canteen', 'area' => '', 'label' => 'local_canteen']], $result['joined']);
        $this->assertArrayNotHasKey('lastnotice', $result);

        $this->notice((int)$contact->id, 'read', self::NOW - 10 * DAYSECS);
        $this->notice((int)$contact->id, 'failed', self::NOW - HOURSECS);
        $this->assertArrayNotHasKey('lastnotice', inbox::context('34600000001', '', 7, self::NOW));
        $this->notice((int)$contact->id, 'delivered', self::NOW - DAYSECS);
        $this->assertSame(['component' => 'local_canteen', 'notice' => 'menu_missing', 'label' => 'local_canteen',
            'timesent' => self::NOW - DAYSECS], inbox::context('34600000001', '', 7, self::NOW)['lastnotice']);
        // Further back, the older one is there too, but the last one is still the one given.
        $this->assertSame(self::NOW - DAYSECS, inbox::context('34600000001', '', 30, self::NOW)['lastnotice']['timesent']);
        $this->assertArrayNotHasKey('lastnotice', inbox::context('34600000001', '', 1, self::NOW + 2 * DAYSECS));
    }

    /**
     * The web service answers whoever has the capability, as it says it does, and nobody else.
     */
    public function test_web_service(): void {
        consent::optin_from_message('34600000001', api::invite('local_canteen', 7), 'join');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        try {
            whatsapp_context::execute('+34600000001');
            $this->fail('Somebody without the capability was told');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/oksigeniamarandu:inbox', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);
        $result = external_api::clean_returnvalue(
            whatsapp_context::execute_returns(),
            whatsapp_context::execute('+34 600 00 00 01', 'wamid.UNKNOWN', 400)
        );
        $this->assertSame(['34600000001', 'active', ''], [$result['phone'], $result['status'], $result['takenby']]);
        $this->assertSame('local_canteen', $result['joined'][0]['component']);
    }

    /**
     * A username shows its last five characters; an email, the first three and last two before the @ and the domain;
     * the short ones, less; never the whole of either.
     */
    public function test_hiding(): void {
        $this->assertSame('······78Z47', inbox::hide_username('12345678Z47'));
        $this->assertSame('··c', inbox::hide_username('abc'));
        $this->assertSame('·', inbox::hide_username('a'));
        $this->assertSame('', inbox::hide_username('  '));
        $this->assertSame('lau·····in@example.com', inbox::hide_email('laura.martin@example.com'));
        $this->assertSame('abc·····ef@x.es', inbox::hide_email('abcdef@x.es'));
        $this->assertSame('a·····@x.es', inbox::hide_email('abcde@x.es'));
        $this->assertSame('·····@x.es', inbox::hide_email('a@x.es'));
        $this->assertSame('ñan·····ña@ejemplo.es', inbox::hide_email('ñandú.niña@ejemplo.es'));
        $this->assertSame('', inbox::hide_email(''));
    }

    /**
     * The accounts in the programs of the centre come for the one active adult of a checked number, half hidden; not
     * for a number of no adult or of several.
     */
    public function test_accounts(): void {
        if (!method_exists('\\local_oksigeniafamily\\api', 'external_accounts')) {
            $this->markTestSkipped('local_oksigeniafamily 0.3.40 or later is not installed');
        }
        set_config('cifrausername', '{nif}47', 'local_oksigeniafamily');
        $familyid = \local_oksigeniafamily\api::create_family('csv');
        \local_oksigeniafamily\api::add_adult($familyid, ['firstname' => 'Laura', 'phone' => '+34600000001',
            'phoneverified' => true, 'sourcekey' => 'cifra:1']);
        \local_oksigeniafamily\local\external::store('cifra', [['key' => 'cifra:1', 'document' => '12345678Z',
            'email' => 'laura.martin@example.com']], time());
        $expected = [['system' => 'cifra', 'label' => 'Cifra', 'username' => '······78Z47', 'derived' => true,
            'email' => 'lau·····in@example.com', 'active' => 'unknown']];
        $this->assertSame($expected, inbox::context('+34 600 00 00 01')['accounts']);

        // By the web service, as declared, and never the whole of either.
        $this->setAdminUser();
        $result = external_api::clean_returnvalue(whatsapp_context::execute_returns(), whatsapp_context::execute('+34600000001'));
        $this->assertSame($expected, $result['accounts']);
        $said = json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('12345678Z47', $said);
        $this->assertStringNotContainsString('laura.martin', $said);

        // No adult with the number, or one that only typed it: nothing.
        $this->assertSame([], inbox::context('+34600000009')['accounts']);
        \local_oksigeniafamily\api::add_adult($familyid, ['firstname' => 'Pedro', 'phone' => '+34600000002',
            'sourcekey' => 'cifra:2']);
        $this->assertSame([], inbox::context('+34600000002')['accounts']);
        // The same number checked for two adults: whose accounts they are is not known, so none.
        $other = \local_oksigeniafamily\api::create_family('csv');
        \local_oksigeniafamily\api::add_adult($other, ['firstname' => 'Marta', 'phone' => '+34600000001', 'phoneverified' => true]);
        $this->assertSame([], inbox::context('+34600000001')['accounts']);
    }
}
