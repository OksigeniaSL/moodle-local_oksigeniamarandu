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
 * Tests for the card of a notice on Telegram. Made-up chats, texts and bots; nothing reaches Telegram.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\rich
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rich::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(telegram_api::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(queue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class rich_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid, out of the quiet hours. */
    private const WEDNESDAY = 1793181600;

    /**
     * Madrid time and the general notice switched on in both channels.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->redirectMessages();
        $this->setTimezone('Europe/Madrid');
        set_config('enablednotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
        set_config('telegramnotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
    }

    /**
     * The address of the small PNG of the tests.
     *
     * @return string
     */
    private function image_url(): string {
        global $CFG;
        return $CFG->wwwroot . '/local/oksigeniamarandu/tests/fixtures/card.png';
    }

    /**
     * A client that keeps what would go to Telegram, and answers for it.
     *
     * @param string $token The token of the bot.
     * @return telegram_api
     */
    private function client(string $token = '123456789:fake'): telegram_api {
        return new class ($token) extends telegram_api {
            /** @var array What was sent without a file, as Telegram would read it. */
            public $calls = [];

            /** @var array What was sent with a file. */
            public $uploads = [];

            /** @var int How many refusals to answer with. */
            public $refuse = 0;

            /** @var array|null What Telegram answers instead of a card, if anything. */
            public $result = null;

            /**
             * What Telegram answers: the message, with the id it gives to the photo, or a refusal.
             *
             * @return string
             */
            private function reply(): string {
                if ($this->refuse-- > 0) {
                    return '{"ok":false,"error_code":400,"description":"Bad Request: fake"}';
                }
                $photo = [['file_id' => 'small'], ['file_id' => 'large']];
                return json_encode(['ok' => true, 'result' => $this->result ?? ['message_id' => 9,
                    'rich_message' => ['blocks' => [['type' => 'photo', 'photo' => $photo]]]]]);
            }

            /**
             * Keeps a request.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->calls[] = [$method, json_decode($body, true)];
                return $this->reply();
            }

            /**
             * Keeps a request with a file.
             *
             * @param string $method The method.
             * @param array $fields Its parameters.
             * @param string $name The name of the file.
             * @param string $bytes The file.
             * @return string
             */
            protected function request_file(string $method, array $fields, string $name, string $bytes): string {
                $this->uploads[] = [$method, $fields, $name, $bytes];
                return $this->reply();
            }
        };
    }

    /**
     * The parts come out as plain text, ready to keep; what is empty goes; a card with nothing to show is no card.
     */
    public function test_clean(): void {
        $this->assertSame(
            ['title' => 'Menú', 'lines' => ['Sopa', 'Pollo'], 'text' => ['Uno', 'Dos', 'Tres'], 'style' => 'danger'],
            rich::clean([
                'title' => ' <b>Menú</b> ',
                'lines' => ['Sopa', '  ', 'Pollo'],
                'text' => ["Uno\nDos", 'Tres'],
                'style' => 'danger',
                'image' => '',
            ])
        );
        $this->assertSame([], rich::clean([]));
        $this->assertSame([], rich::clean(['title' => ' ', 'style' => 'primary']));
        $url = new \moodle_url('/local/oksigeniamarandu/tests/fixtures/card.png');
        $this->assertSame(['image' => $this->image_url()], rich::clean(['image' => $url]));
    }

    /**
     * A mistake of the plugin that asks for the card is told at once, not when it is sent.
     */
    public function test_clean_refuses(): void {
        foreach ([['titulo' => 'Menú'], ['image' => 'https://www.example.net/a.png'], ['style' => 'link']] as $rich) {
            try {
                rich::clean($rich);
                $this->fail('It should refuse ' . json_encode($rich));
            } catch (\coding_exception $e) {
                $this->assertDebuggingNotCalled();
            }
        }
    }

    /**
     * The blocks follow the order they are read in, with the button inside.
     */
    public function test_blocks(): void {
        $rich = ['title' => 'Menú', 'lines' => ['Sopa'], 'text' => ['Uno', 'Dos'], 'style' => 'primary'];
        $this->assertSame([
            ['type' => 'heading', 'text' => 'Menú', 'size' => 2],
            ['type' => 'photo', 'photo' => ['type' => 'photo', 'media' => 'attach://image']],
            ['type' => 'list', 'items' => [['blocks' => [['type' => 'paragraph', 'text' => 'Sopa']]]]],
            ['type' => 'paragraph', 'text' => 'Uno'],
            ['type' => 'paragraph', 'text' => 'Dos'],
            ['type' => 'buttons', 'buttons' => [['text' => 'Ver', 'url' => 'https://www.example.com/go', 'style' => 'primary']]],
        ], rich::blocks($rich, 'attach://image', 'https://www.example.com/go', 'Ver'));

        // Without an image or a link, neither block; without a style, the button of always.
        $this->assertSame(
            [['type' => 'heading', 'text' => 'Menú', 'size' => 2]],
            rich::blocks(['title' => 'Menú'], null, null, '')
        );
        $this->assertSame(
            [['type' => 'buttons', 'buttons' => [['text' => 'Ver', 'url' => 'https://www.example.com/go']]]],
            rich::blocks([], null, 'https://www.example.com/go', 'Ver')
        );
    }

    /**
     * An image of the code comes from the disk, without asking the site itself; any other, from its address; only a PNG or
     * a JPEG counts, and nothing out of the code is read through its address.
     */
    public function test_image(): void {
        global $CFG;
        $png = file_get_contents($CFG->dirroot . '/local/oksigeniamarandu/tests/fixtures/card.png');
        $this->assertSame($png, rich::image($this->image_url()));
        $this->assertNull(rich::image('https://www.example.net/card.png'));

        \curl::mock_response($png);
        $this->assertSame($png, rich::image($CFG->wwwroot . '/local/oksigeniamarandu/card.php?id=1'));

        // A page that asks to log in is not an image.
        \curl::mock_response('<html><body>Log in</body></html>');
        $this->assertNull(rich::image($CFG->wwwroot . '/local/oksigeniamarandu/card.php?id=2'));
        $this->assertDebuggingCalled();

        // An image that exists but is out of the code stays out: it asks the site, which answers with a page.
        $outside = make_request_directory() . '/outside.png';
        file_put_contents($outside, $png);
        \curl::mock_response('<html></html>');
        $this->assertNull(rich::image($CFG->wwwroot . str_repeat('/..', 20) . $outside));
        $this->assertDebuggingCalled();
    }

    /**
     * An image goes up once for each bot: Telegram's id does the rest. If Telegram no longer has it, the next one uploads.
     */
    public function test_upload_once(): void {
        $client = $this->client();
        $rich = ['title' => 'Menú', 'image' => $this->image_url()];
        $this->assertSame('9', $client->send_rich('555', $rich, 'https://www.example.com/go', 'Ver', true));

        $this->assertCount(1, $client->uploads);
        [$method, $fields, $name, $bytes] = $client->uploads[0];
        $this->assertSame(
            ['sendRichMessage', '555', 'image', 'true'],
            [$method, $fields['chat_id'], $name, $fields['disable_notification']]
        );
        $blocks = json_decode($fields['rich_message'], true)['blocks'];
        $this->assertSame('attach://image', $blocks[1]['photo']['media']);
        $this->assertSame('https://www.example.com/go', $blocks[2]['buttons'][0]['url']);
        $this->assertNotEmpty($bytes);

        // The next chat, with the largest size Telegram kept, and no file.
        $client->send_rich('556', $rich);
        $this->assertCount(1, $client->uploads);
        [$method, $params] = $client->calls[0];
        $this->assertSame(['sendRichMessage', '556', 'large'], [$method, $params['chat_id'],
            $params['rich_message']['blocks'][1]['photo']['media']]);

        // Another bot does not have it.
        $other = $this->client('987654321:other');
        $other->send_rich('557', $rich);
        $this->assertCount(1, $other->uploads);

        // Telegram refuses the id: it is forgotten.
        $client->refuse = 1;
        try {
            $client->send_rich('558', $rich);
            $this->fail('It should fail');
        } catch (api_exception $e) {
            $this->assertSame(-400, $e->metacode);
        }
        $client->send_rich('559', $rich);
        $this->assertCount(2, $client->uploads);
    }

    /**
     * A file goes up with the extension of what it is, or Telegram takes an MP4 as a document to download. An animation
     * goes up once for each bot, as an image; what comes back as a document is not kept, and goes up again.
     */
    public function test_animation(): void {
        global $CFG;
        $fixtures = $CFG->dirroot . '/local/oksigeniamarandu/tests/fixtures/';
        $this->assertSame('animation.mp4', telegram_api::filename('animation', file_get_contents($fixtures . 'animation.mp4')));
        $this->assertSame('image.png', telegram_api::filename('image', file_get_contents($fixtures . 'card.png')));
        $this->assertSame('file', telegram_api::filename('file', 'Plain text'));

        $url = $CFG->wwwroot . '/local/oksigeniamarandu/tests/fixtures/animation.mp4';
        $client = $this->client();
        $client->result = ['message_id' => 9, 'document' => ['file_id' => 'doc1']];
        $client->send_animation('555', $url, 'Jornada de puertas abiertas');
        $client->send_animation('556', $url, 'Jornada de puertas abiertas');
        $this->assertCount(2, $client->uploads);
        $this->assertSame(['sendAnimation', 'animation'], [$client->uploads[0][0], $client->uploads[0][2]]);

        $client->result = ['message_id' => 9, 'animation' => ['file_id' => 'anim1']];
        $client->send_animation('557', $url, 'Jornada de puertas abiertas');
        $this->assertSame('9', $client->send_animation('558', $url, 'Jornada de puertas abiertas'));
        $this->assertCount(3, $client->uploads);
        $this->assertSame(['sendAnimation', 'anim1'], [$client->calls[0][0], $client->calls[0][1]['animation']]);
    }

    /**
     * Without an image that can be read, the card goes without it.
     */
    public function test_card_without_its_image(): void {
        global $CFG;
        $client = $this->client();
        \curl::mock_response('<html></html>');
        $client->send_rich('555', ['title' => 'Menú', 'image' => $CFG->wwwroot . '/local/oksigeniamarandu/card.php']);
        $this->assertDebuggingCalled();
        $this->assertSame([], $client->uploads);
        $this->assertSame(
            [['type' => 'heading', 'text' => 'Menú', 'size' => 2]],
            $client->calls[0][1]['rich_message']['blocks']
        );
    }

    /**
     * The queue: the card goes if the notice has one; refused for what it has inside, its text goes so that the notice is
     * not lost; any other problem works as with a text. WhatsApp goes with its template.
     */
    public function test_queue(): void {
        global $DB;
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        consent::optin_from_message('tg:555', null, 'test');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => 'tg:555']);
        $add = function () use ($contact, $notice) {
            return queue::add(
                (int)$contact->id,
                $notice,
                ['Colegio Ejemplo', 'Reunión'],
                'https://www.example.com/x.php',
                false,
                DAYSECS,
                self::WEDNESDAY,
                ['title' => 'Menú', 'style' => 'danger']
            );
        };
        $client = $this->client();

        $id = $add();
        $this->assertSame(1, queue::process(null, self::WEDNESDAY, $client)['sent']);
        $row = $DB->get_record(queue::TABLE, ['id' => $id]);
        [$method, $params] = $client->calls[0];
        $this->assertSame('sendRichMessage', $method);
        $button = $params['rich_message']['blocks'][1]['buttons'][0];
        $this->assertSame([templates::button_url($row->token), 'danger'], [$button['url'], $button['style']]);
        $this->assertSame(notices::text($notice, 'button'), $button['text']);
        $this->assertSame(['sent', 'telegram', 'tg.9', null], [$row->status, $row->kind, $row->wamid, $row->error]);

        // Refused: the same notice goes as text, and the row says why.
        $id = $add();
        $client = $this->client();
        $client->refuse = 1;
        $this->assertSame(1, queue::process(null, self::WEDNESDAY, $client)['sent']);
        $this->assertSame(['sendRichMessage', 'sendMessage'], array_column($client->calls, 0));
        $this->assertSame(queue::render($notice, ['Colegio Ejemplo', 'Reunión']), $client->calls[1][1]['text']);
        $row = $DB->get_record(queue::TABLE, ['id' => $id]);
        $this->assertSame('sent', $row->status);
        $this->assertStringContainsString('fake', $row->error);

        // Too many messages: no text in its place; it waits to try the card again.
        $id = $add();
        $client = new class ('123456789:fake') extends telegram_api {
            /** @var string[] The methods called. */
            public $methods = [];

            /**
             * Telegram is busy.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->methods[] = $method;
                return '{"ok":false,"error_code":429,"description":"Too Many Requests"}';
            }
        };
        $this->assertSame(1, queue::process(null, self::WEDNESDAY, $client)['waiting']);
        $this->assertSame(['sendRichMessage'], $client->methods);
        $this->assertSame('queued', $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * The API keeps the card for each contact, clean; a mistake is told at once.
     */
    public function test_api(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = api::invite('core', 0, (int)$user->id);
        consent::optin_from_message('tg:555', $code, 'test');
        $this->assertSame(1, api::notify(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            0,
            (int)$user->id,
            false,
            DAYSECS,
            ['title' => '<i>Menú</i>']
        ));
        $this->assertSame(['title' => 'Menú'], json_decode($DB->get_field(queue::TABLE, 'rich', []), true));

        $this->expectException(\coding_exception::class);
        api::notify(
            'local_oksigeniamarandu',
            'notification',
            ['Colegio Ejemplo', 'Reunión'],
            null,
            0,
            (int)$user->id,
            false,
            DAYSECS,
            ['titulo' => 'Menú']
        );
    }
}
