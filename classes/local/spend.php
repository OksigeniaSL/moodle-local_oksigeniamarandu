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
 * What WhatsApp costs the site this month, as Meta counts it, by category and day by day, against the budget of the
 * month if the site sets one; with a word to whoever sees everything at 80 % and at 100 %.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spend {
    /** The categories of Meta, in the order they are shown. */
    public const CATEGORIES = ['utility', 'marketing', 'authentication', 'service'];

    /** The shares of the budget that are told. */
    public const ALERTS = [80, 100];

    /** @var string The component. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /**
     * The month as it was last read: cost, count, currency, by category, by day, budget and when it was read.
     *
     * @return array
     */
    public static function month(): array {
        $month = userdate(time(), '%Y-%m', 99, false, false);
        $kept = json_decode((string)get_config(self::COMPONENT, 'spendmonth'), true);
        if (!is_array($kept) || ($kept['month'] ?? '') !== $month) {
            $kept = ['month' => $month, 'cost' => 0.0, 'count' => 0, 'currency' => '', 'categories' => [], 'days' => [],
                'time' => 0];
        }
        $kept['budget'] = (float)get_config(self::COMPONENT, 'budgetmonth');
        $kept['share'] = $kept['budget'] > 0 ? (int)floor(round(100 * $kept['cost'] / $kept['budget'], 6)) : 0;
        return $kept;
    }

    /**
     * Reads the month again from Meta and tells whoever sees everything if it went past 80 % or 100 % of the budget.
     *
     * @param cloud_api $api The number of the site.
     * @param string $wabaid Its WhatsApp Business account.
     * @param int|null $now The time; now if null.
     * @return array The month, as month() gives it.
     */
    public static function refresh(cloud_api $api, string $wabaid, ?int $now = null): array {
        $now = $now ?? time();
        $start = usergetmidnight(make_timestamp(
            (int)userdate($now, '%Y', 99, false, false),
            (int)userdate($now, '%m', 99, false, false),
            1
        ));
        $answer = $api->pricing($wabaid, $start, $now);
        $categories = array_fill_keys(self::CATEGORIES, ['count' => 0, 'cost' => 0.0]);
        $days = [];
        $cost = 0.0;
        $count = 0;
        foreach ($answer['points'] as $point) {
            $category = strtolower((string)($point['pricing_category'] ?? 'service'));
            if (!isset($categories[$category])) {
                $category = 'service';
            }
            $volume = (int)($point['volume'] ?? 0);
            $amount = (float)($point['cost'] ?? 0);
            $categories[$category]['count'] += $volume;
            $categories[$category]['cost'] += $amount;
            // Day by day, what costs, by category: the free service does not show.
            $day = (int)userdate((int)($point['start'] ?? $now), '%d', 99, false, false);
            if ($amount > 0) {
                $days[$day][$category] = ($days[$day][$category] ?? 0) + $amount;
            }
            $cost += $amount;
            $count += $volume;
        }
        ksort($days);
        foreach ($categories as $key => $value) {
            $categories[$key]['cost'] = round($value['cost'], 2);
        }
        $month = [
            'month' => userdate($now, '%Y-%m', 99, false, false),
            'cost' => round($cost, 2),
            'count' => $count,
            'currency' => $answer['currency'] !== '' ? $answer['currency'] : chat\templates::prices()['currency'],
            'categories' => $categories,
            'days' => array_map(function ($bycategory) {
                return array_map(function ($amount) {
                    return round($amount, 2);
                }, $bycategory);
            }, $days),
            'time' => $now,
        ];
        set_config('spendmonth', json_encode($month), self::COMPONENT);
        $month = self::month();
        self::alert($month);
        return $month;
    }

    /**
     * Tells whoever sees everything, once a month for each share, that the month went past 80 % or 100 % of its budget.
     *
     * @param array $month The month, as month() gives it.
     */
    private static function alert(array $month): void {
        if ($month['budget'] <= 0) {
            return;
        }
        $told = json_decode((string)get_config(self::COMPONENT, 'spendalerted'), true);
        $told = is_array($told) && ($told['month'] ?? '') === $month['month'] ? $told : ['month' => $month['month'], 'done' => []];
        foreach (self::ALERTS as $share) {
            if ($month['share'] < $share || in_array($share, $told['done'], true)) {
                continue;
            }
            $told['done'][] = $share;
            $a = (object)['share' => $share, 'cost' => format_float($month['cost'], 2) . ' ' . $month['currency'],
                'budget' => format_float($month['budget'], 2) . ' ' . $month['currency']];
            $subject = get_string($share >= 100 ? 'spend:notify:over' : 'spend:notify:near', self::COMPONENT, $a);
            $url = new \moodle_url('/local/oksigeniamarandu/chat.php', ['b' => 'inicio']);
            foreach (self::people() as $user) {
                $message = new \core\message\message();
                $message->component = self::COMPONENT;
                $message->name = 'spend';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = $subject;
                $message->fullmessage = $subject;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = '';
                $message->smallmessage = $subject;
                $message->notification = 1;
                $message->customdata = \local_oksigeniamarandu\local\message_icon::customdata();
                $message->contexturl = $url->out(false);
                $message->contexturlname = get_string('chat:title', self::COMPONENT);
                message_send($message);
            }
        }
        set_config('spendalerted', json_encode($told), self::COMPONENT);
    }

    /**
     * Whoever sees what WhatsApp costs and approves the sends to many: the people the site chose (spendpeople), or,
     * until it chooses, whoever has the capability to approve them. Nobody else hears of it, though they see every
     * conversation.
     *
     * @return \stdClass[] The users, in the order the site gave them.
     */
    public static function people(): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)get_config(self::COMPONENT, 'spendpeople'))
        ))));
        if (!$ids) {
            $list = array_values(array_filter(get_users_by_capability(
                \context_system::instance(),
                'local/oksigeniamarandu:bulkapprove',
                'u.*',
                'u.lastname ASC'
            ), function ($user) {
                    return !$user->deleted && !$user->suspended;
            }));
            // With nobody chosen and nobody with the capability, the administrators of the site: a send never waits for
            // nobody.
            return $list ?: array_values(array_filter(get_admins(), function ($user) {
                return !$user->deleted && !$user->suspended;
            }));
        }
        $users = $DB->get_records_list('user', 'id', $ids);
        $list = [];
        foreach ($ids as $id) {
            if (isset($users[$id]) && !$users[$id]->deleted && !$users[$id]->suspended) {
                $list[] = $users[$id];
            }
        }
        return $list;
    }

    /**
     * Whether a user is one of the people who see what WhatsApp costs and approve the sends.
     *
     * @param int $userid The user.
     * @return bool
     */
    public static function is_person(int $userid): bool {
        foreach (self::people() as $user) {
            if ((int)$user->id === $userid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Chooses the people who see what WhatsApp costs and approve the sends; none, back to the capability.
     *
     * @param int[] $userids The users.
     */
    public static function set_people(array $userids): void {
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        set_config('spendpeople', implode(',', $userids), self::COMPONENT);
    }
}
