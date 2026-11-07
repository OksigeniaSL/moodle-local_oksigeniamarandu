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
use local_oksigeniamarandu\hook\message_received;
use local_oksigeniamarandu\task\send_reply;

/**
 * Tests for what people write of their own and the texts a plugin sends back. Made-up numbers, chats, people and plugin;
 * nothing reaches Meta or Telegram.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\conversation
 */
#[\PHPUnit\Framework\Attributes\CoversClass(conversation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(message_received::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(inbound::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(telegram::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class conversation_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid. */
    private const WEDNESDAY = 1793181600;

    /** @var message_received[] The messages the plugins heard of. */
    private $heard = [];

    /** @var callable|null What the listener does with each message. */
    private $listener = null;

    /**
     * A site with WhatsApp and a bot, and a listener that keeps what it hears.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('telegrambot', 'marandu_example_bot', 'local_oksigeniamarandu');
        $this->heard = [];
        $this->listener = null;
        $this->redirectHook(message_received::class, function (message_received $hook) {
            $this->heard[] = $hook;
            if ($this->listener) {
                ($this->listener)($hook);
            }
        });
    }

    /**
     * Somebody joins with a code of a made-up plugin.
     *
     * @param string $address A number, or tg: and a chat.
     * @param int $itemid The item of the plugin, such as a household.
     * @param string $component The plugin.
     * @param int|null $lastinbound When they last wrote, if they did.
     * @return \stdClass The contact.
     */
    private function join(
        string $address,
        int $itemid = 7,
        string $component = 'local_example',
        ?int $lastinbound = null
    ): \stdClass {
        global $DB;
        consent::optin_from_message($address, api::invite($component, $itemid), 'join');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address], '*', MUST_EXIST);
        $DB->set_field(consent::CONTACTS, 'lastinbound', $lastinbound, ['id' => $contact->id]);
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
     * What a person writes to the bot from a private chat.
     *
     * @param int $chat The chat.
     * @param int $id The id of the message.
     * @param array $content The text, or a photo, a voice note…
     * @return array The update.
     */
    private function update(int $chat, int $id, array $content): array {
        return ['message' => ['message_id' => $id, 'date' => self::WEDNESDAY, 'chat' => ['id' => $chat, 'type' => 'private'],
            'from' => ['first_name' => 'Luis', 'last_name' => 'Ejemplo']] + $content];
    }

    /**
     * The texts queued to answer on WhatsApp.
     *
     * @return array Each with phone and text.
     */
    private function replies(): array {
        return array_map(function ($task) {
            return (array)$task->get_custom_data();
        }, array_values(\core\task\manager::get_adhoc_tasks(send_reply::class)));
    }

    /**
     * A client of WhatsApp that keeps what it sends, or fails with the code of Meta it is given.
     *
     * @param int|null $error The code to fail with.
     * @return cloud_api
     */
    private function whatsapp(?int $error = null): cloud_api {
        $api = new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was posted. */
            public $posted = [];

            /** @var int|null The code of Meta to fail with. */
            public $error = null;

            /**
             * Keeps it and answers as Meta, or fails.
             *
             * @param string $path Where.
             * @param array $payload What.
             * @return array
             */
            protected function post(string $path, array $payload): array {
                if ($this->error !== null) {
                    throw new api_exception('error:meta', (object)['code' => $this->error, 'message' => 'Fake'], $this->error);
                }
                $this->posted[] = $payload;
                return ['messages' => [['id' => 'wamid.T' . count($this->posted)]]];
            }
        };
        $api->error = $error;
        return $api;
    }

    /**
     * A bot of Telegram that keeps what it sends, or fails with the code it is given.
     *
     * @param int|null $error The code to fail with, as Telegram gives it.
     * @return telegram_api
     */
    private function bot(?int $error = null): telegram_api {
        $bot = new class ('123456789:fake') extends telegram_api {
            /** @var array The methods called, with their parameters. */
            public $calls = [];

            /** @var int|null The code to fail with. */
            public $error = null;

            /**
             * Keeps it and answers as Telegram, or fails.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                if ($this->error !== null) {
                    return json_encode(['ok' => false, 'error_code' => $this->error, 'description' => 'Forbidden: fake']);
                }
                $this->calls[] = [$method, json_decode($body, true)];
                return '{"ok":true,"result":{"message_id":' . (40 + count($this->calls)) . '}}';
            }
        };
        $bot->error = $error;
        return $bot;
    }

    /**
     * A text on WhatsApp reaches the plugin of the code, with whom it is for and who wrote it, once even if the webhook
     * comes twice; what the plugin answers goes back as a free text, and without an answer nothing goes, as before.
     */
    public function test_whatsapp_text(): void {
        $this->join('34600000001');
        $message = ['from' => '34600000001', 'id' => 'wamid.IN1', 'timestamp' => (string)self::WEDNESDAY, 'type' => 'text',
            'text' => ['body' => '  ¿A qué hora es la visita del jueves?  ']];
        inbound::handle($this->webhook($message));

        $this->assertCount(1, $this->heard);
        $hook = $this->heard[0];
        $this->assertSame(
            ['local_example', 7, null, 'whatsapp', '+34600000001', 'text', '¿A qué hora es la visita del jueves?'],
            [$hook->get_component(), $hook->get_itemid(), $hook->get_userid(), $hook->get_channel(), $hook->get_address(),
                $hook->get_kind(), $hook->get_text()]
        );
        $this->assertSame([self::WEDNESDAY, 'wamid.IN1', 'Ana Ejemplo'], [$hook->get_time(), $hook->get_messageid(),
            $hook->get_name()]);
        $this->assertSame([], $this->replies());

        // The same webhook again: the plugin does not hear it twice.
        inbound::handle($this->webhook($message));
        $this->assertCount(1, $this->heard);

        $this->listener = function (message_received $hook) {
            $hook->set_reply('Gracias: te contesta el equipo de admisiones por aquí.');
        };
        $message['id'] = 'wamid.IN2';
        inbound::handle($this->webhook($message));
        $this->assertCount(2, $this->heard);
        $this->assertSame(
            [['phone' => '34600000001', 'text' => 'Gracias: te contesta el equipo de admisiones por aquí.']],
            $this->replies()
        );
    }

    /**
     * An image with its caption and a voice note reach the plugin with their kind; a reaction does not count.
     */
    public function test_whatsapp_other_kinds(): void {
        $this->join('34600000001');
        inbound::handle($this->webhook(['from' => '34600000001', 'id' => 'wamid.IN3', 'type' => 'image',
            'image' => ['id' => 'media1', 'caption' => 'La ficha rellena']]));
        inbound::handle($this->webhook(['from' => '34600000001', 'id' => 'wamid.IN4', 'type' => 'audio',
            'audio' => ['id' => 'media2', 'voice' => true]]));
        inbound::handle($this->webhook(['from' => '34600000001', 'id' => 'wamid.IN5', 'type' => 'reaction',
            'reaction' => ['message_id' => 'wamid.OUT1', 'emoji' => '👍']]));
        $this->assertSame(
            [['image', 'La ficha rellena'], ['audio', '']],
            array_map(function (message_received $hook) {
                return [$hook->get_kind(), $hook->get_text()];
            }, $this->heard)
        );
    }

    /**
     * The opt-in and opt-out words do what they did; a number that has not joined, has opted out or joined only for a
     * user (no plugin) reaches nobody.
     */
    public function test_who_counts(): void {
        $user = $this->getDataGenerator()->create_user();
        consent::optin_from_message('34600000002', api::invite('core', 0, (int)$user->id), 'join');
        $this->join('34600000001');
        foreach (['34600000002', '34600000009'] as $i => $phone) {
            inbound::handle($this->webhook(['from' => $phone, 'id' => 'wamid.S' . $i, 'type' => 'text',
                'text' => ['body' => 'Hola']]));
        }
        inbound::handle($this->webhook(['from' => '34600000001', 'id' => 'wamid.S8', 'type' => 'text',
            'text' => ['body' => 'BAJA']]));
        $this->assertSame([], $this->heard);
        inbound::handle($this->webhook(['from' => '34600000001', 'id' => 'wamid.S9', 'type' => 'text',
            'text' => ['body' => 'Hola']]));
        $this->assertSame([], $this->heard);
    }

    /**
     * On Telegram, a text reaches the plugin; without it taking it, the bot says nobody reads it, as before; taken
     * without an answer, the bot says nothing; with one, the answer goes back at once. Commands and a photo without a
     * plugin that takes it stay as they were.
     */
    public function test_telegram(): void {
        $this->join('tg:555');
        $answer = telegram::handle($this->update(555, 1, ['text' => 'Hola, quiero ir a la jornada de puertas abiertas']));
        $this->assertSame(telegram_menu::nobody('tg:555'), $answer);
        $this->assertCount(1, $this->heard);
        $hook = $this->heard[0];
        $this->assertSame(
            ['telegram', 'tg:555', 'text', 'tg.555.1', 'Luis Ejemplo', self::WEDNESDAY],
            [$hook->get_channel(), $hook->get_address(), $hook->get_kind(), $hook->get_messageid(), $hook->get_name(),
                $hook->get_time()]
        );

        $this->listener = function (message_received $hook) {
            $hook->set_handled();
        };
        $this->assertNull(telegram::handle($this->update(555, 2, ['text' => 'Somos tres en casa'])));

        $this->listener = function (message_received $hook) {
            $hook->set_reply('Para hablar con el centro: 900 000 000.');
        };
        $this->assertSame(
            ['method' => 'sendMessage', 'chat_id' => '555', 'text' => 'Para hablar con el centro: 900 000 000.'],
            telegram::handle($this->update(555, 3, ['text' => '¿Me llaman?']))
        );

        $this->assertSame(
            ['method' => 'sendMessage', 'chat_id' => '555', 'text' => 'Para hablar con el centro: 900 000 000.'],
            telegram::handle($this->update(555, 4, ['photo' => [['file_id' => 'f1']], 'caption' => 'La ficha']))
        );
        $this->assertSame(['image', 'La ficha'], [$this->heard[3]->get_kind(), $this->heard[3]->get_text()]);
        $this->listener = null;
        $this->assertNull(telegram::handle($this->update(555, 5, ['voice' => ['file_id' => 'f2']])));
        $this->assertSame('audio', $this->heard[4]->get_kind());

        $this->assertNotEmpty(telegram::handle($this->update(555, 6, ['text' => '/ayuda'])));
        $this->assertCount(5, $this->heard);
    }

    /**
     * A chat joined for two plugins: both hear of it; the first answer is the one that goes, and a plugin that fails
     * does not stop the other.
     */
    public function test_two_plugins(): void {
        $this->join('tg:556', 7);
        $this->join('tg:556', 3, 'local_other');
        $this->listener = function (message_received $hook) {
            if ($hook->get_component() === 'local_other') {
                throw new \moodle_exception('error');
            }
            $hook->set_reply('Lo apuntamos.');
        };
        $answer = telegram::handle($this->update(556, 1, ['text' => 'Hola']));
        $this->assertDebuggingCalled();
        $this->assertSame('Lo apuntamos.', $answer['text']);
        $this->assertEqualsCanonicalizing(['local_example', 'local_other'], array_map(function (message_received $hook) {
            return $hook->get_component();
        }, $this->heard));
    }

    /**
     * The text of a plugin: on Telegram, with its button; on WhatsApp, inside the 24 hours, with its link below; outside
     * them it does not try. Only to whoever joined for the item, or only to one of them.
     */
    public function test_send(): void {
        global $DB;
        $this->join('34600000001', 7, 'local_example', self::WEDNESDAY - HOURSECS);
        $this->join('34600000002', 7, 'local_example', self::WEDNESDAY - 3 * DAYSECS);
        $this->join('tg:555');
        $this->join('34600000003', 8);
        $this->join('34600000004');
        consent::optout('34600000004', 'keyword', 'test');

        $api = $this->whatsapp();
        $bot = $this->bot();
        $url = 'https://example.org/local/example/household.php?id=7';
        $results = conversation::send(
            'local_example',
            7,
            ' La visita es el jueves a las 10. ',
            $url,
            '',
            null,
            $api,
            $bot,
            self::WEDNESDAY
        );
        $this->assertSame(
            [['whatsapp', '+34600000001', 'sent', 'wamid.T1'], ['whatsapp', '+34600000002', 'closed', null],
                ['telegram', 'tg:555', 'sent', 'tg.555.41']],
            array_map(function ($result) {
                return [$result['channel'], $result['address'], $result['status'], $result['messageid']];
            }, $results)
        );
        $this->assertCount(1, $api->posted);
        $this->assertSame('34600000001', $api->posted[0]['to']);
        $this->assertSame("La visita es el jueves a las 10.\n" . $url, $api->posted[0]['text']['body']);
        [$method, $params] = $bot->calls[0];
        $this->assertSame(['sendMessage', '555', 'La visita es el jueves a las 10.'], [$method, $params['chat_id'],
            $params['text']]);
        $this->assertSame([['text' => 'Open', 'url' => $url]], $params['reply_markup']['inline_keyboard'][0]);

        // Only to whoever wrote.
        $results = conversation::send(
            'local_example',
            7,
            'Sí, a las 10.',
            null,
            '',
            '+34600000001',
            $api,
            $bot,
            self::WEDNESDAY
        );
        $this->assertSame([['+34600000001', 'sent']], array_map(function ($result) {
            return [$result['address'], $result['status']];
        }, $results));
        $this->assertSame('Sí, a las 10.', $api->posted[1]['text']['body']);

        // Meta says the 24 hours are closed; Telegram says the bot is blocked, which opts the chat out.
        $results = conversation::send(
            'local_example',
            7,
            'Hola',
            null,
            '',
            null,
            $this->whatsapp(131047),
            $this->bot(403),
            self::WEDNESDAY
        );
        $this->assertSame(['closed', 'closed', 'failed'], array_column($results, 'status'));
        $this->assertSame('optedout', $DB->get_field(consent::CONTACTS, 'status', ['phone' => 'tg:555']));

        // Without the clients, it says so.
        $results = conversation::send('local_example', 7, 'Hola', null, '', null, null, null, self::WEDNESDAY);
        $this->assertSame(['failed', 'closed'], array_column($results, 'status'));
        $this->assertNotEmpty($results[0]['error']);

        $this->assertSame([], api::send_text('local_example', 99, 'Nadie'));
        $this->expectException(\coding_exception::class);
        conversation::send('local_example', 7, '   ', null, '', null, $api, $bot, self::WEDNESDAY);
    }
}
