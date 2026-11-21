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
 * Who a number is, as the team marks it: several marks at once (a teacher can also be the mother of a pupil), the one
 * whose face it has in the lists, and a short line for each mark that needs one. They live in the contact of Chatwoot,
 * as its custom attributes, so that the whole team sees the same; the index keeps what the lists show.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marks {
    /** The marks, in the order they are offered. */
    public const MARKS = ['family', 'staff', 'supplier', 'alumni', 'other'];

    /** The lines of the marks, by their key: what the person does, what they offer, their company, their year, what they are. */
    public const NOTES = [
        'staff' => 'marandu_note_staff',
        'offer' => 'marandu_note_offer',
        'company' => 'marandu_note_company',
        'year' => 'marandu_note_year',
        'other' => 'marandu_note_other',
    ];

    /** The longest line of a mark. */
    public const NOTE_MAX = 120;

    /**
     * The marks of a list, valid and once each, in its order; an old single mark is a list of one.
     *
     * @param string $list The marks, comma separated.
     * @return string[]
     */
    public static function parse(string $list): array {
        $marks = [];
        foreach (explode(',', strtolower($list)) as $mark) {
            $mark = trim($mark);
            if (in_array($mark, self::MARKS, true) && !in_array($mark, $marks, true)) {
                $marks[] = $mark;
            }
        }
        return $marks;
    }

    /**
     * What a contact of Chatwoot says of who it is.
     *
     * @param array $attributes Its custom attributes.
     * @return array marks, face and notes (by key).
     */
    public static function of_contact(array $attributes): array {
        $notes = [];
        foreach (self::NOTES as $key => $attribute) {
            $notes[$key] = \core_text::substr(trim((string)($attributes[$attribute] ?? '')), 0, self::NOTE_MAX);
        }
        $marks = self::parse((string)($attributes['marandu_kind'] ?? ''));
        $face = (string)($attributes['marandu_face'] ?? '');
        return ['marks' => $marks, 'face' => in_array($face, $marks, true) ? $face : '', 'notes' => $notes];
    }

    /**
     * The face of a contact in the lists: the one chosen, or the first mark; with no mark, a family if the records have
     * the number.
     *
     * @param string[] $marks The marks.
     * @param string $face The one chosen.
     * @param bool $family Whether the family records have the number.
     * @return string A mark, or unknown.
     */
    public static function face(array $marks, string $face, bool $family): string {
        if ($face !== '' && in_array($face, $marks, true)) {
            return $face;
        }
        return $marks[0] ?? ($family ? 'family' : 'unknown');
    }

    /**
     * The line the lists show after the marks, from their notes, in the order of the marks: «profesora de Inglés»,
     * «Panadería Hermanos Suárez», «promoción de 2016».
     *
     * @param string[] $marks The marks.
     * @param array $notes The notes, by key.
     * @return string
     */
    public static function line(array $marks, array $notes): string {
        $parts = [];
        foreach ($marks as $mark) {
            switch ($mark) {
                case 'staff':
                    $parts[] = $notes['staff'] ?? '';
                    break;
                case 'supplier':
                    $parts[] = ($notes['company'] ?? '') !== '' ? $notes['company'] : ($notes['offer'] ?? '');
                    break;
                case 'alumni':
                    $year = $notes['year'] ?? '';
                    $parts[] = $year !== '' ? get_string('chat:marks:year', 'local_oksigeniamarandu', $year) : '';
                    break;
                case 'other':
                    $parts[] = $notes['other'] ?? '';
                    break;
            }
        }
        return \core_text::substr(implode(' · ', array_filter($parts, 'strlen')), 0, 255);
    }

    /**
     * What the index keeps of who a contact is: its marks, its face and the line of its notes.
     *
     * @param array $attributes The custom attributes of the contact.
     * @return array kind (the marks, comma separated), kindface, kindnote (the line) and kindnotes (each line, as JSON).
     */
    public static function for_index(array $attributes): array {
        $said = self::of_contact($attributes);
        return ['kind' => \core_text::substr(implode(',', $said['marks']), 0, 60), 'kindface' => $said['face'],
            'kindnote' => self::line($said['marks'], $said['notes']),
            'kindnotes' => array_filter($said['notes'], 'strlen') ? json_encode($said['notes']) : ''];
    }

    /**
     * Each line of the marks of a row of the index.
     *
     * @param \stdClass $row The row.
     * @return array By key, with every key.
     */
    public static function notes_of(\stdClass $row): array {
        $kept = json_decode((string)($row->kindnotes ?? ''), true);
        $notes = [];
        foreach (array_keys(self::NOTES) as $key) {
            $notes[$key] = is_array($kept) ? (string)($kept[$key] ?? '') : '';
        }
        return $notes;
    }

    /**
     * The attributes that change who a contact is, from what the page sends: the marks, the face or one line.
     *
     * @param array $change marks (a list), face (a mark) or note and text.
     * @param string $by Who changed it.
     * @return array The custom attributes to write.
     */
    public static function attributes(array $change, string $by): array {
        $attributes = ['marandu_kind_by' => $by, 'marandu_kind_at' => time()];
        if (array_key_exists('marks', $change)) {
            $attributes['marandu_kind'] = implode(',', self::parse(implode(',', (array)$change['marks'])));
        }
        if (array_key_exists('face', $change)) {
            $face = (string)$change['face'];
            if (!in_array($face, self::MARKS, true)) {
                throw new \invalid_parameter_exception('Unknown face');
            }
            $attributes['marandu_face'] = $face;
        }
        if (array_key_exists('note', $change)) {
            if (!isset(self::NOTES[$change['note']])) {
                throw new \invalid_parameter_exception('Unknown line');
            }
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($change['text'] ?? ''))));
            $attributes[self::NOTES[$change['note']]] = \core_text::substr($text, 0, self::NOTE_MAX);
        }
        if (count($attributes) === 2) {
            throw new \invalid_parameter_exception('Nothing to change');
        }
        return $attributes;
    }
}
