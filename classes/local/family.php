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
 * The family records of Oksigenia Family (local_oksigeniafamily), when the site has them.
 *
 * A number that joins with a student's code, or that the school already has for an adult, stands for that adult; the
 * notices about a student reach the numbers of the student's adults. When an adult leaves the records or changes their
 * phone, the old number stops standing for them. Being in the records never makes a number receive anything: only
 * writing the opt-in word does. Marandu works the same without the records.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family {
    /** The component of the links that stand for an adult of the records; their item is the adult. */
    public const COMPONENT = 'local_oksigeniafamily';

    /**
     * Whether the site has the family records.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists('\local_oksigeniafamily\api') && \local_oksigeniafamily\api::is_available();
    }

    /**
     * Claims a student's code for a number, as the family wrote it on WhatsApp.
     *
     * @param string $phone Digits with the country code.
     * @param string $code The code.
     * @param string|null $name The name WhatsApp gives, if any.
     * @return int|null The adult, or null if the code is not one of the records.
     */
    public static function claim(string $phone, string $code, ?string $name = null): ?int {
        if (!self::available()) {
            return null;
        }
        // A chat of Telegram has no phone to give the records.
        $phone = telegram::is_chat($phone) ? '' : '+' . $phone;
        try {
            $adult = \local_oksigeniafamily\api::claim($code, array_filter(['phone' => $phone, 'firstname' => $name]));
        } catch (\moodle_exception $e) {
            return null;
        }
        return (int)$adult->id;
    }

    /**
     * The adults of the records with a number.
     *
     * @param string $phone Digits with the country code.
     * @return int[]
     */
    public static function adults(string $phone): array {
        if (!self::available() || telegram::is_chat($phone)) {
            return [];
        }
        return array_map('intval', array_keys(\local_oksigeniafamily\api::adults_by_phone('+' . $phone)));
    }

    /**
     * Makes a number stand for adults of the records, once each.
     *
     * @param int $contactid The contact.
     * @param int[] $adultids The adults.
     */
    public static function link(int $contactid, array $adultids): void {
        global $DB;
        $now = time();
        foreach (array_unique($adultids) as $adultid) {
            $params = ['component' => self::COMPONENT, 'itemid' => $adultid, 'contactid' => $contactid];
            if ($DB->record_exists(consent::LINKS, $params)) {
                continue;
            }
            do {
                $code = keywords::new_code();
            } while ($DB->record_exists(consent::LINKS, ['code' => $code]));
            $DB->insert_record(consent::LINKS, (object)($params + [
                'code' => $code,
                'timeclaimed' => $now,
                'timecreated' => $now,
            ]));
        }
    }

    /**
     * The contacts that stand for the adults of a student, whatever their status.
     *
     * @param int $childuserid The student.
     * @return int[]
     */
    public static function contacts_of_child(int $childuserid): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        $adultids = array_keys(\local_oksigeniafamily\api::adults_of_child($childuserid));
        if (!$adultids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($adultids, SQL_PARAMS_NAMED);
        return array_map('intval', $DB->get_fieldset_select(
            consent::LINKS,
            'DISTINCT contactid',
            "component = :component AND itemid $insql AND contactid IS NOT NULL",
            $params + ['component' => self::COMPONENT]
        ));
    }

    /**
     * Drops the links of adults that no longer have that number in the records: they left, their family closed, or
     * their phone changed (and the old number may belong to somebody else by now).
     *
     * @param int[]|null $adultids Only these adults; all if null.
     * @return int How many links were dropped.
     */
    public static function check(?array $adultids = null): int {
        global $DB;
        if (!self::available() || $adultids === []) {
            return 0;
        }
        $where = 'l.component = :component';
        $params = ['component' => self::COMPONENT];
        if ($adultids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($adultids, SQL_PARAMS_NAMED);
            $where .= " AND l.itemid $insql";
            $params += $inparams;
        }
        $links = $DB->get_records_sql("SELECT l.id, l.itemid, c.phone
                                         FROM {" . consent::LINKS . "} l
                                         JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                        WHERE $where", $params);
        $adults = [];
        $dropped = 0;
        foreach ($links as $link) {
            // A chat of Telegram has no number to compare: it joined with a code, or shared its number once. It stays
            // linked until the adult leaves the records (adult_changed).
            if (telegram::is_chat($link->phone)) {
                continue;
            }
            $adults[$link->phone] = $adults[$link->phone] ?? self::adults($link->phone);
            if (!in_array((int)$link->itemid, $adults[$link->phone], true)) {
                $DB->delete_records(consent::LINKS, ['id' => $link->id]);
                $dropped++;
            }
        }
        return $dropped;
    }

    /**
     * When an adult leaves the records or their details change: their links are checked at once.
     *
     * @param \core\event\base $event adult_removed or adult_updated of local_oksigeniafamily.
     */
    public static function adult_changed(\core\event\base $event): void {
        global $DB;
        if ($event instanceof \local_oksigeniafamily\event\adult_removed) {
            // Gone from the records: no number, whatever its channel, stands for them any more.
            $DB->delete_records(consent::LINKS, ['component' => self::COMPONENT, 'itemid' => (int)$event->objectid]);
            return;
        }
        self::check([(int)$event->objectid]);
    }
}
