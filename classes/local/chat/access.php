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
 * Who opens the conversations: only the people of the account, by a role of the site that gives them the capability.
 * Nobody else sees anything, not even knowing the address. Once the organisation chooses the role, whoever joins the
 * account with the email of a user of the site gets it by themselves, and whoever leaves it loses the one given here.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /** The capability. */
    public const CAPABILITY = 'local/oksigeniamarandu:chat';

    /** The short name of the role made here when the organisation names none. */
    public const SHORTNAME = 'marandu_chat';

    /** The plugin, as the owner of the assignments it gives: they are taken away when the person leaves the account. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /**
     * The role of the people of the chat, if the organisation chose one and it still exists.
     *
     * @return \stdClass|null
     */
    public static function role(): ?\stdClass {
        global $DB;
        $id = (int)get_config(self::COMPONENT, 'chatrole');
        return $id ? ($DB->get_record('role', ['id' => $id]) ?: null) : null;
    }

    /**
     * Chooses the role of the people of the chat: one that exists, by its short name, or the one of the plugin, made
     * if it is not there. It is allowed the capability at the level of the site.
     *
     * @param string $shortname Its short name; empty for the one of the plugin.
     * @return \stdClass The role.
     */
    public static function choose(string $shortname = ''): \stdClass {
        global $DB;
        $shortname = $shortname === '' ? self::SHORTNAME : $shortname;
        $role = $DB->get_record('role', ['shortname' => $shortname]);
        if (!$role) {
            if ($shortname !== self::SHORTNAME) {
                throw new \moodle_exception('chat:access:norole', self::COMPONENT, '', $shortname);
            }
            $id = create_role(
                get_string('chat:access:rolename', self::COMPONENT),
                self::SHORTNAME,
                get_string('chat:access:roledesc', self::COMPONENT)
            );
            set_role_contextlevels($id, [CONTEXT_SYSTEM]);
            $role = $DB->get_record('role', ['id' => $id], '*', MUST_EXIST);
        }
        $system = \context_system::instance();
        assign_capability(self::CAPABILITY, CAP_ALLOW, $role->id, $system->id, true);
        $levels = get_role_contextlevels($role->id);
        if (!in_array(CONTEXT_SYSTEM, $levels)) {
            set_role_contextlevels($role->id, array_merge($levels, [CONTEXT_SYSTEM]));
        }
        set_config('chatrole', $role->id, self::COMPONENT);
        $system->mark_dirty();
        return $role;
    }

    /**
     * The roles that give the capability to every account: the one of every authenticated user, and those made from it.
     *
     * @return \stdClass[] By id.
     */
    public static function open_roles(): array {
        global $CFG, $DB;
        $ids = array_keys(get_archetype_roles('user'));
        if (!empty($CFG->defaultuserroleid)) {
            $ids[] = (int)$CFG->defaultuserroleid;
        }
        $open = [];
        $system = \context_system::instance();
        foreach (array_unique($ids) as $id) {
            $allowed = $DB->get_field('role_capabilities', 'permission', ['roleid' => $id, 'contextid' => $system->id,
                'capability' => self::CAPABILITY]);
            if ((int)$allowed === CAP_ALLOW) {
                $open[$id] = $DB->get_record('role', ['id' => $id]);
            }
        }
        return array_filter($open);
    }

    /**
     * Takes the capability away from the roles that give it to every account.
     *
     * @return int How many roles.
     */
    public static function close(): int {
        $system = \context_system::instance();
        $roles = self::open_roles();
        foreach ($roles as $role) {
            unassign_capability(self::CAPABILITY, $role->id, $system->id);
        }
        if ($roles) {
            $system->mark_dirty();
        }
        return count($roles);
    }

    /**
     * The people of the account with a user of the site that cannot open the chat.
     *
     * @return array Each with agent (the person of the account) and user.
     */
    public static function missing(): array {
        $system = \context_system::instance();
        $missing = [];
        foreach (directory::people() as $agent) {
            $user = notify::user_of($agent);
            if ($user && !has_capability(self::CAPABILITY, $system, $user)) {
                $missing[] = ['agent' => $agent, 'user' => $user];
            }
        }
        return $missing;
    }

    /**
     * The people of the account without a user of the site: no role can let them in until their email matches one.
     *
     * @return array The people of the account.
     */
    public static function unmatched(): array {
        return array_values(array_filter(directory::people(), function ($agent) {
            return notify::user_of($agent) === null;
        }));
    }

    /**
     * Gives the role to the people of the account who cannot open the chat, and takes the one given here away from whoever
     * is no longer in it. Without a role chosen, nothing: the organisation decides who gets in.
     *
     * @return array given and taken: how many.
     */
    public static function sync(): array {
        global $DB;
        $role = self::role();
        if (!$role) {
            return ['given' => 0, 'taken' => 0];
        }
        $system = \context_system::instance();
        $given = 0;
        foreach (self::missing() as $one) {
            role_assign($role->id, $one['user']->id, $system->id, self::COMPONENT);
            $given++;
        }
        $people = [];
        foreach (directory::people() as $agent) {
            $user = notify::user_of($agent);
            if ($user) {
                $people[(int)$user->id] = true;
            }
        }
        $taken = 0;
        // An account that answers with nobody is an account that changed, not a team that left: nothing is taken.
        $mine = !$people ? [] : $DB->get_records('role_assignments', ['roleid' => $role->id, 'contextid' => $system->id,
            'component' => self::COMPONENT]);
        foreach ($mine as $assignment) {
            if (!isset($people[(int)$assignment->userid])) {
                role_unassign($role->id, $assignment->userid, $system->id, self::COMPONENT);
                $taken++;
            }
        }
        return ['given' => $given, 'taken' => $taken];
    }
}
