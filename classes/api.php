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
use local_oksigeniamarandu\local\notices;
use local_oksigeniamarandu\local\queue;

/**
 * What other plugins use: a code and a link so that a family or a user joins on WhatsApp, and whether they have.
 *
 * Example, from a plugin that keeps families without a Moodle account:
 *     $code = \local_oksigeniamarandu\api::invite('local_oksigeniameals', $family->id);
 *     $url = \local_oksigeniamarandu\api::optin_url($code); // Opens WhatsApp with «ALTA <code>» written.
 *
 * And, once the plugin has declared its notices (see \local_oksigeniamarandu\local\notices):
 *     \local_oksigeniamarandu\api::notify('local_oksigeniameals', 'menu_missing', ['Ana', 'lunes 3'],
 *         new moodle_url('/local/oksigeniameals/family.php', ['id' => $family->id]), $family->id);
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
     * The private page where a family joins: it explains, opens WhatsApp on the phone and shows a QR code on a computer.
     * It goes in a letter or a message, never in a public place: whoever has it can join for that family.
     *
     * The page names whom the code stands for: the user, or what the plugin answers to the callback
     * <component>_oksigeniamarandu_label(int $itemid): ?string in its lib.php (for example, "Family of Ana").
     *
     * @param string $code A code from invite().
     * @return \moodle_url
     */
    public static function signup_url(string $code): \moodle_url {
        return new \moodle_url('/local/oksigeniamarandu/alta.php', ['code' => $code]);
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

    /**
     * Queues a notice for everyone who joined for an item of a plugin, or for a user.
     *
     * It goes out when it can: with its template approved, out of the quiet hours (unless it is urgent) and within the
     * limits of the site. Inside the 24 hours after a family last wrote, it goes as plain text, which is free.
     *
     * @param string $component The plugin that declares the notice.
     * @param string $notice The notice.
     * @param string[] $params One value for each {{n}} of its text, in order; plain text.
     * @param \moodle_url|null $url Where its button goes, on this site.
     * @param int $itemid The item of the plugin, such as a family; 0 if the notice is for a user.
     * @param int|null $userid The user, if it is for a user: then every number linked to the user gets it.
     * @param bool $urgent Whether it goes out in the quiet hours too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @return int How many numbers it was queued for.
     */
    public static function notify(
        string $component,
        string $notice,
        array $params,
        ?\moodle_url $url = null,
        int $itemid = 0,
        ?int $userid = null,
        bool $urgent = false,
        int $validfor = 2 * DAYSECS
    ): int {
        global $DB;
        $declared = notices::get($component, $notice);
        if (!$declared) {
            throw new \coding_exception("The notice {$component}/{$notice} is not declared");
        }
        if (count($params) !== count($declared->examples)) {
            throw new \coding_exception("The notice {$component}/{$notice} takes " . count($declared->examples) . ' values');
        }
        // WhatsApp refuses values with new lines, tabs or long runs of spaces.
        $params = array_map(function ($value) {
            return \core_text::substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value))), 0, 200);
        }, array_values($params));
        if ($userid !== null) {
            $where = 'l.userid = :userid';
            $sqlparams = ['userid' => $userid];
        } else {
            $where = 'l.component = :component AND l.itemid = :itemid AND l.userid IS NULL';
            $sqlparams = ['component' => $component, 'itemid' => $itemid];
        }
        $contactids = $DB->get_fieldset_sql("SELECT DISTINCT c.id
                                               FROM {" . consent::LINKS . "} l
                                               JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                              WHERE $where AND c.status = :active", $sqlparams + ['active' => 'active']);
        foreach ($contactids as $contactid) {
            queue::add((int)$contactid, $declared, $params, $url ? $url->out(false) : null, $urgent, $validfor);
        }
        return count($contactids);
    }
}
