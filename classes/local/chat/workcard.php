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

namespace local_oksigeniamarandu\local\chat;

/**
 * The card of the conversations in «Your work» (the dashboard of the block Tembiapo and of the app): what waits for
 * this person, and what has waited too long, counting only the hours of the team.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workcard {
    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** From how long a wait is urgent, in the hours of the team. */
    public const URGENT = 4 * HOURSECS;

    /**
     * The card of a user, if they are a person of the conversations.
     *
     * @param int $userid The user.
     * @param int|null $now The time, for the tests.
     * @return array|null As the dashboard takes it, or null.
     */
    public static function for_user(int $userid, ?int $now = null): ?array {
        global $DB;
        if (!chatwoot::is_configured()) {
            return null;
        }
        try {
            $viewer = viewer::for_user(\core_user::get_user($userid));
            if (!$viewer || $viewer->readonly) {
                return null;
            }
            $hours = hours::of_site();
        } catch (\moodle_exception $e) {
            return null;
        }
        $now = $now ?? time();
        $page = new \moodle_url('/local/oksigeniamarandu/chat.php');
        [$where, $params] = $viewer->where();
        $rows = $DB->get_records_sql("SELECT c.* FROM {" . mirror::TABLE . "} c
                                       WHERE $where AND c.status <> 'resolved' AND c.waitsince > 0
                                    ORDER BY c.waitsince ASC", $params);
        $mine = [];
        $unassigned = [];
        foreach ($rows as $row) {
            if ((int)$row->assigneeid === $viewer->agent['id']) {
                $mine[] = $row;
            } else if (!$row->assigneeid && (!$viewer->teams || in_array((int)$row->teamid, $viewer->teams))) {
                $unassigned[] = $row;
            }
        }

        $figures = [];
        if ($mine) {
            $figures[] = count($mine) === 1 ? get_string('chat:work:mineone', self::COMPONENT)
                : get_string('chat:work:mine', self::COMPONENT, count($mine));
        }
        if ($unassigned) {
            $figures[] = get_string('chat:work:unassigned', self::COMPONENT, count($unassigned));
        }
        if (!$figures) {
            $figures[] = get_string('chat:work:nothing', self::COMPONENT);
        }
        if (!$hours->is_open($now)) {
            $opens = $hours->next_open($now);
            if ($opens) {
                $figures[] = get_string('chat:work:closed', self::COMPONENT, self::when($opens, $now));
            }
        }

        $alerts = [];
        $late = array_filter(array_merge($mine, $unassigned), function ($row) use ($hours, $now) {
            return $hours->waited((int)$row->waitsince, $now) >= self::URGENT;
        });
        if (count($late) === 1) {
            $row = reset($late);
            $alerts[] = ['text' => get_string('chat:work:lateone', self::COMPONENT, (object)[
                'name' => $row->name !== '' ? $row->name : '+' . $row->phone,
                'hours' => intdiv($hours->waited((int)$row->waitsince, $now), HOURSECS),
            ]), 'url' => (new \moodle_url($page, ['c' => $row->cwid]))->out(false), 'level' => 'urgent',
                'due' => (int)$row->waitsince];
        } else if ($late) {
            $teams = array_unique(array_map(function ($row) {
                return (int)$row->teamid;
            }, $late));
            $url = count($teams) === 1 ? new \moodle_url($page, ['b' => 'team-' . reset($teams)]) : $page;
            $alerts[] = ['text' => get_string('chat:work:latemany', self::COMPONENT, count($late)),
                'url' => $url->out(false), 'level' => 'urgent', 'due' => (int)reset($late)->waitsince];
        }
        if ($mine) {
            $text = count($mine) === 1 ? get_string('chat:work:minelineone', self::COMPONENT)
                : get_string('chat:work:mineline', self::COMPONENT, count($mine));
            $alerts[] = ['text' => $text,
                'url' => (new \moodle_url($page, ['b' => 'mine']))->out(false), 'level' => 'today'];
        }
        if ($unassigned) {
            $alerts[] = ['text' => get_string('chat:work:unassignedline', self::COMPONENT, count($unassigned)),
                'url' => (new \moodle_url($page, ['b' => 'unassigned']))->out(false), 'level' => 'today'];
        }
        $passed = $DB->get_records_select(
            mirror::TABLE,
            "passedto = :me AND passedat > :since AND passedby <> :userid
            AND status <> 'resolved'",
            ['me' => $viewer->agent['id'], 'since' => $now - DAYSECS, 'userid' => $userid],
            'passedat DESC',
            '*',
            0,
            3
        );
        $teams = directory::teams();
        foreach ($passed as $row) {
            $by = \core_user::get_user((int)$row->passedby);
            $alerts[] = ['text' => get_string('chat:work:passed', self::COMPONENT, (object)[
                'by' => $by ? $by->firstname : '',
                'team' => $teams[(int)$row->teamid]['name'] ?? '',
            ]), 'url' => (new \moodle_url($page, ['c' => $row->cwid]))->out(false), 'level' => 'today'];
        }

        return [
            'key' => self::COMPONENT . '-chat',
            'title' => get_string('chat:work:title', self::COMPONENT),
            'url' => $page->out(false),
            'status' => implode(' · ', $figures),
            'alerts' => $alerts,
            'actions' => [
                ['label' => get_string('chat:work:actionmine', self::COMPONENT),
                    'url' => (new \moodle_url($page, ['b' => 'mine']))->out(false)],
                ['label' => get_string('chat:work:actionunassigned', self::COMPONENT),
                    'url' => (new \moodle_url($page, ['b' => 'unassigned']))->out(false)],
            ],
        ];
    }

    /**
     * When the team opens, as it is said: at a time today, or the day of the week and the time.
     *
     * @param int $time When it opens.
     * @param int $now Now.
     * @return string
     */
    private static function when(int $time, int $now): string {
        $hour = userdate($time, get_string('strftimetime', 'langconfig'));
        if (userdate($time, '%Y%m%d') === userdate($now, '%Y%m%d')) {
            return get_string('chat:work:attime', self::COMPONENT, $hour);
        }
        return get_string('chat:work:atday', self::COMPONENT, (object)[
            'day' => \core_text::strtolower(userdate($time, '%A')),
            'time' => $hour,
        ]);
    }
}
