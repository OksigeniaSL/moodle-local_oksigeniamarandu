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
use local_oksigeniamarandu\local\keywords;

/**
 * The reply to an opt-in or an opt-out, sent after the webhook has answered Meta.
 *
 * The family has just written, so the reply goes inside the 24-hour window, as plain text and without a template.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_reply extends \core\task\adhoc_task {
    /** The replies there are, as the end of their language strings. */
    public const REPLIES = ['optin', 'optout', 'unknowncode'];

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
        if (empty($data->phone) || !in_array($data->reply ?? '', self::REPLIES, true)) {
            return;
        }
        $lang = $DB->get_field('local_oksigeniamarandu_contact', 'lang', ['phone' => $data->phone]);
        try {
            $this->api()->send_text($data->phone, self::text($data->reply, $lang ?: null));
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
}
