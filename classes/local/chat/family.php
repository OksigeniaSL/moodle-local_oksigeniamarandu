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
        // An adult whose number the centre checked goes before one whose number someone only typed.
        usort($adults, function ($a, $b) {
            return (int)!empty($b->phoneverified) <=> (int)!empty($a->phoneverified);
        });
        $adult = $adults[0];
        $household = $api::household((int)$adult->familyid, true);
        if (!$household) {
            return null;
        }
        $family = self::describe((array)$household);
        $surname = $family['children'][0]['lastname'] ?? trim((string)($adult->lastname ?? ''));
        return [
            'title' => $surname !== '' ? get_string('chat:family:title', 'local_oksigeniamarandu', $surname) : '',
            'line' => $family['line'],
            'verified' => !empty($adult->phoneverified),
            'children' => $family['children'],
            'adults' => $family['adults'],
        ];
    }

    /**
     * A household of the record as the conversations say it: its children in the order of their stages, with their
     * group as people say it, its adults and the line that says whose family it is.
     *
     * @param array $household As the record gives it.
     * @return array children, adults (name, phone and verified) and line.
     */
    protected static function describe(array $household): array {
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
        $adults = [];
        foreach ($household['adults'] ?? [] as $one) {
            $one = (array)$one;
            $adults[] = ['name' => trim(($one['firstname'] ?? '') . ' ' . ($one['lastname'] ?? '')),
                'phone' => preg_replace('/[^0-9]/', '', (string)($one['phone'] ?? '')),
                'verified' => !empty($one['phoneverified'])];
        }
        $names = array_map(function ($child) {
            return $child['group'] !== '' ? $child['name'] . ' (' . $child['group'] . ')' : $child['name'];
        }, $children);
        $line = $names ? get_string('chat:family:line', 'local_oksigeniamarandu', self::join($names)) : '';
        return ['children' => $children, 'adults' => $adults, 'line' => $line];
    }

    /**
     * The families whose adults or children are called as the words say, for whoever starts a conversation: «pablo»,
     * «gil» or «laura gil». Each word has to start a word of a name of the family.
     *
     * @param string $query What was typed.
     * @param int $limit How many families at most.
     * @return array Each with line, children and adults (name, phone, verified and whether their name matched).
     */
    public static function search(string $query, int $limit = 6): array {
        $words = self::words($query);
        if (!$words || !self::available() || !method_exists(self::API, 'each_household')) {
            return [];
        }
        $found = [];
        foreach (self::index() as $family) {
            if (!self::matches($words, $family['words'])) {
                continue;
            }
            $adults = [];
            foreach ($family['adults'] as $adult) {
                $adults[] = $adult + ['matched' => self::matches($words, self::words($adult['name']), true)];
            }
            usort($adults, function ($a, $b) {
                return $b['matched'] <=> $a['matched'];
            });
            $found[] = ['line' => $family['line'], 'children' => $family['children'], 'adults' => $adults];
            if (count($found) >= $limit) {
                break;
            }
        }
        return $found;
    }

    /**
     * The roll of families, short, to look names up in it: kept a few minutes, as the rest of the directory.
     *
     * @return array Each with words, line, children and adults.
     */
    protected static function index(): array {
        $cache = \cache::make('local_oksigeniamarandu', 'chat');
        $index = $cache->get('familyindex');
        if (is_array($index)) {
            return $index;
        }
        $api = self::API;
        $index = [];
        foreach ($api::each_household(true, true) as $household) {
            $family = self::describe((array)$household);
            $names = array_merge(array_column($family['adults'], 'name'), array_map(function ($child) {
                return $child['name'] . ' ' . $child['lastname'];
            }, $family['children']));
            $index[] = ['words' => self::words(implode(' ', $names)), 'line' => $family['line'],
                'children' => $family['children'], 'adults' => array_values(array_filter($family['adults'], function ($adult) {
                    return $adult['phone'] !== '';
                }))];
        }
        $cache->set('familyindex', $index);
        return $index;
    }

    /**
     * The words of a text, in small letters and without accents, to compare names as people type them.
     *
     * @param string $text The text.
     * @return string[]
     */
    public static function words(string $text): array {
        $text = \core_text::strtolower(trim($text));
        if (class_exists('\\Normalizer')) {
            $text = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($text, \Normalizer::FORM_D));
        }
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text), 'strlen'));
    }

    /**
     * Whether each word typed starts a word of the name (or, with $any, at least one does).
     *
     * @param string[] $typed The words typed.
     * @param string[] $words The words of the name.
     * @param bool $any Whether one is enough.
     * @return bool
     */
    protected static function matches(array $typed, array $words, bool $any = false): bool {
        foreach ($typed as $one) {
            $hit = false;
            foreach ($words as $word) {
                if (strpos($word, $one) === 0) {
                    $hit = true;
                    break;
                }
            }
            if ($hit && $any) {
                return true;
            }
            if (!$hit && !$any) {
                return false;
            }
        }
        return !$any;
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
