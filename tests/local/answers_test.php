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
 * Tests for the questions with buttons and their answers. Made-up numbers, chats, people and question; nothing reaches
 * Meta or Telegram.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\answers
 */
#[\PHPUnit\Framework\Attributes\CoversClass(answers::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(queue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(templates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(answer_received::class)]
final class answers_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid, out of the quiet hours. */
    private const WEDNESDAY = 1793181600;

    /** @var answer_received[] The answers the plugin heard of. */
    private $heard = [];

    /**
     * A question declared as a plugin would, with its texts, and the site sending it on both channels.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        // The texts of the question, as a language pack of the site would have them.
        require_once($CFG->dirroot . '/local/oksigeniamarandu/tests/fixtures/string_manager.php');
        \local_oksigeniamarandu_test_string_manager::use_with(['local_oksigeniamarandu' => [
            'marandu:question' => 'Permission',
            'marandu:question_desc' => 'A permission for an outing',
            'marandu:question_template' => '{{1}} asks for your permission: {{2}}. Answer with a button.',
            'marandu:question_button' => 'Read the letter',
            'marandu:question_option_yes' => 'I authorise',
            'marandu:question_option_no' => 'I do not authorise',
        ]]);
        notices::declare_for_tests(['local_oksigeniamarandu' => ['question' => [
            'examples' => ['Lucas', 'outing to the museum'],
            'options' => ['yes', 'no', 'Not valid', 'yes'],
        ]]]);
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('enablednotices', 'local_oksigeniamarandu/question', 'local_oksigeniamarandu');
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
     * A client of WhatsApp that keeps what it sends.
     *
     * @return cloud_api
     */
    private function whatsapp(): cloud_api {
        return new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was posted. */
            public $posted = [];

            /**
             * Keeps it and answers as Meta.
             *
             * @param string $path Where.
             * @param array $payload What.
             * @return array
             */
            protected function post(string $path, array $payload): array {
                $this->posted[] = $payload;
                if (str_ends_with($path, 'message_templates')) {
                    return ['id' => '9001', 'status' => 'APPROVED', 'category' => 'UTILITY'];
                }
                return ['messages' => [['id' => 'wamid.Q' . count($this->posted)]]];
            }
        };
    }

    /**
     * A bot of Telegram that keeps what it sends.
     *
     * @return telegram_api
     */
    private function telegram(): telegram_api {
        return new class ('123456789:fake') extends telegram_api {
            /** @var array The methods called, with their parameters. */
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
                return '{"ok":true,"result":{"message_id":5}}';
            }
        };
    }

    /**
     * Somebody joins for the made-up item 7 of the plugin.
     *
     * @param string $address A number, or tg: and a chat.
     * @param bool $wrotelately Whether they wrote in the last 24 hours.
     * @return \stdClass The contact.
     */
    private function join(string $address, bool $wrotelately = false): \stdClass {
        global $DB;
        consent::optin_from_message($address, api::invite('local_oksigeniamarandu', 7), 'join');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address], '*', MUST_EXIST);
        $DB->set_field(
            consent::CONTACTS,
            'lastinbound',
            $wrotelately ? self::WEDNESDAY - HOURSECS : self::WEDNESDAY - 3 * DAYSECS,
            ['id' => $contact->id]
        );
        return $contact;
    }

    /**
     * What Meta sends to the webhook when somebody writes.
     *
     * @param array $message The message.
     * @return array
     */
    private function webhook(array $message): array {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1110000000'],
            'contacts' => [['wa_id' => $message['from'], 'profile' => ['name' => 'Ana Ejemplo']]],
            'messages' => [$message],
        ]]]]]];
    }

    /**
     * The answers of a question, as declared: short keys, without repeats, three at most; its template has them as quick
     * replies before the link.
     */
    public function test_declared(): void {
        $notice = notices::get('local_oksigeniamarandu', 'question');
        $this->assertSame(['yes', 'no'], $notice->options);
        $this->assertSame([], notices::get('local_oksigeniamarandu', 'notification')->options);
        $this->assertSame('I do not authorise', notices::option_label($notice, 'no'));

        $buttons = templates::payload($notice, 'en')['components'][1]['buttons'];
        $this->assertSame(['QUICK_REPLY', 'QUICK_REPLY', 'URL'], array_column($buttons, 'type'));
        $this->assertSame(['I authorise', 'I do not authorise'], array_column(array_slice($buttons, 0, 2), 'text'));
        $buttons = templates::payload(notices::get('local_oksigeniamarandu', 'notification'), 'en')['components'][1]['buttons'];
        $this->assertSame(['URL'], array_column($buttons, 'type'));
    }

    /**
     * Only a question can be asked, and with a reference.
     */
    public function test_ask_refuses(): void {
        try {
            api::ask('local_oksigeniamarandu', 'notification', ['Colegio Ejemplo', 'Hola'], 'ref', 7);
            $this->fail('A notice without answers is no question');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('no options', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        api::ask('local_oksigeniamarandu', 'question', ['Lucas', 'outing'], '', 7);
    }

    /**
     * Outside the 24 hours, the template with the answers before the link; tapping one comes back with the id of the
     * message and its label. Only the number it went to counts, once for each answer, and what the plugin says goes back.
     */
    public function test_whatsapp_template(): void {
        global $DB;
        $api = $this->whatsapp();
        $notice = notices::get('local_oksigeniamarandu', 'question');
        templates::submit($api, '2220000000', $notice, 'en');
        templates::update_from_meta(notices::template_name($notice), 'en', 'APPROVED');
        $this->join('34600000001');
        $this->join('34600000002');
        $this->assertSame(2, api::ask(
            'local_oksigeniamarandu',
            'question',
            ['Lucas', 'outing to the museum'],
            'request5:child9',
            7,
            null,
            new \moodle_url('/local/oksigeniamarandu/index.php'),
            DAYSECS
        ));
        $api->posted = [];
        $this->assertSame(2, queue::process($api, self::WEDNESDAY)['sent']);
        $button = $api->posted[0]['template']['components'][1];
        $this->assertSame(['url', '2'], [$button['sub_type'], $button['index']]);
        $row = $DB->get_record(queue::TABLE, ['wamid' => 'wamid.Q1']);
        $this->assertSame(['request5:child9', 7], [$row->ref, (int)$row->itemid]);

        $tap = ['from' => '34600000001', 'id' => 'wamid.A1', 'timestamp' => (string)(self::WEDNESDAY + 60), 'type' => 'button',
            'context' => ['from' => '34900000000', 'id' => 'wamid.Q1'],
            'button' => ['payload' => 'I do not authorise', 'text' => 'I do not authorise']];
        $this->redirectHook(answer_received::class, function (answer_received $hook) {
            $this->heard[] = $hook;
            $hook->set_reply('Done: you do not authorise the outing of Lucas.');
        });
        inbound::handle($this->webhook($tap));
        $this->assertCount(1, $this->heard);
        $hook = $this->heard[0];
        $this->assertSame(
            ['local_oksigeniamarandu', 'question', 'request5:child9', 7, 'no', 'whatsapp', '+34600000001', self::WEDNESDAY + 60],
            [$hook->get_component(), $hook->get_notice(), $hook->get_ref(), $hook->get_itemid(), $hook->get_option(),
                $hook->get_channel(), $hook->get_address(), $hook->get_time()]
        );
        $answer = $DB->get_record(answers::TABLE, []);
        $this->assertSame(['no', 'wamid.A1', 'wamid.Q1'], [$answer->choice, $answer->messageid, $answer->contextid]);
        $tasks = \core\task\manager::get_adhoc_tasks('\local_oksigeniamarandu\task\send_reply');
        $this->assertSame('Done: you do not authorise the outing of Lucas.', reset($tasks)->get_custom_data()->text);

        // Meta sends the same webhook again: nothing new. Another number with the same message: nothing.
        inbound::handle($this->webhook($tap));
        inbound::handle($this->webhook(['from' => '34600000002', 'id' => 'wamid.A2'] + $tap));
        $this->assertCount(1, $this->heard);
        $this->assertSame(1, $DB->count_records(answers::TABLE));

        // The other button later: another answer.
        inbound::handle($this->webhook(['id' => 'wamid.A3', 'button' => ['payload' => 'I authorise', 'text' => 'I authorise']]
            + $tap));
        $this->assertSame('yes', $this->heard[1]->get_option());
    }

    /**
     * Inside the 24 hours, reply buttons without a template; without a reply of the plugin, a short acknowledgement.
     */
    public function test_whatsapp_reply_buttons(): void {
        global $DB;
        $this->join('34600000001', true);
        api::ask('local_oksigeniamarandu', 'question', ['Lucas', 'outing to the museum'], 'request5:child9', 7);
        $api = $this->whatsapp();
        $this->assertSame(1, queue::process($api, self::WEDNESDAY)['sent']);
        $interactive = $api->posted[0]['interactive'];
        $row = $DB->get_record(queue::TABLE, []);
        $this->assertSame('button', $interactive['type']);
        $this->assertSame(
            ['a:' . $row->token . ':yes', 'a:' . $row->token . ':no'],
            array_column(array_column($interactive['action']['buttons'], 'reply'), 'id')
        );

        inbound::handle($this->webhook(['from' => '34600000001', 'id' => 'wamid.B1', 'timestamp' => (string)self::WEDNESDAY,
            'type' => 'interactive', 'context' => ['id' => $row->wamid],
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'a:' . $row->token . ':yes',
            'title' => 'I authorise']]]));
        $this->assertSame('yes', $this->heard[0]->get_option());
        $tasks = \core\task\manager::get_adhoc_tasks('\local_oksigeniamarandu\task\send_reply');
        $this->assertSame(
            get_string('answer:received', 'local_oksigeniamarandu', 'I authorise'),
            reset($tasks)->get_custom_data()->text
        );

        // An id that is not of a question that went out is a message like any other.
        inbound::handle($this->webhook(['from' => '34600000001', 'id' => 'wamid.B2', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'a:ZZZZZZZZZZ:yes', 'title' => 'x']]]));
        $this->assertCount(1, $this->heard);
    }

    /**
     * On Telegram, a button for each answer above the link; only the chat it went to counts, and the reply goes at once.
     */
    public function test_telegram(): void {
        global $DB;
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'marandu_example_bot', 'local_oksigeniamarandu');
        $this->join('tg:555');
        api::ask(
            'local_oksigeniamarandu',
            'question',
            ['Lucas', 'outing to the museum'],
            'request5:child9',
            7,
            null,
            new \moodle_url('/local/oksigeniamarandu/index.php')
        );
        $bot = $this->telegram();
        $this->assertSame(1, queue::process(null, self::WEDNESDAY, $bot)['sent']);
        [$method, $params] = $bot->calls[0];
        $row = $DB->get_record(queue::TABLE, []);
        $this->assertSame('sendMessage', $method);
        $keyboard = $params['reply_markup']['inline_keyboard'];
        $this->assertSame(['a:' . $row->token . ':yes', 'a:' . $row->token . ':no'], array_column($keyboard[0], 'callback_data'));
        $this->assertSame(templates::button_url($row->token), $keyboard[1][0]['url']);

        $press = function (int $chat) use ($row) {
            return ['callback_query' => ['id' => 'cb' . $chat, 'data' => 'a:' . $row->token . ':yes',
                'from' => ['id' => $chat], 'message' => ['message_id' => 5, 'chat' => ['id' => $chat, 'type' => 'private']]]];
        };
        $this->assertNull(telegram::handle($press(556), $bot));
        $this->assertSame([], $this->heard);
        $reply = telegram::handle($press(555), $bot);
        $this->assertSame(
            ['sendMessage', '555', 5],
            [$reply['method'], $reply['chat_id'], $reply['reply_parameters']['message_id']]
        );
        $this->assertSame(['yes', 'telegram', 'tg:555'], [$this->heard[0]->get_option(), $this->heard[0]->get_channel(),
            $this->heard[0]->get_address()]);
        $this->assertSame('answerCallbackQuery', end($bot->calls)[0]);
        $this->assertSame('tg.5', $DB->get_field(answers::TABLE, 'contextid', []));
    }

    /**
     * A question for one kind of item of a plugin, such as its kitchen: only whoever joined for it gets it, and the
     * answer comes back with the area.
     */
    public function test_area(): void {
        global $DB;
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'marandu_example_bot', 'local_oksigeniamarandu');
        $this->join('tg:555');
        consent::optin_from_message('tg:557', api::invite('local_oksigeniamarandu', 7, null, 'kitchen'), 'join');
        $count = api::ask(
            'local_oksigeniamarandu',
            'question',
            ['Lucas', 'outing to the museum'],
            'kitchen7',
            7,
            area: 'kitchen'
        );
        $this->assertSame(1, $count);
        $row = $DB->get_record(queue::TABLE, []);
        $this->assertSame('kitchen', $row->area);
        $bot = $this->telegram();
        $this->assertSame(1, queue::process(null, self::WEDNESDAY, $bot)['sent']);
        telegram::handle(['callback_query' => ['id' => 'cb557', 'data' => 'a:' . $row->token . ':no', 'from' => ['id' => 557],
            'message' => ['message_id' => 6, 'chat' => ['id' => 557, 'type' => 'private']]]], $bot);
        $this->assertSame(['no', 7, 'kitchen', 'kitchen7'], [$this->heard[0]->get_option(), $this->heard[0]->get_itemid(),
            $this->heard[0]->get_area(), $this->heard[0]->get_ref()]);
    }
}
