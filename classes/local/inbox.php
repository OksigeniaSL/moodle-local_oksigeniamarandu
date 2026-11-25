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

/**
 * What the site knows of a WhatsApp number, for the shared inbox that gets its messages too: whom it is joined with, the
 * last notice it got and whether a plugin of the site already answered a message. So that the inbox sends a reply to a
 * notice straight to whoever sent the notice, and does not answer what a plugin answered.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inbox {
    /** Statuses of a notice that left the site. */
    private const SENT = ['sent', 'delivered', 'read'];

    /**
     * What the site knows of a number.
     *
     * @param string $phone The number with its country code; a + and spaces are fine.
     * @param string $messageid The id Meta gave to a message of that number (wamid), if any.
     * @param int $days How many days back the last notice is looked for.
     * @param int|null $now The time, for the tests.
     * @return array phone, status (active, pending, optedout or none), joined (component, area, label), takenby (the
     *               plugin that answered or kept the message, or empty), accounts (as accounts() gives them) and, if
     *               there is one, lastnotice (component, notice, label, timesent).
     */
    public static function context(string $phone, string $messageid = '', int $days = 7, ?int $now = null): array {
        global $DB;
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $result = [
            'phone' => $digits,
            'status' => 'none',
            'joined' => [],
            'takenby' => conversation::taken_by($messageid),
            'accounts' => self::accounts($digits),
        ];
        $since = ($now ?? time()) - max(1, $days) * DAYSECS;
        $asked = $digits === '' ? null : joinask::last_sent($digits, $since);
        if ($asked) {
            // The question to join is a notice too: whoever answers it with words goes to the plugin that asked.
            $result['lastnotice'] = ['component' => (string)$asked->component, 'notice' => joinask::NOTICE,
                'label' => notices::label(notices::get('local_oksigeniamarandu', joinask::NOTICE)),
                'timesent' => (int)$asked->timeasked];
        }
        $contact = $digits === '' ? false : $DB->get_record(consent::CONTACTS, ['phone' => $digits]);
        if (!$contact) {
            return $result;
        }
        $result['status'] = (string)$contact->status;

        $seen = [];
        $links = $DB->get_records_select(
            consent::LINKS,
            "contactid = :contactid AND component <> 'core'",
            ['contactid' => $contact->id],
            'timeclaimed DESC, id DESC',
            'id, component, area'
        );
        foreach ($links as $link) {
            $key = $link->component . '/' . $link->area;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result['joined'][] = [
                    'component' => (string)$link->component,
                    'area' => (string)$link->area,
                    'label' => notices::item((string)$link->component, (string)$link->area),
                ];
            }
        }

        [$in, $params] = $DB->get_in_or_equal(self::SENT, SQL_PARAMS_NAMED);
        $params += ['contactid' => $contact->id, 'since' => $since];
        $last = $DB->get_records_select(
            queue::TABLE,
            "contactid = :contactid AND status $in AND timesent >= :since",
            $params,
            'timesent DESC, id DESC',
            'id, component, notice, timesent',
            0,
            1
        );
        $last = reset($last);
        if ($last && (int)$last->timesent >= (int)($result['lastnotice']['timesent'] ?? 0)) {
            $notice = notices::get((string)$last->component, (string)$last->notice);
            $result['lastnotice'] = [
                'component' => (string)$last->component,
                'notice' => (string)$last->notice,
                'label' => $notice ? notices::label($notice) : notices::item((string)$last->component),
                'timesent' => (int)$last->timesent,
            ];
        }
        return $result;
    }

    /**
     * The accounts the adult of a number has in the management programs of the centre, to help them sign in there, as
     * the common record of families keeps them (Oksigenia Family 0.3.40 on): only when the number is of one active adult
     * of the record, the centre checked it, and always half hidden. The whole username or email never leaves the site.
     *
     * @param string $digits The number, only digits with the country code.
     * @return array Each with system, label, username (hidden but its last five characters), derived, email (hidden but
     *               its first three and last two characters before the @, and the domain), and active (yes, no or
     *               unknown). Empty without the record, or with no adult or several for the number.
     */
    public static function accounts(string $digits): array {
        $api = '\\local_oksigeniafamily\\api';
        $ready = $digits !== '' && class_exists($api) && method_exists($api, 'external_accounts')
            && method_exists($api, 'adults_by_phone') && (!method_exists($api, 'is_available') || $api::is_available());
        if (!$ready) {
            return [];
        }
        $adults = $api::adults_by_phone('+' . $digits);
        if (count($adults) !== 1) {
            return [];
        }
        $adult = reset($adults);
        $list = [];
        foreach ($api::external_accounts((int)$adult->id) as $one) {
            $one = (array)$one;
            $active = $one['active'] ?? null;
            $list[] = [
                'system' => (string)($one['system'] ?? ''),
                'label' => (string)($one['label'] ?? ''),
                'username' => self::hide_username((string)($one['username'] ?? '')),
                'derived' => !empty($one['derived']),
                'email' => self::hide_email((string)($one['email'] ?? '')),
                'active' => $active === null ? 'unknown' : ($active ? 'yes' : 'no'),
            ];
        }
        return $list;
    }

    /**
     * A username half hidden: «·» but for its last five characters; a short one shows only its last, and a single
     * character none.
     *
     * @param string $username The username.
     * @return string Empty if it was.
     */
    public static function hide_username(string $username): string {
        $username = trim($username);
        $length = \core_text::strlen($username);
        if ($length === 0) {
            return '';
        }
        $shown = $length > 5 ? 5 : min(1, $length - 1);
        return str_repeat('·', $length - $shown) . \core_text::substr($username, $length - $shown);
    }

    /**
     * An email half hidden: the first three and the last two characters before the @, «·····» between them, and the
     * whole domain; before an @ of five characters or fewer, only the first one (none, if it has one only).
     *
     * @param string $email The email.
     * @return string Empty if it was.
     */
    public static function hide_email(string $email): string {
        $email = trim($email);
        if ($email === '') {
            return '';
        }
        $at = \core_text::strrpos($email, '@');
        $local = $at === false ? $email : \core_text::substr($email, 0, $at);
        $domain = $at === false ? '' : \core_text::substr($email, $at);
        $length = \core_text::strlen($local);
        if ($length > 5) {
            $local = \core_text::substr($local, 0, 3) . '·····' . \core_text::substr($local, $length - 2);
        } else {
            $local = ($length > 1 ? \core_text::substr($local, 0, 1) : '') . '·····';
        }
        return $local . $domain;
    }
}
