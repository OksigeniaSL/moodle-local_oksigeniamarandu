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
use local_oksigeniamarandu\task\send_reply;

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
#[\PHPUnit\Framework\Attributes\CoversClass(telegram_menu::class)]
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
        set_config('telegramnotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
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

            /** @var array The other methods called, with their parameters. */
            public $calls = [];

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
             * @param bool $silent Without sound.
             * @return string
             */
            public function send_text(
                string $chatid,
                string $text,
                ?string $url = null,
                string $label = '',
                bool $silent = false
            ): string {
                $code = array_shift($this->errors);
                if ($code !== null) {
                    throw new api_exception('error:telegram', (object)['code' => -$code, 'message' => 'Fake'], $code);
                }
                $this->sent[] = [$chatid, $text, $url, $label, $silent];
                return (string)count($this->sent);
            }

            /**
             * Any other method: kept, and answered as done.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->calls[] = [$method, json_decode($body, true)];
                return '{"ok":true,"result":true}';
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
        $answer = telegram::handle($this->message(555, '/start ' . $code));
        // The reply goes back at once, in the answer to the webhook.
        $this->assertSame(
            ['sendMessage', '555', send_reply::text('optin')],
            [$answer['method'], $answer['chat_id'], $answer['text']]
        );

        $contact = $DB->get_record(consent::CONTACTS, ['phone' => 'tg:555']);
        $this->assertSame(['telegram', 'active', 'Ana Ejemplo'], [$contact->channel, $contact->status, $contact->name]);
        $this->assertTrue(api::user_joined((int)$user->id, 'telegram'));
        $this->assertFalse(api::user_joined((int)$user->id, 'whatsapp'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks('\local_oksigeniamarandu\task\send_reply'));

        telegram::handle($this->message(555, '/stop'));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => 'tg:555']));
        telegram::handle($this->message(555, '/start'));
        $this->assertSame('active', $DB->get_field(consent::CONTACTS, 'status', ['phone' => 'tg:555']));
        telegram::handle(['my_chat_member' => ['chat' => ['id' => 555, 'type' => 'private'],
            'new_chat_member' => ['status' => 'kicked']]]);
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => 'tg:555']));

        $group = $this->message(-100, '/start ' . $code);
        $group['message']['chat']['type'] = 'group';
        $this->assertNull(telegram::handle($group));
        // Writing anything else: the bot says that nobody reads it.
        $this->assertSame(telegram_menu::nobody('tg:556'), telegram::handle($this->message(556, 'hola')));
    }

    /**
     * The menu of the bot: Telegram learns it in each language of the site; the words of the site and the English ones
     * work; whom the notices are about, notices without sound (but not the urgent ones), the language with its buttons,
     * and leaving.
     */
    public function test_menu(): void {
        global $DB;
        $bot = $this->bot();
        telegram_menu::register($bot);
        [$method, $params] = $bot->calls[0];
        $this->assertSame(['setMyCommands', 'all_private_chats'], [$method, $params['scope']['type']]);
        $this->assertSame(['help', 'who', 'mute', 'stop'], array_column($params['commands'], 'command'));
        $this->assertSame('en', $bot->calls[1][1]['language_code']);

        $this->assertSame('mute', telegram_menu::command('Mute'));
        $this->assertNull(telegram_menu::command('nada'));

        // Before joining, only the help and how to join.
        $answer = telegram::handle($this->message(560, '/who'));
        $this->assertSame(get_string('telegram:menu:out', 'local_oksigeniamarandu'), $answer['text']);

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ejemplo']);
        telegram::handle($this->message(560, '/start ' . api::invite('core', 0, (int)$user->id)));
        $answer = telegram::handle($this->message(560, '/who@marandu_example_bot'));
        $this->assertStringContainsString('Ana Ejemplo', $answer['text']);
        $this->assertStringContainsString('/mute', telegram::handle($this->message(560, '/help'))['text']);
        $this->assertStringContainsString('/help', telegram::handle($this->message(560, '/whatever'))['text']);

        // Without sound: what does not hurry; an urgent notice still sounds.
        telegram::handle($this->message(560, '/mute'));
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => 'tg:560']);
        $this->assertSame(1, (int)$contact->silent);
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        queue::add((int)$contact->id, $notice, ['Colegio Ejemplo', 'Hola'], null, false, DAYSECS, self::WEDNESDAY);
        queue::add((int)$contact->id, $notice, ['Colegio Ejemplo', 'Hoy'], null, true, DAYSECS, self::WEDNESDAY);
        $bot = $this->bot();
        queue::process(null, self::WEDNESDAY, $bot);
        $this->assertSame([true, false], array_column($bot->sent, 4));
        telegram::handle($this->message(560, '/mute'));
        $this->assertSame(0, (int)$DB->get_field(consent::CONTACTS, 'silent', ['id' => $contact->id]));

        // The language: one site language only, so the buttons offer it, and a button changes the chat.
        $answer = telegram::handle($this->message(560, '/language'));
        $this->assertSame('lang:en', $answer['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $bot = $this->bot();
        $answer = telegram::handle(['callback_query' => ['id' => 'q1', 'data' => 'lang:en',
            'message' => ['message_id' => 9, 'chat' => ['id' => 560, 'type' => 'private']]]], $bot);
        $this->assertSame(['editMessageText', 9], [$answer['method'], $answer['message_id']]);
        $this->assertSame('en', $DB->get_field(consent::CONTACTS, 'lang', ['id' => $contact->id]));
        $this->assertSame('answerCallbackQuery', $bot->calls[0][0]);
        $this->assertNull(telegram::handle(['callback_query' => ['id' => 'q2', 'data' => 'other',
            'message' => ['message_id' => 9, 'chat' => ['id' => 560, 'type' => 'private']]]]));

        // Leaving from the menu.
        telegram::handle($this->message(560, '/stop'));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['id' => $contact->id]));
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
        // And the bot gets its menu.
        $this->assertSame('setMyCommands', $bot->calls[0][0]);

        // After an update of the plugin, the bot is told again what it reads and its menu, with the same secret.
        $refresh = new class extends \local_oksigeniamarandu\task\telegram_refresh {
            /** @var telegram_api The fake bot. */
            public $bot;

            /**
             * The fake bot.
             *
             * @return telegram_api
             */
            protected function api(): telegram_api {
                return $this->bot;
            }
        };
        $refresh->bot = $this->bot();
        $refresh->execute();
        $this->assertSame(['webhook', $url, $secret], $refresh->bot->sent[0]);
        $this->assertSame('setMyCommands', $refresh->bot->calls[0][0]);

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
     * Joining without a code, a chat can share its own number: if the family records have it, the chat joins that
     * adult; someone else's number does nothing. The link stays at the daily check, and goes when the adult leaves.
     */
    public function test_share_number(): void {
        global $DB;
        if (!class_exists('\\local_oksigeniafamily\\api')) {
            $this->markTestSkipped('local_oksigeniafamily is not installed');
        }
        $child = $this->getDataGenerator()->create_user(['firstname' => 'Lucas', 'lastname' => 'Ejemplo']);
        $familyid = \local_oksigeniafamily\api::create_family('csv');
        $adultid = \local_oksigeniafamily\api::add_adult($familyid, ['firstname' => 'Ana', 'phone' => '+34600111222']);
        \local_oksigeniafamily\api::add_child($familyid, (int)$child->id);

        $update = function (array $message): array {
            return ['message' => $message + ['message_id' => 8, 'chat' => ['id' => 570, 'type' => 'private'],
                'from' => ['id' => 570, 'first_name' => 'Ana']]];
        };
        // The offer comes with the reply to «/start» without a code.
        $answer = telegram::handle($update(['text' => '/start']));
        $this->assertTrue($answer['reply_markup']['keyboard'][0][0]['request_contact']);
        $this->assertStringContainsString(
            get_string('telegram:share:button', 'local_oksigeniamarandu'),
            $answer['reply_markup']['keyboard'][0][0]['text']
        );

        // Someone else's number: nothing.
        $answer = telegram::handle($update(['contact' => ['phone_number' => '34600111222', 'user_id' => 999]]));
        $this->assertSame(['remove_keyboard' => true], $answer['reply_markup']);
        $this->assertFalse($DB->record_exists(consent::LINKS, ['component' => 'local_oksigeniafamily']));

        // Their own number, in the records: the chat stands for that adult.
        $answer = telegram::handle($update(['contact' => ['phone_number' => '+34 600 111 222', 'user_id' => 570]]));
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => 'tg:570']);
        $this->assertTrue($DB->record_exists(consent::LINKS, ['component' => 'local_oksigeniafamily', 'itemid' => $adultid,
            'contactid' => $contact->id]));
        // The answer says whom the notices are about now.
        $this->assertStringContainsString("\n• ", $answer['text']);
        $this->assertSame(1, api::notify_child(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            (int)$child->id
        ));
        // Already linked: no more offers.
        $this->assertArrayNotHasKey('reply_markup', telegram::handle($update(['text' => '/who'])));

        // The daily check keeps it; the adult leaving the records drops it.
        $this->assertSame(0, family::check());
        \local_oksigeniafamily\api::remove_adult($adultid);
        $this->assertFalse($DB->record_exists(consent::LINKS, ['component' => 'local_oksigeniafamily', 'itemid' => $adultid]));

        // A number that is not in the records.
        telegram::handle($update(['text' => '/start']));
        $answer = telegram::handle($update(['contact' => ['phone_number' => '34699999999', 'user_id' => 570]]));
        $site = format_string(get_site()->fullname, true, ['escape' => false]);
        $this->assertSame(get_string('telegram:share:none', 'local_oksigeniamarandu', $site), $answer['text']);
    }

    /**
     * A text of several lines goes with its first line in bold, measured in UTF-16; one of a single line, as it is.
     */
    public function test_first_line_in_bold(): void {
        $api = new class ('123456789:fake') extends telegram_api {
            /** @var array What each call sent. */
            public $bodies = [];

            /**
             * Keeps what would go to Telegram.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->bodies[] = json_decode($body, true);
                return '{"ok":true,"result":{"message_id":1}}';
            }
        };
        $api->send_text('555', "Menú 🍝 de mañana\nLeo come sopa.\nPuedes cambiarlo en el enlace.");
        // The 🍝 takes two units of UTF-16, and the rest one each.
        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 17]], $api->bodies[0]['entities']);
        $api->send_text('555', 'Una sola línea');
        $this->assertArrayNotHasKey('entities', $api->bodies[1]);
        $api->send_text('555', "\nEmpieza en blanco");
        $this->assertArrayNotHasKey('entities', $api->bodies[2]);
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

        // The same for an item of a plugin: the page of a family can say where it is and offer what is left. The link
        // keeps its code while nobody has used it.
        $channels = api::item_channels('local_example', 42);
        $this->assertSame(['telegram'], array_keys($channels));
        $this->assertFalse($channels['telegram']['joined']);
        $this->assertSame($channels['telegram']['url'], api::item_channels('local_example', 42)['telegram']['url']);
        $code = substr($channels['telegram']['url'], strlen('https://t.me/marandu_example_bot?start='));
        telegram::handle($this->message(559, '/start ' . $code));
        $channels = api::item_channels('local_example', 42);
        $this->assertSame([true, 'Telegram'], [$channels['telegram']['joined'], $channels['telegram']['where']]);
        $this->assertFalse(api::item_channels('local_example', 43)['telegram']['joined']);
    }
}
