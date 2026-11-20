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
 * Connects the site with an account of Chatwoot: checks the token, keeps it encrypted, and creates the webhook of the
 * account that tells the site of every change.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection {
    /** What the webhook of the account tells. */
    public const EVENTS = ['conversation_created', 'conversation_updated', 'conversation_status_changed', 'message_created',
        'message_updated', 'contact_updated'];

    /**
     * Connects the account, if its token works.
     *
     * @param string $url The address of Chatwoot.
     * @param int $account The number of the account.
     * @param string $token The access token of an administrator of the account.
     * @param string $platform The token of a platform app, so that each person answers as themself; empty for none.
     * @param int[] $inboxes The inboxes to attend; empty for all.
     * @return array What the account has: agents, teams and inboxes, counted.
     * @throws \moodle_exception If the token does not reach the account.
     */
    public static function connect(string $url, int $account, string $token, string $platform = '', array $inboxes = []): array {
        $url = rtrim(trim($url), '/');
        if (!preg_match('~^https?://~', $url) || $account <= 0 || trim($token) === '') {
            throw new \invalid_parameter_exception('Address, account and token are needed');
        }
        $client = new chatwoot($url, $account, trim($token));
        $agents = $client->call('GET', '/agents');
        $self = (int)($client->call('GET', '/profile')['id'] ?? 0);
        $teams = $client->call('GET', '/teams');
        $list = $client->call('GET', '/inboxes');
        $old = get_config('local_oksigeniamarandu');
        $changed = ($old->chatwooturl ?? '') !== $url || (int)($old->chatwootaccount ?? 0) !== $account;
        set_config('chatwooturl', $url, 'local_oksigeniamarandu');
        set_config('chatwootaccount', $account, 'local_oksigeniamarandu');
        set_config('chatwoottoken', \core\encryption::encrypt(trim($token)), 'local_oksigeniamarandu');
        set_config(
            'chatwootplatform',
            trim($platform) === '' ? '' : \core\encryption::encrypt(trim($platform)),
            'local_oksigeniamarandu'
        );
        set_config('chatwootinboxes', implode(',', array_map('intval', $inboxes)), 'local_oksigeniamarandu');
        set_config('chatwootself', $self, 'local_oksigeniamarandu');
        // The tokens of the people are asked for again, with the new platform app.
        set_config('chatagenttokens', '', 'local_oksigeniamarandu');
        if ($changed) {
            // Another account: its index starts again.
            global $DB;
            $DB->delete_records(mirror::TABLE);
            $DB->delete_records(viewer::SEEN);
            set_config('chatsynced', 0, 'local_oksigeniamarandu');
            set_config('chatwootsecret', '', 'local_oksigeniamarandu');
            set_config('chatwootwebhook', 0, 'local_oksigeniamarandu');
        }
        directory::forget();
        return ['agents' => count($agents), 'teams' => count($teams), 'inboxes' => count($list['payload'] ?? $list)];
    }

    /**
     * Creates the webhook of the account towards this site, or brings the one there is up to date.
     *
     * @param string|null $address The address it calls; the page of the site if null.
     * @return int Its id.
     */
    public static function webhook(?string $address = null): int {
        $address = $address ?? (new \moodle_url('/local/oksigeniamarandu/chatwoot.php'))->out(false);
        $client = directory::client();
        $list = $client->call('GET', '/webhooks');
        $found = null;
        foreach ($list['payload']['webhooks'] ?? $list['payload'] ?? [] as $webhook) {
            if (is_array($webhook) && ($webhook['url'] ?? '') === $address) {
                $found = $webhook;
                break;
            }
        }
        $body = ['webhook' => ['url' => $address, 'name' => 'Oksigenia Marandu', 'subscriptions' => self::EVENTS]];
        if ($found) {
            $answer = $client->call('PATCH', '/webhooks/' . (int)$found['id'], $body);
        } else {
            $answer = $client->call('POST', '/webhooks', $body);
        }
        $webhook = $answer['payload']['webhook'] ?? $answer['payload'] ?? $answer;
        $secret = (string)($webhook['secret'] ?? $found['secret'] ?? '');
        set_config('chatwootsecret', $secret === '' ? '' : \core\encryption::encrypt($secret), 'local_oksigeniamarandu');
        set_config('chatwootwebhook', (int)($webhook['id'] ?? 0), 'local_oksigeniamarandu');
        return (int)($webhook['id'] ?? 0);
    }

    /**
     * Disconnects the site: forgets the tokens and the index.
     */
    public static function disconnect(): void {
        global $DB;
        foreach (
            ['chatwooturl', 'chatwootaccount', 'chatwoottoken', 'chatwootplatform', 'chatwootinboxes', 'chatagenttokens',
                'chatwootself', 'chatwootsecret', 'chatwootwebhook', 'chatsynced', 'chatwebhookat'] as $name
        ) {
            unset_config($name, 'local_oksigeniamarandu');
        }
        $DB->delete_records(mirror::TABLE);
        $DB->delete_records(viewer::SEEN);
        directory::forget();
    }
}
