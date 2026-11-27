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
 * What Mailjet tells of the mails (mailjet.php): a mail that came back for good, was blocked or was marked as spam. The
 * address is noted, so that it receives nothing more (or, after spam, no more sends to many), and the plugin whose mail
 * it was hears it; a mail Marandu did not send (the id it carries is not one of its own) goes to every plugin that
 * listens, and each knows its own.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class events {
    /**
     * Takes the events of a call of Mailjet.
     *
     * @param array[] $events As Mailjet posts them: event, email, time, CustomID, hard_bounce, error_related_to, error,
     *     comment, source.
     * @param int $now
     * @return int How many were taken.
     */
    public static function take(array $events, int $now): int {
        global $DB;
        $taken = 0;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $what = (string)($event['event'] ?? '');
            $email = \core_text::strtolower(trim((string)($event['email'] ?? '')));
            if (!in_array($what, mailjet_api::EVENTS, true) || !validate_email($email)) {
                continue;
            }
            $said = trim(implode(' ', array_filter([
                (string)($event['error_related_to'] ?? ''),
                (string)($event['error'] ?? ''),
                (string)($event['comment'] ?? ''),
                $what === 'spam' ? (string)($event['source'] ?? '') : '',
            ])));
            // A bounce that may pass is tried again by Mailjet; a mail blocked as a repeat in a send is not the address.
            $soft = $what === 'bounce' && empty($event['hard_bounce']);
            if ($soft || ($what === 'blocked' && stripos($said, 'duplicate') !== false)) {
                continue;
            }
            $time = (int)($event['time'] ?? 0) ?: $now;
            $row = null;
            if (preg_match('/^mrd-(\d+)$/', (string)($event['CustomID'] ?? ''), $match)) {
                $row = $DB->get_record(mailer::TABLE, ['id' => (int)$match[1]], 'id, component, ref, email, status');
            }
            if ($row && $what !== 'spam') {
                $DB->update_record(mailer::TABLE, (object)['id' => $row->id, 'status' => 'failed',
                    'error' => \core_text::substr($what . ': ' . $said, 0, 250)]);
            }
            self::note($email, $what, $said, $row ? (string)$row->component : '', $now);
            mailer::tell($row ? (string)$row->component : '', $email, $what, $said, $row ? (string)$row->ref : '', $time);
            $taken++;
        }
        return $taken;
    }

    /**
     * What an address is, for the mails: whether it came back, was blocked or marked a mail as spam, and when.
     *
     * @param string $email
     * @return array|null event, reason, component and time; null if it receives as any.
     */
    public static function state(string $email): ?array {
        global $DB;
        $row = $DB->get_record(mailer::BAD, ['email' => \core_text::strtolower(trim($email))]);
        return $row ? ['event' => $row->event, 'reason' => (string)$row->reason, 'component' => (string)$row->component,
            'time' => (int)$row->timemodified] : null;
    }

    /**
     * An address receives again, as a person decided (it was written wrong and is right now, or the mailbox is back).
     *
     * @param string $email
     * @return bool Whether it was noted.
     */
    public static function forget(string $email): bool {
        global $DB;
        $email = \core_text::strtolower(trim($email));
        if (!$DB->record_exists(mailer::BAD, ['email' => $email])) {
            return false;
        }
        $DB->delete_records(mailer::BAD, ['email' => $email]);
        return true;
    }

    /**
     * Notes what an address is now: a bounce or a block is not undone by a mark of spam, which is the lighter.
     *
     * @param string $email
     * @param string $event
     * @param string $said
     * @param string $component
     * @param int $now
     */
    private static function note(string $email, string $event, string $said, string $component, int $now): void {
        global $DB;
        $said = \core_text::substr(clean_param($said, PARAM_TEXT), 0, 250);
        $row = $DB->get_record(mailer::BAD, ['email' => $email]);
        if (!$row) {
            $DB->insert_record(mailer::BAD, (object)['email' => $email, 'event' => $event, 'reason' => $said,
                'component' => $component ?: null, 'timecreated' => $now, 'timemodified' => $now]);
        } else if ($event !== 'spam' || $row->event === 'spam') {
            $DB->update_record(mailer::BAD, (object)['id' => $row->id, 'event' => $event, 'reason' => $said,
                'component' => $component ?: $row->component, 'timemodified' => $now]);
        }
    }
}
