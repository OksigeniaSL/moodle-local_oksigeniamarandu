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

namespace local_oksigeniamarandu\task;

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\cloud_api;

/**
 * Tests of the replies to opt-ins and opt-outs. Meta is never called: a fake keeps what would be sent.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\task\send_reply
 */
#[\PHPUnit\Framework\Attributes\CoversClass(send_reply::class)]
final class send_reply_test extends \advanced_testcase {
    /**
     * Runs a reply with a fake client and returns what it sent.
     *
     * @param string $phone Digits with the country code.
     * @param string $reply The reply.
     * @param bool $fail Whether Meta refuses it.
     * @return array The texts sent, by number.
     */
    private function run_reply(string $phone, string $reply, bool $fail = false): array {
        $client = new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was sent. */
            public $sent = [];

            /** @var bool Whether Meta refuses. */
            public $fail = false;

            /**
             * Keeps the text instead of sending it.
             *
             * @param string $to Number.
             * @param string $text Text.
             * @return string
             */
            public function send_text(string $to, string $text): string {
                if ($this->fail) {
                    throw new api_exception('error:token');
                }
                $this->sent[] = [$to, $text];
                return 'wamid.FAKE';
            }
        };
        $client->fail = $fail;
        $task = new class extends send_reply {
            /** @var cloud_api The fake client. */
            public $client;

            /**
             * The fake client.
             *
             * @return cloud_api
             */
            protected function api(): cloud_api {
                return $this->client;
            }
        };
        $task->client = $client;
        $task->set_custom_data(['phone' => $phone, 'reply' => $reply]);
        $task->execute();
        return $client->sent;
    }

    /**
     * The reply names the site and the word to undo it.
     */
    public function test_texts(): void {
        $this->resetAfterTest();
        $sent = $this->run_reply('34600000001', 'optin');
        $this->assertCount(1, $sent);
        $this->assertSame('34600000001', $sent[0][0]);
        $this->assertStringContainsString(format_string(get_site()->fullname), $sent[0][1]);
        $this->assertStringContainsString('BAJA', $sent[0][1]);

        $sent = $this->run_reply('34600000001', 'optout');
        $this->assertStringContainsString('ALTA', $sent[0][1]);
    }

    /**
     * A reply that is not one of the known ones is never sent, and a refusal of Meta does not throw.
     */
    public function test_unknown_reply_and_refusal(): void {
        $this->resetAfterTest();
        $this->assertSame([], $this->run_reply('34600000001', 'anything'));

        $this->expectOutputRegex('/the reply was not sent/');
        $this->assertSame([], $this->run_reply('34600000001', 'optin', true));
    }

    /**
     * Queuing keeps the number and the reply for the task.
     */
    public function test_queue(): void {
        $this->resetAfterTest();
        send_reply::queue('34600000001', 'unknowncode');
        $tasks = \core\task\manager::get_adhoc_tasks(send_reply::class);
        $this->assertCount(1, $tasks);
        $this->assertEquals((object)['phone' => '34600000001', 'reply' => 'unknowncode'], reset($tasks)->get_custom_data());
    }
}
