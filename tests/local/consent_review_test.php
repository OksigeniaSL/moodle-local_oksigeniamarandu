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
use local_oksigeniamarandu\hook\answer_received;

/**
 * What a person said no to, the codes that join them and the questions that close, after the review of 21 November
 * 2026. Numbers and chats are made up; nothing reaches Meta or Telegram.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\consent
 */
#[\PHPUnit\Framework\Attributes\CoversClass(consent::class)]
final class consent_review_test extends \advanced_testcase {
    /** @var answer_received[] What the plugins heard. */
    private $heard = [];

    /**
     * A question of the plugin, and no quiet hours.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/oksigeniamarandu/tests/fixtures/string_manager.php');
        \local_oksigeniamarandu_test_string_manager::use_with(['local_oksigeniamarandu' => [
            'marandu:question' => 'Permission',
            'marandu:question_desc' => 'A permission',
            'marandu:question_template' => '{{1}} asks: {{2}}.',
            'marandu:question_button' => 'Read',
            'marandu:question_option_yes' => 'Yes',
            'marandu:question_option_no' => 'No',
        ]]);
        notices::declare_for_tests(['local_oksigeniamarandu' => ['question' => [
            'examples' => ['Lucas', 'outing'],
            'options' => ['yes', 'no'],
        ]]]);
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('telegramnotices', 'local_oksigeniamarandu/question', 'local_oksigeniamarandu');
        set_config('quietfrom', '', 'local_oksigeniamarandu');
        set_config('quietto', '', 'local_oksigeniamarandu');
        set_config('quietdays', '', 'local_oksigeniamarandu');
        $this->heard = [];
        $this->redirectHook(answer_received::class, function (answer_received $hook) {
            $this->heard[] = $hook;
        });
    }

    /**
     * The tests forget their question.
     */
    protected function tearDown(): void {
        notices::declare_for_tests([]);
        \local_oksigeniamarandu_test_string_manager::use_standard();
        parent::tearDown();
    }

    /**
     * A bot of Telegram that keeps what it is asked.
     *
     * @return telegram_api
     */
    private function bot(): telegram_api {
        return new class ('123456789:fake') extends telegram_api {
            /** @var array The methods called. */
            public $calls = [];

            /**
             * Keeps it and answers as Telegram.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->calls[] = [$method, json_decode($body, true)];
                return '{"ok":true,"result":{"message_id":' . (40 + count($this->calls)) . '}}';
            }
        };
    }

    /**
     * Joining with the code of another plugin keeps the «no» said to the first one.
     */
    public function test_another_code_keeps_the_other_no(): void {
        global $DB;
        consent::optin_from_message('tg:602', api::invite('local_oksigeniaeguahe', 7), 'join');
        api::set_optout('local_oksigeniaeguahe', 7, 'local_oksigeniaeguahe', true, 'eguahe:family');
        consent::optin_from_message('tg:602', api::invite('local_oksigeniameals', 9), 'join2');
        $id = $DB->get_field(consent::CONTACTS, 'id', ['phone' => 'tg:602']);
        $this->assertTrue($DB->record_exists(consent::OPTOUTS, ['contactid' => $id, 'scope' => 'local_oksigeniaeguahe']));
    }

    /**
     * A text to every chat of an item skips the one that said no to the plugin; to that chat by its address, it goes.
     */
    public function test_text_to_all_skips_who_said_no(): void {
        consent::optin_from_message('tg:601', api::invite('local_oksigeniaeguahe', 7), 'join');
        api::set_optout('local_oksigeniaeguahe', 7, 'local_oksigeniaeguahe', true, 'eguahe:family', 'telegram');
        $bot = $this->bot();
        $this->assertSame([], conversation::send('local_oksigeniaeguahe', 7, 'Hello', null, '', null, null, $bot));
        $this->assertCount(1, conversation::send('local_oksigeniaeguahe', 7, 'Hello', null, '', 'tg:601', null, $bot));
    }

    /**
     * A code for a while is always a new one and stops working when its time is over; the code of a user lasts a day,
     * and the user hears that a chat joined their account.
     */
    public function test_codes_that_expire(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $first = api::invite('factor_oksigeniaaty', 0, (int)$user->id, '', 600);
        $second = api::invite('factor_oksigeniaaty', 0, (int)$user->id, '', 600);
        $this->assertNotSame($first, $second);
        $this->assertSame('unknowncode', consent::optin_from_message('tg:610', $first, 'join'));
        $DB->set_field(consent::LINKS, 'timeexpires', time() - 1, ['code' => $second]);
        $this->assertSame('unknowncode', consent::optin_from_message('tg:611', $second, 'join'));

        $code = api::invite('message_oksigeniamarandu', 0, (int)$user->id);
        $this->assertSame($code, api::invite('message_oksigeniamarandu', 0, (int)$user->id));
        $sink = $this->redirectMessages();
        $this->assertSame('optin', consent::optin_from_message('tg:612', $code, 'join'));
        $this->assertSame(['newchat'], array_column($sink->get_messages(), 'eventtype'));
        $sink->close();
        $old = api::invite('message_oksigeniamarandu', 0, (int)$user->id);
        $DB->set_field(consent::LINKS, 'timeexpires', time() - 1, ['code' => $old]);
        $this->assertNotSame($old, api::invite('message_oksigeniamarandu', 0, (int)$user->id));
        $this->assertSame('unknowncode', consent::optin_from_message('tg:613', $old, 'join'));

        $chats = api::own_chats('message_oksigeniamarandu', (int)$user->id);
        $this->assertSame(['telegram'], array_column($chats, 'channel'));
        $this->assertTrue(api::unlink_chat('message_oksigeniamarandu', (int)$user->id, $chats[0]['id']));
        $this->assertSame([], api::own_chats('message_oksigeniamarandu', (int)$user->id));
    }

    /**
     * A question closed while the queue is sending stays closed: a late answer does not count, and its button says so.
     */
    public function test_closed_while_sending(): void {
        global $DB;
        consent::optin_from_message('tg:601', api::invite('local_oksigeniamarandu', 7), 'join');
        consent::optin_from_message('tg:602', api::invite('local_oksigeniamarandu', 8), 'join');
        api::ask('local_oksigeniamarandu', 'question', ['Lucas', 'outing'], 'first', 7);
        api::ask('local_oksigeniamarandu', 'question', ['Ana', 'login'], 'second', 8);
        $bot = new class ('123456789:fake') extends telegram_api {
            /** @var int How many went. */
            public $n = 0;

            /**
             * While the first goes, the plugin closes the second.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                if ($method === 'sendMessage' && $this->n++ === 0) {
                    api::close_question('local_oksigeniamarandu', 'second');
                }
                return '{"ok":true,"result":{"message_id":' . (10 + $this->n) . '}}';
            }
        };
        queue::process(null, time(), $bot);
        $row = $DB->get_record(queue::TABLE, ['ref' => 'second'], '*', MUST_EXIST);
        $this->assertNotSame(0, (int)$row->timeclosed);
        $this->assertNotSame('sent', $row->status);
        $answer = telegram::handle(['callback_query' => ['id' => 'cb2', 'data' => 'a:' . $row->token . ':yes',
            'from' => ['id' => 602], 'message' => ['message_id' => 12, 'chat' => ['id' => 602, 'type' => 'private']]]]);
        $this->assertSame([], $this->heard);
        $this->assertSame('editMessageText', $answer['method'] ?? '');
    }
}
