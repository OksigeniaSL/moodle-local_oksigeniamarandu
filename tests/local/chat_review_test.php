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

use local_oksigeniamarandu\external\chat_agenda;
use local_oksigeniamarandu\external\chat_messages;
use local_oksigeniamarandu\local\chat\actions;
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\reminders;
use local_oksigeniamarandu\local\chat\starter;
use local_oksigeniamarandu\local\chat\templates;
use local_oksigeniamarandu\local\chat\viewer;

/**
 * Who sees what in the conversations of the team, after the review of 21 November 2026: what is passed, reminded, started
 * or marked as a favourite never opens a conversation of another department. Made-up people; Chatwoot is never called.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\chat\viewer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_oksigeniamarandu\local\chat\viewer::class)]
final class chat_review_test extends \advanced_testcase {
    /** @var chatwoot The made-up Chatwoot. */
    private $client;

    /**
     * A Chatwoot of made-up people and conversations, never called.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('chatwooturl', 'https://chat.example.com', 'local_oksigeniamarandu');
        set_config('chatwootaccount', 7, 'local_oksigeniamarandu');
        set_config('chatwoottoken', \core\encryption::encrypt('token'), 'local_oksigeniamarandu');
        set_config('chatwootplatform', '', 'local_oksigeniamarandu');
        $this->client = new class ('https://chat.example.com', 7, 'token') extends chatwoot {
            /** @var array What was asked. */
            public $calls = [];
            /** @var array The conversations, by id. */
            public $conversations = [];
            /** @var array Their messages, by conversation. */
            public $messages = [];
            /** @var bool Inbox with «lock to single conversation»: POST /conversations gives the last one back. */
            public $lock = false;
            /**
             * Answers as Chatwoot would.
             *
             * @param string $method The method.
             * @param string $path The path.
             * @param array $data What goes with it.
             * @return array
             */
            public function call(string $method, string $path, array $data = []): array {
                $this->calls[] = [$method, $path, $data];
                if ($path === '/agents') {
                    return [
                        ['id' => 1, 'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'role' => 'agent'],
                        ['id' => 2, 'name' => 'Luis Gómez', 'email' => 'luis@example.com', 'role' => 'agent'],
                    ];
                }
                if ($path === '/teams') {
                    return [['id' => 10, 'name' => 'secretaría'], ['id' => 11, 'name' => 'orientación']];
                }
                if (preg_match('~^/teams/(\d+)/team_members$~', $path, $m)) {
                    return $m[1] == 10 ? [['id' => 1]] : [['id' => 2]];
                }
                if ($path === '/inboxes') {
                    $week = [];
                    for ($d = 0; $d < 7; $d++) {
                        $week[] = ['day_of_week' => $d, 'closed_all_day' => false, 'open_all_day' => true, 'open_hour' => 0,
                            'open_minutes' => 0, 'close_hour' => 23, 'close_minutes' => 59];
                    }
                    return ['payload' => [['id' => 3, 'name' => 'WhatsApp', 'timezone' => 'Atlantic/Canary',
                        'working_hours_enabled' => true, 'working_hours' => $week, 'message_templates' => [
                            ['name' => 'chat_retomar', 'status' => 'APPROVED', 'language' => 'es', 'category' => 'UTILITY',
                                'components' => [['type' => 'BODY', 'text' => 'Hola, {{1}}.',
                                    'example' => ['body_text' => [['Laura']]]]]],
                        ]]]];
                }
                if ($path === '/canned_responses') {
                    return [];
                }
                if ($path === '/contacts/search') {
                    $found = [];
                    foreach ($this->conversations as $one) {
                        $s = $one['meta']['sender'];
                        if (strpos($s['phone_number'], $data['q']) !== false) {
                            $found[$s['id']] = $s;
                        }
                    }
                    return ['payload' => array_values($found)];
                }
                if (preg_match('~^/contacts/(\d+)/conversations$~', $path, $m)) {
                    return ['payload' => array_values(array_filter($this->conversations, function ($one) use ($m) {
                        return (int)$one['meta']['sender']['id'] === (int)$m[1];
                    }))];
                }
                if ($path === '/conversations' && $method === 'POST') {
                    if ($this->lock) {
                        // Chatwoot's ConversationBuilder: look_up_exising_conversation gives the last one as it is.
                        foreach (array_reverse($this->conversations, true) as $id => $one) {
                            if ((int)$one['meta']['sender']['id'] === (int)$data['contact_id']) {
                                return $one;
                            }
                        }
                    }
                    throw new \coding_exception('not expected');
                }
                if (preg_match('~^/conversations/(\d+)/toggle_status$~', $path, $m)) {
                    $this->conversations[$m[1]]['status'] = $data['status'];
                    $this->conversations[$m[1]]['updated_at'] = (string)microtime(true);
                    return [];
                }
                if (preg_match('~^/conversations/(\d+)/messages$~', $path, $m)) {
                    if ($method === 'POST') {
                        $message = ['id' => 500 + count($this->calls), 'content' => $data['content'] ?? '',
                            'message_type' => 1, 'private' => !empty($data['private']), 'created_at' => time(),
                            'sender' => ['type' => 'user', 'id' => 1]];
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
        };
        directory::use_client($this->client);
    }

    /**
     * The real client again.
     */
    protected function tearDown(): void {
        directory::use_client(null);
        parent::tearDown();
    }

    /**
     * A conversation as Chatwoot gives it.
     *
     * @param int $id Its id.
     * @param int $team Its department.
     * @param int $assignee Who has it, or 0.
     * @param string $status Its status.
     * @param int $contact Its contact.
     * @param string $phone The number of the contact.
     * @return array
     */
    private function conv(int $id, int $team, int $assignee, string $status, int $contact, string $phone): array {
        return ['id' => $id, 'inbox_id' => 3, 'status' => $status, 'updated_at' => (string)microtime(true),
            'last_activity_at' => time(), 'meta' => ['sender' => ['id' => $contact, 'name' => 'Familia ' . $id,
                'phone_number' => $phone], 'team' => ['id' => $team], 'assignee' => $assignee ? ['id' => $assignee] : null,
                'assignee_type' => $assignee ? 'User' : null]];
    }

    /**
     * A message as Chatwoot gives it.
     *
     * @param int $id Its id.
     * @param int $type 0 from the contact, 1 from the team.
     * @param string $text Its text.
     * @param bool $private Whether it is a note.
     * @return array
     */
    private function msg(int $id, int $type, string $text, bool $private = false): array {
        return ['id' => $id, 'message_type' => $type, 'content' => $text, 'created_at' => time() - 60, 'private' => $private,
            'sender' => ['type' => $type === 0 ? 'contact' : 'user', 'id' => 2]];
    }

    /**
     * Two people of the team: Ana in Secretaría (10) and Luis in Orientación (11).
     *
     * @return \stdClass[]
     */
    private function people(): array {
        $gen = $this->getDataGenerator();
        return [$gen->create_user(['email' => 'ana@example.com', 'firstname' => 'Ana']),
            $gen->create_user(['email' => 'luis@example.com', 'firstname' => 'Luis'])];
    }

    /**
     * Passed to a person and taken back by its department: that person no longer sees it, nor its notes.
     */
    public function test_passed_does_not_stick(): void {
        global $DB;
        [$ana, $luis] = $this->people();
        $this->client->conversations[2] = $this->conv(2, 11, 2, 'open', 102, '+34600000002');
        $this->client->messages[2] = [$this->msg(20, 0, 'Hola')];
        mirror::refresh(2);
        actions::run(viewer::for_user($luis), $DB->get_record(mirror::TABLE, ['cwid' => 2]), 'person', 1);
        $this->assertTrue(viewer::for_user($ana)->sees($DB->get_record(mirror::TABLE, ['cwid' => 2])));
        actions::run(viewer::for_user($luis), $DB->get_record(mirror::TABLE, ['cwid' => 2]), 'take');
        $this->client->messages[2][] = $this->msg(22, 1, 'A note of Orientación', true);
        $this->client->conversations[2]['updated_at'] = (string)microtime(true);
        mirror::refresh(2);
        $row = $DB->get_record(mirror::TABLE, ['cwid' => 2]);
        $this->assertSame([11, 2, 0], [(int)$row->teamid, (int)$row->assigneeid, (int)$row->passedto]);
        $this->assertFalse(viewer::for_user($ana)->sees($row));
        $this->setUser($ana);
        $this->expectException(\moodle_exception::class);
        chat_messages::execute(2, 0);
    }

    /**
     * A reminder whose conversation went to a department the person does not see does not take it from whoever has it.
     */
    public function test_reminder_does_not_take_it_back(): void {
        global $DB;
        [$ana] = $this->people();
        $this->client->conversations[1] = $this->conv(1, 10, 0, 'open', 101, '+34600000001');
        $this->client->messages[1] = [$this->msg(10, 0, 'Hola')];
        mirror::refresh(1);
        reminders::set(
            viewer::for_user($ana),
            $DB->get_record(mirror::TABLE, ['cwid' => 1]),
            date('Y-m-d', time() + 2 * DAYSECS),
            'Call back'
        );
        $this->client->conversations[1]['meta']['team'] = ['id' => 11];
        $this->client->conversations[1]['meta']['assignee'] = ['id' => 2];
        $this->client->conversations[1]['meta']['assignee_type'] = 'User';
        $this->client->conversations[1]['updated_at'] = (string)microtime(true);
        mirror::refresh(1);
        reminders::due(time() + 4 * DAYSECS);
        $row = $DB->get_record(mirror::TABLE, ['cwid' => 1]);
        $this->assertSame(2, (int)$row->assigneeid);
        $this->assertFalse(viewer::for_user($ana)->sees($row));
    }

    /**
     * In an inbox that keeps one conversation per contact, a new one is not started over a resolved one of another
     * department: the person hears where it is.
     */
    public function test_start_does_not_bring_another_department(): void {
        global $DB;
        [$ana] = $this->people();
        $this->client->lock = true;
        $this->client->conversations[2] = $this->conv(2, 11, 2, 'resolved', 102, '+34600000002');
        $this->client->messages[2] = [$this->msg(20, 0, 'A question for Orientación')];
        mirror::refresh(2);
        $template = templates::find('chat_retomar', 'es');
        $this->assertNotNull($template);
        $result = starter::start(viewer::for_user($ana), '34600000002', '', 10, $template, ['1' => 'Laura']);
        $this->assertSame('busy', $result['status']);
        $this->assertSame(11, (int)$DB->get_field(mirror::TABLE, 'teamid', ['cwid' => 2]));
        $this->assertSame([], array_filter($this->client->calls, function ($call) {
            return $call[0] === 'POST' && $call[1] === '/conversations';
        }));
    }

    /**
     * Where several accounts share the email of a person of the account, none of them is that person.
     */
    public function test_shared_email_is_nobody(): void {
        [$ana] = $this->people();
        $this->assertSame(1, viewer::for_user($ana)->agent['id']);
        set_config('allowaccountssameemail', 1);
        $child = $this->getDataGenerator()->create_user(['email' => 'ANA@example.com']);
        $this->assertNull(viewer::for_user($child));
        $this->assertNull(viewer::for_user($ana));
    }

    /**
     * A favourite whose conversations the person does not see does not show who it is.
     */
    public function test_favourite_of_another_department(): void {
        [$ana] = $this->people();
        $this->client->conversations[2] = $this->conv(2, 11, 2, 'open', 102, '+34600000002');
        $this->client->messages[2] = [$this->msg(20, 0, 'Hola')];
        mirror::refresh(2);
        $this->setUser($ana);
        $this->assertSame([], chat_agenda::execute(102, true)['favourites']);
    }
}
