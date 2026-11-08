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
 * What the channel has for whoever reviews it or runs it, on their dashboard («Your work», of the Oksigenia plugins):
 * a door to the page of the channel, a line with its figures and what needs them, most urgent first.
 *
 * It runs on every load of the dashboard, so it is kept for a minute and forgotten as soon as a post changes. It is the
 * same for everybody who may review, in each language.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class work {
    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** Posts to review named one by one; more than this, in one line. */
    private const NAMED = 3;

    /** How long a post that could not go out is still told. */
    private const FAILEDFOR = 3 * DAYSECS;

    /**
     * The cards of a user: the channel, if they may review it and the site has one or posts waiting for one.
     *
     * @param int $userid The user.
     * @param int|null $now The time, for the tests; with it, nothing is kept.
     * @return array As the dashboard takes them: key, title, url, status, alerts (text, url, level, due), actions (label, url).
     */
    public static function for_user(int $userid, ?int $now = null): array {
        if (!has_capability('local/oksigeniamarandu:channel', \context_system::instance(), $userid)) {
            return [];
        }
        if ($now !== null) {
            $card = self::channel($now);
        } else {
            $cache = \cache::make(self::COMPONENT, 'work');
            $key = 'channel_' . current_language();
            $kept = $cache->get($key);
            if (is_array($kept)) {
                $card = $kept['card'];
            } else {
                $card = self::channel(time());
                $cache->set($key, ['card' => $card]);
            }
        }
        return $card ? [$card] : [];
    }

    /**
     * Forgets what was kept: something of the channel changed.
     */
    public static function changed(): void {
        \cache::make(self::COMPONENT, 'work')->purge();
    }

    /**
     * The card of the channel.
     *
     * @param int $now The time.
     * @return array|null Null if the site has no channel and nothing waits for one.
     */
    private static function channel(int $now): ?array {
        global $DB;
        $counts = $DB->get_records_sql_menu(
            "SELECT status, COUNT(1)
               FROM {" . channel::TABLE . "}
              WHERE status IN ('review', 'ready') OR (status = 'failed' AND timemodified >= :since)
           GROUP BY status",
            ['since' => $now - self::FAILEDFOR]
        );
        $review = (int)($counts['review'] ?? 0);
        $ready = (int)($counts['ready'] ?? 0);
        $failed = (int)($counts['failed'] ?? 0);
        $current = channel::current();
        if (!$current && !$review && !$ready) {
            return null;
        }
        $page = new \moodle_url('/local/oksigeniamarandu/channel.php');
        $alerts = [];

        // What stops the channel, before anything else: its time is already here.
        if (!$current) {
            $alerts[] = self::alert('work:disconnected', $page, 'urgent', null, $now);
        } else if (!telegram_api::is_configured() || queue::blocked('telegram')) {
            $alerts[] = self::alert('work:nobot', $page, 'urgent', null, $now);
        } else if ($current->problem !== '') {
            $alerts[] = self::alert('work:problem', $page, 'urgent', null, $now);
        }

        // What waits for a review is something to do once it reached whoever reviews (in the hours of review, or at once
        // if it is urgent): until then, the line of figures says when it will. Without anybody who reviews on Telegram,
        // it is done on the page, in the hours of review.
        $reviewers = $current ? channel::reviewers() : [];
        $sent = $review ? $DB->count_records_select(channel::TABLE, "status = 'review' AND timereview IS NOT NULL") : 0;
        $onpage = !$reviewers && $review && channel::open('review', $now);
        $due = $reviewers ? $sent : ($onpage ? $review : 0);
        $later = $review - $due;
        if ($due) {
            $select = "status = 'review'" . ($reviewers ? ' AND timereview IS NOT NULL' : '');
            $posts = $DB->get_records_select(
                channel::TABLE,
                $select,
                null,
                'urgent DESC, timeplanned ASC, id ASC',
                '*',
                0,
                self::NAMED + 1
            );
            $reviewurl = new \moodle_url($page, null, 'mrd-review');
            $string = $reviewers ? 'work:review' : 'work:reviewpage';
            $levels = [];
            $named = [];
            $first = null;
            foreach ($posts as $post) {
                // Its deadline: when it goes out if it is said yes.
                $at = channel::goes_at($post, $now);
                $first = min($first ?? $at, $at);
                // Urgent if it is urgent, or if it would go out now: only the review holds it back.
                $levels[] = $post->urgent || $at <= $now ? 'urgent' : 'today';
                $content = json_decode($post->content, true) ?: [];
                $a = (object)[
                    'title' => shorten_text((string)($content['title'] ?? ($content['text'][0] ?? '')), 60),
                    'when' => $at <= $now ? get_string('channel:review:now', self::COMPONENT) : self::when($at, $now),
                ];
                $named[] = self::alert($string, $reviewurl, end($levels), $a, $at);
            }
            if ($due <= self::NAMED) {
                array_push($alerts, ...$named);
            } else {
                $level = in_array('urgent', $levels, true) ? 'urgent' : 'today';
                $alerts[] = self::alert('work:reviewmany', $reviewurl, $level, $due, $first);
            }
        }
        if ($review && $current && !$reviewers) {
            $alerts[] = self::alert('work:noreviewers', new \moodle_url($page, null, 'mrd-review'), 'today');
        }
        if ($failed) {
            $alerts[] = self::alert('work:failed', new \moodle_url($page, null, 'mrd-failed'), 'today', $failed);
        }
        // The most urgent first and, in each level, by deadline; what has none, after.
        usort($alerts, function (array $a, array $b): int {
            return [$a['level'] === 'urgent' ? 0 : 1, isset($a['due']) ? 0 : 1, $a['due'] ?? 0]
                <=> [$b['level'] === 'urgent' ? 0 : 1, isset($b['due']) ? 0 : 1, $b['due'] ?? 0];
        });

        $figures = [];
        if ($due) {
            $figures[] = get_string('work:status:review', self::COMPONENT, $due);
        }
        if ($later) {
            $figures[] = get_string('work:status:later', self::COMPONENT, (object)['count' => $later,
                'when' => self::when(channel::next_open('review', $now), $now)]);
        }
        if ($ready) {
            $figures[] = get_string('work:status:ready', self::COMPONENT, $ready);
        }
        $members = $current ? channel::members($now) : null;
        if ($members) {
            $figures[] = get_string('work:status:members', self::COMPONENT, $members->now);
        }
        if (!$review && !$ready) {
            $last = $DB->get_records(channel::TABLE, ['status' => 'published'], 'timepublished DESC', 'id, timepublished', 0, 1);
            $figures[] = $last
                ? get_string('work:status:last', self::COMPONENT, self::when((int)reset($last)->timepublished, $now))
                : get_string('work:status:none', self::COMPONENT);
        }

        $actions = [];
        if ($current && $current->username !== '') {
            $actions[] = ['label' => get_string('work:open', self::COMPONENT), 'url' => 'https://t.me/' . $current->username];
        }
        return [
            'key' => self::COMPONENT . '-channel',
            'title' => get_string('work:title', self::COMPONENT),
            'url' => $page->out(false),
            'status' => self::capital(implode(' · ', $figures)),
            'alerts' => $alerts,
            'actions' => $actions,
        ];
    }

    /**
     * A time near now, as it is said: today, tomorrow or yesterday, the day of the week within one, or the date; and the
     * hour.
     *
     * @param int $time The time.
     * @param int $now Now.
     * @return string
     */
    public static function when(int $time, int $now): string {
        $hour = ltrim(userdate($time, '%H'), '0') ?: '0';
        $hour .= ':' . userdate($time, '%M');
        $days = (int)round((usergetmidnight($time) - usergetmidnight($now)) / DAYSECS);
        $names = [0 => 'today', 1 => 'tomorrow', -1 => 'yesterday'];
        if (isset($names[$days])) {
            return get_string('work:when:' . $names[$days], self::COMPONENT, $hour);
        }
        $day = abs($days) < 7 ? userdate($time, '%A') : userdate($time, get_string('work:when:dateformat', self::COMPONENT));
        return get_string('work:when:day', self::COMPONENT, (object)['day' => $day, 'hour' => $hour]);
    }

    /**
     * A line with its first letter in capitals.
     *
     * @param string $text The line.
     * @return string
     */
    private static function capital(string $text): string {
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * One thing to do.
     *
     * @param string $string Its string.
     * @param \moodle_url $url Where it is done.
     * @param string $level urgent or today.
     * @param mixed $a What the string takes.
     * @param int|null $due When it has to be done by, if it has a deadline.
     * @return array
     */
    private static function alert(string $string, \moodle_url $url, string $level, $a = null, ?int $due = null): array {
        $alert = ['text' => get_string($string, self::COMPONENT, $a), 'url' => $url->out(false), 'level' => $level];
        return $due === null ? $alert : $alert + ['due' => $due];
    }
}
