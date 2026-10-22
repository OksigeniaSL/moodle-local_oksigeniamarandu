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

namespace local_oksigeniamarandu;

use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\keywords;

/**
 * What other plugins use: a code and a link so that a family or a user joins on WhatsApp, and whether they have.
 *
 * Example, from a plugin that keeps families without a Moodle account:
 *     $code = \local_oksigeniamarandu\api::invite('local_oksigeniameals', $family->id);
 *     $url = \local_oksigeniamarandu\api::optin_url($code); // Opens WhatsApp with «ALTA <code>» written.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /**
     * A code that links whoever writes it on WhatsApp with something of a plugin, or with a user.
     *
     * The code of a link nobody has claimed yet is reused; once claimed, a new call gives a new code, so that a second
     * person (the other parent) can join too.
     *
     * @param string $component The plugin, such as local_oksigeniameals.
     * @param int $itemid Its item, such as a family; 0 if the link is only for a user.
     * @param int|null $userid The Moodle user, if there is one.
     * @return string The code.
     */
    public static function invite(string $component, int $itemid = 0, ?int $userid = null): string {
        global $DB;
        $component = clean_param($component, PARAM_COMPONENT);
        if ($component === '') {
            throw new \coding_exception('invite() needs the name of a plugin');
        }
        $params = ['component' => $component, 'itemid' => $itemid];
        $usersql = 'userid IS NULL';
        if ($userid !== null) {
            $usersql = 'userid = :userid';
            $params['userid'] = $userid;
        }
        $open = $DB->get_records_select(consent::LINKS, "component = :component AND itemid = :itemid AND $usersql
            AND contactid IS NULL", $params, 'id ASC', 'id, code', 0, 1);
        if ($open) {
            return reset($open)->code;
        }
        do {
            $code = keywords::new_code();
        } while ($DB->record_exists(consent::LINKS, ['code' => $code]));
        $DB->insert_record(consent::LINKS, (object)[
            'component' => $component,
            'itemid' => $itemid,
            'userid' => $userid,
            'code' => $code,
            'timecreated' => time(),
        ]);
        return $code;
    }

    /**
     * The link that opens WhatsApp with the opt-in word and the code written, ready to send to the number of the site.
     *
     * @param string $code A code from invite().
     * @return string|null Null if the site has not set its number yet.
     */
    public static function optin_url(string $code): ?string {
        $number = preg_replace('/[^0-9]/', '', (string)get_config('local_oksigeniamarandu', 'displayphone'));
        if ($number === '') {
            return null;
        }
        return 'https://wa.me/' . $number . '?text=' . rawurlencode(keywords::first('optinkeywords') . ' ' . $code);
    }

    /**
     * Whether somebody has joined for this item or user and has not opted out.
     *
     * @param string $component The plugin.
     * @param int $itemid Its item; 0 if the link is only for a user.
     * @param int|null $userid The Moodle user, if there is one.
     * @return bool
     */
    public static function has_active_contact(string $component, int $itemid = 0, ?int $userid = null): bool {
        global $DB;
        $params = ['component' => $component, 'itemid' => $itemid, 'active' => 'active'];
        $usersql = 'l.userid IS NULL';
        if ($userid !== null) {
            $usersql = 'l.userid = :userid';
            $params['userid'] = $userid;
        }
        return $DB->record_exists_sql("SELECT 1
                                         FROM {" . consent::LINKS . "} l
                                         JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                        WHERE l.component = :component AND l.itemid = :itemid AND $usersql
                                              AND c.status = :active", $params);
    }
}
