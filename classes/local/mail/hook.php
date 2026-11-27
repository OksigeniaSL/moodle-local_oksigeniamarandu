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

namespace local_oksigeniamarandu\local\mail;

/**
 * The notices of the plugins that Moodle turns into a mail, by the way of Marandu (Moodle 5.3 and later, by the hook
 * before_email_to_user). Only those a plugin marked, sending them from a marked sender (api::mail_notice() or
 * api::mail_from()):
 * - with a mail in the common look kept for it: Marandu sends that mail, with its pictures inside, by its way (home by
 *   the mail of the site, the rest by Mailjet when the site chose it), and Moodle does not send it again; if it cannot,
 *   Moodle sends the notice as it came;
 * - without it: to an address out of home and with Mailjet chosen, by Mailjet as it came; if not, Moodle as always.
 * Nothing else is touched. What Marandu sent, Moodle skips as it skips a mail to a suspended account: done, so that the
 * notice counts as sent.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook {
    /** The header that marks a mail for the way of Marandu: the plugin, «routine» if it is, and its kept mail. */
    public const HEADER = 'X-Marandu-Mail';

    /**
     * The sender of the notices of a plugin that go by the way of Marandu: the no-reply user of the site, marked.
     *
     * @param string $component The plugin.
     * @param bool $routine Daily and repeated: by the mail of the site once the month is near its quota.
     * @param string $letter The mail in the common look kept for it (compose::build()), if any.
     * @return \stdClass For userfrom of a message of Moodle.
     */
    public static function from(string $component, bool $routine = false, string $letter = ''): \stdClass {
        return self::sender(\core_user::get_noreply_user(), $component, $routine, $letter);
    }

    /**
     * A sender marked for the way of Marandu: a copy of it, with the mark among its headers.
     *
     * @param \stdClass $from
     * @param string $component
     * @param bool $routine
     * @param string $letter
     * @return \stdClass
     */
    public static function sender(\stdClass $from, string $component, bool $routine = false, string $letter = ''): \stdClass {
        $from = clone $from;
        $headers = isset($from->customheaders) ? (array)$from->customheaders : [];
        $headers = array_values(array_filter($headers, fn($header) => self::mark([$header]) === null));
        $headers[] = self::HEADER . ': ' . preg_replace('/[^a-z0-9_]/', '', $component) . ($routine ? '; routine' : '')
            . (preg_match('/^[a-f0-9]{40}$/', $letter) ? '; letter=' . $letter : '');
        $from->customheaders = $headers;
        return $from;
    }

    /**
     * Before Moodle sends a mail: a marked one, by the way of Marandu.
     *
     * @param \core\hook\email\before_email_to_user $hook
     */
    public static function before(\core\hook\email\before_email_to_user $hook): void {
        global $CFG;
        $email = $hook->email;
        $mark = self::mark($email->get_additional_headers());
        $user = $email->user;
        if (
            $mark === null || !empty($CFG->noemailever) || $email->attachment !== '' || empty($user->email)
                || email_should_be_diverted((string)$user->email) || !empty($user->deleted) || !empty($user->suspended)
                || ($user->auth ?? '') === 'nologin'
        ) {
            return;
        }
        $kept = $mark['letter'] !== '' ? compose::kept($mark['letter']) : null;
        if (!$kept && (!settings::mailjet_chosen() || settings::is_home((string)$user->email))) {
            return;
        }
        $went = mailer::send([
            'email' => (string)$user->email,
            'name' => isset($user->firstname) ? fullname($user) : '',
            'userid' => (int)($user->id ?? 0),
            'subject' => $email->subject,
            'html' => $kept ? $kept['html'] : $email->messagehtml,
            'text' => $kept ? $kept['text'] : $email->messagetext,
            'images' => $kept ? $kept['images'] : [],
            'component' => $mark['component'],
            'routine' => $mark['routine'],
            'replyto' => $email->replyto,
        ], null, !$kept);
        // Went, or the address does not receive: Moodle does not send it, and tells whoever sent the notice that it is done.
        // Stopping it (add_block_reason) would make email_to_user() say false, and Moodle would take the whole notice as
        // not sent («Message was not sent»); a mail to a suspended account is skipped as done.
        if (in_array($went, ['site', 'mailjet', 'bad'], true)) {
            $skip = clone $user;
            $skip->suspended = 1;
            $email->user = $skip;
        }
    }

    /**
     * The mark of Marandu among the headers of a mail.
     *
     * @param string[] $headers
     * @return array|null component, routine and letter.
     */
    public static function mark(array $headers): ?array {
        $pattern = '/^' . preg_quote(self::HEADER, '/') . ':\s*([a-z0-9_]+)\s*(;\s*routine)?\s*(;\s*letter=([a-f0-9]{40}))?\s*$/i';
        foreach ($headers as $header) {
            if (preg_match($pattern, (string)$header, $m)) {
                return ['component' => $m[1], 'routine' => !empty($m[2]), 'letter' => (string)($m[4] ?? '')];
            }
        }
        return null;
    }
}
