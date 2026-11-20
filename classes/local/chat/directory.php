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
 * Who and what the account of Chatwoot has: its people (agents), its departments (teams) with whoever is in each, the
 * inbox with its hours and its approved messages, and the saved answers. Kept for a few minutes.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class directory {
    /** @var array|null The whole of it, for this request. */
    protected static $data = null;

    /** @var chatwoot|null The client, for the tests. */
    protected static $client = null;

    /**
     * Uses another client, for the tests.
     *
     * @param chatwoot|null $client The client, or null to go back to the one of the site.
     */
    public static function use_client(?chatwoot $client): void {
        self::$client = $client;
        self::$data = null;
    }

    /**
     * The client of the site, or the one of the tests.
     *
     * @return chatwoot
     */
    public static function client(): chatwoot {
        return self::$client ?? chatwoot::from_config();
    }

    /**
     * Does something in Chatwoot as a person of the team, with their own token if the site has a platform app; once
     * again with a new token if the one kept is no longer good.
     *
     * @param viewer $viewer The person.
     * @param callable $action Gets the client and gives what it did.
     * @return mixed What the action gives.
     */
    public static function as_person(viewer $viewer, callable $action) {
        if (self::$client) {
            return $action(self::$client);
        }
        try {
            return $action(chatwoot::for_agent($viewer->agent));
        } catch (\local_oksigeniamarandu\local\api_exception $e) {
            if ($e->errorcode !== 'error:chatwoot' || (int)$e->a !== 401) {
                throw $e;
            }
            return $action(chatwoot::for_agent($viewer->agent, true));
        }
    }

    /**
     * The signature a person has in Chatwoot (their profile), on one line; empty if they have none or the site has no
     * platform app to read it with their own token. Kept a few minutes.
     *
     * @param array $agent The person.
     * @return string
     */
    public static function signature_of(array $agent): string {
        if (self::$client || empty($agent['id']) || empty(get_config('local_oksigeniamarandu', 'chatwootplatform'))) {
            return '';
        }
        $cache = \cache::make('local_oksigeniamarandu', 'chat');
        $key = 'signature_' . (int)$agent['id'];
        $kept = $cache->get($key);
        if ($kept !== false) {
            return $kept;
        }
        try {
            $profile = self::as_person_client($agent)->call('GET', '/profile');
        } catch (\moodle_exception $e) {
            return '';
        }
        // Only the profile of that person: with the token of the site, the profile would be another one.
        $signature = '';
        if ((int)($profile['id'] ?? 0) === (int)$agent['id']) {
            $signature = self::one_line((string)($profile['message_signature'] ?? ''));
        }
        $cache->set($key, $signature);
        return $signature;
    }

    /**
     * The client of a person, with their own token.
     *
     * @param array $agent The person.
     * @return chatwoot
     */
    protected static function as_person_client(array $agent): chatwoot {
        return chatwoot::for_agent($agent);
    }

    /**
     * A signature as the family reads it on top of a message: one line, without the marks of bold, not too long.
     *
     * @param string $text The signature.
     * @return string
     */
    public static function one_line(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace(['*', '_', '~', "\x60"], '', $text)));
        return \core_text::substr($text, 0, 80);
    }

    /**
     * Forgets what it had, so that the next question asks Chatwoot again.
     */
    public static function forget(): void {
        self::$data = null;
        \cache::make('local_oksigeniamarandu', 'chat')->delete('directory');
    }

    /**
     * Everything, from the cache or from Chatwoot.
     *
     * @return array agents, teams, members, inboxes and canned.
     */
    protected static function data(): array {
        if (self::$data !== null) {
            return self::$data;
        }
        $cache = \cache::make('local_oksigeniamarandu', 'chat');
        $data = self::$client ? false : $cache->get('directory');
        if ($data === false) {
            $data = self::load(self::client());
            if (!self::$client) {
                $cache->set('directory', $data);
            }
        }
        return self::$data = $data;
    }

    /**
     * Asks Chatwoot for everything.
     *
     * @param chatwoot $client The client.
     * @return array
     */
    protected static function load(chatwoot $client): array {
        $agents = [];
        foreach ($client->call('GET', '/agents') as $agent) {
            $agents[(int)$agent['id']] = [
                'id' => (int)$agent['id'],
                'name' => (string)($agent['available_name'] ?? $agent['name'] ?? ''),
                'email' => \core_text::strtolower(trim((string)($agent['email'] ?? ''))),
                'role' => (string)($agent['role'] ?? 'agent'),
            ];
        }
        $teams = [];
        $members = [];
        foreach ($client->call('GET', '/teams') as $team) {
            $id = (int)$team['id'];
            $teams[$id] = ['id' => $id, 'name' => self::label((string)$team['name'])];
            $members[$id] = [];
            foreach ($client->call('GET', '/teams/' . $id . '/team_members') as $member) {
                $members[$id][] = (int)$member['id'];
            }
        }
        $wanted = self::inbox_ids();
        $inboxes = [];
        $list = $client->call('GET', '/inboxes');
        foreach ($list['payload'] ?? $list as $inbox) {
            $id = (int)($inbox['id'] ?? 0);
            if (!$id || ($wanted && !in_array($id, $wanted))) {
                continue;
            }
            $inboxes[$id] = [
                'id' => $id,
                'name' => (string)($inbox['name'] ?? ''),
                'channel' => (string)($inbox['channel_type'] ?? ''),
                'hoursenabled' => !empty($inbox['working_hours_enabled']),
                'timezone' => (string)($inbox['timezone'] ?? ''),
                'hours' => is_array($inbox['working_hours'] ?? null) ? $inbox['working_hours'] : [],
                'templates' => is_array($inbox['message_templates'] ?? null) ? $inbox['message_templates'] : [],
            ];
        }
        $canned = [];
        foreach ($client->call('GET', '/canned_responses') as $answer) {
            $canned[] = ['short' => (string)($answer['short_code'] ?? ''), 'text' => (string)($answer['content'] ?? '')];
        }
        return ['agents' => $agents, 'teams' => $teams, 'members' => $members, 'inboxes' => $inboxes, 'canned' => $canned];
    }

    /**
     * A name of Chatwoot as people write it: Chatwoot keeps the names of teams in small letters.
     *
     * @param string $name The name.
     * @return string With its first letter capital.
     */
    public static function label(string $name): string {
        $name = trim($name);
        return \core_text::strtoupper(\core_text::substr($name, 0, 1)) . \core_text::substr($name, 1);
    }

    /**
     * The inboxes the site attends, or empty for all of them.
     *
     * @return int[]
     */
    public static function inbox_ids(): array {
        $ids = array_filter(array_map('intval', explode(',', (string)get_config('local_oksigeniamarandu', 'chatwootinboxes'))));
        return array_values($ids);
    }

    /**
     * The people of the account.
     *
     * @return array By id: id, name, email and role.
     */
    public static function agents(): array {
        return self::data()['agents'];
    }

    /**
     * The users of the account that are not people: the bot of the menu with its own user, the user of this site.
     * What they write is not an answer of a person, and what they have nobody has.
     *
     * @return int[]
     */
    public static function automatic_ids(): array {
        $ids = array_map('intval', explode(',', (string)get_config('local_oksigeniamarandu', 'chatautomatic')));
        $ids[] = (int)get_config('local_oksigeniamarandu', 'chatwootself');
        return array_values(array_filter(array_unique($ids)));
    }

    /**
     * The people of the team: the users of the account without the automatic ones.
     *
     * @return array By id.
     */
    public static function people(): array {
        return array_diff_key(self::agents(), array_flip(self::automatic_ids()));
    }

    /**
     * One person of the account.
     *
     * @param int $id Its id.
     * @return array|null
     */
    public static function agent(int $id): ?array {
        return self::data()['agents'][$id] ?? null;
    }

    /**
     * The person of the account with this email.
     *
     * @param string $email The email.
     * @return array|null
     */
    public static function agent_by_email(string $email): ?array {
        $email = \core_text::strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        foreach (self::agents() as $agent) {
            if ($agent['email'] === $email) {
                return $agent;
            }
        }
        return null;
    }

    /**
     * The departments.
     *
     * @return array By id: id and name, with the internal ones marked.
     */
    public static function teams(): array {
        $internal = self::internal_ids();
        $teams = [];
        foreach (self::data()['teams'] as $id => $team) {
            $teams[$id] = $team + ['internal' => in_array($id, $internal)];
        }
        return $teams;
    }

    /**
     * The departments that do not appear in the menu of the families: one goes there only from inside.
     *
     * @return int[]
     */
    public static function internal_ids(): array {
        return array_values(array_filter(array_map(
            'intval',
            explode(',', (string)get_config('local_oksigeniamarandu', 'chatinternal'))
        )));
    }

    /**
     * The departments of one person.
     *
     * @param int $agentid Its id.
     * @return int[]
     */
    public static function teams_of(int $agentid): array {
        $ids = [];
        foreach (self::data()['members'] as $teamid => $members) {
            if (in_array($agentid, $members)) {
                $ids[] = (int)$teamid;
            }
        }
        return $ids;
    }

    /**
     * The people of a department.
     *
     * @param int $teamid The department.
     * @return int[]
     */
    public static function members_of(int $teamid): array {
        return array_map('intval', self::data()['members'][$teamid] ?? []);
    }

    /**
     * The inboxes, with their hours and their approved messages.
     *
     * @return array By id.
     */
    public static function inboxes(): array {
        return self::data()['inboxes'];
    }

    /**
     * The saved answers.
     *
     * @return array Of short and text.
     */
    public static function canned(): array {
        return self::data()['canned'];
    }
}
