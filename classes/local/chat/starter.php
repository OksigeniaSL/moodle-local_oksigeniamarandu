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

use local_oksigeniamarandu\local\phone;

/**
 * A conversation a person of the team starts: whom to write to (a number, a family of the common record, someone of
 * the admissions or a contact who wrote before) and the first message, an approved one, since WhatsApp only lets an
 * organisation write first that way. The conversation is born in the inbox of that message, in the department chosen,
 * kept by whoever starts it; if the contact already has one open, that one is used and no other is made.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class starter {
    /** How many people the search gives at most. */
    public const LIMIT = 12;

    /**
     * The country code of the organisation, as the common record of families keeps it (its phoneprefix), only digits:
     * the one a number typed without its own gets. Empty if there is none: then a number needs its own.
     *
     * @return string
     */
    public static function prefix(): string {
        $importer = '\\local_oksigeniafamily\\local\\import\\importer';
        if (!method_exists($importer, 'phone_prefix')) {
            return '';
        }
        return preg_replace('/[^0-9]/', '', (string)$importer::phone_prefix());
    }

    /**
     * A number as a person types it, with its country code: «+34 600 00 01 01», «0034…» or, with the prefix of the
     * organisation, «600 00 01 01» (a first 0 of the national way of writing it goes away, as in «07700…»).
     *
     * @param string $typed The number.
     * @return string|null Only digits, or null if it cannot be one.
     */
    public static function number(string $typed): ?string {
        $typed = trim($typed);
        if (preg_match('/^(\+|00)/', $typed)) {
            return phone::normalise($typed);
        }
        $prefix = self::prefix();
        $digits = preg_replace('/[^0-9]/', '', $typed);
        if ($prefix === '' || $digits === '') {
            return null;
        }
        return phone::normalise('+' . $prefix . preg_replace('/^0/', '', $digits));
    }

    /**
     * Whom a person can write to, from what they typed: a number, or a name of the record of families, of the
     * admissions or of the contacts of the account.
     *
     * @param viewer $viewer Who looks.
     * @param string $query What they typed.
     * @return array candidates (name, phone, line, rings, source, verified, conversation, open and elsewhere) and
     *               needprefix (a number without its country code, when the organisation has none).
     */
    public static function find(viewer $viewer, string $query): array {
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        $digits = preg_replace('/[^0-9]/', '', $query);
        $candidates = [];
        if (strlen($digits) >= 6 && !preg_match('/\p{L}/u', $query)) {
            $phone = self::number($query);
            if ($phone === null) {
                return ['candidates' => [], 'needprefix' => !preg_match('/^(\+|00)/', $query)];
            }
            $candidates[$phone] = self::candidate($phone, '', 'number');
        } else if (\core_text::strlen($query) >= 2) {
            foreach (family::search($query) as $family) {
                foreach ($family['adults'] as $adult) {
                    // A student of age, when the site writes to them, beside the adults of their family.
                    $candidates[$adult['phone']] = $candidates[$adult['phone']] ?? [
                        'name' => $adult['name'], 'phone' => $adult['phone'], 'line' => $family['line'],
                        'rings' => self::rings($family['children']), 'source' => 'family', 'verified' => $adult['verified'],
                        'student' => !empty($adult['student']),
                    ];
                }
            }
            foreach (self::admissions($viewer, $query) as $one) {
                $candidates[$one['phone']] = $candidates[$one['phone']] ?? $one;
            }
            foreach (self::contacts($query) as $one) {
                $candidates[$one['phone']] = $candidates[$one['phone']] ?? self::candidate($one['phone'], $one['name'], 'contact');
            }
        }
        $list = [];
        foreach (array_slice($candidates, 0, self::LIMIT, true) as $phone => $candidate) {
            $list[] = $candidate + self::conversation_of($viewer, (string)$phone);
        }
        return ['candidates' => $list, 'needprefix' => false];
    }

    /**
     * Someone by their number: who the record of families says it is, if it says.
     *
     * @param string $phone Only digits.
     * @param string $name The name known, if any.
     * @param string $source number or contact.
     * @return array
     */
    protected static function candidate(string $phone, string $name, string $source): array {
        global $DB;
        $result = ['name' => $name, 'phone' => $phone, 'line' => '', 'rings' => [], 'source' => $source, 'verified' => false];
        $family = family::of_phone($phone);
        if ($family) {
            $result['source'] = $source === 'number' ? 'family' : $source;
            foreach ($family['adults'] as $adult) {
                if ($adult['phone'] === $phone && $result['name'] === '') {
                    $result['name'] = $adult['name'];
                }
            }
            $result['line'] = $family['line'];
            $result['rings'] = self::rings($family['children']);
            $result['verified'] = $family['verified'];
        }
        $known = $DB->get_records(mirror::TABLE, ['phone' => $phone], 'lastactivity DESC', 'id, name, lastincoming', 0, 1);
        $known = $known ? reset($known) : null;
        if ($known && $result['name'] === '') {
            $result['name'] = (string)$known->name;
        }
        if ($known && $known->lastincoming && $result['source'] === 'number') {
            $result['source'] = 'contact';
        }
        return $result;
    }

    /**
     * The colours of the stages of the children, as the rings of the picture of a contact.
     *
     * @param array $children As the record of families gives them.
     * @return array
     */
    protected static function rings(array $children): array {
        $rings = [];
        foreach ($children as $child) {
            if (!empty($child['stage']) && $child['stage']['color'] !== '') {
                $rings[] = ['color' => $child['stage']['color'], 'dark' => $child['stage']['dark'], 'dashed' => false,
                    'name' => $child['name']];
            }
        }
        return $rings;
    }

    /**
     * The families of the admissions with that name, if the site has them (Oksigenia Eguahe, or any plugin with the
     * same call): only those its person can see.
     *
     * @param viewer $viewer Who looks.
     * @param string $query What they typed.
     * @return array As find() gives them.
     */
    protected static function admissions(viewer $viewer, string $query): array {
        $api = '\\local_oksigeniaeguahe\\api';
        if (!method_exists($api, 'chat_candidates')) {
            return [];
        }
        $list = [];
        try {
            foreach ($api::chat_candidates($viewer->userid, $query, 6) as $one) {
                $one = (array)$one;
                $phone = phone::normalise((string)($one['phone'] ?? ''));
                if ($phone) {
                    $list[] = ['name' => trim((string)($one['name'] ?? '')), 'phone' => $phone,
                        'line' => trim((string)($one['line'] ?? '')), 'rings' => [], 'source' => 'admissions', 'verified' => false];
                }
            }
        } catch (\Throwable $e) {
            debugging('The admissions did not answer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $list;
    }

    /**
     * The contacts of the account with that name or number, with a number.
     *
     * @param string $query What was typed.
     * @return array Each with name and phone (only digits).
     */
    protected static function contacts(string $query): array {
        try {
            $found = directory::client()->call('GET', '/contacts/search', ['q' => $query]);
        } catch (\Throwable $e) {
            return [];
        }
        $list = [];
        foreach ($found['payload'] ?? [] as $contact) {
            $phone = preg_replace('/[^0-9]/', '', (string)($contact['phone_number'] ?? ''));
            if ($phone !== '') {
                $list[] = ['name' => trim((string)($contact['name'] ?? '')), 'phone' => $phone];
            }
        }
        return $list;
    }

    /**
     * The conversation a number already has, as the person sees it: the last one they see (to open it instead of
     * starting another), and, if someone else has one open that they do not see, with whom.
     *
     * @param viewer $viewer Who looks.
     * @param string $phone Only digits.
     * @return array conversation (or 0), open and elsewhere (a department, the menu, or empty).
     */
    protected static function conversation_of(viewer $viewer, string $phone): array {
        global $DB;
        $result = ['conversation' => 0, 'open' => false, 'elsewhere' => ''];
        foreach ($DB->get_records(mirror::TABLE, ['phone' => $phone], 'lastactivity DESC') as $row) {
            $open = $row->status !== 'resolved';
            if ($viewer->sees($row)) {
                if (!$result['conversation'] || ($open && !$result['open'])) {
                    $result['conversation'] = (int)$row->cwid;
                    $result['open'] = $open;
                }
            } else if ($open && $result['elsewhere'] === '') {
                $result['elsewhere'] = self::where_it_is($row);
            }
        }
        if ($result['open']) {
            $result['elsewhere'] = '';
        }
        return $result;
    }

    /**
     * Where a conversation someone does not see is: with the menu, or in a department.
     *
     * @param \stdClass $row Its row.
     * @return string
     */
    protected static function where_it_is(\stdClass $row): string {
        if ($row->status === 'pending') {
            return get_string('chat:start:withmenu', 'local_oksigeniamarandu');
        }
        foreach (directory::teams() as $team) {
            if ((int)$team['id'] === (int)$row->teamid) {
                return $team['name'];
            }
        }
        return get_string('chat:start:withteam', 'local_oksigeniamarandu');
    }

    /**
     * Whether an inbox keeps one conversation per contact, as Chatwoot says of it.
     *
     * @param int $inboxid The inbox.
     * @return bool True too if Chatwoot cannot say, to be on the safe side.
     */
    private static function locked(int $inboxid): bool {
        try {
            $inbox = directory::client()->call('GET', '/inboxes/' . $inboxid);
        } catch (\moodle_exception $e) {
            return true;
        }
        return !array_key_exists('lock_to_single_conversation', $inbox) || !empty($inbox['lock_to_single_conversation']);
    }

    /**
     * Starts the conversation with an approved message, in the inbox of that message, kept by whoever starts it.
     *
     * @param viewer $viewer Who starts it.
     * @param string $phone The number, only digits with the country code.
     * @param string $name The name to give the contact if the account does not have it.
     * @param int $teamid The department, or 0.
     * @param array $template The approved message, as templates::find() gives it.
     * @param array $values Its gaps.
     * @return array status (started, exists or busy) and the row of the conversation (started and exists) or where it
     *               is (busy).
     */
    public static function start(
        viewer $viewer,
        string $phone,
        string $name,
        int $teamid,
        array $template,
        array $values
    ): array {
        $inboxid = (int)($template['inbox'] ?? 0);
        $contact = self::contact($phone);
        if ($contact) {
            $existing = directory::client()->call('GET', '/contacts/' . (int)$contact['id'] . '/conversations');
            $mine = array_values(array_filter($existing['payload'] ?? [], function ($one) use ($inboxid) {
                return (int)($one['inbox_id'] ?? 0) === $inboxid;
            }));
            usort($mine, function ($a, $b) {
                return (int)($b['last_activity_at'] ?? 0) <=> (int)($a['last_activity_at'] ?? 0);
            });
            $locked = null;
            foreach ($mine as $one) {
                if (($one['status'] ?? '') === 'resolved') {
                    // An inbox that keeps one conversation per contact gives back the last one, resolved or not: it
                    // would come here with its history, so whoever does not see it does not start it.
                    $locked = $locked ?? self::locked($inboxid);
                    if (!$locked) {
                        continue;
                    }
                    $row = mirror::refresh((int)$one['id'], false);
                    if ($row && !$viewer->sees($row)) {
                        return ['status' => 'busy', 'where' => self::where_it_is($row)];
                    }
                    break;
                }
                $row = mirror::refresh((int)$one['id'], false);
                if ($row && $viewer->sees($row)) {
                    return ['status' => 'exists', 'row' => $row];
                }
                return ['status' => 'busy', 'where' => $row ? self::where_it_is($row) : ''];
            }
        }
        $made = directory::as_person($viewer, function (chatwoot $client) use (
            $viewer,
            $phone,
            $name,
            $teamid,
            $template,
            $values,
            $inboxid,
            $contact
) {
            if ($contact) {
                $contactid = (int)$contact['id'];
            } else {
                $new = $client->call('POST', '/contacts', ['name' => $name !== '' ? $name : '+' . $phone,
                    'phone_number' => '+' . $phone, 'inbox_id' => $inboxid]);
                $contactid = (int)($new['payload']['contact']['id'] ?? $new['id'] ?? 0);
            }
            $conversation = $client->call('POST', '/conversations', [
                'inbox_id' => $inboxid,
                'contact_id' => $contactid,
                'status' => 'open',
                'assignee_id' => $viewer->agent['id'],
                'team_id' => $teamid ?: null,
                'message' => templates::message($template, $values),
            ]);
            $id = (int)($conversation['id'] ?? 0);
            // With a bot in the inbox, Chatwoot makes every new conversation pending (the menu's), and an inbox that
            // keeps one conversation per contact gives back its last one as it was: it is opened and kept here.
            if (($conversation['status'] ?? 'open') !== 'open') {
                $client->call('POST', '/conversations/' . $id . '/toggle_status', ['status' => 'open']);
            }
            if ($teamid && (int)($conversation['meta']['team']['id'] ?? 0) !== $teamid) {
                $client->call('POST', '/conversations/' . $id . '/assignments', ['team_id' => $teamid]);
            }
            if ((int)($conversation['meta']['assignee']['id'] ?? 0) !== (int)$viewer->agent['id']) {
                $client->call('POST', '/conversations/' . $id . '/assignments', ['assignee_id' => $viewer->agent['id']]);
            }
            return $conversation;
        });
        $id = (int)($made['id'] ?? 0);
        $messageid = (int)($made['last_non_activity_message']['id'] ?? $made['messages'][0]['id'] ?? 0);
        if ($messageid) {
            message::remember($messageid, $id, $viewer->userid);
        }
        $row = mirror::refresh($id);
        if (!$row) {
            throw new \moodle_exception('chat:start:failed', 'local_oksigeniamarandu');
        }
        return ['status' => 'started', 'row' => $row];
    }

    /**
     * The contact of the account with this number, if there is one.
     *
     * @param string $phone Only digits.
     * @return array|null
     */
    protected static function contact(string $phone): ?array {
        $found = directory::client()->call('GET', '/contacts/search', ['q' => $phone]);
        foreach ($found['payload'] ?? [] as $contact) {
            if (preg_replace('/[^0-9]/', '', (string)($contact['phone_number'] ?? '')) === $phone) {
                return $contact;
            }
        }
        return null;
    }
}
