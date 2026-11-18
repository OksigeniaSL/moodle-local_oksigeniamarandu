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
     * @param string[] $components The plugins, as local_oksigeniaeguahe; all for every plugin.
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
        $keys = array_keys(array_filter($all, function ($notice) use ($every, $components) {
            return $every || in_array($notice->component, $components, true);
        }));
        foreach ($channels as $channel) {
            foreach ($keys as $key) {
                notices::set($key, $channel, true);
            }
        }
        return $keys;
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
        return $lines;
    }
}
