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

use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\family;
use local_oksigeniamarandu\local\keywords;
use local_oksigeniamarandu\local\notices;
use local_oksigeniamarandu\local\queue;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

/**
 * What other plugins use: a code and a link so that a family or a user joins on WhatsApp, whether they have, and the
 * notices for them.
 *
 * With the family records of Oksigenia Family (local_oksigeniafamily), a plugin tells the family of a student without
 * knowing their numbers, once it has declared its notices (see \local_oksigeniamarandu\local\notices):
 *     \local_oksigeniamarandu\api::notify_child('local_oksigeniameals', 'menu_missing', ['Ana', 'lunes 3'],
 *         new moodle_url('/local/oksigeniameals/k.php'), $student->id);
 * The family joins by writing ALTA with the student's code of the records, or just ALTA from the number the school has.
 *
 * Without the records, a plugin gives a code of its own to whom it keeps (a user, or an item such as a reader):
 *     $code = \local_oksigeniamarandu\api::invite('local_example', $item->id);
 *     $url = \local_oksigeniamarandu\api::optin_url($code); // Opens WhatsApp with «ALTA <code>» written.
 *     \local_oksigeniamarandu\api::notify('local_example', 'reminder', ['Ana', 'lunes 3'], $pageurl, $item->id);
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
     * The channels the site has ready: WhatsApp with its account and number, Telegram with its bot.
     *
     * @return array By channel (whatsapp, telegram), with name and ready.
     */
    public static function channels(): array {
        return [
            'whatsapp' => ['name' => 'WhatsApp', 'ready' => cloud_api::is_configured()
                && (string)get_config('local_oksigeniamarandu', 'displayphone') !== ''],
            'telegram' => ['name' => 'Telegram', 'ready' => telegram_api::is_configured()],
        ];
    }

    /**
     * The link that joins with a code on a channel: WhatsApp with the opt-in word and the code written, or the bot of
     * Telegram started with the code.
     *
     * @param string $channel whatsapp or telegram.
     * @param string $code A code from invite(), or of the family records.
     * @return string|null Null if the site does not have that channel ready.
     */
    public static function join_url(string $channel, string $code): ?string {
        if ($channel === 'telegram') {
            return telegram::join_url($code);
        }
        return $channel === 'whatsapp' ? self::optin_url($code) : null;
    }

    /**
     * Whether a user has joined on a channel, with whatever code: from their notification preferences, the app or a
     * letter.
     *
     * @param int $userid The user.
     * @param string|null $channel whatsapp or telegram; either if null.
     * @return bool
     */
    public static function user_joined(int $userid, ?string $channel = null): bool {
        global $DB;
        $params = ['userid' => $userid, 'active' => 'active'];
        $where = '';
        if ($channel !== null) {
            $where = ' AND c.channel = :channel';
            $params['channel'] = $channel;
        }
        return $DB->record_exists_sql("SELECT 1
                                         FROM {" . consent::LINKS . "} l
                                         JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                        WHERE l.userid = :userid AND c.status = :active$where", $params);
    }

    /**
     * For each channel the site has ready, whether a user is on it and the link to join it, for a screen where the user
     * chooses where to get the notices (such as the one of the app).
     *
     * @param int $userid The user.
     * @return array By channel, with name, joined, where (the number or the chat, half hidden) and url (to join).
     */
    public static function user_channels(int $userid): array {
        return self::channel_list('core', 0, $userid);
    }

    /**
     * The same for an adult of the family records of Oksigenia Family, who may have no account.
     *
     * @param int $adultid The adult.
     * @return array By channel, with name, joined, where and url.
     */
    public static function adult_channels(int $adultid): array {
        return self::channel_list(family::COMPONENT, $adultid, null);
    }

    /**
     * Whom a channel list is for, and how they are on each channel.
     *
     * @param string $component The plugin of the links.
     * @param int $itemid Its item.
     * @param int|null $userid The user, if they are one.
     * @return array By channel, with name, joined, where and url.
     */
    private static function channel_list(string $component, int $itemid, ?int $userid): array {
        global $DB;
        $params = ['active' => 'active', 'component' => $component, 'itemid' => $itemid];
        $where = 'l.component = :component AND l.itemid = :itemid AND ';
        if ($userid !== null) {
            $where = 'l.userid = :userid AND ';
            $params['userid'] = $userid;
        }
        $joined = $DB->get_records_sql("SELECT c.id, c.channel, c.phone
                                          FROM {" . consent::LINKS . "} l
                                          JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                         WHERE {$where}c.status = :active", $params);
        $list = [];
        $code = null;
        foreach (self::channels() as $channel => $info) {
            if (!$info['ready']) {
                continue;
            }
            $contacts = array_filter($joined, function ($contact) use ($channel) {
                return $contact->channel === $channel;
            });
            $contact = reset($contacts);
            $code = $code ?? self::invite($component, $itemid, $userid);
            $list[$channel] = [
                'name' => $info['name'],
                'joined' => (bool)$contact,
                // To the person themselves, the number half hidden, or just Telegram.
                'where' => $contact ? (local\telegram::is_chat($contact->phone) ? 'Telegram'
                    : local\panel::mask($contact->phone)) : '',
                'url' => self::join_url($channel, $code),
            ];
        }
        return $list;
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
        [$declared, $params] = self::prepare($component, $notice, $params);
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
        return self::queue_for($contactids, $declared, $params, $url, $urgent, $validfor);
    }

    /**
     * Queues a notice about a student for the numbers of the student's family, from the family records of Oksigenia
     * Family. Nothing goes out without them, nor to a number that has not opted in.
     *
     * @param string $component The plugin that declares the notice.
     * @param string $notice The notice.
     * @param string[] $params One value for each {{n}} of its text, in order; plain text.
     * @param \moodle_url|null $url Where its button goes, on this site.
     * @param int $childuserid The student.
     * @param bool $urgent Whether it goes out in the quiet hours too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @return int How many numbers it was queued for.
     */
    public static function notify_child(
        string $component,
        string $notice,
        array $params,
        ?\moodle_url $url,
        int $childuserid,
        bool $urgent = false,
        int $validfor = 2 * DAYSECS
    ): int {
        global $DB;
        [$declared, $params] = self::prepare($component, $notice, $params);
        $contactids = family::contacts_of_child($childuserid);
        if (!$contactids) {
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($contactids, SQL_PARAMS_NAMED);
        $active = $DB->get_fieldset_select(
            consent::CONTACTS,
            'id',
            "id $insql AND status = :active",
            $inparams + ['active' => 'active']
        );
        return self::queue_for($active, $declared, $params, $url, $urgent, $validfor);
    }

    /**
     * Stops numbers standing for an item of a plugin or for a user, when the plugin no longer has them (a family that
     * left, a user who is gone). The numbers keep their consent; they just stop getting these notices.
     *
     * @param string $component The plugin.
     * @param int $itemid Its item; 0 if the link is only for a user.
     * @param int|null $userid The user, if the links are for one.
     * @return int How many links were dropped.
     */
    public static function unlink(string $component, int $itemid = 0, ?int $userid = null): int {
        global $DB;
        $params = ['component' => $component, 'itemid' => $itemid];
        $where = 'component = :component AND itemid = :itemid AND ' . ($userid === null ? 'userid IS NULL' : 'userid = :userid');
        if ($userid !== null) {
            $params['userid'] = $userid;
        }
        $count = $DB->count_records_select(consent::LINKS, $where, $params);
        $DB->delete_records_select(consent::LINKS, $where, $params);
        return $count;
    }

    /**
     * A declared notice and its values, as WhatsApp takes them.
     *
     * @param string $component The plugin that declares the notice.
     * @param string $notice The notice.
     * @param string[] $params Its values.
     * @return array The notice and the values.
     * @throws \coding_exception If the notice is not declared or takes another number of values.
     */
    private static function prepare(string $component, string $notice, array $params): array {
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
        return [$declared, $params];
    }

    /**
     * Queues a notice for some contacts.
     *
     * @param int[] $contactids The contacts.
     * @param \stdClass $declared The notice.
     * @param string[] $params Its values.
     * @param \moodle_url|null $url Where its button goes.
     * @param bool $urgent Whether it goes out in the quiet hours too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @return int How many.
     */
    private static function queue_for(
        array $contactids,
        \stdClass $declared,
        array $params,
        ?\moodle_url $url,
        bool $urgent,
        int $validfor
    ): int {
        foreach ($contactids as $contactid) {
            queue::add((int)$contactid, $declared, $params, $url ? $url->out(false) : null, $urgent, $validfor);
        }
        return count($contactids);
    }
}
