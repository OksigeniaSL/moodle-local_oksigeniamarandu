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
 * How the mails of the plugins go out: the domains of home, which go by the mail of the site, and the way of the rest,
 * the mail of the site too or Mailjet from the authenticated domain of the organization, with its keys, its sender, the
 * address to answer to and the quota of the month.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /** The share of the quota from which what is routine goes by the mail of the site, until the site says another. */
    public const ROUTINE = 80;

    /** The domains of Microsoft, unless the site says others: a «.*» is any ending (hotmail.es, hotmail.co.uk). */
    public const MICROSOFT = 'hotmail.*, outlook.*, live.*, msn.com, windowslive.com';

    /** How many mails a day to Microsoft still go by Mailjet when they go by the mail of the site: its warming up. */
    public const WARM = 10;

    /** The most mails a day to Microsoft by the mail of the site; past it, by Mailjet. */
    public const SITECAP = 1500;

    /**
     * The domains of home: what is written on the page, or the domain of the no-reply address of the site.
     *
     * @return string[]
     */
    public static function home_domains(): array {
        global $CFG;
        $list = self::domains((string)get_config(self::C, 'mailhome'));
        if (!$list && !empty($CFG->noreplyaddress) && strpos((string)$CFG->noreplyaddress, '@') !== false) {
            $list = self::domains(substr((string)$CFG->noreplyaddress, strrpos((string)$CFG->noreplyaddress, '@') + 1));
        }
        return $list;
    }

    /**
     * A list of domains, written with commas, spaces or lines, clean and without repeats.
     *
     * @param string $text
     * @return string[]
     */
    public static function domains(string $text): array {
        $out = [];
        foreach (preg_split('/[\s,;]+/', \core_text::strtolower($text)) as $one) {
            $one = trim(ltrim($one, '@'), '. ');
            if ($one !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $one)) {
                $out[$one] = $one;
            }
        }
        return array_values($out);
    }

    /**
     * Whether an address is of home: its domain is one of home, or under one of them.
     *
     * @param string $email
     * @return bool
     */
    public static function is_home(string $email): bool {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = \core_text::strtolower(substr($email, $at + 1));
        foreach (self::home_domains() as $home) {
            if ($domain === $home || substr($domain, -strlen($home) - 1) === '.' . $home) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the mails to addresses out of home go by Mailjet: chosen, with its keys and its sender.
     *
     * @return bool
     */
    public static function mailjet_chosen(): bool {
        return get_config(self::C, 'mailway') === 'mailjet' && self::keys() !== null && self::from()[0] !== '';
    }

    /**
     * The keys of Mailjet, if the site has them.
     *
     * @return string[]|null [key, secret]
     */
    public static function keys(): ?array {
        $key = (string)get_config(self::C, 'mjkey');
        $secret = (string)get_config(self::C, 'mjsecret');
        if ($key === '' || $secret === '') {
            return null;
        }
        try {
            return [\core\encryption::decrypt($key), \core\encryption::decrypt($secret)];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Keeps the keys of Mailjet, encrypted; empty takes them away.
     *
     * @param string $key
     * @param string $secret
     */
    public static function set_keys(string $key, string $secret): void {
        $key = trim($key);
        $secret = trim($secret);
        set_config('mjkey', $key === '' ? '' : \core\encryption::encrypt($key), self::C);
        set_config('mjsecret', $secret === '' ? '' : \core\encryption::encrypt($secret), self::C);
        set_config('mjkeystime', $key === '' ? 0 : time(), self::C);
        set_config('mjchecked', '', self::C);
    }

    /**
     * The address the mails out of home go from by Mailjet, and its name: the one written, or the name of the site.
     *
     * @return string[] [address, name]
     */
    public static function from(): array {
        $email = trim((string)get_config(self::C, 'mjfrom'));
        $name = trim((string)get_config(self::C, 'mjfromname'));
        if ($name === '') {
            $name = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
        }
        return [validate_email($email) ? $email : '', str_replace(["\r", "\n", '"'], ' ', $name)];
    }

    /**
     * The address of the organization that the answers go to, if the site has one.
     *
     * @return string
     */
    public static function replyto(): string {
        $email = trim((string)get_config(self::C, 'mjreplyto'));
        return validate_email($email) ? $email : '';
    }

    /**
     * The mails a month the plan of Mailjet has; 0, without a quota.
     *
     * @return int
     */
    public static function quota(): int {
        return max(0, (int)get_config(self::C, 'mailquota'));
    }

    /**
     * The domains of Microsoft as the site writes them: whole domains, or the start of one with «.*».
     *
     * @return string[]
     */
    public static function microsoft_domains(): array {
        $value = get_config(self::C, 'mailmsdomains');
        $text = $value === false || trim((string)$value) === '' ? self::MICROSOFT : (string)$value;
        $out = [];
        foreach (preg_split('/[\s,;]+/', \core_text::strtolower($text)) as $one) {
            $one = trim(ltrim($one, '@'), ' ');
            if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(\.\*)?$/', $one)) {
                $out[$one] = $one;
            }
        }
        return array_values($out);
    }

    /**
     * Whether an address is of Microsoft (Hotmail, Outlook, Live…), whose filters judge the reputation of a new domain.
     *
     * @param string $email
     * @return bool
     */
    public static function is_microsoft(string $email): bool {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = \core_text::strtolower(substr($email, $at + 1));
        foreach (self::microsoft_domains() as $one) {
            if (
                substr($one, -2) === '.*' ? strpos($domain, substr($one, 0, -1)) === 0
                    : ($domain === $one || substr($domain, -strlen($one) - 1) === '.' . $one)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the mails to Microsoft go by the mail of the site (site) or by Mailjet like the rest (mailjet).
     *
     * @return string
     */
    public static function microsoft_way(): string {
        return get_config(self::C, 'mailmsway') === 'site' ? 'site' : 'mailjet';
    }

    /**
     * How many mails a day to Microsoft still go by Mailjet, to warm up the domain; 0 for none.
     *
     * @return int
     */
    public static function warm(): int {
        $value = get_config(self::C, 'mailmswarm');
        return $value === false || $value === '' ? self::WARM : max(0, (int)$value);
    }

    /**
     * The most mails a day to Microsoft by the mail of the site.
     *
     * @return int
     */
    public static function site_cap(): int {
        $value = get_config(self::C, 'mailsitecap');
        return $value === false || $value === '' ? self::SITECAP : max(0, (int)$value);
    }

    /**
     * The share of the quota from which what is routine goes by the mail of the site.
     *
     * @return int A percentage.
     */
    public static function routine_share(): int {
        $value = get_config(self::C, 'mailroutine');
        return $value === false || $value === '' ? self::ROUTINE : max(0, min(100, (int)$value));
    }

    /**
     * The key in the address Mailjet tells its events to; made the first time it is asked for.
     *
     * @return string
     */
    public static function hook_key(): string {
        $key = (string)get_config(self::C, 'mailhook');
        if ($key === '') {
            $key = random_string(32);
            set_config('mailhook', $key, self::C);
        }
        return $key;
    }

    /**
     * The address Mailjet tells its events to.
     *
     * @return string
     */
    public static function hook_url(): string {
        return (new \moodle_url('/local/oksigeniamarandu/mailjet.php', ['k' => self::hook_key()]))->out(false);
    }
}
