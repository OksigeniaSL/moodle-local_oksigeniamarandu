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

use local_oksigeniamarandu\hook\answer_received;
use local_oksigeniamarandu\task\send_reply;

/**
 * The answers to the questions that plugins send with api::ask(): the buttons, and what comes back when one is pressed.
 *
 * An answer counts only from the number or chat the question went to. On WhatsApp, a button of a template comes back with
 * the id of the message that carried it, and a button inside the 24 hours with the id Marandu gave it; on Telegram, with
 * the data of the button. Each answer is kept as it came, and the plugin that asked hears of it with the hook
 * answer_received. Pressing again is another answer.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answers {
    /** The answers, as they came. */
    public const TABLE = 'local_oksigeniamarandu_answer';

    /** The start of the data of a button of an answer on Telegram, apart from the buttons of the menu. */
    private const PREFIX = 'a:';

    /** The statuses of a question that has gone out, and so can be answered. */
    private const OUT = ['sent', 'delivered', 'read'];

    /**
     * The buttons of a question on Telegram.
     *
     * @param \stdClass $row The row of the queue.
     * @param \stdClass $notice The question.
     * @param string|null $lang The language of the person.
     * @return array Each with data and text.
     */
    public static function telegram_choices(\stdClass $row, \stdClass $notice, ?string $lang): array {
        return array_map(function ($option) use ($row, $notice, $lang) {
            return ['data' => self::PREFIX . $row->token . ':' . $option, 'text' => notices::option_label($notice, $option, $lang)];
        }, $notice->options);
    }

    /**
     * The buttons of a question on WhatsApp, inside the 24 hours.
     *
     * @param \stdClass $row The row of the queue.
     * @param \stdClass $notice The question.
     * @param string|null $lang The language of the person.
     * @return array Each with id and title.
     */
    public static function whatsapp_buttons(\stdClass $row, \stdClass $notice, ?string $lang): array {
        return array_map(function ($option) use ($row, $notice, $lang) {
            return ['id' => self::PREFIX . $row->token . ':' . $option, 'title' => notices::option_label($notice, $option, $lang)];
        }, $notice->options);
    }

    /**
     * A message of WhatsApp that may be an answer: a button of a template, or a reply button.
     *
     * @param array $message The message, as Meta sends it.
     * @param string $phone Who wrote, digits with the country code.
     * @return bool Whether it was an answer to a question, and so nothing else.
     */
    public static function from_whatsapp(array $message, string $phone): bool {
        global $DB;
        $type = $message['type'] ?? '';
        $row = null;
        $option = null;
        if ($type === 'interactive' && ($message['interactive']['type'] ?? '') === 'button_reply') {
            [$row, $option] = self::by_data((string)($message['interactive']['button_reply']['id'] ?? ''));
        } else if ($type === 'button' && !empty($message['context']['id'])) {
            $rows = $DB->get_records_select(
                queue::TABLE,
                'wamid = ? AND ref IS NOT NULL',
                [(string)$message['context']['id']],
                'id DESC',
                '*',
                0,
                1
            );
            $row = $rows ? reset($rows) : null;
            $option = $row ? self::by_label($row, (string)($message['button']['payload'] ?? $message['button']['text'] ?? ''))
                : null;
        }
        if (!$row || $option === null) {
            return false;
        }
        $contact = $DB->get_record(consent::CONTACTS, ['id' => $row->contactid]);
        if (!$contact || $contact->phone !== $phone) {
            return false;
        }
        $time = (int)($message['timestamp'] ?? 0) ?: time();
        $reply = self::record(
            $row,
            $contact,
            $option,
            'whatsapp',
            (string)($message['id'] ?? ''),
            (string)($message['context']['id'] ?? $row->wamid),
            $time
        );
        if ($reply !== null) {
            send_reply::queue_text($phone, $reply);
        }
        return true;
    }

    /**
     * A button of Telegram that may be an answer.
     *
     * @param array $query The callback query.
     * @param telegram_api|null $api The bot, to stop the wait on the button; null not to.
     * @return array|null The reply, as a method of the Bot API; null if it was not an answer.
     */
    public static function from_telegram(array $query, ?telegram_api $api = null): ?array {
        global $DB;
        [$row, $option] = self::by_data((string)($query['data'] ?? ''));
        $chat = $query['message']['chat'] ?? [];
        if (!$row || $option === null || ($chat['type'] ?? '') !== 'private') {
            return null;
        }
        $contact = $DB->get_record(consent::CONTACTS, ['id' => $row->contactid]);
        if (!$contact || $contact->phone !== telegram::address((string)$chat['id'])) {
            return null;
        }
        if ($api && isset($query['id'])) {
            try {
                $api->answer_callback((string)$query['id']);
            } catch (api_exception $e) {
                // The button stops waiting by itself after a while.
                debugging('Marandu: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $messageid = (int)($query['message']['message_id'] ?? 0);
        // Telegram gives no time for a press: the arrival is when it was answered.
        $reply = self::record($row, $contact, $option, 'telegram', 'tgq.' . ($query['id'] ?? ''), 'tg.' . $messageid, time());
        if ($reply === null) {
            return null;
        }
        return ['method' => 'sendMessage', 'chat_id' => (string)$chat['id'], 'text' => $reply,
            'reply_parameters' => ['message_id' => $messageid, 'allow_sending_without_reply' => true]];
    }

    /**
     * Keeps an answer and tells the plugin that asked.
     *
     * @param \stdClass $row The question, in the queue.
     * @param \stdClass $contact Who answered.
     * @param string $option The key of the answer.
     * @param string $channel whatsapp or telegram.
     * @param string $messageid The id of the answer.
     * @param string $contextid The id of the message it answers.
     * @param int $time When it was answered.
     * @return string|null What to answer to the person; null if this answer had already come (a webhook sent twice).
     */
    public static function record(
        \stdClass $row,
        \stdClass $contact,
        string $option,
        string $channel,
        string $messageid,
        string $contextid,
        int $time
    ): ?string {
        global $DB;
        if ($messageid !== '' && $DB->record_exists(self::TABLE, ['messageid' => $messageid])) {
            return null;
        }
        $DB->insert_record(self::TABLE, (object)[
            'queueid' => $row->id,
            'contactid' => $contact->id,
            'component' => $row->component,
            'notice' => $row->notice,
            'ref' => $row->ref,
            'choice' => $option,
            'channel' => $channel,
            'messageid' => $messageid,
            'contextid' => $contextid,
            'timeanswered' => $time,
            'timecreated' => time(),
        ]);
        $notice = notices::get($row->component, $row->notice);
        $hook = new answer_received(
            $row->component,
            $row->notice,
            (string)$row->ref,
            (int)$row->itemid,
            $option,
            $channel,
            $channel === 'telegram' ? $contact->phone : '+' . $contact->phone,
            $time,
            (string)$row->area
        );
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        $lang = $contact->lang ?: null;
        return $hook->get_reply() ?? get_string_manager()->get_string(
            'answer:received',
            'local_oksigeniamarandu',
            $notice ? notices::option_label($notice, $option, $lang) : $option,
            $lang
        );
    }

    /**
     * The question and the answer of the data of a button: a: (on Telegram), the token of the question and the key.
     *
     * @param string $data The data.
     * @return array The row of the queue and the key; nulls if it is not an answer to a question that went out.
     */
    private static function by_data(string $data): array {
        global $DB;
        if (!preg_match('/^' . self::PREFIX . '([A-Za-z0-9]{1,16}):([a-z0-9]{1,10})$/', $data, $parts)) {
            return [null, null];
        }
        $row = $DB->get_record(queue::TABLE, ['token' => $parts[1]]);
        if (!$row || empty($row->ref) || !in_array($row->status, self::OUT, true)) {
            return [null, null];
        }
        $notice = notices::get($row->component, $row->notice);
        return $notice && in_array($parts[2], $notice->options, true) ? [$row, $parts[2]] : [null, null];
    }

    /**
     * The answer of the label of a button of a template, in any language of the site: the template went in one of them.
     *
     * @param \stdClass $row The question.
     * @param string $label What the button said.
     * @return string|null The key.
     */
    private static function by_label(\stdClass $row, string $label): ?string {
        $notice = notices::get($row->component, $row->notice);
        if (!$notice || !in_array($row->status, self::OUT, true)) {
            return null;
        }
        $label = \core_text::strtolower(trim($label));
        foreach (array_keys(get_string_manager()->get_list_of_translations()) as $lang) {
            foreach ($notice->options as $option) {
                if (\core_text::strtolower(notices::option_label($notice, $option, $lang)) === $label) {
                    return $option;
                }
            }
        }
        return null;
    }
}
