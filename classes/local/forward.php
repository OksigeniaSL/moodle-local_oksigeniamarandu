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

use local_oksigeniamarandu\task\forward_webhook;

/**
 * What Meta sends to the webhook, passed on as it came to another address too: the shared inbox where people answer the
 * chats, for one. Meta calls a single address per number, so the site takes it, does its part (opt-ins, opt-outs,
 * statuses) and hands the same body, with the same signature, to the other one.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forward {
    /** Seconds to connect to the other address and to get its answer: Meta is waiting for the webhook meanwhile. */
    private const TIMEOUT = [3, 5];

    /**
     * The address everything is passed on to, if the site has one.
     *
     * @return string
     */
    public static function url(): string {
        return trim((string)get_config('local_oksigeniamarandu', 'forwardurl'));
    }

    /**
     * Passes a body on to the address of the site; if it does not take it now, an ad hoc task tries again later.
     *
     * @param string $raw The body, exactly as Meta sent it.
     * @param string $signature Its header X-Hub-Signature-256, also as it came.
     * @param forward|null $sender Who sends it; tests give their own.
     * @return bool Whether the address took it now.
     */
    public static function deliver(string $raw, string $signature, ?forward $sender = null): bool {
        $url = self::url();
        if ($url === '') {
            return false;
        }
        if (($sender ?? new self())->send($url, $raw, $signature)) {
            return true;
        }
        $task = new forward_webhook();
        $task->set_custom_data((object)['body' => $raw, 'signature' => $signature]);
        \core\task\manager::queue_adhoc_task($task);
        return false;
    }

    /**
     * Posts the body to an address, as Meta would.
     *
     * @param string $url The address.
     * @param string $raw The body.
     * @param string $signature Its signature header.
     * @return bool Whether the address answered with a 2xx.
     */
    public function send(string $url, string $raw, string $signature): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        // The address is one the administrator of the site wrote, and it may be inside the same server.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Hub-Signature-256: ' . $signature,
        ]);
        $curl->post($url, $raw, [
            'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT[0],
            'CURLOPT_TIMEOUT' => self::TIMEOUT[1],
        ]);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        return !$curl->get_errno() && $code >= 200 && $code < 300;
    }
}
