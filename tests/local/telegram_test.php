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
 * Tests for Telegram, the second channel. A fake bot answers for Telegram; made-up chats and people.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\telegram
 */
#[\PHPUnit\Framework\Attributes\CoversClass(telegram::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(telegram_api::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(queue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class telegram_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid, out of the quiet hours. */
    private const WEDNESDAY = 1793181600;

    /**
     * A site with a bot, Madrid time, and the general notice switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->redirectMessages();
        $this->redirectEmails();
        $this->setTimezone('Europe/Madrid');
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'marandu_example_bot', 'local_oksigeniamarandu');
        set_config('telegramsecret', 'the-secret', 'local_oksigeniamarandu');
        set_config('enablednotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
    }

    /**
     * A fake bot: keeps what it would send, or fails with the code of Telegram it is given.
     *
     * @param array $errors The codes to fail with, in turn (negative, as the client throws them); then it sends.
     * @return telegram_api
     */
    private function bot(array $errors = []): telegram_api {
        $bot = new class ('123456789:fake') extends telegram_api {
            /** @var array What was sent. */
            public $sent = [];

            /** @var array The errors to answer with, in turn. */
            public $errors = [];

            /**
             * The bot.
             *
             * @return array
             */
            public function get_me(): array {
                return ['first_name' => 'Marandu Ejemplo', 'username' => 'marandu_example_bot'];
            }

            /**
             * The webhook is taken.
             *
             * @param string $url Where.
             * @param string $secret The secret.
             */
            public function set_webhook(string $url, string $secret): void {
                $this->sent[] = ['webhook', $url, $secret];
            }

            /**
             * Keeps the text, or fails.
             *
             * @param string $chatid The chat.
             * @param string $text The text.
             * @param string|null $url The link of the button.
             * @param string $label The label of the button.
             * @return string
             */
            public function send_text(string $chatid, string $text, ?string $url = null, string $label = ''): string {
                $code = array_shift($this->errors);
                if ($code !== null) {
                    throw new api_exception('error:telegram', (object)['code' => -$code, 'message' => 'Fake'], $code);
                }
                $this->sent[] = [$chatid, $text, $url, $label];
                return (string)count($this->sent);
            }
        };
        $bot->errors = $errors;
        return $bot;
    }

    /**
     * What a person writes to the bot from a private chat.
     *
     * @param int $chat The chat.
     * @param string $text What they write.
     * @return array The update.
     */
    private function message(int $chat, string $text): array {
        return ['message' => ['message_id' => 7, 'chat' => ['id' => $chat, 'type' => 'private'],
            'from' => ['first_name' => 'Ana', 'last_name' => 'Ejemplo'], 'text' => $text]];
    }

    /**
     * Opening the bot with a code joins the chat for whom the code stands; «/stop» and blocking the bot opt it out; a
     * group does not count.
     */
    public function test_start_stop_and_block(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = api::invite('core', 0, (int)$user->id);
        $this->assertTrue(telegram::handle($this->message(555, '/start ' . $code)));

        $contact = $DB->get_record(consent::CONTACTS, ['phone' => 'tg:555']);
        $this->assertSame(['telegram', 'active', 'Ana Ejemplo'], [$contact->channel, $contact->status, $contact->name]);
        $this->assertTrue(api::user_joined((int)$user->id, 'telegram'));
        $this->assertFalse(api::user_joined((int)$user->id, 'whatsapp'));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\local_oksigeniamarandu\task\send_reply'));

        telegram::handle($this->message(555, '/stop'));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => 'tg:555']));
        telegram::handle($this->message(555, '/start'));
        $this->assertSame('active', $DB->get_field(consent::CONTACTS, 'status', ['phone' => 'tg:555']));
        telegram::handle(['my_chat_member' => ['chat' => ['id' => 555, 'type' => 'private'],
            'new_chat_member' => ['status' => 'kicked']]]);
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => 'tg:555']));

        $group = $this->message(-100, '/start ' . $code);
        $group['message']['chat']['type'] = 'group';
        $this->assertFalse(telegram::handle($group));
        $this->assertFalse(telegram::handle($this->message(556, 'hola')));
    }

    /**
     * A student's code of the family records works on Telegram too, without a phone.
     */
    public function test_family_code(): void {
        if (!class_exists('\local_oksigeniafamily\api')) {
            $this->markTestSkipped('local_oksigeniafamily is not installed');
        }
        $child = $this->getDataGenerator()->create_user();
        $code = \local_oksigeniafamily\api::issue_claim_code((int)$child->id);
        telegram::handle($this->message(557, '/start ' . $code));
        $adults = \local_oksigeniafamily\api::adults_of_child((int)$child->id);
        $this->assertCount(1, $adults);
        $this->assertEmpty(reset($adults)->phone);
        $this->assertSame(1, api::notify_child(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            (int)$child->id
        ));
    }

    /**
     * A notice to a chat goes as text with its link as a button; it waits while the bot is out, and a person who
     * blocked the bot is opted out.
     */
    public function test_queue(): void {
        global $DB;
        telegram::handle($this->message(555, '/start'));
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => 'tg:555']);
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $add = function () use ($contact, $notice) {
            return queue::add(
                (int)$contact->id,
                $notice,
                ['Colegio Ejemplo', 'Reunión el jueves'],
                'https://www.example.com/x',
                false,
                2 * DAYSECS,
                self::WEDNESDAY
            );
        };
        $id = $add();

        // Without the bot, it waits; WhatsApp does not matter to it.
        $this->assertSame(1, queue::process(null, self::WEDNESDAY)['waiting']);

        $bot = $this->bot();
        $this->assertSame(1, queue::process(null, self::WEDNESDAY, $bot)['sent']);
        [$chat, $text, $url, $label] = $bot->sent[0];
        $this->assertSame('555', $chat);
        $this->assertSame(queue::render($notice, ['Colegio Ejemplo', 'Reunión el jueves']), $text);
        $row = $DB->get_record(queue::TABLE, ['id' => $id]);
        $this->assertSame(templates::button_url($row->token), $url);
        $this->assertSame(notices::text($notice, 'button'), $label);
        $this->assertSame(['sent', 'telegram'], [$row->status, $row->kind]);
        $this->assertSame(0.0, queue::spent(self::WEDNESDAY));
        $this->assertSame(1, panel::month(self::WEDNESDAY)->telegram);

        // Too many messages: tried again later.
        $id = $add();
        queue::process(null, self::WEDNESDAY, $this->bot([-429]));
        $this->assertSame(1, (int)$DB->get_field(queue::TABLE, 'attempts', ['id' => $id]));

        // A token that is no longer valid stops Telegram, and only Telegram.
        queue::process(null, self::WEDNESDAY + HOURSECS, $this->bot([-401]));
        $this->assertSame(-401, queue::blocked('telegram')->code);
        $this->assertNull(queue::blocked('whatsapp'));
        $this->assertSame('blocked', panel::state(self::WEDNESDAY + HOURSECS)->kind ?? null);
        $bot = $this->bot();
        $this->assertSame(1, queue::process(null, self::WEDNESDAY + 2 * HOURSECS, $bot)['sent']);
        $this->assertNull(queue::blocked('telegram'));

        // The person blocked the bot: opted out, and the notice failed with that reason.
        $id = $add();
        queue::process(null, self::WEDNESDAY + 3 * HOURSECS, $this->bot([-403]));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['id' => $contact->id]));
        $this->assertSame('tgblocked', panel::why($DB->get_record(queue::TABLE, ['id' => $id])));
    }

    /**
     * Connecting the bot keeps its token encrypted and its name, and gives Telegram a secret that the webhook checks.
     */
    public function test_connect(): void {
        unset_config('telegrambot', 'local_oksigeniamarandu');
        $bot = $this->bot();
        $token = '123456789:' . str_repeat('B', 35);
        telegram::connect($bot, $token);

        $this->assertSame('marandu_example_bot', get_config('local_oksigeniamarandu', 'telegrambot'));
        $this->assertSame($token, \core\encryption::decrypt(get_config('local_oksigeniamarandu', 'telegramtoken')));
        [, $url, $secret] = $bot->sent[0];
        $this->assertStringEndsWith('/local/oksigeniamarandu/tgwebhook.php', $url);
        $this->assertTrue(telegram::verify_secret($secret));
        $this->assertFalse(telegram::verify_secret('not-it'));
        $this->assertSame('https://t.me/marandu_example_bot?start=K7M2Q9XA', telegram::join_url('K7M2Q9XA'));

        // The bot is known by the number before the colon: a token renewed with /revoke is the same bot.
        $this->assertSame('123456789', get_config('local_oksigeniamarandu', 'telegrambotid'));
        $this->assertTrue(telegram::is_same_bot(['id' => 123456789, 'username' => 'renamed_bot']));
        $this->assertFalse(telegram::is_same_bot(['id' => 987654321, 'username' => 'marandu_example_bot']));
    }

    /**
     * Changing to another bot: the chats of the old one wait for the new one, without opting out and still standing for
     * whom they stood; nothing goes out to them meanwhile; the old bot can tell them; a «/start» on the new one brings
     * each back.
     */
    public function test_switch_bot(): void {
        global $DB;
        $ana = $this->getDataGenerator()->create_user();
        $luis = $this->getDataGenerator()->create_user();
        telegram::handle($this->message(555, '/start ' . api::invite('core', 0, (int)$ana->id)));
        telegram::handle($this->message(556, '/start ' . api::invite('core', 0, (int)$luis->id)));
        telegram::handle($this->message(557, '/start'));
        telegram::handle($this->message(557, '/stop'));
        $this->assertCount(2, telegram::joined());

        $this->assertSame(2, telegram::switch_bot('marandu_new_bot', '123456789:' . str_repeat('A', 35)));
        $this->assertSame([], telegram::joined());
        $this->assertSame(['pending', 'pending', 'optedout'], array_values($DB->get_fieldset_select(
            consent::CONTACTS,
            'status',
            "channel = 'telegram' ORDER BY phone"
        )));
        $this->assertFalse(api::user_joined((int)$ana->id, 'telegram'));
        $this->assertEquals((object)['back' => 0, 'of' => 2, 'told' => true], telegram::returned());

        // Nothing goes out to a chat that waits: the new bot cannot write first.
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => 'tg:555']);
        queue::add(
            (int)$contact->id,
            notices::get('local_oksigeniamarandu', 'notification'),
            ['Colegio Ejemplo', 'Hola'],
            null,
            false,
            DAYSECS,
            self::WEDNESDAY
        );
        $bot = $this->bot();
        $this->assertSame(1, queue::process(null, self::WEDNESDAY, $bot)['skipped']);
        $this->assertSame([], $bot->sent);

        // The old bot tells them, with a button to the new one.
        $tasks = \core\task\manager::get_adhoc_tasks('\local_oksigeniamarandu\task\telegram_farewell');
        $this->assertCount(1, $tasks);
        $old = $this->bot();
        $farewell = new class extends \local_oksigeniamarandu\task\telegram_farewell {
            /** @var telegram_api The fake old bot. */
            public $bot;

            /**
             * The fake old bot.
             *
             * @param string $token The token.
             * @return telegram_api
             */
            protected function api(string $token): telegram_api {
                return $this->bot;
            }
        };
        $farewell->bot = $old;
        $farewell->set_custom_data(reset($tasks)->get_custom_data());
        $farewell->execute();
        $this->assertSame(['555', '556'], array_column($old->sent, 0));
        $this->assertSame('https://t.me/marandu_new_bot', $old->sent[0][2]);
        $this->assertStringContainsString('@marandu_new_bot', $old->sent[0][1]);

        // Ana starts the new bot: back, still linked to her account.
        telegram::handle($this->message(555, '/start'));
        $this->assertTrue(api::user_joined((int)$ana->id, 'telegram'));
        $this->assertEquals((object)['back' => 1, 'of' => 2, 'told' => true], telegram::returned());
        telegram::handle($this->message(556, '/start'));
        $this->assertNull(telegram::returned());
    }

    /**
     * The channels of the site, and how a user is on each, for a screen that lets them choose.
     */
    public function test_channels(): void {
        $this->assertSame(['whatsapp' => false, 'telegram' => true], array_map(function ($channel) {
            return $channel['ready'];
        }, api::channels()));

        $user = $this->getDataGenerator()->create_user();
        $channels = api::user_channels((int)$user->id);
        $this->assertSame(['telegram'], array_keys($channels));
        $this->assertFalse($channels['telegram']['joined']);
        $code = substr($channels['telegram']['url'], strlen('https://t.me/marandu_example_bot?start='));
        telegram::handle($this->message(558, '/start ' . $code));
        $channels = api::user_channels((int)$user->id);
        $this->assertSame([true, 'Telegram'], [$channels['telegram']['joined'], $channels['telegram']['where']]);
        $this->assertSame('https://wa.me/', substr((string)api::join_url('whatsapp', 'X') ?: 'https://wa.me/', 0, 14));
    }
}
