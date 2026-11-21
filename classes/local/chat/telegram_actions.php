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

namespace local_oksigeniamarandu\local\chat;

use local_oksigeniamarandu\hook\answer_received;
use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\queue;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

/**
 * The conversations of the team from Telegram, for whoever linked their chat: the notice of a conversation brings «I'll
 * take it», «Mark as resolved» and «Pass it», which act in the person's name as on the page; «Pass it» asks for the
 * department with a button for each; and a reply to the notice is a note of the team in that conversation. Answering the
 * family is done on the page: the button of the notice opens it.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_actions {
    /** The reference of a notice of a conversation: c: and its number. */
    public const REF = 'c:';

    /** The data of a button to pass a conversation: the conversation and the department. */
    public const PASS = 'mrdp:';

    /**
     * A choice of the notice of a conversation, pressed on Telegram (hook answer_received).
     *
     * @param answer_received $hook The answer.
     */
    public static function answered(answer_received $hook): void {
        if ($hook->get_component() === 'local_oksigeniamarandu' && $hook->get_notice() === 'board') {
            self::board_seen($hook);
            return;
        }
        if ($hook->get_component() !== 'local_oksigeniamarandu' || $hook->get_notice() !== 'chat') {
            return;
        }
        [$viewer, $row, $problem] = self::who_and_what($hook->get_address(), $hook->get_ref());
        if ($problem !== null) {
            $hook->set_reply($problem);
            return;
        }
        $name = self::name($row);
        try {
            switch ($hook->get_option()) {
                case 'take':
                    if ((int)$row->assigneeid === (int)$viewer->agent['id']) {
                        $hook->set_reply(self::str('alreadyyours', $name));
                        return;
                    }
                    actions::run($viewer, $row, 'take');
                    $hook->set_reply(self::str('taken', $name));
                    return;
                case 'resolve':
                    actions::run($viewer, $row, 'resolve');
                    $hook->set_reply(self::str('resolved', $name));
                    return;
                case 'pass':
                    $rows = [];
                    foreach (directory::teams() as $id => $team) {
                        if ((int)$id !== (int)$row->teamid) {
                            $rows[] = [[$team['name'], self::PASS . (int)$row->cwid . ':' . (int)$id]];
                        }
                    }
                    $hook->set_reply(self::str('passwhere', $name));
                    $hook->set_reply_buttons($rows);
                    return;
            }
        } catch (\moodle_exception $e) {
            $hook->set_reply(self::str('failed', $name));
        }
    }

    /**
     * «Seen» pressed on Telegram on a notice of the board.
     *
     * @param answer_received $hook The answer.
     */
    private static function board_seen(answer_received $hook): void {
        $ref = $hook->get_ref();
        $id = strpos($ref, board::REF) === 0 ? (int)substr($ref, strlen(board::REF)) : 0;
        $userid = self::user_of_chat($hook->get_address());
        if (!$id || !$userid) {
            $hook->set_reply(self::str('notyours', ''));
            return;
        }
        $hook->set_reply(self::str(board::seen($userid, $id) ? 'boardseen' : 'boardalready', ''));
    }

    /**
     * The user a chat of Telegram is linked to, if it is active.
     *
     * @param string $address The chat, as Marandu writes it.
     * @return int The user; 0 if none.
     */
    private static function user_of_chat(string $address): int {
        global $DB;
        return (int)$DB->get_field_sql(
            "SELECT l.userid FROM {" . consent::LINKS . "} l
               JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
              WHERE c.phone = :phone AND c.status = 'active' AND l.userid IS NOT NULL",
            ['phone' => $address],
            IGNORE_MULTIPLE
        );
    }

    /**
     * A button of a department, after «Pass it»: the conversation goes there.
     *
     * @param array $query The callback query of Telegram.
     * @param telegram_api|null $api The bot, to stop the button waiting.
     * @return array|null The answer; null if the button is not one of these.
     */
    public static function callback(array $query, ?telegram_api $api = null): ?array {
        $data = (string)($query['data'] ?? '');
        $chat = $query['message']['chat'] ?? [];
        $pattern = '/^' . preg_quote(self::PASS, '/') . '(\d{1,10}):(\d{1,10})$/';
        if (!preg_match($pattern, $data, $parts) || ($chat['type'] ?? '') !== 'private') {
            return null;
        }
        if ($api && isset($query['id'])) {
            try {
                $api->answer_callback((string)$query['id']);
            } catch (api_exception $e) {
                debugging('Marandu: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $address = telegram::address((string)$chat['id']);
        [$viewer, $row, $problem] = self::who_and_what($address, self::REF . $parts[1]);
        $teams = directory::teams();
        if ($problem === null && !isset($teams[(int)$parts[2]])) {
            $problem = self::str('failed', self::name($row));
        }
        if ($problem === null) {
            try {
                actions::run($viewer, $row, 'team', (int)$parts[2]);
                $problem = self::str('passed', (object)['name' => self::name($row), 'team' => $teams[(int)$parts[2]]['name']]);
            } catch (\moodle_exception $e) {
                $problem = self::str('failed', self::name($row));
            }
        }
        // The buttons of departments give way to what was done.
        return ['method' => 'editMessageText', 'chat_id' => (string)$chat['id'],
            'message_id' => (int)($query['message']['message_id'] ?? 0), 'text' => $problem];
    }

    /**
     * A reply on Telegram to the notice of a conversation: a note of the team in it, in the person's name.
     *
     * @param string $address The chat, as Marandu writes it.
     * @param array $message The message of Telegram.
     * @return array|null The answer; null if the message does not reply to a notice of a conversation.
     */
    public static function note(string $address, array $message): ?array {
        global $DB;
        $replied = (int)($message['reply_to_message']['message_id'] ?? 0);
        $text = trim((string)($message['text'] ?? ''));
        if (!$replied || $text === '' || strpos($text, '/') === 0) {
            return null;
        }
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address]);
        if (!$contact) {
            return null;
        }
        $notice = $DB->get_record_select(
            queue::TABLE,
            'contactid = :contactid AND component = :component AND notice = :notice AND wamid = :wamid',
            ['contactid' => $contact->id, 'component' => 'local_oksigeniamarandu', 'notice' => 'chat', 'wamid' => 'tg.' . $replied],
            'id, ref',
            IGNORE_MULTIPLE
        );
        if (!$notice) {
            return null;
        }
        [$viewer, $row, $problem] = self::who_and_what($address, (string)$notice->ref);
        if ($problem === null) {
            try {
                actions::note($viewer, $row, $text);
                $problem = self::str('noted', self::name($row));
            } catch (\moodle_exception $e) {
                $problem = self::str('failed', self::name($row));
            }
        }
        return ['method' => 'sendMessage', 'chat_id' => telegram::chat($address), 'text' => $problem,
            'reply_parameters' => ['message_id' => (int)$message['message_id'], 'allow_sending_without_reply' => true]];
    }

    /**
     * Who acts, from their chat, and on what: the person of the team the chat is linked to, and the conversation of
     * the notice, if they still see it.
     *
     * @param string $address The chat, as Marandu writes it.
     * @param string $ref The reference of the notice.
     * @return array [viewer or null, conversation or null, what to answer if it cannot be done, or null].
     */
    private static function who_and_what(string $address, string $ref): array {
        global $DB;
        $userid = self::user_of_chat($address);
        $user = $userid ? \core_user::get_user($userid) : null;
        $viewer = $user && !$user->deleted && !$user->suspended ? viewer::for_user($user) : null;
        if (!$viewer || $viewer->readonly) {
            return [null, null, self::str('notyours', '')];
        }
        $id = strpos($ref, self::REF) === 0 ? (int)substr($ref, strlen(self::REF)) : 0;
        $row = $id ? $DB->get_record(mirror::TABLE, ['cwid' => $id]) : null;
        if (!$row || !$viewer->sees($row)) {
            return [$viewer, null, self::str('gone', '')];
        }
        return [$viewer, $row, null];
    }

    /**
     * Who wrote in a conversation, as the notices say it.
     *
     * @param \stdClass|null $row The conversation.
     * @return string
     */
    private static function name(?\stdClass $row): string {
        if (!$row) {
            return '';
        }
        return $row->name !== '' ? $row->name : '+' . $row->phone;
    }

    /**
     * A text of these answers.
     *
     * @param string $key Its key, after chat:telegram:.
     * @param mixed $a Its values.
     * @return string
     */
    private static function str(string $key, $a): string {
        return get_string('chat:telegram:' . $key, 'local_oksigeniamarandu', $a);
    }
}
