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

use core_external\external_api;
use local_oksigeniamarandu\external\chat_action;
use local_oksigeniamarandu\external\chat_agenda;
use local_oksigeniamarandu\external\chat_board;
use local_oksigeniamarandu\external\chat_contact;
use local_oksigeniamarandu\external\chat_find;
use local_oksigeniamarandu\external\chat_flag;
use local_oksigeniamarandu\external\chat_poll;
use local_oksigeniamarandu\external\chat_resolved;
use local_oksigeniamarandu\external\chat_send;
use local_oksigeniamarandu\external\chat_start;
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\closed;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\hours;
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\starter;
use local_oksigeniamarandu\local\chat\templates;
use local_oksigeniamarandu\local\chat\viewer;

/**
 * Tests for the conversations of the team over Chatwoot: a made-up account, people and messages.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\chat\mirror
 * @covers     \local_oksigeniamarandu\local\chat\hours
 * @covers     \local_oksigeniamarandu\local\chat\viewer
 * @covers     \local_oksigeniamarandu\local\chat\message
 * @covers     \local_oksigeniamarandu\local\chat\templates
 * @covers     \local_oksigeniamarandu\local\chat\closed
 * @covers     \local_oksigeniamarandu\local\chat\workcard
 * @covers     \local_oksigeniamarandu\local\chat\notify
 * @covers     \local_oksigeniamarandu\local\chat\starter
 * @covers     \local_oksigeniamarandu\local\chat\reminders
 * @covers     \local_oksigeniamarandu\local\chat\agenda
 * @covers     \local_oksigeniamarandu\local\chat\board
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mirror::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hours::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(viewer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(message::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(templates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(closed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chat\workcard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chat\notify::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(starter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chat\reminders::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chat\agenda::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chat\board::class)]
final class chat_test extends \advanced_testcase {
    /** Monday 16 November 2026 at 12:00 in the Canary Islands (UTC). */
    private const MONDAY_NOON = 1794830400;

    /** @var chatwoot The made-up account. */
    private $client;

    /**
     * An account with two departments, three people and three conversations.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('chatwooturl', 'https://chat.example.com', 'local_oksigeniamarandu');
        set_config('chatwootaccount', 7, 'local_oksigeniamarandu');
        set_config('chatwoottoken', \core\encryption::encrypt('token'), 'local_oksigeniamarandu');
        $this->client = new class ('https://chat.example.com', 7, 'token') extends chatwoot {
            /** @var array What was asked, as [method, path, data]. */
            public $calls = [];

            /** @var array The conversations, by id. */
            public $conversations = [];

            /** @var array The messages of each conversation. */
            public $messages = [];

            /** @var array The contacts made here. */
            public $contacts = [];

            /**
             * Answers as the account would.
             *
             * @param string $method The method.
             * @param string $path The path.
             * @param array $data The data.
             * @return array
             */
            public function call(string $method, string $path, array $data = []): array {
                $this->calls[] = [$method, $path, $data];
                if ($path === '/agents') {
                    return [
                        ['id' => 1, 'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'role' => 'agent'],
                        ['id' => 2, 'name' => 'Luis Gómez', 'email' => 'luis@example.com', 'role' => 'agent'],
                        ['id' => 3, 'name' => 'Marta Ruiz', 'email' => 'marta@example.com', 'role' => 'administrator'],
                        ['id' => 9, 'name' => 'Reparto', 'email' => 'bot@example.com', 'role' => 'administrator'],
                    ];
                }
                if ($path === '/teams') {
                    return [['id' => 10, 'name' => 'secretaría'], ['id' => 11, 'name' => 'recibos']];
                }
                if (preg_match('~^/teams/(\d+)/team_members$~', $path, $m)) {
                    return $m[1] == 10 ? [['id' => 1]] : [['id' => 2]];
                }
                if ($path === '/inboxes') {
                    return ['payload' => [['id' => 3, 'name' => 'WhatsApp', 'timezone' => 'Atlantic/Canary',
                        'working_hours' => self::week(), 'message_templates' => [
                            ['name' => 'chat_retomar', 'status' => 'APPROVED', 'language' => 'es', 'category' => 'UTILITY',
                                'components' => [['type' => 'BODY', 'text' => 'Hola, {{1}}: seguimos con su consulta.',
                                    'example' => ['body_text' => [['Laura']]]]]],
                            ['name' => 'mrd_local_canteen_menu', 'status' => 'APPROVED', 'language' => 'es',
                                'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Menú']]],
                            ['name' => 'chat_pending', 'status' => 'PENDING', 'language' => 'es', 'category' => 'UTILITY',
                                'components' => [['type' => 'BODY', 'text' => 'Hola']]],
                        ]]]];
                }
                if ($path === '/canned_responses') {
                    return [['short_code' => 'horario', 'content' => 'Atendemos de 7:00 a 17:00.']];
                }
                if ($path === '/search/messages') {
                    $found = [];
                    foreach ($this->messages as $conversation => $messages) {
                        foreach ($messages as $message) {
                            if (stripos($message['content'], $data['q']) !== false) {
                                $found[] = $message + ['conversation_id' => $conversation];
                            }
                        }
                    }
                    return ['payload' => ['messages' => $data['page'] > 1 ? [] : $found]];
                }
                if ($path === '/conversations' && $method === 'GET') {
                    return ['data' => ['payload' => array_values($this->conversations)]];
                }
                $people = array_merge(array_column(array_column($this->conversations, 'meta'), 'sender'), $this->contacts);
                if ($path === '/contacts/search') {
                    $found = [];
                    foreach ($people as $one) {
                        if (strpos($one['phone_number'], $data['q']) !== false || stripos($one['name'], $data['q']) !== false) {
                            $found[$one['id']] = $one;
                        }
                    }
                    return ['payload' => array_values($found)];
                }
                if ($path === '/contacts' && $method === 'POST') {
                    $contact = ['id' => 300 + count($this->contacts), 'name' => $data['name'],
                        'phone_number' => $data['phone_number']];
                    $this->contacts[] = $contact;
                    return ['payload' => ['contact' => $contact]];
                }
                if (preg_match('~^/contacts/(\d+)/conversations$~', $path, $m)) {
                    return ['payload' => array_values(array_filter($this->conversations, function ($one) use ($m) {
                        return (int)$one['meta']['sender']['id'] === (int)$m[1];
                    }))];
                }
                if ($path === '/conversations' && $method === 'POST') {
                    $id = 50 + count($this->conversations);
                    $sender = null;
                    foreach ($people as $one) {
                        $sender = (int)$one['id'] === (int)$data['contact_id'] ? $one : $sender;
                    }
                    // A bot in the inbox: Chatwoot makes it pending.
                    $this->conversations[$id] = ['id' => $id, 'inbox_id' => $data['inbox_id'], 'status' => 'pending',
                        'updated_at' => '2.0', 'last_activity_at' => time(), 'last_non_activity_message' => ['id' => 700 + $id],
                        'meta' => ['sender' => $sender, 'team' => $data['team_id'] ? ['id' => $data['team_id']] : null,
                            'assignee' => ['id' => $data['assignee_id']], 'assignee_type' => 'User']];
                    $this->messages[$id] = [['id' => 700 + $id, 'message_type' => 1, 'content' => $data['message']['content'],
                        'private' => false, 'created_at' => time(), 'sender' => ['type' => 'user', 'id' => $data['assignee_id']]]];
                    return $this->conversations[$id];
                }
                if (preg_match('~^/conversations/(\d+)/toggle_status$~', $path, $m)) {
                    $this->conversations[$m[1]]['status'] = $data['status'];
                    $this->conversations[$m[1]]['updated_at'] = (string)microtime(true);
                    return [];
                }
                if (preg_match('~^/conversations/(\d+)/messages$~', $path, $m)) {
                    if ($method === 'POST') {
                        $message = ['id' => 500 + count($this->calls), 'content' => $data['content'], 'message_type' => 1,
                            'private' => $data['private'], 'created_at' => time(), 'sender' => ['type' => 'user', 'id' => 2]];
                        $this->messages[$m[1]][] = $message;
                        return $message;
                    }
                    return ['payload' => $this->messages[$m[1]] ?? []];
                }
                if (preg_match('~^/conversations/(\d+)/assignments$~', $path, $m)) {
                    if (array_key_exists('assignee_id', $data)) {
                        $assignee = $data['assignee_id'] ? ['id' => $data['assignee_id']] : null;
                        $this->conversations[$m[1]]['meta']['assignee'] = $assignee;
                        $this->conversations[$m[1]]['meta']['assignee_type'] = $data['assignee_id'] ? 'User' : null;
                    } else {
                        $this->conversations[$m[1]]['meta']['team'] = ['id' => $data['team_id']];
                    }
                    $this->conversations[$m[1]]['updated_at'] = (string)microtime(true);
                    return [];
                }
                if (preg_match('~^/conversations/(\d+)$~', $path, $m)) {
                    return $this->conversations[$m[1]];
                }
                return [];
            }

            /**
             * Monday to Thursday from 7:00 to 17:00 and Friday to 15:00.
             *
             * @return array
             */
            public static function week(): array {
                $week = [];
                for ($day = 0; $day < 7; $day++) {
                    $open = $day >= 1 && $day <= 5;
                    $week[] = ['day_of_week' => $day, 'closed_all_day' => !$open, 'open_all_day' => false, 'open_hour' => 7,
                        'open_minutes' => 0, 'close_hour' => $day === 5 ? 15 : 17, 'close_minutes' => 0];
                }
                return $week;
            }
        };
        $this->client->conversations = [
            1 => $this->conversation(1, 10, 0, 'Laura Martín', '+34600000001'),
            2 => $this->conversation(2, 11, 0, 'Iván Torres', '+34600000002'),
            3 => $this->conversation(3, 10, 1, 'Nerea Castro', '+34600000003'),
        ];
        $now = time();
        $this->client->messages = [
            1 => [$this->message(10, 0, 'Hola', $now - 600), $this->message(11, 1, 'Elija una opción', $now - 590, 'agent_bot')],
            2 => [$this->message(20, 0, '¿Cuándo pasa el recibo?', $now - 300)],
            3 => [$this->message(30, 0, 'Gracias', $now - 900), $this->message(31, 1, 'A usted', $now - 800)],
        ];
        directory::use_client($this->client);
    }

    /**
     * Back to the client of the site.
     */
    protected function tearDown(): void {
        directory::use_client(null);
        closed::fake_for_tests(null);
        parent::tearDown();
    }

    /**
     * A conversation as Chatwoot gives it.
     *
     * @param int $id Its number.
     * @param int $team Its department.
     * @param int $assignee Who has it, or 0.
     * @param string $name The contact.
     * @param string $phone Its number.
     * @return array
     */
    private function conversation(int $id, int $team, int $assignee, string $name, string $phone): array {
        return ['id' => $id, 'inbox_id' => 3, 'status' => 'open', 'updated_at' => '1.0', 'last_activity_at' => time(),
            'meta' => ['sender' => ['id' => 100 + $id, 'name' => $name, 'phone_number' => $phone],
                'team' => ['id' => $team], 'assignee' => $assignee ? ['id' => $assignee] : null,
                'assignee_type' => $assignee ? 'User' : null]];
    }

    /**
     * A message as Chatwoot gives it.
     *
     * @param int $id Its id.
     * @param int $type 0 incoming, 1 outgoing, 2 activity.
     * @param string $text Its text.
     * @param int $at When.
     * @param string $sender The type of whoever sent it.
     * @param bool $private Whether it is a note.
     * @return array
     */
    private function message(int $id, int $type, string $text, int $at, string $sender = 'user', bool $private = false): array {
        return ['id' => $id, 'message_type' => $type, 'content' => $text, 'created_at' => $at, 'private' => $private,
            'sender' => ['type' => $type === 0 ? 'contact' : $sender, 'id' => 1]];
    }

    /**
     * A user of Moodle for a person of the account.
     *
     * @param string $email Their email.
     * @param string $firstname Their first name.
     * @return \stdClass
     */
    private function person(string $email, string $firstname): \stdClass {
        return $this->getDataGenerator()->create_user(['email' => $email, 'firstname' => $firstname]);
    }

    /**
     * The hours count only the time the team attends, and skip the closed days.
     */
    public function test_hours(): void {
        $hours = new hours('Atlantic/Canary', $this->client::week(), ['2026-11-17']);
        $this->assertTrue($hours->is_open(self::MONDAY_NOON));
        // Monday 22:00 to Wednesday 7:05: Tuesday is closed, so five minutes.
        $night = self::MONDAY_NOON + 10 * HOURSECS;
        $wednesday = self::MONDAY_NOON + 2 * DAYSECS - 5 * HOURSECS + 5 * MINSECS;
        $this->assertFalse($hours->is_open($night));
        $this->assertSame(5 * MINSECS, $hours->waited($night, $wednesday));
        $this->assertSame(self::MONDAY_NOON + 2 * DAYSECS - 5 * HOURSECS, $hours->next_open($night));
        // Friday closes at 15:00; Saturday and Sunday are closed: it opens on Monday at 7:00.
        $friday = self::MONDAY_NOON + 4 * DAYSECS + 4 * HOURSECS;
        $this->assertSame(self::MONDAY_NOON + 7 * DAYSECS - 5 * HOURSECS, $hours->next_open($friday));
        // Without hours, always open.
        $always = new hours('Atlantic/Canary', []);
        $this->assertTrue($always->is_open($night));
        $this->assertFalse($always->export()['enabled']);
        $this->assertSame(['2026-11-17'], $hours->export()['closed']);
    }

    /**
     * The closed days come from the events of a calendar whose name starts with one of the words.
     */
    public function test_closed(): void {
        set_config('chatcalendar', 'https://calendar.example.com/basic.ics', 'local_oksigeniamarandu');
        set_config('chatclosedwords', 'Closed,Bank holiday', 'local_oksigeniamarandu');
        closed::fake_for_tests("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" .
            "BEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20261208\r\nDTEND;VALUE=DATE:20261209\r\n" .
            "SUMMARY:Bank holiday\r\nEND:VEVENT\r\n" .
            "BEGIN:VEVENT\r\nUID:b\r\nDTSTART;VALUE=DATE:20261221\r\nDTEND;VALUE=DATE:20261224\r\n" .
            "SUMMARY:Holidays\r\nEND:VEVENT\r\n" .
            "BEGIN:VEVENT\r\nUID:c\r\nDTSTART;VALUE=DATE:20261231\r\nDTEND;VALUE=DATE:20270102\r\nSUMMARY:🔒 Closed\r\n" .
            "END:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->assertSame(['2026-12-08', '2026-12-31', '2027-01-01'], closed::days(self::MONDAY_NOON));
    }

    /**
     * The wait starts with the first message of the contact after the last answer of a person: a bot does not end it,
     * nor does a note.
     */
    public function test_wait(): void {
        $now = time();
        $wait = mirror::from_messages([
            $this->message(1, 0, 'Hola', $now - 100),
            $this->message(2, 1, 'Elija', $now - 90, 'agent_bot'),
            $this->message(3, 0, 'Secretaría', $now - 80),
            $this->message(4, 1, 'Ojo', $now - 70, 'user', true),
        ]);
        $this->assertSame($now - 100, $wait['waitsince']);
        $this->assertSame($now - 80, $wait['lastincoming']);
        $this->assertSame('note', $wait['previewkind']);
        $answered = mirror::from_messages([$this->message(1, 0, 'Hola', $now - 100), $this->message(2, 1, 'Dígame', $now - 90)]);
        $this->assertSame(0, $answered['waitsince']);
        // The bot of the menu with its own user writes as a bot.
        set_config('chatautomatic', '9', 'local_oksigeniamarandu');
        $bot = $this->message(2, 1, 'Les pasamos', $now - 90);
        $bot['sender']['id'] = 9;
        $this->assertSame('bot', message::kind($bot));
    }

    /**
     * What the family reads has the signature on top; the page shows it apart, and the mentions as names.
     */
    public function test_message(): void {
        $user = $this->person('luis@example.com', 'Luis');
        $viewer = new viewer((int)$user->id, 'Luis', ['id' => 2, 'name' => 'Luis Gómez', 'email' => 'luis@example.com',
            'role' => 'agent'], false, [11]);
        $this->assertSame('Luis · Recibos', message::signature($viewer));
        $exported = message::export(['id' => 5, 'message_type' => 1, 'content' => "*Luis · Recibos*\nLo miramos.",
            'created_at' => 1, 'sender' => ['type' => 'user']], $viewer, [5 => $user->id]);
        $this->assertSame(['Luis · Recibos', 'Lo miramos.', true], [$exported['signature'], $exported['text'],
            $exported['author']['me']]);
        $note = message::mentions('Mira esto, @Ana Pérez, y tú @Marta.');
        $this->assertStringContainsString('[@Ana Pérez](mention://user/1/', $note);
        $this->assertStringContainsString('[@Marta](mention://user/3/', $note);
        $this->assertSame('Mira esto, @Ana Pérez, y tú @Marta.', message::plain_text($note));
    }

    /**
     * The lines of Chatwoot about what happened are said as the page says things, with first names and departments
     * as people write them; the «unassigned» that comes with passing to a department does not show.
     */
    public function test_activity(): void {
        $this->assertSame(['Ana passed it to Secretaría', 'passedteam'], message::activity('Asignado a secretaría por Ana Pérez'));
        $this->assertSame(['Ana passed it to Luis', 'gave'], message::activity('Assigned to Luis Gómez by Ana Pérez'));
        $this->assertSame(['Luis marked it resolved', 'resolved'], message::activity('La conversación fue marcada por Luis Gómez'));
        $this->assertSame(['Ana took it', 'took'], message::activity('Asignado a Ana Pérez via secretaría por Ana Pérez'));
        $this->assertSame(['Algo nuevo', ''], message::activity('Algo nuevo'));
        $user = $this->person('ana@example.com', 'Ana');
        $viewer = viewer::for_user($user);
        $list = message::export_list([
            ['id' => 1, 'message_type' => 2, 'content' => 'Asignado a secretaría por Ana Pérez', 'created_at' => 100],
            ['id' => 2, 'message_type' => 2, 'content' => 'Conversación no asignada por Ana Pérez', 'created_at' => 101],
            ['id' => 3, 'message_type' => 2, 'content' => 'Conversación no asignada por Ana Pérez', 'created_at' => 500],
        ], $viewer);
        $this->assertSame(['Ana passed it to Secretaría', 'Ana left it for anyone'], array_column($list, 'text'));
    }

    /**
     * The approved messages are those of the inbox that are approved and not of a notice.
     */
    public function test_templates(): void {
        $all = templates::all();
        $this->assertSame(['chat_retomar'], array_column($all, 'name'));
        $this->assertSame([['key' => '1', 'example' => 'Laura', 'label' => '']], $all[0]['params']);
        $this->assertSame('Hola, Pedro: seguimos con su consulta.', templates::render($all[0], ['1' => 'Pedro']));
    }

    /**
     * The messages to write first go first, and those Meta has not decided yet are counted.
     */
    public function test_templates_to_start(): void {
        set_config('chatstarttemplates', 'chat_retomar', 'local_oksigeniamarandu');
        set_config('chattemplatesinfo', json_encode(['chat_retomar' => ['title' => 'Retomar',
            'description' => 'Pregunta si siguen por aquí.', 'gaps' => ['1' => 'nombre']]]), 'local_oksigeniamarandu');
        $all = templates::all();
        $this->assertSame([true, 3, 'Retomar', 'Pregunta si siguen por aquí.', 'nombre'], [$all[0]['start'], $all[0]['inbox'],
            $all[0]['title'], $all[0]['description'], $all[0]['params'][0]['label']]);
        $this->assertSame(1, templates::pending());
        $message = templates::message($all[0], ['1' => 'Pedro']);
        $this->assertSame(['Hola, Pedro: seguimos con su consulta.', 'chat_retomar'], [$message['content'],
            $message['template_params']['name']]);
    }

    /**
     * A number is written with its country code; a person finds who already has a conversation, and starting one with
     * someone new makes the contact and the conversation, open, in the department chosen and kept by whoever starts it.
     */
    public function test_start(): void {
        mirror::sync();
        $this->assertSame('34600000101', starter::number('+34 600 00 01 01'));
        $this->assertSame('34600000101', starter::number('0034 600-000-101'));
        $this->assertNull(starter::number('600 00 01 01'));
        $ana = $this->person('ana@example.com', 'Ana');
        $luis = $this->person('luis@example.com', 'Luis');
        $this->setUser($ana);

        $found = external_api::clean_returnvalue(chat_find::execute_returns(), chat_find::execute('+34 600 000 001'));
        $this->assertSame([['Laura Martín', '34600000001', 'contact', 1, true]], array_map(function ($one) {
            return [$one['name'], $one['phone'], $one['source'], $one['conversation'], $one['open']];
        }, $found['candidates']));
        $card = external_api::clean_returnvalue(chat_contact::execute_returns(), chat_contact::execute(0, '+34600000001', 'Laura'));
        $this->assertSame(['34600000001', [1]], [$card['phone'], array_column($card['conversations'], 'id')]);
        $found = external_api::clean_returnvalue(chat_find::execute_returns(), chat_find::execute('600 000 001'));
        $this->assertSame([[], true], [$found['candidates'], $found['needprefix']]);
        $found = external_api::clean_returnvalue(chat_find::execute_returns(), chat_find::execute('Iván'));
        $this->assertSame([['Iván Torres', 'contact', 0, 'Recibos']], array_map(function ($one) {
            return [$one['name'], $one['source'], $one['conversation'], $one['elsewhere']];
        }, $found['candidates']));

        $gaps = [['key' => '1', 'value' => 'Pedro']];
        $result = external_api::clean_returnvalue(
            chat_start::execute_returns(),
            chat_start::execute('+34 600 000 009', 'Pedro Gil', 10, 'chat_retomar', 'es', $gaps)
        );
        $this->assertSame('started', $result['status']);
        $conversation = $result['conversation'];
        $this->assertSame(['Pedro Gil', '34600000009', 10, true, 'open'], [$conversation['contact']['name'],
            $conversation['contact']['phone'], $conversation['teamid'], $conversation['assignee']['me'], $conversation['status']]);
        $made = array_values(array_filter($this->client->calls, function ($call) {
            return $call[0] === 'POST' && in_array($call[1], ['/contacts', '/conversations']);
        }));
        $this->assertSame('+34600000009', $made[0][2]['phone_number']);
        $this->assertSame([3, 1, 10, 'chat_retomar', 'Hola, Pedro: seguimos con su consulta.'], [$made[1][2]['inbox_id'],
            $made[1][2]['assignee_id'], $made[1][2]['team_id'], $made[1][2]['message']['template_params']['name'],
            $made[1][2]['message']['content']]);
        $this->assertSame('open', $this->client->conversations[$conversation['id']]['status']);

        // Someone with a conversation open: the person who sees it gets it; whoever does not, where it is.
        $result = external_api::clean_returnvalue(
            chat_start::execute_returns(),
            chat_start::execute('+34600000001', '', 10, 'chat_retomar', 'es', $gaps)
        );
        $this->assertSame(['exists', 1], [$result['status'], $result['conversation']['id']]);
        $this->setUser($luis);
        $result = external_api::clean_returnvalue(
            chat_start::execute_returns(),
            chat_start::execute('+34600000001', '', 11, 'chat_retomar', 'es', $gaps)
        );
        $this->assertSame(['busy', 'Secretaría'], [$result['status'], $result['where']]);
        $this->assertArrayNotHasKey('conversation', $result);

        $this->expectException(\moodle_exception::class);
        chat_start::execute('+34600000010', '', 10, 'chat_retomar', 'es', []);
    }

    /**
     * Each person sees their departments and what they have; whoever administers the account sees everything; whoever
     * is not a person of the account sees nothing, unless they may see everything, and then they only look.
     */
    public function test_viewer(): void {
        mirror::sync();
        $ana = viewer::for_user($this->person('ana@example.com', 'Ana'));
        $luis = viewer::for_user($this->person('LUIS@example.com', 'Luis'));
        $marta = viewer::for_user($this->person('marta@example.com', 'Marta'));
        $this->assertNull(viewer::for_user($this->person('nadie@example.com', 'Nadie')));
        $this->assertSame([[10], false], [$ana->teams, $ana->seesall]);
        $this->assertTrue($marta->seesall);
        $rows = array_values($GLOBALS['DB']->get_records(mirror::TABLE, null, 'cwid'));
        $this->assertSame([true, false, true], array_map([$ana, 'sees'], $rows));
        $this->assertSame([false, true, false], array_map([$luis, 'sees'], $rows));
        $this->assertSame([1, 1], [$ana->inboxes()['mine']['total'], $ana->inboxes()['unassigned']['reply']]);
        $admin = viewer::for_user(get_admin());
        $this->assertTrue($admin->readonly && $admin->seesall);
        // The bot was answering conversation 1: it still waits for a person.
        $first = $GLOBALS['DB']->get_record(mirror::TABLE, ['cwid' => 1]);
        $this->assertGreaterThan(0, (int)$first->waitsince);
        $this->assertSame('34600000001', $first->phone);
    }

    /**
     * Answering a conversation nobody has signs it and makes it yours; passing it to another department takes it out of
     * your list and tells that department who passed it.
     */
    public function test_answer_and_pass(): void {
        mirror::sync();
        $luis = $this->person('luis@example.com', 'Luis');
        $ana = $this->person('ana@example.com', 'Ana');
        $this->setUser($luis);
        $result = external_api::clean_returnvalue(chat_send::execute_returns(), chat_send::execute(2, 'Lo miramos.'));
        $this->assertSame("*Luis · Recibos*\nLo miramos.", $this->client->messages[2][1]['content']);
        $this->assertSame(['Luis · Recibos', 'Lo miramos.'], [$result['message']['signature'], $result['message']['text']]);
        $this->assertTrue($result['conversation']['assignee']['me']);
        $this->assertSame('waiting', $result['conversation']['section']);

        $result = external_api::clean_returnvalue(chat_action::execute_returns(), chat_action::execute(2, 'team', 10));
        $this->assertSame('gone', $result['conversation']['status']);

        $this->setUser($ana);
        $poll = external_api::clean_returnvalue(chat_poll::execute_returns(), chat_poll::execute(0, 2, 0));
        $two = array_values(array_filter($poll['conversations'], function ($one) {
            return $one['id'] === 2;
        }))[0];
        $this->assertSame(['Luis', true], [$two['passed']['by'], $two['passed']['toteam']]);
        $this->assertArrayNotHasKey('assignee', $two);
        $this->assertSame([], $poll['readby']);
        $this->assertCount(2, $poll['messages']);
    }

    /**
     * The card of «Your work» says what waits for the person and, as urgent, what has waited four hours of the team.
     */
    public function test_work_card(): void {
        mirror::sync();
        $ana = $this->person('ana@example.com', 'Ana');
        $card = chat\workcard::for_user((int)$ana->id);
        $this->assertSame('local_oksigeniamarandu-chat', $card['key']);
        $this->assertSame(['today'], array_unique(array_column($card['alerts'], 'level')));
        $this->assertCount(2, $card['actions']);
        // A line about a single conversation opens it, not the list.
        foreach ($card['alerts'] as $alert) {
            if (!preg_match('/to answer|nobody has/', $alert['text'])) {
                continue;
            }
            $one = preg_match('/^1 /', $alert['text']) === 1;
            $this->assertSame($one, strpos($alert['url'], 'c=') !== false, $alert['text']);
        }
        // Four hours of the team later, the contact of the first conversation has waited too long.
        $GLOBALS['DB']->set_field(mirror::TABLE, 'waitsince', time() - 5 * DAYSECS, ['cwid' => 1]);
        $card = chat\workcard::for_user((int)$ana->id);
        $this->assertSame('urgent', $card['alerts'][0]['level']);
        $this->assertStringContainsString('Laura Martín', $card['alerts'][0]['text']);
        $this->assertNull(chat\workcard::for_user((int)$this->person('nadie@example.com', 'Nadie')->id));
    }

    /**
     * Whoever is in the department hears when a contact starts waiting and nobody has it, once in ten minutes; whoever
     * is named in a note, and whoever gets a conversation passed, hear of it too; never the one who did it.
     */
    public function test_notify(): void {
        global $DB;
        mirror::sync();
        $ana = $this->person('ana@example.com', 'Ana');
        $luis = $this->person('luis@example.com', 'Luis');
        $sink = $this->redirectMessages();
        $row = $DB->get_record(mirror::TABLE, ['cwid' => 1]);
        $old = clone $row;
        $old->waitsince = 0;
        $old->lastincoming = 0;
        $row->lastincoming = self::MONDAY_NOON - 60;
        $row->waitsince = self::MONDAY_NOON - 60;
        chat\notify::changed($old, $row, [], self::MONDAY_NOON);
        // The same again, a minute later: held.
        chat\notify::changed($old, $row, [], self::MONDAY_NOON + 60);
        // A note of Luis that names Ana.
        $old->lastmsgid = 10;
        chat\notify::changed(
            $old,
            $row,
            [['id' => 11, 'message_type' => 1, 'private' => true, 'created_at' => self::MONDAY_NOON,
            'content' => 'Mira [@Ana](mention://user/1/Ana%20P%C3%A9rez), por favor', 'sender' => ['id' => 2, 'type' => 'user']]],
            self::MONDAY_NOON
        );
        // Luis passes conversation 2 to Ana.
        mirror::passed(2, (int)$luis->id, 1, 0);
        $this->runAdhocTasks(\local_oksigeniamarandu\task\chat_notify::class);
        $messages = $sink->get_messages();
        $this->assertCount(3, $messages);
        $this->assertSame([(int)$ana->id], array_unique(array_map('intval', array_column($messages, 'useridto'))));
        $texts = array_column($messages, 'subject');
        $this->assertStringContainsString('Laura Martín writes to Secretaría', $texts[0]);
        $this->assertStringContainsString('Luis names you', $texts[1]);
        $this->assertStringContainsString('Luis passed you the conversation of Iván Torres', $texts[2]);
        $this->assertStringContainsString('/local/oksigeniamarandu/chat.php?c=1', $messages[0]->contexturl);
        // At night nobody hears of a message.
        $sink->clear();
        $row->lastincoming = self::MONDAY_NOON + 10 * HOURSECS;
        chat\notify::changed($old, $row, [], self::MONDAY_NOON + 10 * HOURSECS);
        $this->runAdhocTasks(\local_oksigeniamarandu\task\chat_notify::class);
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * What was resolved on other days comes back on request, only what the person sees and only the last days; the
     * notices of the conversations come with the poll, for the desktop; the small choices of the person are kept.
     */
    public function test_resolved_notices_and_flags(): void {
        global $DB;
        mirror::sync();
        $ana = $this->person('ana@example.com', 'Ana');
        $this->setUser($ana);
        $DB->set_field(mirror::TABLE, 'status', 'resolved', ['cwid' => 1]);
        $DB->set_field(mirror::TABLE, 'lastactivity', time() - 2 * DAYSECS, ['cwid' => 1]);
        $DB->set_field(mirror::TABLE, 'status', 'resolved', ['cwid' => 3]);
        $DB->set_field(mirror::TABLE, 'lastactivity', time() - 40 * DAYSECS, ['cwid' => 3]);
        $DB->set_field(mirror::TABLE, 'status', 'resolved', ['cwid' => 2]);
        $DB->set_field(mirror::TABLE, 'lastactivity', time() - DAYSECS, ['cwid' => 2]);
        $poll = external_api::clean_returnvalue(chat_poll::execute_returns(), chat_poll::execute(0, 0, 0));
        $this->assertNotContains(1, array_column($poll['conversations'], 'id'));
        $this->assertSame([], $poll['notices']);
        // Conversation 2 is of a department Ana is not in; 3 is too old.
        $resolved = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute());
        $this->assertSame([1], array_column($resolved['conversations'], 'id'));
        $first = $resolved['conversations'][0];
        $this->assertSame(['resolved', false, 30], [$first['section'], $resolved['more'], $resolved['days']]);
        $older = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute(time() - 3 * DAYSECS));
        $this->assertSame([], $older['conversations']);

        $since = time() - 5;
        $DB->insert_record('notifications', (object)['useridfrom' => \core_user::NOREPLY_USER, 'useridto' => $ana->id,
            'subject' => 'Laura Martín: ¿Me confirman el recibo?', 'fullmessage' => '', 'fullmessageformat' => FORMAT_PLAIN,
            'fullmessagehtml' => '', 'smallmessage' => '', 'component' => 'local_oksigeniamarandu', 'eventtype' => 'chat',
            'contexturl' => (new \moodle_url('/local/oksigeniamarandu/chat.php', ['c' => 1]))->out(false),
            'timecreated' => time()]);
        $poll = external_api::clean_returnvalue(chat_poll::execute_returns(), chat_poll::execute($since, 0, 0));
        $this->assertSame([[1, 'Laura Martín: ¿Me confirman el recibo?']], array_map(function ($notice) {
            return [$notice['conversation'], $notice['text']];
        }, $poll['notices']));

        $this->assertSame(['tour' => '', 'sound' => 'on', 'desktop' => ''], chat_flag::current());
        chat_flag::execute('sound', 'off');
        chat_flag::execute('tour', '1');
        $this->assertSame(['tour' => '1', 'sound' => 'off', 'desktop' => ''], chat_flag::current());
        // Both tours seen: the list is kept, with its comma.
        chat_flag::execute('tour', 'inicio,conv');
        $this->assertSame('inicio,conv', chat_flag::current()['tour']);
        $this->expectException(\invalid_parameter_exception::class);
        chat_flag::execute('colour', 'red');
    }

    /**
     * What was brought into Chatwoot directly (an old history) comes into the index with a full pass, whenever it was;
     * an old resolved conversation opens by its number, if the person sees it.
     */
    public function test_sync_all_and_old_conversation(): void {
        global $DB;
        $this->assertSame([3, 3], mirror::sync_all());
        $this->assertSame([0, 3], mirror::sync_all());
        $DB->delete_records(mirror::TABLE);
        $this->assertSame([3, 3], mirror::sync_all());
        $this->assertSame(3, $DB->count_records(mirror::TABLE));
        $DB->set_field(mirror::TABLE, 'status', 'resolved', ['cwid' => 1]);
        $DB->set_field(mirror::TABLE, 'lastactivity', time() - 200 * DAYSECS, ['cwid' => 1]);
        $this->setUser($this->person('ana@example.com', 'Ana'));
        $old = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute(0, 1));
        $this->assertSame([1], array_column($old['conversations'], 'id'));
        $recent = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute());
        $this->assertSame([], $recent['conversations']);
        // The history brings it, whenever it was.
        $history = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute(0, 0, '', true));
        $this->assertSame([1], array_column($history['conversations'], 'id'));
        // Conversation 2 is of a department Ana does not see.
        $other = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute(0, 2));
        $this->assertSame([], $other['conversations']);
        // A search finds every conversation of whom it finds, whenever it was.
        $found = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute(0, 0, 'laura'));
        $this->assertSame([1], array_column($found['conversations'], 'id'));
        // Resolved without a department, as a history brought from a phone: everyone of the team sees it.
        $DB->set_field(mirror::TABLE, 'teamid', 0, ['cwid' => 2]);
        $DB->set_field(mirror::TABLE, 'status', 'resolved', ['cwid' => 2]);
        $other = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute(0, 2));
        $this->assertSame([2], array_column($other['conversations'], 'id'));
    }

    /**
     * A search finds what was said, messages and notes, with the sentence where it is, only in what the person sees;
     * the history filters by who had it, by department and from a date.
     */
    public function test_search_said_and_history_filters(): void {
        global $DB;
        $now = time();
        $this->client->messages[3][] = $this->message(
            32,
            1,
            'El recibo de octubre ya está pagado; lo miró Luis.',
            $now - 700,
            'user',
            true
        );
        mirror::sync();
        $DB->set_field(mirror::TABLE, 'status', 'resolved', []);
        $DB->set_field(mirror::TABLE, 'lastactivity', $now - 2 * DAYSECS, ['cwid' => 1]);
        $DB->set_field(mirror::TABLE, 'lastactivity', $now - 40 * DAYSECS, ['cwid' => 3]);
        $this->setUser($this->person('ana@example.com', 'Ana'));
        // The word is said in 2 too, but Ana does not see its department.
        $found = external_api::clean_returnvalue(chat_resolved::execute_returns(), chat_resolved::execute(0, 0, 'recibo'));
        $this->assertSame([3], array_column($found['conversations'], 'id'));
        $this->assertSame(
            [[3, 'El recibo de octubre ya está pagado; lo miró Luis.', $now - 700, true]],
            array_map('array_values', $found['hits'])
        );

        $history = function (bool $mine = false, int $team = 0, int $from = 0) {
            $result = external_api::clean_returnvalue(
                chat_resolved::execute_returns(),
                chat_resolved::execute(0, 0, '', true, $mine, $team, $from)
            );
            return array_column($result['conversations'], 'id');
        };
        $this->assertSame([1, 3], $history());
        $this->assertSame([3], $history(true));
        $this->assertSame([1, 3], $history(false, 10));
        $this->assertSame([], $history(false, 11));
        $this->assertSame([1], $history(false, 0, $now - 10 * DAYSECS));

        // The sentence: around the words, with what is cut marked, and found without accents.
        $long = str_repeat('Buenas tardes, ', 6) . 'el menú de la semana que viene ya está' . str_repeat(', gracias', 15) . '.';
        $snippet = chat_resolved::snippet($long, 'menu');
        $this->assertStringStartsWith('…', $snippet);
        $this->assertStringEndsWith('…', $snippet);
        $this->assertStringContainsString('el menú de la semana', $snippet);
        $this->assertSame('Hola, ¿qué tal?', chat_resolved::snippet("Hola,\n  ¿qué tal?", 'tal'));
    }

    /**
     * «Remind me»: the conversation is the person's and waits apart until the day chosen, when the team opens; that day
     * it is open again, with the line as a note and a notice. On a resolved one nothing changes until then.
     */
    public function test_remind_me(): void {
        global $DB;
        mirror::sync();
        $ana = $this->person('ana@example.com', 'Ana');
        $viewer = viewer::for_user($ana);
        $hours = hours::of_site();
        // Monday 23 November opens at 7:00 in the Canary Islands (UTC in winter); a Saturday has no hours: 8:00.
        $monday = self::MONDAY_NOON + 7 * DAYSECS - 5 * HOURSECS;
        $this->assertSame($monday, $hours->opening('2026-11-23'));
        $this->assertSame(self::MONDAY_NOON + 5 * DAYSECS - 4 * HOURSECS, $hours->opening('2026-11-21'));
        $this->assertSame(0, $hours->opening('2026-02-30'));

        $row = $DB->get_record(mirror::TABLE, ['cwid' => 1]);
        $now = chat\reminders::set($viewer, $row, '2026-11-23', "  Llamar   por\nla beca ", $hours, self::MONDAY_NOON);
        $this->assertContains(['POST', '/conversations/1/assignments', ['assignee_id' => 1]], $this->client->calls);
        $this->assertContains(
            ['POST', '/conversations/1/toggle_status', ['status' => 'snoozed', 'snoozed_until' => $monday]],
            $this->client->calls
        );
        $this->assertSame('snoozed', $now->status);
        $this->assertSame([1 => ['at' => $monday, 'reason' => 'Llamar por la beca']], chat\reminders::of_user((int)$ana->id));
        $viewer = viewer::for_user($ana);
        $shown = $viewer->export($now, [], self::MONDAY_NOON);
        $this->assertSame(['later', 0, $monday], [$shown['section'], $shown['waitsince'], $shown['remind']['at']]);
        // Ana's now, but apart: «Mine» counts only conversation 3.
        $this->assertSame(1, $viewer->inboxes()['mine']['total']);

        // Not yet; then its day.
        $this->assertSame(0, chat\reminders::due(self::MONDAY_NOON + DAYSECS));
        $sink = $this->redirectMessages();
        $this->client->calls = [];
        $this->assertSame(1, chat\reminders::due($monday + 60));
        $this->assertContains(['POST', '/conversations/1/toggle_status', ['status' => 'open']], $this->client->calls);
        $notes = array_values(array_filter($this->client->calls, function ($call) {
            return $call[1] === '/conversations/1/messages' && $call[0] === 'POST';
        }));
        $this->assertSame(['Reminder: Llamar por la beca', true], [$notes[0][2]['content'], $notes[0][2]['private']]);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('For today: Laura Martín. Llamar por la beca', $messages[0]->subject);
        $this->assertSame([], chat\reminders::of_user((int)$ana->id));
        $this->assertSame(0, chat\reminders::due($monday + 120));

        // A resolved one stays as it is until its day; taken away, nothing is left.
        $DB->set_field(mirror::TABLE, 'status', 'resolved', ['cwid' => 3]);
        $three = $DB->get_record(mirror::TABLE, ['cwid' => 3]);
        $this->client->calls = [];
        chat\reminders::set($viewer, $three, '2026-11-24', '', $hours, self::MONDAY_NOON);
        $this->assertSame([], $this->client->calls);
        $this->assertCount(1, chat\reminders::of_user((int)$ana->id));
        chat\reminders::remove($viewer, $three);
        $this->assertSame([], chat\reminders::of_user((int)$ana->id));
        $this->assertSame([], $this->client->calls);

        $this->expectException(\invalid_parameter_exception::class);
        chat\reminders::set($viewer, $row, '2026-11-16', '', $hours, self::MONDAY_NOON);
    }

    /**
     * The address book: the favourites of the person, the last starred first, and the contacts of the centre by what
     * they are, each with the latest conversation the person sees, or none.
     */
    public function test_agenda(): void {
        global $DB;
        mirror::sync();
        $DB->set_field(mirror::TABLE, 'kind', 'supplier', ['cwid' => 2]);
        $DB->set_field(mirror::TABLE, 'kind', 'staff', ['cwid' => 3]);
        $ana = $this->person('ana@example.com', 'Ana');
        $this->setUser($ana);
        $this->assertSame([], chat\agenda::favourites((int)$ana->id));
        $book = external_api::clean_returnvalue(chat_agenda::execute_returns(), chat_agenda::execute(101, true));
        $this->assertSame([101], chat\agenda::favourites((int)$ana->id));
        $this->assertSame([[101, 'Laura Martín', 1, true]], array_map(function ($contact) {
            return [$contact['contactid'], $contact['name'], $contact['conversation'], $contact['favourite']];
        }, $book['favourites']));
        // The supplier is of a department Ana does not see: it is there, with nothing to open.
        $this->assertSame([['supplier', [[102, 0]]], ['staff', [[103, 3]]]], array_map(function ($group) {
            return [$group['kind'], array_map(function ($contact) {
                return [$contact['contactid'], $contact['conversation']];
            }, $group['contacts'])];
        }, $book['groups']));
        chat_agenda::execute(103, true);
        $this->assertSame([103, 101], chat\agenda::favourites((int)$ana->id));
        $book = external_api::clean_returnvalue(chat_agenda::execute_returns(), chat_agenda::execute(101, false));
        $this->assertSame([103], array_column($book['favourites'], 'contactid'));
    }

    /**
     * The board: a notice reaches the people of the departments chosen (or the whole team), each marks it seen once;
     * only whoever put it tells again, changes it or takes it off.
     */
    public function test_board(): void {
        $ana = $this->person('ana@example.com', 'Ana');
        $luis = $this->person('luis@example.com', 'Luis');
        $marta = $this->person('marta@example.com', 'Marta');
        $viewana = viewer::for_user($ana);
        $viewluis = viewer::for_user($luis);
        $sink = $this->redirectMessages();
        $id = chat\board::post($viewana, 'La fotocopiadora vuelve a funcionar.', [11], false, self::MONDAY_NOON);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame(
            [(int)$luis->id, 'Ana, on the board: La fotocopiadora vuelve a funcionar.'],
            [(int)$messages[0]->useridto, $messages[0]->subject]
        );
        $items = chat\board::for_viewer($viewluis, self::MONDAY_NOON);
        $this->assertSame([[$id, false, 0, 2, 1, ['recibos']]], array_map(function ($item) {
            $teams = array_map('strtolower', $item['teams']);
            return [$item['id'], $item['mine'], $item['seen'], $item['total'], $item['seencount'], $teams];
        }, $items));
        $this->assertSame(1, chat\board::counts((int)$luis->id)['tosee']);
        $this->assertTrue(chat\board::seen((int)$luis->id, $id, self::MONDAY_NOON + 60));
        $this->assertFalse(chat\board::seen((int)$luis->id, $id, self::MONDAY_NOON + 120));
        $this->assertSame(0, chat\board::counts((int)$luis->id)['tosee']);
        $this->assertSame(2, chat\board::for_viewer($viewana, self::MONDAY_NOON)[0]['seencount']);
        // Marta is in no department of it: she does not see it.
        $this->assertSame([], chat\board::for_viewer(viewer::for_user($marta), self::MONDAY_NOON));

        // For the whole team: the three people (the bot of the account is not one).
        $sink->clear();
        $all = chat\board::post($viewana, 'El lunes no hay comedor.', [], true, self::MONDAY_NOON);
        $this->assertCount(2, $sink->get_messages());
        $this->assertSame(3, chat\board::for_viewer($viewana, self::MONDAY_NOON)[0]['total']);
        $sink->clear();
        chat\board::remind($viewana, $all, (int)$marta->id, self::MONDAY_NOON + 300);
        $this->assertSame('Ana reminds you, on the board: El lunes no hay comedor.', $sink->get_messages()[0]->subject);
        chat\board::change($viewana, $all, 'El lunes 23 no hay comedor.', self::MONDAY_NOON + 400);
        $this->assertSame(
            ['El lunes 23 no hay comedor.', true],
            array_values(array_intersect_key(chat\board::for_viewer($viewana, self::MONDAY_NOON)[0], ['text' => 0, 'edited' => 0]))
        );
        chat\board::change($viewana, $all, null, self::MONDAY_NOON + 500);
        $this->assertSame([$id], array_column(chat\board::for_viewer($viewana, self::MONDAY_NOON), 'id'));

        // The service gives the same, and only whoever put a notice changes it.
        $this->setUser($luis);
        $board = external_api::clean_returnvalue(chat_board::execute_returns(), chat_board::execute());
        $this->assertSame([$id], array_column($board['items'], 'id'));
        $this->expectException(\moodle_exception::class);
        chat_board::execute('remove', $id);
    }

    /**
     * A history brought in from a phone opens no 24 hours (Meta counts only what reaches the API); a full pass that
     * rebuilds writes again what changed in Chatwoot without moving its time, such as a department set in bulk.
     */
    public function test_imported_and_rebuild(): void {
        global $DB;
        $now = time();
        $imported = [['id' => 1, 'message_type' => 0, 'content' => 'Recibido, gracias', 'created_at' => $now - 600,
            'private' => false, 'sender' => ['type' => 'contact', 'id' => 1], 'content_attributes' => ['imported' => true]]];
        $this->assertSame(0, mirror::from_messages($imported)['lastincoming']);
        $imported[0]['content_attributes'] = [];
        $this->assertSame($now - 600, mirror::from_messages($imported)['lastincoming']);

        mirror::sync_all();
        $this->client->conversations[2]['meta']['team'] = ['id' => 10];
        $this->assertSame([0, 3], mirror::sync_all());
        $this->assertSame(11, (int)$DB->get_field(mirror::TABLE, 'teamid', ['cwid' => 2]));
        $this->assertSame([1, 3], mirror::sync_all(true));
        $this->assertSame(10, (int)$DB->get_field(mirror::TABLE, 'teamid', ['cwid' => 2]));
    }

    /**
     * What a number said no to shows in its file, with the trail; the team marks it from the chat, and writing again
     * takes it back. The price of each category is what the centre says, or a rough one.
     */
    public function test_optout_in_chat_and_prices(): void {
        mirror::sync();
        $this->setUser($this->person('ana@example.com', 'Ana'));
        $file = function () {
            return external_api::clean_returnvalue(chat_contact::execute_returns(), chat_contact::execute(1))['optout'];
        };
        $this->assertSame(['all' => 0, 'scopes' => [], 'trail' => []], $file());
        chat_action::execute(1, 'optout', 0, 'out');
        $said = $file();
        $this->assertGreaterThan(0, $said['all']);
        $step = $said['trail'][0];
        $this->assertSame(['optout', 'chat:Ana', 'all'], [$step['action'], $step['source'], $step['scope']]);
        \local_oksigeniamarandu\local\conversation::received('34600000001', 'text', 'Hola', time(), 'wamid.Z', null);
        $said = $file();
        $this->assertSame([0, 'optin', 'wrote'], [$said['all'], $said['trail'][0]['action'], $said['trail'][0]['source']]);

        $this->assertSame(templates::PRICES, templates::prices());
        $set = ['utility' => 0.0166, 'marketing' => 'x', 'currency' => 'USD'];
        set_config('chatprices', json_encode($set), 'local_oksigeniamarandu');
        $this->assertSame(
            ['currency' => 'USD', 'utility' => 0.0166, 'marketing' => 0.06, 'authentication' => 0.03],
            templates::prices()
        );
    }

    /**
     * From Telegram, whoever linked their chat keeps a conversation, leaves a note replying to its notice and passes it
     * to another department with the button of that department.
     */
    public function test_telegram_actions(): void {
        global $DB;
        mirror::sync();
        $ana = $this->person('ana@example.com', 'Ana');
        $code = \local_oksigeniamarandu\api::invite('local_oksigeniamarandu', 0, (int)$ana->id);
        $contact = \local_oksigeniamarandu\local\consent::get_or_create('tg:4242');
        $DB->update_record(\local_oksigeniamarandu\local\consent::CONTACTS, (object)['id' => $contact->id, 'status' => 'active']);
        $DB->set_field(\local_oksigeniamarandu\local\consent::LINKS, 'contactid', $contact->id, ['code' => $code]);
        $this->redirectMessages();
        chat\notify::send(1, (int)$ana->id, 'incoming', ['name' => 'Laura Martín', 'text' => '¿Me confirman el recibo?']);
        $queue = \local_oksigeniamarandu\local\queue::TABLE;
        $row = $DB->get_record($queue, ['notice' => 'chat'], '*', MUST_EXIST);
        $this->assertSame(['c:1', (int)$contact->id], [$row->ref, (int)$row->contactid]);
        $DB->update_record($queue, (object)['id' => $row->id, 'status' => 'sent', 'wamid' => 'tg.555']);
        $chat = ['id' => 4242, 'type' => 'private'];
        $press = function (string $data) use ($chat) {
            return \local_oksigeniamarandu\local\telegram::handle(['callback_query' => ['id' => 'q' . $data, 'data' => $data,
                'message' => ['message_id' => 555, 'chat' => $chat]]]);
        };

        $answer = $press('a:' . $row->token . ':take');
        $this->assertSame('It is yours: the conversation of Laura Martín.', $answer['text']);
        $this->assertContains(['POST', '/conversations/1/assignments', ['assignee_id' => 1]], $this->client->calls);

        $note = \local_oksigeniamarandu\local\telegram::handle(['message' => ['message_id' => 556, 'chat' => $chat,
            'from' => ['id' => 4242], 'text' => 'La llamo mañana', 'reply_to_message' => ['message_id' => 555]]]);
        $this->assertStringContainsString('Note kept in the conversation of Laura Martín', $note['text']);
        $posted = array_values(array_filter($this->client->calls, function ($call) {
            return $call[0] === 'POST' && $call[1] === '/conversations/1/messages';
        }));
        $this->assertSame([['La llamo mañana', true]], array_map(function ($call) {
            return [$call[2]['content'], $call[2]['private']];
        }, $posted));

        $answer = $press('a:' . $row->token . ':pass');
        $this->assertSame([[['text' => 'Recibos', 'callback_data' => 'mrdp:1:11']]], $answer['reply_markup']['inline_keyboard']);
        $answer = $press('mrdp:1:11');
        $this->assertSame('editMessageText', $answer['method']);
        $this->assertStringContainsString('Passed to Recibos', $answer['text']);
        $this->assertContains(['POST', '/conversations/1/assignments', ['team_id' => 11]], $this->client->calls);

        // A chat that is not of the team cannot do anything.
        $this->assertNull(\local_oksigeniamarandu\local\telegram::handle(['callback_query' => ['id' => 'q9',
            'data' => 'mrdp:1:10', 'message' => ['message_id' => 9, 'chat' => ['id' => 777, 'type' => 'private']]]])['reply_markup']
            ?? null);
    }

    /**
     * The stages are data of the organisation: a group is known by the stage the record guesses or by a pattern, and is
     * shown as people say it; the list of a conversation does not show the signature on top.
     */
    public function test_stages(): void {
        set_config('chatstages', json_encode([
            ['label' => 'Nursery', 'color' => '#0a8288', 'dark' => '#38b5b8', 'match' => '^\\d+\\s+años?\\b',
                'show' => '^(\\d+\\s+años?)'],
            ['label' => 'Primaria', 'color' => '#067e36', 'code' => 'PRI'],
        ]), 'local_oksigeniamarandu');
        [$stage, $group, $order] = chat\family::stage_of('PRI 5º B', 'PRI');
        $this->assertSame(['Primaria', '5.º B', 1], [$stage['label'], $group, $order]);
        [$stage, $group] = chat\family::stage_of('5º PRI B');
        $this->assertSame(['Primaria', '5.º B'], [$stage['label'], $group]);
        [$stage, $group, $order] = chat\family::stage_of('2 años El Rinconcito');
        $this->assertSame(['Nursery', '2 años', 0, '#38b5b8'], [$stage['label'], $group, $order, $stage['dark']]);
        $this->assertNull(chat\family::stage_of('Otro grupo')[0]);
        $preview = mirror::from_messages([['id' => 1, 'message_type' => 1, 'content' => "*Ana · Secretaría*\nYa está.",
            'created_at' => 1, 'sender' => ['type' => 'user', 'id' => 1]]]);
        $this->assertSame('Ya está.', $preview['preview']);
    }

    /**
     * Only a webhook signed with the secret and recent is taken.
     */
    public function test_verify(): void {
        $body = '{"event":"message_created"}';
        $time = (string)self::MONDAY_NOON;
        $signature = 'sha256=' . hash_hmac('sha256', $time . '.' . $body, 'secret');
        $this->assertTrue(mirror::verify($body, $time, $signature, 'secret', self::MONDAY_NOON + 10));
        $this->assertFalse(mirror::verify($body, $time, $signature, 'other', self::MONDAY_NOON + 10));
        $this->assertFalse(mirror::verify($body, $time, $signature, 'secret', self::MONDAY_NOON + HOURSECS));
        $this->assertFalse(mirror::verify($body, $time, $signature, '', self::MONDAY_NOON));
    }
}
