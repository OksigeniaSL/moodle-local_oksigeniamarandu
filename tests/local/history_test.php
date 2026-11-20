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

/**
 * Tests for the chats of a number that comes from the app of WhatsApp Business: made-up numbers and messages.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\history
 */
#[\PHPUnit\Framework\Attributes\CoversClass(history::class)]
final class history_test extends \advanced_testcase {
    /** The number of the business, as Meta writes it. */
    private const BUSINESS = '34600000900';

    /**
     * A site with a number.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('phonenumberid', '1000000000', 'local_oksigeniamarandu');
    }

    /**
     * A change of the webhook, as Meta sends it.
     *
     * @param string $field The field.
     * @param array $value Its value, without the metadata.
     * @return array
     */
    private function payload(string $field, array $value): array {
        $value['messaging_product'] = 'whatsapp';
        $value['metadata'] = ['display_phone_number' => self::BUSINESS, 'phone_number_id' => '2000000000'];
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '3000000000', 'changes' => [
            ['field' => $field, 'value' => $value],
        ]]]];
    }

    /**
     * A message of a chat.
     *
     * @param string $id Its id.
     * @param string $from Who wrote it.
     * @param int $at When.
     * @param string $text Its text.
     * @return array
     */
    private function message(string $id, string $from, int $at, string $text): array {
        return ['from' => $from, 'id' => $id, 'timestamp' => (string)$at, 'type' => 'text', 'text' => ['body' => $text],
            'history_context' => ['status' => 'read']];
    }

    /**
     * The pieces come in any order and twice; the file has each chat once, in order, with names and who wrote.
     */
    public function test_export(): void {
        $own = null;
        inbound::handle($this->payload('smb_app_state_sync', ['state_sync' => [
            ['type' => 'contact', 'contact' => ['full_name' => 'Marta Gil', 'phone_number' => '34600000101'], 'action' => 'add'],
            ['type' => 'contact', 'contact' => ['full_name' => 'Pedro Sanz', 'phone_number' => '34600000102'], 'action' => 'add'],
            ['type' => 'contact', 'contact' => ['phone_number' => '34600000102'], 'action' => 'remove'],
        ]]), $own);
        $this->assertFalse($own);
        $late = ['history' => [['metadata' => ['phase' => 1, 'chunk_order' => 2, 'progress' => 100], 'threads' => [
            ['id' => '34600000101', 'messages' => [
                $this->message('wamid.B', self::BUSINESS, 200, 'Mañana se lo damos.'),
                $this->message('wamid.A', '34600000101', 100, '¿Tienen el certificado?'),
            ]],
        ]]]];
        $early = ['history' => [['metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 40], 'threads' => [
            ['id' => '34600000103', 'messages' => [
                $this->message('wamid.C', '34600000103', 50, 'Hola'),
                ['from' => '34600000103', 'id' => 'wamid.D', 'timestamp' => '60', 'type' => 'media_placeholder'],
            ]],
            ['id' => '34600000101', 'messages' => [$this->message('wamid.A', '34600000101', 100, '¿Tienen el certificado?')]],
        ]]]];
        inbound::handle($this->payload('history', $late));
        inbound::handle($this->payload('history', $early));

        $export = history::export()['2000000000'];
        $this->assertSame(self::BUSINESS, $export['business']);
        $this->assertSame([['phone' => '34600000101', 'name' => 'Marta Gil']], $export['contacts']);
        $this->assertSame(['34600000103', '34600000101'], array_column($export['threads'], 'phone'));
        $marta = $export['threads'][1];
        $this->assertSame('Marta Gil', $marta['name']);
        $this->assertSame(['wamid.A', 'wamid.B'], array_column($marta['messages'], 'id'));
        $this->assertSame([false, true], array_column($marta['messages'], 'from_me'));
        $this->assertSame('Attachment that was not kept', $export['threads'][0]['messages'][1]['text']);

        $status = history::status()['2000000000'];
        $this->assertSame([3, 3, 2, 4, false], [$status['pieces'], $status['contacts'], $status['threads'],
            $status['messages'], $status['declined']]);
        $this->assertSame([1 => 100, 0 => 40], $status['progress']);
        $this->assertSame(3, history::forget('2000000000'));
        $this->assertSame([], history::export());
    }

    /**
     * When the business does not share its chats, it says so.
     */
    public function test_declined(): void {
        inbound::handle($this->payload('history', ['history' => [['errors' => [['code' => history::DECLINED]]]]]));
        $this->assertTrue(history::status()['2000000000']['declined']);
    }
}
