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

    /** @var array The students of age found in this request, by account: name, phone and userid, or null. */
    protected static $students = [];

    /** @var array The field of the date of birth, by its short name, once read. */
    protected static $fields = [];

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
        // A number that the centre checked for adults of several households is of all of them: they are all shown,
        // saying so, never one of them as if it were the only one.
        $ids = [(int)$adult->familyid];
        if (!empty($adult->phoneverified)) {
            foreach ($adults as $other) {
                if (!empty($other->phoneverified) && !in_array((int)$other->familyid, $ids, true)) {
                    $ids[] = (int)$other->familyid;
                }
            }
        }
        $found = [];
        foreach ($ids as $id) {
            $household = $api::household($id, true);
            if ($household) {
                $found[] = self::describe((array)$household);
            }
        }
        if (!$found) {
            return null;
        }
        $titles = [];
        foreach ($found as $family) {
            $surname = $family['children'][0]['lastname'] ?? trim((string)($adult->lastname ?? ''));
            if ($surname !== '' && !in_array($surname, $titles, true)) {
                $titles[] = $surname;
            }
        }
        if (count($found) === 1) {
            return [
                'title' => $titles ? get_string('chat:family:title', 'local_oksigeniamarandu', $titles[0]) : '',
                'line' => $found[0]['line'],
                'verified' => !empty($adult->phoneverified),
                'children' => $found[0]['children'],
                'adults' => $found[0]['adults'],
                'students' => $found[0]['students'],
                'households' => 1,
            ];
        }
        $lines = array_filter(array_column($found, 'line'), 'strlen');
        return [
            'title' => $titles ? get_string('chat:family:titles', 'local_oksigeniamarandu', self::join($titles)) : '',
            'line' => get_string('chat:family:shared', 'local_oksigeniamarandu', count($found)) .
                ($lines ? ' ' . implode(' · ', $lines) : ''),
            'verified' => true,
            'children' => array_merge(...array_column($found, 'children')),
            'adults' => array_merge(...array_column($found, 'adults')),
            'students' => array_merge(...array_column($found, 'students')),
            'households' => count($found),
        ];
    }

    /**
     * A household of the record as the conversations say it: its children in the order of their stages, with their
     * group as people say it, its adults and the line that says whose family it is.
     *
     * @param array $household As the record gives it.
     * @return array children, adults (name, phone and verified), students (the children of age with a mobile, when the
     *               site wants them: name, phone, userid, group and stage) and line.
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
                'userid' => (int)($child['userid'] ?? 0),
                // From Oksigenia Family 0.3.40 on: 12:00 UTC of the day of birth, or 0.
                'birthdate' => (int)($child['birthdate'] ?? 0),
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
        // A student of age can be written to beside the adults, never instead of them, when the site wants it.
        $students = [];
        $ofage = self::adult_students_on() ? self::students($children) : [];
        foreach ($children as $child) {
            if (isset($ofage[$child['userid']])) {
                // Their own group and stage: it is the student who is written to, not their family.
                $students[] = $ofage[$child['userid']] + ['group' => $child['group'], 'stage' => $child['stage']];
            }
        }
        return ['children' => $children, 'adults' => $adults, 'students' => $students, 'line' => $line];
    }

    /**
     * The families whose adults or children are called as the words say, for whoever starts a conversation: «pablo»,
     * «gil» or «laura gil». Each word has to start a word of a name of the family.
     *
     * @param string $query What was typed.
     * @param int $limit How many families at most.
     * @return array Each with line, children and adults (name, phone, verified, whether their name matched and whether
     *               they are a student of age, written to beside the adults, with their group and stage).
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
                $adults[] = $adult + ['matched' => self::matches($words, self::words($adult['name']), true), 'student' => false];
            }
            foreach ($family['students'] ?? [] as $student) {
                $adults[] = ['name' => $student['name'], 'phone' => $student['phone'], 'verified' => false,
                    'matched' => self::matches($words, self::words($student['name']), true), 'student' => true,
                    'group' => (string)($student['group'] ?? ''), 'stage' => $student['stage'] ?? null];
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
     * @return array Each with words, line, children, adults and students.
     */
    protected static function index(): array {
        $cache = \cache::make('local_oksigeniamarandu', 'chat');
        $index = $cache->get('familyindex');
        if (is_array($index)) {
            return $index;
        }
        $api = self::API;
        $index = [];
        $batch = [];
        $add = function (array $households) use (&$index) {
            // The students of age of a few hundred households, in one query.
            if (self::adult_students_on()) {
                $children = [];
                foreach ($households as $household) {
                    foreach ($household['children'] ?? [] as $child) {
                        $children[] = (array)$child;
                    }
                }
                self::students($children);
            }
            foreach ($households as $household) {
                $family = self::describe($household);
                $names = array_merge(array_column($family['adults'], 'name'), array_map(function ($child) {
                    return $child['name'] . ' ' . $child['lastname'];
                }, $family['children']));
                $index[] = ['words' => self::words(implode(' ', $names)), 'line' => $family['line'],
                    'children' => $family['children'], 'students' => $family['students'],
                    'adults' => array_values(array_filter($family['adults'], function ($adult) {
                        return $adult['phone'] !== '';
                    }))];
            }
        };
        foreach ($api::each_household(true, true) as $household) {
            $batch[] = (array)$household;
            if (count($batch) >= 500) {
                $add($batch);
                $batch = [];
            }
        }
        $add($batch);
        $cache->set('familyindex', $index);
        return $index;
    }

    /**
     * Whether the students of age are written to beside the adults of their family (cli/chat.php --adult-students=1).
     * Their date of birth comes from the record of families; a site whose record has none can say a field of the profile
     * that has it (--birth-field). Without either, nobody's age is known and nobody is shown.
     *
     * @return bool
     */
    public static function adult_students_on(): bool {
        return (bool)get_config('local_oksigeniamarandu', 'chatadultstudents');
    }

    /**
     * Where the day of birth of the students comes from, for the command line: the record of families, a field of the
     * profile, both, or nowhere.
     *
     * @return string
     */
    public static function births_from(): string {
        global $DB;
        $record = self::available() && $DB->get_manager()->field_exists('local_oksigeniafamily_child', 'birthdate');
        $field = self::birth_field();
        $from = array_filter([$record ? 'the record of families' : '',
            $field ? 'the field ' . $field->shortname . ' (' . $field->datatype . ')' : '']);
        return $from ? implode(', else ', $from) : 'nowhere: nobody is shown (--birth-field)';
    }

    /**
     * The field of the profile with the date of birth, as the site said it (setting chatbirthfield, its short name): a
     * date field, or a text one with the date written as 2008-03-15, 15/03/2008, 15-03-2008 or 15.03.2008.
     *
     * @return \stdClass|null id, shortname and datatype; null if the site said none or it does not exist.
     */
    public static function birth_field(): ?\stdClass {
        global $DB;
        $short = trim((string)get_config('local_oksigeniamarandu', 'chatbirthfield'));
        if ($short === '') {
            return null;
        }
        if (!array_key_exists($short, self::$fields)) {
            self::$fields[$short] = $DB->get_record('user_info_field', ['shortname' => $short], 'id, shortname, datatype') ?: null;
        }
        return self::$fields[$short];
    }

    /**
     * The students among these children who are 18 or older and have a mobile (the second phone of their profile): the
     * day of birth the record of families gives (Oksigenia Family 0.3.40 on), or else the one of the field of the profile
     * the site said.
     *
     * @param array $children The children, each with userid and, from the record, birthdate (12:00 UTC of that day, or 0).
     * @param int|null $now The time, for the tests.
     * @return array By account: name, phone (only digits, with the country code) and userid.
     */
    public static function students(array $children, ?int $now = null): array {
        global $DB;
        $field = self::birth_field();
        // The day of birth the record gives each account, if it gives one.
        $days = [];
        foreach ($children as $child) {
            $child = (array)$child;
            $userid = (int)($child['userid'] ?? 0);
            if ($userid) {
                $born = (int)($child['birthdate'] ?? 0);
                $days[$userid] = $born > 0 ? gmdate('Y-m-d', $born) : ($days[$userid] ?? '');
            }
        }
        $userids = array_keys($days);
        if (!$userids) {
            return [];
        }
        $ask = array_values(array_diff($userids, array_keys(self::$students)));
        foreach (array_chunk($ask, 1000) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'kid');
            $join = $field ? 'LEFT JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :fieldid' : '';
            $sql = "SELECT u.id, u.firstname, u.lastname, u.phone2" . ($field ? ', d.data' : '') . "
                      FROM {user} u $join
                     WHERE u.id $in AND u.deleted = 0 AND u.suspended = 0";
            $rows = $DB->get_records_sql($sql, $params + ($field ? ['fieldid' => $field->id] : []));
            foreach ($chunk as $userid) {
                self::$students[$userid] = null;
                $row = $rows[$userid] ?? null;
                $birth = $days[$userid] !== '' ? $days[$userid] : null;
                if (!$birth && $row && $field) {
                    $birth = self::birth_date((string)$row->data, (string)$field->datatype);
                }
                $phone = $row && trim((string)$row->phone2) !== '' ? starter::number((string)$row->phone2) : null;
                if ($birth && $phone && self::age($birth, $now ?? time()) >= 18) {
                    self::$students[$userid] = ['name' => trim($row->firstname . ' ' . $row->lastname), 'phone' => $phone,
                        'userid' => $userid];
                }
            }
        }
        $result = [];
        foreach ($userids as $userid) {
            if (!empty(self::$students[$userid])) {
                $result[$userid] = self::$students[$userid];
            }
        }
        return $result;
    }

    /**
     * A date of birth as the profile keeps it, as a day.
     *
     * @param string $data What the profile has.
     * @param string $datatype The type of the field: datetime keeps a time, the rest the text written.
     * @return string|null The day as Y-m-d, or null if it is not a date.
     */
    public static function birth_date(string $data, string $datatype): ?string {
        $data = trim($data);
        if ($data === '' || $data === '0') {
            return null;
        }
        if ($datatype === 'datetime') {
            // Midnight of that day where it was written: half a day later it is that day in any time zone.
            return ctype_digit($data) ? gmdate('Y-m-d', (int)$data + 12 * HOURSECS) : null;
        }
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $data, new \DateTimeZone('UTC'));
            if ($date && $date->format(substr($format, 1)) === $data) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    /**
     * How many years someone born that day has at a time, in the time zone of the site.
     *
     * @param string $birth The day, as Y-m-d.
     * @param int $now The time.
     * @return int
     */
    public static function age(string $birth, int $now): int {
        [$year, $month, $day] = array_map('intval', explode('-', $birth));
        $today = (new \DateTime('@' . $now))->setTimezone(\core_date::get_server_timezone_object());
        $age = (int)$today->format('Y') - $year;
        if ([(int)$today->format('n'), (int)$today->format('j')] < [$month, $day]) {
            $age--;
        }
        return max(0, $age);
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
        self::$students = [];
        self::$fields = [];
    }
}
