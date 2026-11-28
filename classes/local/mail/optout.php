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

namespace local_oksigeniamarandu\local\mail;

/**
 * Leaving the mails that can be done without, with one touch: by tool and by kind of mail. What cannot be done without
 * (an enrolment, an appointment, what the person asked for) keeps coming; each tool says which of its mails are which.
 *
 * The way out of a mail is an address of baja.php with a signed token (the address, the tool and the kind): a POST
 * «List-Unsubscribe=One-Click» (RFC 8058) leaves at once; opening it shows a page with a button. The tool hears it in
 * <component>_marandu_mail_optout($email, $category, $out), and can name its kinds of mail in
 * <component>_marandu_mail_category_name($category): string.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optout {
    /** The table: the addresses that left the mails of a tool, or one kind of them. */
    public const TABLE = 'local_oksigeniamarandu_mailout';

    /** Every kind of mail of a tool that can be done without. */
    public const ALL = '*';

    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /**
     * The address to leave, for the mail of a tool to an address.
     *
     * @param string $email
     * @param string $component The tool.
     * @param string $category Its kind of mail; empty, or the mail cannot be done without, for all its kinds.
     * @param string $lang The language of the page; that of the site if empty.
     * @return \moodle_url
     */
    public static function url(string $email, string $component, string $category = '', string $lang = ''): \moodle_url {
        $params = ['t' => self::token($email, $component, $category)];
        $lang = clean_param($lang, PARAM_LANG);
        if ($lang !== '' && $lang !== (string)get_config('core', 'lang')) {
            $params['lang'] = $lang;
        }
        return new \moodle_url('/local/oksigeniamarandu/baja.php', $params);
    }

    /**
     * The signed token of an address, a tool and a kind of mail.
     *
     * @param string $email
     * @param string $component
     * @param string $category
     * @return string
     */
    public static function token(string $email, string $component, string $category = ''): string {
        $data = json_encode([self::email($email), self::component($component), self::category($category)]);
        $body = rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        return $body . '.' . substr(hash_hmac('sha256', $body, self::key()), 0, 32);
    }

    /**
     * What a token says, if it is ours.
     *
     * @param string $token
     * @return array|null The address, the tool and the kind; null if it was not signed here.
     */
    public static function read(string $token): ?array {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || !hash_equals(substr(hash_hmac('sha256', $parts[0], self::key()), 0, 32), $parts[1])) {
            return null;
        }
        $data = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($data) || count($data) !== 3 || !validate_email((string)$data[0])) {
            return null;
        }
        return ['email' => (string)$data[0], 'component' => (string)$data[1], 'category' => (string)$data[2]];
    }

    /**
     * Leaves, or comes back to, the mails of a tool, or one kind of them, and tells the tool.
     *
     * @param string $email
     * @param string $component
     * @param string $category Empty for all its kinds that can be done without.
     * @param bool $out Leave (true) or come back (false).
     * @param string $source oneclick (the button of the mail program), page (the page of the way out) or tool.
     * @return bool Whether anything changed.
     */
    public static function set(
        string $email,
        string $component,
        string $category = '',
        bool $out = true,
        string $source = 'tool'
    ): bool {
        global $DB;
        $email = self::email($email);
        $component = self::component($component);
        $category = self::category($category);
        if (!validate_email($email)) {
            return false;
        }
        $where = ['email' => $email, 'component' => $component, 'category' => $category];
        $exists = $DB->record_exists(self::TABLE, $where);
        if ($out === $exists) {
            return false;
        }
        if ($out) {
            $DB->insert_record(self::TABLE, (object)($where + ['source' => \core_text::substr($source, 0, 10),
                'timecreated' => time()]));
        } else {
            $DB->delete_records(self::TABLE, $where);
        }
        if ($component !== 'core' && component_callback_exists($component, 'marandu_mail_optout')) {
            try {
                component_callback($component, 'marandu_mail_optout', [$email, $category, $out]);
            } catch (\Throwable $e) {
                debugging('Marandu: ' . $component . ' failed to hear a way out: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return true;
    }

    /**
     * Whether an address left the mails of a tool of that kind (or all those that can be done without).
     *
     * @param string $email
     * @param string $component
     * @param string $category
     * @return bool
     */
    public static function state(string $email, string $component, string $category = ''): bool {
        global $DB;
        $category = self::category($category);
        [$in, $params] = $DB->get_in_or_equal(array_unique([$category, self::ALL]), SQL_PARAMS_NAMED);
        return $DB->record_exists_select(
            self::TABLE,
            "email = :email AND component = :component AND category $in",
            ['email' => self::email($email), 'component' => self::component($component)] + $params
        );
    }

    /**
     * The kind of mail in words, with its article, for the page of the way out: as the tool names it, or empty.
     *
     * @param string $component
     * @param string $category
     * @return string
     */
    public static function category_name(string $component, string $category): string {
        // For «*», the tool may name every kind it has that can be done without («los avisos del comedor»).
        if ($component === 'core' || !component_callback_exists($component, 'marandu_mail_category_name')) {
            return '';
        }
        try {
            return trim((string)component_callback($component, 'marandu_mail_category_name', [$category], ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The address as it is kept.
     *
     * @param string $email
     * @return string
     */
    private static function email(string $email): string {
        return \core_text::strtolower(trim($email));
    }

    /**
     * The tool as it is kept: core for the platform.
     *
     * @param string $component
     * @return string
     */
    private static function component(string $component): string {
        $component = clean_param($component, PARAM_COMPONENT);
        return $component === '' ? 'core' : \core_text::substr($component, 0, 100);
    }

    /**
     * The kind as it is kept: * for all.
     *
     * @param string $category
     * @return string
     */
    private static function category(string $category): string {
        $category = trim(clean_param($category, PARAM_ALPHANUMEXT));
        return $category === '' ? self::ALL : \core_text::substr($category, 0, 40);
    }

    /**
     * The key that signs the tokens, made once for the site.
     *
     * @return string
     */
    private static function key(): string {
        $key = (string)get_config(self::C, 'mailoutkey');
        if (strlen($key) < 32) {
            $key = random_string(48);
            set_config('mailoutkey', $key, self::C);
        }
        return $key;
    }
}
