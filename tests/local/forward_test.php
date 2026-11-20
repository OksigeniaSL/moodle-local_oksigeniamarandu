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

use local_oksigeniamarandu\task\forward_webhook;

/**
 * Tests for what the webhook passes on to another address: made-up addresses and bodies.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\forward
 * @covers     \local_oksigeniamarandu\task\forward_webhook
 */
#[\PHPUnit\Framework\Attributes\CoversClass(forward::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(forward_webhook::class)]
final class forward_test extends \advanced_testcase {
    /** A body as Meta sends it. */
    private const BODY = '{"object":"whatsapp_business_account","entry":[]}';

    /** Its signature header. */
    private const SIGNATURE = 'sha256=0123456789abcdef';

    /**
     * Every test leaves the site as it was.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Someone who sends as told and keeps what it sent.
     *
     * @param bool $takes Whether the address takes it.
     * @return forward
     */
    private function sender(bool $takes): forward {
        $sender = new class extends forward {
            /** @var bool Whether the address takes it. */
            public $takes = true;

            /** @var array What was sent, as [url, body, signature]. */
            public $sent = [];

            /**
             * Keeps what it would send.
             *
             * @param string $url The address.
             * @param string $raw The body.
             * @param string $signature Its signature header.
             * @return bool
             */
            public function send(string $url, string $raw, string $signature): bool {
                $this->sent[] = [$url, $raw, $signature];
                return $this->takes;
            }
        };
        $sender->takes = $takes;
        return $sender;
    }

    /**
     * Without an address, nothing goes anywhere; with one, the body goes as it came, with its signature.
     */
    public function test_deliver(): void {
        $sender = $this->sender(true);
        $this->assertFalse(forward::deliver(self::BODY, self::SIGNATURE, $sender));
        $this->assertSame([], $sender->sent);

        set_config('forwardurl', ' https://inbox.example.com/webhooks/whatsapp/+15550000000 ', 'local_oksigeniamarandu');
        $this->assertTrue(forward::deliver(self::BODY, self::SIGNATURE, $sender));
        $this->assertSame(
            [['https://inbox.example.com/webhooks/whatsapp/+15550000000', self::BODY, self::SIGNATURE]],
            $sender->sent
        );
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(forward_webhook::class));
    }

    /**
     * What the address does not take at once waits in a task, which fails while the address does not take it, so that
     * Moodle tries it again, and does nothing once the site stops passing things on.
     */
    public function test_later(): void {
        // A port nobody listens on, on the same machine: it says no at once.
        set_config('forwardurl', 'http://127.0.0.1:9/', 'local_oksigeniamarandu');
        $this->assertFalse(forward::deliver(self::BODY, self::SIGNATURE, $this->sender(false)));
        $tasks = \core\task\manager::get_adhoc_tasks(forward_webhook::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertEquals((object)['body' => self::BODY, 'signature' => self::SIGNATURE], $task->get_custom_data());

        try {
            $task->execute();
            $this->fail('An address that does not answer took it');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:forward', $e->errorcode);
        }

        set_config('forwardurl', '', 'local_oksigeniamarandu');
        $task->execute();
    }

    /**
     * The address is written as a web address, the plus of a phone number included.
     */
    public function test_address(): void {
        $url = 'https://inbox.example.com/webhooks/whatsapp/+15550000000';
        $this->assertSame($url, clean_param($url, PARAM_URL));
        $this->assertSame('', clean_param('javascript:alert(1)', PARAM_URL));
    }
}
