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
 * What whoever hosts a site sets from the command line (cli/setup.php), without going through the pages: the bot of
 * Telegram and the notices of the plugins, switched on for a channel. The same checks as the pages.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup {
    /** @var string The shape of the token of a bot, as the page of Telegram checks it. */
    public const TOKEN = '/^[0-9]{5,15}:[A-Za-z0-9_-]{30,}$/';

    /**
     * Connects the bot of the site, as its page does. Another bot, when people joined the one of now, is changed only
     * from the page, which says who would have to tap Start again.
     *
     * @param telegram_api $api The client, with the token.
     * @param string $token The token.
     * @return array The bot (first_name, username), and same: whether it was the bot of now with a new token.
     * @throws \moodle_exception If the token has not the shape of one, or it is another bot and people joined.
     * @throws api_exception If Telegram does not take the token or the webhook.
     */
    public static function telegram(telegram_api $api, string $token): array {
        if (!preg_match(self::TOKEN, $token)) {
            throw new \moodle_exception('telegram:error:token', 'local_oksigeniamarandu');
        }
        $bot = $api->get_me();
        $configured = telegram_api::is_configured();
        $same = $configured && telegram::is_same_bot($bot);
        if ($configured && !$same && ($joined = count(telegram::joined()))) {
            throw new \moodle_exception('setup:otherbot', 'local_oksigeniamarandu', '', (object)[
                'bot' => '@' . get_config('local_oksigeniamarandu', 'telegrambot'),
                'people' => $joined,
            ]);
        }
        telegram::connect($api, $token);
        return $bot + ['same' => $same];
    }

    /**
     * Switches on, for each channel given, every notice that the plugins given declare.
     *
     * @param string[] $components The plugins, as local_oksigeniaeguahe; all for every plugin; or one notice, as
     *                             local_oksigeniaeguahe/act_invitation.
     * @param string[] $channels whatsapp, telegram or both.
     * @return string[] The notices switched on, as component/notice.
     * @throws \moodle_exception If a channel is not one, or a plugin declares no notices.
     */
    public static function notices(array $components, array $channels): array {
        foreach ($channels as $channel) {
            if (!isset(notices::SETTING[$channel])) {
                throw new \moodle_exception('setup:nochannel', 'local_oksigeniamarandu', '', $channel);
            }
        }
        $all = notices::all();
        $declaring = array_unique(array_map(function ($notice) {
            return $notice->component;
        }, $all));
        $every = in_array('all', $components, true);
        // One notice by itself, as component/notice: such as the exception to what Meta counts as marketing.
        $single = array_values(array_filter($components, function ($one) use ($all) {
            return isset($all[$one]);
        }));
        $components = array_diff($components, $single);
        if (!$every) {
            foreach ($components as $component) {
                if (!in_array($component, $declaring, true)) {
                    throw new \moodle_exception('setup:nonotices', 'local_oksigeniamarandu', '', (object)[
                        'component' => $component,
                        'declaring' => implode(', ', $declaring),
                    ]);
                }
            }
        }
        $keys = array_keys(array_filter($all, function ($notice) use ($every, $components, $single) {
            return $every || in_array($notice->component, $components, true) || in_array($notice->key, $single, true);
        }));
        foreach ($channels as $channel) {
            foreach ($keys as $key) {
                notices::set($key, $channel, true);
            }
        }
        return $keys;
    }

    /**
     * Moves the site to another number of the same WhatsApp account, with the same token: the number Meta has for it,
     * the links to join, the 24 hours of each person (they are with a number) and how the templates are.
     *
     * @param string $phonenumberid The id of the number, as Meta gives it.
     * @param cloud_api|null $api The client for that number, for the tests.
     * @return array number, name, old (the id before), reset (people whose 24 hours were open), templates (how many
     *               the site has) and webhook (where Meta sends what reaches the number).
     */
    public static function whatsapp_number(string $phonenumberid, ?cloud_api $api = null): array {
        global $DB;
        $component = 'local_oksigeniamarandu';
        if (!preg_match('/^[0-9]{5,30}$/', $phonenumberid)) {
            throw new \moodle_exception('wizard:error:digits', $component);
        }
        $config = get_config($component);
        if (empty($config->accesstoken)) {
            throw new api_exception('error:notconfigured');
        }
        if (!$api) {
            $version = empty($config->graphversion) ? cloud_api::DEFAULT_GRAPH_VERSION : $config->graphversion;
            $api = new cloud_api($phonenumberid, \core\encryption::decrypt($config->accesstoken), $version);
        }
        // The number has to answer with the token of the site, and the token still reach the templates.
        $info = $api->get_phone_number();
        $wabaid = (string)($config->wabaid ?? '');
        if ($wabaid !== '' && !$api->reaches_account($wabaid)) {
            throw new \moodle_exception('wizard:error:waba', $component);
        }
        $old = (string)($config->phonenumberid ?? '');
        set_config('phonenumberid', $phonenumberid, $component);
        set_config('connectednumber', $info['number'], $component);
        set_config('connectedname', $info['name'], $component);
        // The links to join (the page, its QR, the wa.me of the notices) are made from it each time.
        set_config('displayphone', $info['number'], $component);
        unset_config('sendblocked', $component);
        $reset = 0;
        if ($old !== $phonenumberid) {
            // The 24 hours of a person are with a number: with another one, what goes out first needs its template.
            $reset = $DB->count_records_select(consent::CONTACTS, "channel = 'whatsapp' AND lastinbound > 0");
            $DB->set_field_select(consent::CONTACTS, 'lastinbound', 0, "channel = 'whatsapp'");
        }
        $templates = 0;
        if ($wabaid !== '' && $DB->record_exists(templates::TABLE, [])) {
            templates::refresh($api, $wabaid);
            $templates = $DB->count_records(templates::TABLE);
        }
        return ['number' => $info['number'], 'name' => $info['name'], 'old' => $old, 'reset' => $reset,
            'templates' => $templates, 'webhook' => $api->webhook_configuration()];
    }

    /**
     * How the site is, one line each, for whoever runs the command: the channels and their notices.
     *
     * @return string[]
     */
    public static function status(): array {
        $lines = [];
        foreach (\local_oksigeniamarandu\api::channels() as $channel => $info) {
            $line = $info['name'] . ': ' . ($info['ready'] ? 'ready' : 'not set up');
            if ($channel === 'telegram' && $info['ready']) {
                $line .= ', @' . get_config('local_oksigeniamarandu', 'telegrambot') . ', ' . count(telegram::joined()) . ' joined';
            }
            $on = array_values(array_intersect(notices::enabled($channel), array_keys(notices::all())));
            $lines[] = $line . '; notices on: ' . ($on ? implode(', ', $on) : 'none');
        }
        $off = notices::marketing_off();
        $lines[] = 'Marketing by WhatsApp: ' . (get_config('local_oksigeniamarandu', 'marketingoff') ? 'not' : 'yes') .
            ($off ? '; stopped when Meta moved them: ' . implode(', ', array_keys($off)) : '');
        $lines[] = self::whatsapp_channel();
        return $lines;
    }

    /**
     * How the channel of WhatsApp is, in one line: on or off, its name and link, and who publishes there.
     *
     * @return string
     */
    public static function whatsapp_channel(): string {
        $people = array_map('fullname', wachannel::people());
        $line = 'Channel of WhatsApp: ' . (wachannel::enabled() ? 'on' : 'off');
        $name = wachannel::name();
        $url = wachannel::channel_url();
        if ($name !== '' || $url !== '') {
            $line .= ', ' . trim($name . ($url !== '' ? ' ' . $url : ''));
        }
        return $line . '; published by ' . ($people ? implode(', ', $people) : 'nobody')
            . (wachannel::chosen() ? '' : ' (whoever reviews)');
    }
}
