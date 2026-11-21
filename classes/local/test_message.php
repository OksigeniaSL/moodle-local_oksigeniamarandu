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
 * The test message of the set-up wizard: sent, delivered and answered, as Meta tells the webhook.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_message {
    /** Setting that keeps the last test message. */
    private const CONFIG = 'teststate';

    /**
     * Sends the test message: the sample template of Meta, which only the test accounts have; if the account has not got
     * it, the general notice of the site, once Meta approved it, with the name of the site and a line that says it is a
     * test.
     *
     * @param cloud_api $api The number of the site.
     * @param string $to Digits with the country code.
     * @return string The id Meta gives to the message.
     * @throws api_exception If Meta takes neither.
     */
    public static function send(cloud_api $api, string $to): string {
        try {
            return $api->send_template($to, 'hello_world', 'en_US');
        } catch (api_exception $e) {
            $notice = notices::get('local_oksigeniamarandu', 'notification');
            $template = $notice && $e->metacode === 132001 ? templates::approved($notice) : null;
            if (!$template) {
                throw $e;
            }
            $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
            return $api->send_template($to, $template->name, notices::metalang($template->lang), [
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => \core_text::substr($site, 0, 200)],
                    ['type' => 'text', 'text' => get_string('wizard:test:subject', 'local_oksigeniamarandu')],
                ]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'prueba']]],
            ]);
        }
    }

    /**
     * Whether a number is a test number of Meta: those are of the United States, of the 555 numbers.
     *
     * @param string $number The number, as written.
     * @return bool
     */
    public static function is_test_number(string $number): bool {
        return strpos(preg_replace('/\D/', '', $number), '1555') === 0;
    }

    /**
     * Starts following a test message that has just been sent.
     *
     * @param string $phone Digits with the country code.
     * @param string $wamid The id Meta gave to the message.
     * @param string $typed The number as the person wrote it, to show it the same way; empty to show the digits.
     */
    public static function start(string $phone, string $wamid, string $typed = ''): void {
        self::save([
            'phone' => $phone,
            'typed' => trim($typed) === '' ? '+' . $phone : trim($typed),
            'wamid' => $wamid,
            'sent' => time(),
            'delivered' => 0,
            'failed' => '',
            'replied' => 0,
        ]);
    }

    /**
     * The last test message, or null if there is none.
     *
     * @return array|null
     */
    public static function get(): ?array {
        $state = json_decode((string)get_config('local_oksigeniamarandu', self::CONFIG), true);
        return is_array($state) && !empty($state['wamid']) ? $state : null;
    }

    /**
     * A status Meta sends: delivered or read mean it reached the phone; failed, that it never will.
     *
     * @param string $wamid The id of the message.
     * @param string $status sent, delivered, read or failed.
     * @param string $error What Meta says when it fails.
     */
    public static function status(string $wamid, string $status, string $error = ''): void {
        $state = self::get();
        if (!$state || $state['wamid'] !== $wamid) {
            return;
        }
        if (in_array($status, ['delivered', 'read'], true) && empty($state['delivered'])) {
            $state['delivered'] = time();
            self::save($state);
        } else if ($status === 'failed') {
            $state['failed'] = \core_text::substr($error === '' ? 'failed' : $error, 0, 200);
            self::save($state);
        }
    }

    /**
     * A message from a number: if it is the phone of the test, written after it, the test is answered.
     *
     * @param string $phone Digits with the country code.
     */
    public static function inbound(string $phone): void {
        $state = self::get();
        if ($state && $state['phone'] === $phone && empty($state['replied'])) {
            $state['replied'] = time();
            self::save($state);
        }
    }

    /**
     * Keeps the state.
     *
     * @param array $state The state.
     */
    private static function save(array $state): void {
        set_config(self::CONFIG, json_encode($state), 'local_oksigeniamarandu');
    }
}
