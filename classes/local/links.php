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
 * The links of the posts of the channel, measured: with the UTM marks the site sets and, if it has one, shortened by a
 * shortener with the API of YOURLS, which then counts the visits; without it, Marandu counts them (go.php).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class links {
    /** The marks until the site says others. */
    public const DEFAULTS = ['utmsource' => 'telegram', 'utmmedium' => 'channel'];

    /** @var callable|null What answers for the shortener in the tests. */
    private static $fortests = null;

    /**
     * A link with the UTM marks of the site, if it measures them: source, medium and the kind of post as campaign. Marks
     * the link already has are left as they are.
     *
     * @param string $url The link.
     * @param string $campaign The kind of post.
     * @param string $source The source instead of the one of the site, such as whatsapp for the channel of WhatsApp.
     * @return string
     */
    public static function utm(string $url, string $campaign, string $source = ''): string {
        $config = get_config('local_oksigeniamarandu');
        if (empty($config->utm) || !preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $marks = array_filter([
            'utm_source' => $source !== '' ? $source : (string)($config->utmsource ?? self::DEFAULTS['utmsource']),
            'utm_medium' => (string)($config->utmmedium ?? self::DEFAULTS['utmmedium']),
            'utm_campaign' => $campaign,
        ], 'strlen');
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $query = (string)parse_url($url, PHP_URL_QUERY);
        parse_str($query, $has);
        $add = array_diff_key($marks, $has);
        if (!$add) {
            return $url . $fragment;
        }
        // Joined with &, whatever separator Moodle sets for PHP (&amp; on some versions).
        return $url . ($query === '' && strpos($url, '?') === false ? '?' : '&') . http_build_query($add, '', '&') . $fragment;
    }

    /**
     * A link made for the channel of Telegram, for the one of WhatsApp: what its query says is «telegram», such as the door a
     * plugin counts (d=telegram), says «whatsapp». The rest stays letter by letter.
     *
     * @param string $url The link.
     * @return string
     */
    public static function for_whatsapp(string $url): string {
        [$address, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $address = preg_replace('~([?&][^=&]+=)telegram(?=&|$)~', '${1}whatsapp', $address) ?? $address;
        return $address . ($fragment === null ? '' : '#' . $fragment);
    }

    /**
     * Whether the site has a shortener.
     *
     * @return bool
     */
    public static function has_shortener(): bool {
        return self::$fortests !== null || (string)get_config('local_oksigeniamarandu', 'shortener') !== '';
    }

    /**
     * The short link of a link, from the shortener of the site, which counts its visits.
     *
     * @param string $url The link.
     * @return string|null Null without a shortener, or if it did not answer with one.
     */
    public static function shorten(string $url): ?string {
        if (!self::has_shortener()) {
            return null;
        }
        $config = get_config('local_oksigeniamarandu');
        try {
            $params = ['action' => 'shorturl', 'format' => 'json', 'url' => $url,
                'signature' => empty($config->shortenersignature) ? '' : \core\encryption::decrypt($config->shortenersignature)];
            // As a form, urlencoded and joined with &: some shorteners take nothing else (they answer 415), and Moodle may
            // set &amp; as the separator of PHP.
            $body = http_build_query($params, '', '&');
            if (self::$fortests !== null) {
                $answer = (string)(self::$fortests)($params, $body);
            } else {
                global $CFG;
                require_once($CFG->libdir . '/filelib.php');
                $curl = new \curl(['ignoresecurity' => true]);
                $curl->setHeader(['Content-Type: application/x-www-form-urlencoded']);
                $answer = (string)$curl->post((string)$config->shortener, $body, ['CURLOPT_TIMEOUT' => 15]);
                if ($curl->get_errno()) {
                    return null;
                }
            }
        } catch (\Throwable $e) {
            debugging('Marandu: the shortener did not answer: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        // A link it had already shortened comes back as a failure, but with its short link: that one is good.
        $short = (string)(json_decode($answer, true)['shorturl'] ?? '');
        return preg_match('~^https?://~i', $short) ? $short : null;
    }

    /**
     * What answers for the shortener while the tests run; null to forget it.
     *
     * @param callable|null $fetch Takes the parameters and the body as it would go (urlencoded), gives the body of the
     *                             answer.
     */
    public static function fake_for_tests(?callable $fetch): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Only for the tests');
        }
        self::$fortests = $fetch;
    }
}
