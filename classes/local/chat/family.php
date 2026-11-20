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
 * Who writes, from the common record of families of the site (Oksigenia Family, if it is there): the family of the
 * number, its children with their group and stage, and its other adults. A hint for the team, never an access: a
 * number found in a record that nobody vouched for still says who it probably is.
 *
 * The stages are data of each organisation (the setting chatstages): their name, colour and how a group is known to
 * be of each, by the stage the record guesses from the group (such as PRI) or by a pattern of the group.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family {
    /** The common record of families. */
    private const API = '\\local_oksigeniafamily\\api';

    /** @var array The families found in this request, by phone. */
    protected static $found = [];

    /**
     * Whether the site has the common record of families, with the search by number.
     *
     * @return bool
     */
    public static function available(): bool {
        $api = self::API;
        return class_exists($api) && method_exists($api, 'adults_by_phone_hint') && method_exists($api, 'household')
            && (!method_exists($api, 'is_available') || $api::is_available());
    }

    /**
     * The stages of the organisation, in their order (the youngest first).
     *
     * @return array Each with label, color, dark, code and match.
     */
    public static function stages(): array {
        $list = json_decode((string)get_config('local_oksigeniamarandu', 'chatstages'), true);
        $stages = [];
        foreach (is_array($list) ? $list : [] as $one) {
            if (!is_array($one) || trim((string)($one['label'] ?? '')) === '') {
                continue;
            }
            $stages[] = [
                'label' => trim((string)$one['label']),
                'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($one['color'] ?? '')) ? (string)$one['color'] : '',
                'dark' => preg_match('/^#[0-9a-f]{6}$/i', (string)($one['dark'] ?? '')) ? (string)$one['dark'] : '',
                'code' => trim((string)($one['code'] ?? '')),
                'match' => (string)($one['match'] ?? ''),
                'show' => (string)($one['show'] ?? ''),
            ];
        }
        return $stages;
    }

    /**
     * The stage of a group, and the group as people say it: «PRI 5º B» is «5.º B» of Primaria.
     *
     * @param string $group The group, as the record has it.
     * @param string $code The stage the record guessed from it, if any.
     * @return array [stage or null, the group as it is shown, the order of the stage].
     */
    public static function stage_of(string $group, string $code = ''): array {
        $group = trim(preg_replace('/\s+/u', ' ', $group));
        foreach (self::stages() as $order => $stage) {
            $same = \core_text::strtolower($stage['code']) === \core_text::strtolower($code);
            $bycode = $stage['code'] !== '' && $code !== '' && $same;
            $bymatch = $stage['match'] !== '' && @preg_match('~' . str_replace('~', '\\~', $stage['match']) . '~iu', $group);
            $bytoken = $stage['code'] !== '' && preg_match('~(^|\s)' . preg_quote($stage['code'], '~') . '(\s|$)~iu', $group);
            if (!$bycode && !$bymatch && !$bytoken) {
                continue;
            }
            $shown = $group;
            if ($stage['show'] !== '' && @preg_match('~' . str_replace('~', '\\~', $stage['show']) . '~iu', $group, $m)) {
                $shown = trim($m[1] ?? $m[0]);
            } else if ($stage['code'] !== '') {
                $shown = trim(preg_replace('~(^|\s)' . preg_quote($stage['code'], '~') . '(\s|$)~iu', ' ', $group));
            }
            return [$stage, self::ordinal($shown), $order];
        }
        return [null, self::ordinal($group), PHP_INT_MAX];
    }

    /**
     * A course written with its ordinal as in Spanish books: «5º» is «5.º».
     *
     * @param string $text The group.
     * @return string
     */
    protected static function ordinal(string $text): string {
        return preg_replace('/(\d)\s*([ºª])/u', '$1.$2', $text);
    }

    /**
     * The family of a number, if the record has it.
     *
     * @param string $phone Only digits, with the country code.
     * @return array|null title, line, verified, children (name, group, stage with label, color and dark, order) and
     *                    adults (name and phone).
     */
    public static function of_phone(string $phone): ?array {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone === '' || !self::available()) {
            return null;
        }
        if (array_key_exists($phone, self::$found)) {
            return self::$found[$phone];
        }
        $cache = \cache::make('local_oksigeniamarandu', 'chat');
        $kept = $cache->get('family_' . $phone);
        if (is_array($kept)) {
            return self::$found[$phone] = $kept['family'];
        }
        $family = self::find($phone);
        $cache->set('family_' . $phone, ['family' => $family]);
        return self::$found[$phone] = $family;
    }

    /**
     * Looks the number up in the record.
     *
     * @param string $phone Only digits.
     * @return array|null
     */
    protected static function find(string $phone): ?array {
        $api = self::API;
        $adults = array_values($api::adults_by_phone_hint('+' . $phone));
        if (!$adults) {
            return null;
        }
        $adult = $adults[0];
        $household = $api::household((int)$adult->familyid, true);
        if (!$household) {
            return null;
        }
        $household = (array)$household;
        $children = [];
        foreach ($household['children'] ?? [] as $child) {
            $child = (array)$child;
            [$stage, $group, $order] = self::stage_of((string)($child['group'] ?? ''), (string)($child['stage'] ?? ''));
            $children[] = [
                'name' => trim((string)($child['firstname'] ?? '')),
                'lastname' => trim((string)($child['lastname'] ?? '')),
                'group' => $group,
                'stage' => $stage ? ['label' => $stage['label'], 'color' => $stage['color'], 'dark' => $stage['dark']] : null,
                'order' => $order,
            ];
        }
        usort($children, function ($a, $b) {
            return [$a['order'], $a['group'], $a['name']] <=> [$b['order'], $b['group'], $b['name']];
        });
        $others = [];
        foreach ($household['adults'] ?? [] as $one) {
            $one = (array)$one;
            $others[] = ['name' => trim(($one['firstname'] ?? '') . ' ' . ($one['lastname'] ?? '')),
                'phone' => preg_replace('/[^0-9]/', '', (string)($one['phone'] ?? ''))];
        }
        $names = array_map(function ($child) {
            return $child['group'] !== '' ? $child['name'] . ' (' . $child['group'] . ')' : $child['name'];
        }, $children);
        $surname = $children[0]['lastname'] ?? trim((string)($adult->lastname ?? ''));
        return [
            'title' => $surname !== '' ? get_string('chat:family:title', 'local_oksigeniamarandu', $surname) : '',
            'line' => $names ? get_string('chat:family:line', 'local_oksigeniamarandu', self::join($names)) : '',
            'verified' => !empty($adult->phoneverified),
            'children' => $children,
            'adults' => $others,
        ];
    }

    /**
     * A list said as people say it: «A, B y C».
     *
     * @param string[] $items The items.
     * @return string
     */
    protected static function join(array $items): string {
        if (count($items) < 2) {
            return (string)reset($items);
        }
        $last = array_pop($items);
        return get_string('chat:family:and', 'local_oksigeniamarandu', (object)['list' => implode(', ', $items), 'last' => $last]);
    }

    /**
     * Forgets the families of this request, for the tests.
     */
    public static function forget(): void {
        self::$found = [];
    }
}
