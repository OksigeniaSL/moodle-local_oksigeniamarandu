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
     * @param array|null $data Other data for the task, instead of the reply.
     * @return array The texts sent, by number, with the image if it went with one.
     */
    private function run_reply(string $phone, string $reply, bool $fail = false, ?array $data = null): array {
        $client = new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was sent. */
            public $sent = [];

            /** @var bool Whether Meta refuses. */
            public $fail = false;

            /** @var int Images uploaded. */
            public $uploads = 0;

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

            /**
             * Keeps the image instead of uploading it.
             *
             * @param string $bytes The image.
             * @param string $name Its name.
             * @return string
             */
            public function upload_media(string $bytes, string $name): string {
                if ($this->fail) {
                    throw new api_exception('error:token');
                }
                $this->uploads++;
                return 'media.' . strlen($bytes);
            }

            /**
             * Keeps the image message instead of sending it.
             *
             * @param string $to Number.
             * @param string $mediaid The image.
             * @param string $caption Text.
             * @return string
             */
            public function send_image(string $to, string $mediaid, string $caption): string {
                $this->sent[] = [$to, $caption, $mediaid];
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
        $task->set_custom_data($data ?? ['phone' => $phone, 'reply' => $reply]);
        $task->execute();
        return $client->sent;
    }

    /**
     * The reply names the site and the word to undo it (the short replies, without the confirmations).
     */
    public function test_texts(): void {
        $this->resetAfterTest();
        set_config('confirmcards', 0, 'local_oksigeniamarandu');
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

        $this->expectOutputRegex('/was not sent/');
        $this->assertSame([], $this->run_reply('34600000001', 'optin', true));
    }

    /**
     * The confirmations: joining goes with its card and the warm text; a second code is not welcomed twice; leaving says
     * the door is open; a change of channel names the channels. Without GD, the text alone.
     */
    public function test_confirmations(): void {
        $this->resetAfterTest();
        $code = \local_oksigeniamarandu\api::invite('core', 0, (int)$this->getDataGenerator()->create_user()->id);
        $this->assertSame('optin', \local_oksigeniamarandu\local\consent::optin_from_message('34600000001', $code, 'join'));
        $sent = $this->run_reply('34600000001', 'optin');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('How lovely to have you here', $sent[0][1]);
        $this->assertStringContainsString('BAJA', $sent[0][1]);
        if (\local_oksigeniamarandu\local\cards::can_draw()) {
            $this->assertStringStartsWith('media.', $sent[0][2]);
        }

        // The opt-in word again: already in, the short reply.
        \local_oksigeniamarandu\local\consent::optin_from_message('34600000001', null, 'again');
        $sent = $this->run_reply('34600000001', 'optin');
        $this->assertStringContainsString('To stop them', $sent[0][1]);
        $this->assertCount(2, $sent[0]);

        $sent = $this->run_reply('34600000001', 'optout');
        $this->assertStringContainsString('we will not send you notices here any more', $sent[0][1]);
        $this->assertStringContainsString('just ask', $sent[0][1]);

        $sent = $this->run_reply('34600000001', '', false, ['phone' => '34600000001', 'confirm' => 'away',
            'others' => ['telegram', 'email']]);
        $this->assertStringContainsString('by Telegram and email', $sent[0][1]);
        $message = \local_oksigeniamarandu\local\confirm::build('away', 'whatsapp', null, ['email']);
        if ($message['card']) {
            $this->assertSame('channel_changed_mail', $message['card']['type']);
            $this->assertSame('By email', $message['card']['zones']['channel']);
            $this->assertNotEmpty(\local_oksigeniamarandu\local\confirm::image($message['card']));
        }
        // The message that joins, as a link writes it: a greeting and the code between brackets.
        $this->assertSame(
            'Hello, we would like to get the notices of PHPUnit test site here. (K7M2Q9XA)',
            \local_oksigeniamarandu\local\keywords::join_text('K7M2Q9XA')
        );
        $this->assertSame(
            'Hola, somos la familia de Leo. (K7M2Q9XA)',
            \local_oksigeniamarandu\local\keywords::join_text('K7M2Q9XA', " Hola, somos\n la familia de Leo. ")
        );

        // Letters the font has not, such as a name in Arabic: the text alone.
        set_config('cardorg', 'مدرسة النور', 'local_oksigeniamarandu');
        $this->assertNull(\local_oksigeniamarandu\local\confirm::build('joined', 'telegram')['card']);
    }

    /**
     * A plugin confirms a change of channels: by the one taken, «here»; by the one left, «by …»; WhatsApp only inside the
     * 24 hours; nothing for a channel that did not change.
     */
    public function test_confirm_channel(): void {
        global $DB;
        $this->resetAfterTest();
        foreach (['34600000001', 'tg:555'] as $address) {
            \local_oksigeniamarandu\local\consent::optin_from_message(
                $address,
                \local_oksigeniamarandu\api::invite('local_oksigeniamarandu', 7),
                'join'
            );
        }
        $tasks = function (): array {
            $list = [];
            foreach (\core\task\manager::get_adhoc_tasks(send_reply::class) as $task) {
                $data = $task->get_custom_data();
                if (isset($data->confirm)) {
                    $list[$data->phone] = [$data->confirm, $data->others];
                }
            }
            return $list;
        };
        // From WhatsApp to Telegram and email.
        $count = \local_oksigeniamarandu\api::confirm_channel('local_oksigeniamarandu', 7, ['telegram', 'email'], ['whatsapp']);
        $this->assertSame(2, $count);
        $this->assertEquals(['34600000001' => ['away', ['telegram', 'email']], 'tg:555' => ['here', ['whatsapp']]], $tasks());

        // WhatsApp outside the 24 hours: only Telegram.
        $DB->delete_records('task_adhoc', ['classname' => '\\' . send_reply::class]);
        $DB->set_field(
            \local_oksigeniamarandu\local\consent::CONTACTS,
            'lastinbound',
            time() - 2 * DAYSECS,
            ['phone' => '34600000001']
        );
        $this->assertSame(1, \local_oksigeniamarandu\api::confirm_channel(
            'local_oksigeniamarandu',
            7,
            [],
            ['whatsapp', 'telegram']
        ));
        $this->assertEquals(['tg:555' => ['out', []]], $tasks());

        // Nothing changed for these channels; or the confirmations are off.
        $this->assertSame(0, \local_oksigeniamarandu\api::confirm_channel('local_oksigeniamarandu', 7, ['email'], []));
        set_config('confirmcards', 0, 'local_oksigeniamarandu');
        $this->assertSame(0, \local_oksigeniamarandu\api::confirm_channel('local_oksigeniamarandu', 7, [], ['telegram']));
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
