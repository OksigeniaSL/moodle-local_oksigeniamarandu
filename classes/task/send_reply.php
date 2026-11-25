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

namespace local_oksigeniamarandu\task;

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\confirm;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\keywords;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

/**
 * The reply to an opt-in or an opt-out, sent after the webhook has answered Meta, and the confirmations of a change of
 * channel: with the card of the confirmation if the site wants them (confirm), or else as plain text.
 *
 * The family has just written, so the reply goes inside the 24-hour window, without a template.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_reply extends \core\task\adhoc_task {
    /** The replies there are, as the end of their language strings. */
    public const REPLIES = ['optin', 'optout', 'unknowncode', 'stillout', 'joinno'];

    /**
     * Queues a reply.
     *
     * @param string $phone Digits with the country code.
     * @param string $reply One of REPLIES.
     */
    public static function queue(string $phone, string $reply): void {
        $task = new static();
        $task->set_custom_data(['phone' => $phone, 'reply' => $reply]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Queues a reply with a text of its own, such as what a plugin answers to an answer.
     *
     * @param string $phone Digits with the country code.
     * @param string $text The text.
     */
    public static function queue_text(string $phone, string $text): void {
        $task = new static();
        $task->set_custom_data(['phone' => $phone, 'text' => \core_text::substr($text, 0, 4096)]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Queues the question «do you want the notices again?», with its two buttons, to whoever said no and wrote again.
     *
     * @param string $phone The number or the chat.
     */
    public static function queue_back(string $phone): void {
        $task = new static();
        $task->set_custom_data(['phone' => $phone, 'back' => 1]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * The question «do you want the notices again?» and its two buttons, in the language of the person.
     *
     * @param string|null $lang The language; the site's if empty.
     * @return array text, and buttons (yes and no, each with id and title).
     */
    public static function back_question(?string $lang): array {
        global $CFG;
        $lang = $lang ?: $CFG->lang;
        $strings = get_string_manager();
        return ['text' => $strings->get_string('back:ask', 'local_oksigeniamarandu', null, $lang), 'buttons' => [
            ['id' => consent::BACK['yes'], 'title' => $strings->get_string('back:yes', 'local_oksigeniamarandu', null, $lang)],
            ['id' => consent::BACK['no'], 'title' => $strings->get_string('back:no', 'local_oksigeniamarandu', null, $lang)],
        ]];
    }

    /**
     * Queues a confirmation of the channels of a person, with its card.
     *
     * @param string $phone The number or the chat.
     * @param string $kind One of confirm::KINDS.
     * @param array $others The other channels, as confirm::build() takes them.
     */
    public static function queue_confirm(string $phone, string $kind, array $others = []): void {
        $task = new static();
        $task->set_custom_data(['phone' => $phone, 'confirm' => $kind, 'others' => array_values($others)]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * The confirmation a reply becomes, if the site wants them: joining (not again) and leaving.
     *
     * @param string $phone The number or the chat.
     * @param string $reply One of REPLIES.
     * @return string|null One of confirm::KINDS, or null for the plain reply.
     */
    public static function confirmation(string $phone, string $reply): ?string {
        if (!confirm::enabled()) {
            return null;
        }
        if ($reply === 'optin') {
            return consent::rejoined($phone) ? null : 'joined';
        }
        return $reply === 'optout' ? 'out' : null;
    }

    /**
     * The text of a reply, in the language of the contact or of the site.
     *
     * @param string $reply One of REPLIES.
     * @param string|null $lang Language; the site's if empty.
     * @return string
     */
    public static function text(string $reply, ?string $lang = null): string {
        global $CFG;
        $a = (object)[
            'site' => format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]),
            'optin' => keywords::first('optinkeywords'),
            'optout' => keywords::first('optoutkeywords'),
        ];
        return get_string_manager()->get_string('reply:' . $reply, 'local_oksigeniamarandu', $a, $lang ?: $CFG->lang);
    }

    /**
     * Sends the reply. If Meta does not take it, it is written in the log of the task and not tried again: the
     * family can always write again.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        if (!empty($data->back) && !empty($data->phone)) {
            $lang = $DB->get_field('local_oksigeniamarandu_contact', 'lang', ['phone' => $data->phone]);
            $question = self::back_question($lang ?: null);
            try {
                if (telegram::is_chat($data->phone)) {
                    $this->telegram()->send_question(telegram::chat($data->phone), $question['text'], array_map(function ($button) {
                        return ['data' => $button['id'], 'text' => $button['title']];
                    }, $question['buttons']));
                } else {
                    $this->api()->send_buttons($data->phone, $question['text'], $question['buttons']);
                }
            } catch (api_exception $e) {
                mtrace('Marandu: the question to come back was not sent: ' . $e->getMessage());
            }
            return;
        }
        $own = isset($data->text) && trim((string)$data->text) !== '';
        $kind = isset($data->confirm) && in_array($data->confirm, confirm::KINDS, true) ? (string)$data->confirm : null;
        if (empty($data->phone) || (!$own && $kind === null && !in_array($data->reply ?? '', self::REPLIES, true))) {
            return;
        }
        $lang = $DB->get_field('local_oksigeniamarandu_contact', 'lang', ['phone' => $data->phone]);
        if (!$own && $kind === null) {
            $kind = self::confirmation($data->phone, $data->reply);
        }
        if ($kind !== null) {
            $tg = telegram::is_chat($data->phone);
            $message = confirm::build($kind, $tg ? 'telegram' : 'whatsapp', $lang ?: null, (array)($data->others ?? []));
            try {
                if ($tg) {
                    confirm::telegram($this->telegram(), telegram::chat($data->phone), $message);
                } else {
                    confirm::whatsapp($this->api(), $data->phone, $message);
                }
            } catch (api_exception $e) {
                mtrace('Marandu: the confirmation was not sent: ' . $e->getMessage());
            }
            return;
        }
        $text = $own ? (string)$data->text : self::text($data->reply, $lang ?: null);
        try {
            if (telegram::is_chat($data->phone)) {
                $this->telegram()->send_text(telegram::chat($data->phone), $text);
            } else {
                $this->api()->send_text($data->phone, $text);
            }
        } catch (api_exception $e) {
            mtrace('Marandu: the reply was not sent: ' . $e->getMessage());
        }
    }

    /**
     * The client of the API. Kept apart so that the tests can answer for Meta.
     *
     * @return cloud_api
     */
    protected function api(): cloud_api {
        return cloud_api::from_config();
    }

    /**
     * The client of the bot of Telegram. Kept apart so that the tests can answer for Telegram.
     *
     * @return telegram_api
     */
    protected function telegram(): telegram_api {
        return telegram_api::from_config();
    }
}
