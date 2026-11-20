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
 * Tests for what whoever hosts a site sets from the command line, and for the languages the families read. A fake bot
 * answers for Telegram; made-up plugins and chats.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\setup
 */
#[\PHPUnit\Framework\Attributes\CoversClass(setup::class)]
final class setup_test extends \advanced_testcase {
    /** What two made-up plugins declare. */
    private const DECLARED = [
        'local_admissions' => [
            'visit_confirmed' => ['examples' => ['Los Almendros', 'sábado 21']],
            'request_received' => ['examples' => ['Los Almendros']],
        ],
        'local_canteen' => [
            'menu_changed' => ['examples' => ['Ana', 'lunes 3']],
        ],
    ];

    /** A token with the shape of one. */
    private const TOKEN = '123456789:AAEexampleexampleexampleexample123';

    /**
     * A site with two made-up plugins that declare notices.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        notices::declare_for_tests(self::DECLARED);
    }

    /**
     * The tests forget their notices.
     */
    protected function tearDown(): void {
        notices::declare_for_tests([]);
        parent::tearDown();
    }

    /**
     * A fake bot that answers getMe with the bot given and keeps every call.
     *
     * @param int $id The id of the bot.
     * @param string $username Its username.
     * @return telegram_api
     */
    private function bot(int $id, string $username): telegram_api {
        $bot = new class (self::TOKEN) extends telegram_api {
            /** @var array The bot getMe answers with. */
            public $me = [];

            /** @var array The methods called, with their parameters. */
            public $calls = [];

            /**
             * Answers as Telegram would.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->calls[] = [$method, json_decode($body, true)];
                return json_encode(['ok' => true, 'result' => $method === 'getMe' ? $this->me : true]);
            }
        };
        $bot->me = ['id' => $id, 'is_bot' => true, 'first_name' => 'Avisos de ejemplo', 'username' => $username];
        return $bot;
    }

    /**
     * Another number of the same account: checked with Meta, kept with the number Meta has (the links to join follow
     * it), and the 24 hours of everyone closed, because they were with the other number.
     */
    public function test_whatsapp_number(): void {
        global $DB;
        set_config('accesstoken', \core\encryption::encrypt('token'), 'local_oksigeniamarandu');
        set_config('phonenumberid', '1111111111', 'local_oksigeniamarandu');
        set_config('wabaid', '3333333333', 'local_oksigeniamarandu');
        $contact = consent::get_or_create('34600000001');
        $DB->set_field(consent::CONTACTS, 'lastinbound', time() - HOURSECS, ['id' => $contact->id]);
        $api = new class ('2222222222', 'token') extends cloud_api {
            /**
             * The number Meta has.
             *
             * @return array
             */
            public function get_phone_number(): array {
                return ['number' => '+34 600 00 09 99', 'name' => 'Centro de ejemplo'];
            }
            /**
             * Whether the token reaches the account.
             *
             * @param string $wabaid The account.
             * @return bool
             */
            public function reaches_account(string $wabaid): bool {
                return $wabaid === '3333333333';
            }
            /**
             * Where Meta sends what reaches the number.
             *
             * @return array
             */
            public function webhook_configuration(): array {
                return ['phone_number' => 'https://example.com/webhook.php', 'application' => 'https://example.com/app'];
            }
        };
        $moved = setup::whatsapp_number('2222222222', $api);
        $got = [$moved['number'], $moved['old'], $moved['reset'], $moved['webhook']['phone_number']];
        $this->assertSame(['+34 600 00 09 99', '1111111111', 1, 'https://example.com/webhook.php'], $got);
        $config = get_config('local_oksigeniamarandu');
        $got = [$config->phonenumberid, $config->displayphone, $config->connectednumber];
        $this->assertSame(['2222222222', '+34 600 00 09 99', '+34 600 00 09 99'], $got);
        $this->assertSame(0, (int)$DB->get_field(consent::CONTACTS, 'lastinbound', ['id' => $contact->id]));
        $this->expectException(\moodle_exception::class);
        setup::whatsapp_number('not a number', $api);
    }

    /**
     * A token without the shape of one goes nowhere; a good one connects the bot, its webhook and its menu, and the same
     * bot with a new token says so.
     */
    public function test_telegram_connects(): void {
        $bot = $this->bot(123456789, 'avisos_ejemplo_bot');
        try {
            setup::telegram($bot, 'not-a-token');
            $this->fail('A token without its shape went through');
        } catch (\moodle_exception $e) {
            $this->assertSame('telegram:error:token', $e->errorcode);
        }
        $this->assertSame([], $bot->calls);

        $result = setup::telegram($bot, self::TOKEN);
        $this->assertSame('avisos_ejemplo_bot', $result['username']);
        $this->assertFalse($result['same']);
        $this->assertTrue(api::channels()['telegram']['ready']);
        $this->assertSame(self::TOKEN, \core\encryption::decrypt(get_config('local_oksigeniamarandu', 'telegramtoken')));
        $methods = array_column($bot->calls, 0);
        $this->assertContains('setWebhook', $methods);
        $this->assertContains('setMyCommands', $methods);

        $this->assertTrue(setup::telegram($this->bot(123456789, 'avisos_ejemplo_bot'), self::TOKEN)['same']);
    }

    /**
     * Another bot takes over only while nobody joined the one of now; with people joined, it is changed from its page.
     */
    public function test_telegram_other_bot(): void {
        setup::telegram($this->bot(123456789, 'avisos_ejemplo_bot'), self::TOKEN);
        $this->assertFalse(setup::telegram($this->bot(987654321, 'otro_ejemplo_bot'), self::TOKEN)['same']);
        $this->assertSame('otro_ejemplo_bot', get_config('local_oksigeniamarandu', 'telegrambot'));

        $user = $this->getDataGenerator()->create_user();
        telegram::handle(['message' => ['message_id' => 7, 'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['first_name' => 'Ana'], 'text' => '/start ' . api::invite('core', 0, (int)$user->id)]]);
        $this->assertCount(1, telegram::joined());
        try {
            setup::telegram($this->bot(123456789, 'avisos_ejemplo_bot'), self::TOKEN);
            $this->fail('Another bot took over with people joined');
        } catch (\moodle_exception $e) {
            $this->assertSame('setup:otherbot', $e->errorcode);
        }
        $this->assertSame('otro_ejemplo_bot', get_config('local_oksigeniamarandu', 'telegrambot'));
    }

    /**
     * The notices of the plugins given are switched on for the channels given, and only those; a plugin without notices
     * or a channel that is none stop everything.
     */
    public function test_notices(): void {
        $this->assertEqualsCanonicalizing(
            ['local_admissions/visit_confirmed', 'local_admissions/request_received'],
            setup::notices(['local_admissions'], ['telegram'])
        );
        $this->assertEqualsCanonicalizing(
            ['local_admissions/visit_confirmed', 'local_admissions/request_received'],
            notices::enabled('telegram')
        );
        $this->assertSame([], notices::enabled('whatsapp'));

        foreach ([[['local_nothing'], ['telegram'], 'setup:nonotices'], [['local_canteen'], ['sms'], 'setup:nochannel']] as $case) {
            try {
                setup::notices($case[0], $case[1]);
                $this->fail('It went through: ' . $case[2]);
            } catch (\moodle_exception $e) {
                $this->assertSame($case[2], $e->errorcode);
            }
        }
        $this->assertNotContains('local_canteen/menu_changed', notices::enabled('telegram'));

        setup::notices(['all'], ['whatsapp', 'telegram']);
        $this->assertContains('local_canteen/menu_changed', notices::enabled('whatsapp'));
        $this->assertContains('local_canteen/menu_changed', notices::enabled('telegram'));

        $status = setup::status();
        $this->assertStringStartsWith('WhatsApp: not set up', $status[0]);
        $this->assertStringContainsString('local_canteen/menu_changed', $status[1]);
    }

    /**
     * What families read in other languages has the same strings and the same placeholders as in English, and the words
     * of the commands of the bot are words Telegram takes.
     */
    public function test_languages(): void {
        $read = function (string $lang): array {
            $string = [];
            require(__DIR__ . '/../../lang/' . $lang . '/local_oksigeniamarandu.php');
            return $string;
        };
        $placeholders = function (string $text): array {
            preg_match_all('/\{\$a(->[a-z]+)?\}/', $text, $found);
            sort($found[0]);
            return $found[0];
        };
        $en = $read('en');
        foreach (['es', 'pt', 'pt_br', 'ar'] as $lang) {
            foreach ($read($lang) as $key => $text) {
                $this->assertArrayHasKey($key, $en, "$lang: $key");
                $this->assertSame($placeholders($en[$key]), $placeholders($text), "$lang: $key");
                if (preg_match('/^telegram:cmd:[a-z]+$/', $key)) {
                    $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $text, "$lang: $key");
                }
            }
        }
    }
}
