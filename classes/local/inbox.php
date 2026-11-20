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
     *               plugin that answered or kept the message, or empty) and, if there is one, lastnotice (component,
     *               notice, label, timesent).
     */
    public static function context(string $phone, string $messageid = '', int $days = 7, ?int $now = null): array {
        global $DB;
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $result = [
            'phone' => $digits,
            'status' => 'none',
            'joined' => [],
            'takenby' => conversation::taken_by($messageid),
        ];
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
        $params += ['contactid' => $contact->id, 'since' => ($now ?? time()) - max(1, $days) * DAYSECS];
        $last = $DB->get_records_select(
            queue::TABLE,
            "contactid = :contactid AND status $in AND timesent >= :since",
            $params,
            'timesent DESC, id DESC',
            'id, component, notice, timesent',
            0,
            1
        );
        if ($last) {
            $last = reset($last);
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
}
