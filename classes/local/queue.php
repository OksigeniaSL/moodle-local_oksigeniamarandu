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
 * The queue of notices: each one waits for its template, the quiet hours and the limits of the site, and then goes out.
 *
 * Inside the 24 hours after a family last wrote, the notice goes as plain text, which is free up to a thousand a month
 * by number (a template costs there too since October 2026); outside, with its template, which Meta charges. Nothing
 * goes to a number that has not opted in, nor after the monthly limit.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue {
    /** Seconds a notice that has to wait (the quiet hours, a template still to approve, a channel stopped) is left aside. */
    private const LATER = 15 * MINSECS;

    /** The code of a request of Telegram refused for what it has inside, as an error of the client gives it. */
    private const TELEGRAM_REFUSED = -400;

    /** Table of the queue. */
    public const TABLE = 'local_oksigeniamarandu_queue';

    /** What a template costs when the site does not say, in euros: a guess, to be checked with the prices of Meta. */
    public const DEFAULT_COST = 0.02;

    /** The plain texts a number may send free each month inside the 24 hours; Meta charges from the next one. */
    public const FREE_TEXTS = 1000;

    /** Free replies are possible until 24 hours after the family last wrote; a margin, so as not to arrive late. */
    public const WINDOW = DAYSECS - 30 * MINSECS;

    /** How long a notice waits before each new try after an error that passes; after the last, it has failed. */
    private const RETRY = [MINSECS, 5 * MINSECS, 15 * MINSECS, HOURSECS];

    /** Errors of Meta that pass: too many messages, Meta busy or unavailable. */
    private const TRANSIENT = [130429, 131000, 131016, 131056, 133004];

    /** Errors of the account of the site (token, permissions, restrictions, payment, number): nothing can go out. */
    private const ACCOUNT = [0, 3, 10, 190, 368, 131005, 131031, 131042, 131045, 131057, 133010];

    /** The 24 hours after the family last wrote have passed: the notice has to go with its template. */
    private const WINDOW_CLOSED = 131047;

    /** Meta's code when the person stopped the marketing messages of the business (por verificar in its documentation). */
    private const NO_MARKETING = 131050;

    /** The scope of the «no» to the marketing templates, beside the «no» to a plugin. */
    public const MARKETING = 'marketing';

    /** Errors of a template that is no longer approved, and the status it gets. */
    private const TEMPLATE = [132001 => 'pending', 132015 => 'paused', 132016 => 'disabled'];

    /** While the account has a problem, the queue tries again this often. */
    private const BLOCKED_RETRY = 15 * MINSECS;

    /** The longest text WhatsApp takes under an image or a video. */
    private const CAPTION = 1024;

    /** For how many of the 30 days Meta keeps an image or a video uploaded it is used again. */
    private const MEDIA_DAYS = 25;

    /** Where the problem of the account of each channel is kept. */
    private const BLOCKED_SETTING = ['whatsapp' => 'sendblocked', 'telegram' => 'telegramblocked'];

    /**
     * Queues a notice for one number.
     *
     * @param int $contactid The contact.
     * @param \stdClass $notice The notice.
     * @param string[] $params Values of the template.
     * @param string|null $url Where the button goes, on this site.
     * @param bool $urgent Whether it goes out in the quiet hours and over the notices a day too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param int|null $now The time, for the tests.
     * @param array $rich The clean parts of its card on Telegram, if it has one.
     * @param string|null $ref For a question, what the plugin needs to know it again when it is answered.
     * @param int $itemid For a question, the item of the plugin it goes to.
     * @param string $area For a question, the kind of that item.
     * @param string $lang The language its plugin asked for; empty for that of the person or the site.
     * @return int The id in the queue.
     */
    public static function add(
        int $contactid,
        \stdClass $notice,
        array $params,
        ?string $url,
        bool $urgent,
        int $validfor,
        ?int $now = null,
        array $rich = [],
        ?string $ref = null,
        int $itemid = 0,
        string $area = '',
        string $lang = ''
    ): int {
        global $DB;
        do {
            $token = random_string(10);
        } while ($DB->record_exists(self::TABLE, ['token' => $token]));
        $now = $now ?? time();
        return $DB->insert_record(self::TABLE, (object)[
            'contactid' => $contactid,
            'component' => $notice->component,
            'notice' => $notice->notice,
            'params' => json_encode(array_values($params)),
            'rich' => $rich ? json_encode($rich) : null,
            'ref' => $ref,
            'itemid' => $itemid,
            'area' => $area,
            'lang' => $lang === '' ? null : $lang,
            'url' => $url,
            'token' => $token,
            'urgent' => (int)$urgent,
            'status' => 'queued',
            'timequeued' => $now,
            'timeexpires' => $now + $validfor,
        ]);
    }

    /**
     * Sends what can go out now, each notice by the channel of its contact.
     *
     * @param cloud_api|null $api The client of WhatsApp, if the site has it.
     * @param int|null $now The time, for the tests.
     * @param telegram_api|null $telegram The client of Telegram, if the site has a bot.
     * @return array Counts by what happened: sent, waiting, failed, expired, skipped.
     */
    public static function process(?cloud_api $api, ?int $now = null, ?telegram_api $telegram = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0];
        $config = get_config('local_oksigeniamarandu');
        $cost = self::cost();
        $cap = (float)($config->capmonth ?? 0);
        $perday = (int)($config->capfamily ?? 0);
        $quiet = self::is_quiet($now);
        $enabled = ['whatsapp' => notices::enabled('whatsapp'), 'telegram' => notices::enabled('telegram')];
        $urgent = notices::urgent();
        $spent = self::spent($now);
        $clients = ['whatsapp' => $api, 'telegram' => $telegram];
        // The numbers written first to in the last 24 hours, read once the first template needs it.
        $today = null;

        $gone = $DB->get_records_select(self::TABLE, 'status = ? AND timeexpires < ?', ['queued', $now], 'id ASC');
        foreach ($gone as $row) {
            $DB->set_field(self::TABLE, 'status', 'expired', ['id' => $row->id]);
            self::tell_plugin($row, 'expired', 'expired');
        }
        $counts['expired'] = count($gone);
        // The questions that went out and whose time is over: their buttons die, and on Telegram the message says so.
        $over = $DB->get_records_select(self::TABLE, "ref IS NOT NULL AND timeclosed = 0 AND timeexpires < :now
            AND status IN ('sent', 'delivered', 'read')", ['now' => $now], 'id ASC', '*', 0, 50);
        foreach ($over as $row) {
            self::close($row, 'expired', $telegram, $now);
        }

        // While the account of a channel has a problem, one try now and then is enough to see whether it is fixed; the
        // other channel goes on.
        $paused = [];
        $wasblocked = [];
        foreach (array_keys(self::BLOCKED_SETTING) as $channel) {
            $blocked = self::blocked($channel);
            $wasblocked[$channel] = (bool)$blocked;
            $paused[$channel] = $blocked && $blocked->time > $now - self::BLOCKED_RETRY;
        }

        // The urgent first; what has to wait is looked at again a while later (later()), so that a long line of notices
        // that wait (the quiet hours, a template Meta has not approved) does not hold back what is behind it.
        $select = "status = 'queued' AND (timeretry IS NULL OR timeretry <= :now)";
        foreach ($DB->get_records_select(self::TABLE, $select, ['now' => $now], 'urgent DESC, id ASC', '*', 0, 200) as $row) {
            $key = $row->component . '/' . $row->notice;
            $notice = notices::get($row->component, $row->notice);
            $contact = $DB->get_record(consent::CONTACTS, ['id' => $row->contactid]);
            $active = $contact && $contact->status === 'active' && !consent::out_of($contact, (string)$row->component);
            // Each channel has its own notices switched on.
            $channel = $contact && telegram::is_chat($contact->phone) ? 'telegram' : 'whatsapp';
            if (!$notice || !$active || !in_array($key, $enabled[$channel], true)) {
                $why = !$notice ? 'undeclared' : (!$active ? 'notactive' : 'disabled');
                self::finish($row, $now, 'skipped', null, null, $why);
                $counts['skipped']++;
                continue;
            }
            if ($paused[$channel] || !$clients[$channel]) {
                self::later($row, $now, self::LATER);
                $counts['waiting']++;
                continue;
            }
            $canwait = self::can_wait($row, $notice, $urgent);
            if ($quiet && $canwait) {
                self::later($row, $now, self::LATER, 'quiet');
                $counts['waiting']++;
                continue;
            }
            if ($canwait && $perday > 0 && empty($notice->uncapped) && self::sent_today($contact->id, $now, $urgent) >= $perday) {
                self::later($row, $now, HOURSECS);
                $counts['waiting']++;
                continue;
            }
            if (!empty($row->ref) && (int)$DB->get_field(self::TABLE, 'timeclosed', ['id' => $row->id])) {
                // A question closed since it was read (answered in another way): it does not go.
                self::finish($row, $now, 'expired', null, null, 'closed');
                $counts['expired']++;
                continue;
            }
            $params = json_decode($row->params, true) ?: [];
            // The language of its values goes first, so that the text and the values go in the same: the one the plugin
            // asked for or wrote them in (api::notify()); then the one the person chose; then that of the site.
            $lang = ($row->lang ?? '') ?: ($contact->lang ?: null);
            if ($channel === 'telegram') {
                // Telegram has no templates and no window: the notice, with its link as a button. It is free.
                try {
                    // Without sound if the person asked for it, unless the notice is urgent.
                    [$id, $refused] = self::send_telegram(
                        $telegram,
                        $row,
                        $notice,
                        $params,
                        telegram::chat($contact->phone),
                        $lang,
                        !empty($contact->silent) && !$row->urgent && !in_array($key, $urgent, true)
                    );
                    self::finish($row, $now, 'sent', 'telegram', 'tg.' . $id, $refused);
                    $counts['sent']++;
                    self::unblock('telegram', $wasblocked);
                } catch (api_exception $e) {
                    self::failed($row, $e, $now, 'telegram', $contact, null, $counts, $paused);
                }
                continue;
            }
            // A notice that Meta refused as free text goes with its template.
            $free = $row->kind !== 'template' && !empty($contact->lastinbound) && $contact->lastinbound > $now - self::WINDOW;
            if (!$free && $notice->freeonly) {
                // Never with a template, such as a question whose answers change each time: outside the 24 hours, not by
                // WhatsApp.
                self::finish($row, $now, 'skipped', null, null, 'freeonly');
                $counts['skipped']++;
                continue;
            }
            $template = $free ? null : templates::approved($notice, $lang);
            if (!$free && !$template) {
                self::later($row, $now, self::LATER);
                $counts['waiting']++;
                continue;
            }
            if (
                $template && strtolower((string)$template->category) === 'marketing'
                    && $DB->record_exists(consent::OPTOUTS, ['contactid' => $contact->id, 'scope' => self::MARKETING])
            ) {
                // They told WhatsApp they want no marketing from the centre: not by WhatsApp.
                self::finish($row, $now, 'skipped', null, null, 'nomarketing');
                $counts['skipped']++;
                continue;
            }
            if (!$free && !empty($contact->implied) && !implied::quality_ok()) {
                // Meta takes the messages of the number worse: what goes to whoever did not join waits.
                self::later($row, $now, HOURSECS, 'quality');
                $counts['waiting']++;
                continue;
            }
            $today = $today ?? ($free ? null : implied::written_today($now));
            if (!$free && !implied::room($today, (string)$contact->phone)) {
                // The people a day the line may write first to: the rest waits for room, it is not lost.
                self::later($row, $now, HOURSECS, 'limit');
                $counts['waiting']++;
                continue;
            }
            // What this template costs, by its category (a marketing one costs about three times a utility one).
            $rowcost = $template ? (float)(chat\templates::prices()[strtolower((string)$template->category)] ?? $cost) : 0.0;
            if (!$free && $cap > 0 && $spent + $rowcost > $cap + 0.00001) {
                self::warn_cap($now);
                self::later($row, $now, HOURSECS);
                $counts['waiting']++;
                continue;
            }
            try {
                $question = !empty($row->ref) && $notice->options;
                $rich = $question || empty($row->rich) ? [] : (json_decode($row->rich, true) ?: []);
                if ($free) {
                    $text = self::render($notice, $params, $lang);
                    if ($row->url) {
                        $text .= "\n" . templates::button_url($row->token);
                    }
                    $buttons = $question ? answers::whatsapp_buttons($row, $notice, $lang) : [];
                    if (!empty($rich['caption'])) {
                        // A message made to be forwarded, such as a post for a channel: its video or its image with its
                        // text below, as it is; the notice and its link go after it, so that they are not forwarded.
                        $wamid = self::send_forwardable($api, $contact->phone, $rich);
                        try {
                            $api->send_text($contact->phone, $text);
                        } catch (api_exception $e) {
                            debugging(
                                'Marandu: the link after a message to forward did not go: ' . $e->getMessage(),
                                DEBUG_DEVELOPER
                            );
                        }
                    } else if (count($buttons) > 3) {
                        // More answers than the three buttons WhatsApp allows: a list.
                        $wamid = $api->send_list($contact->phone, $text, get_string_manager()->get_string(
                            'answer:choose',
                            'local_oksigeniamarandu',
                            null,
                            $lang ?: null
                        ), $buttons);
                    } else {
                        $wamid = $question ? $api->send_buttons($contact->phone, $text, $buttons)
                            : $api->send_text($contact->phone, $text);
                    }
                    self::finish($row, $now, 'sent', 'text', $wamid);
                } else {
                    // The quick replies of a question go before the link, which comes after them in the template.
                    $wamid = $api->send_template($contact->phone, $template->name, notices::metalang($template->lang), [
                        ['type' => 'body', 'parameters' => array_map(function ($value) {
                            return ['type' => 'text', 'text' => $value];
                        }, $params)],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => (string)count($notice->options), 'parameters' => [
                            ['type' => 'text', 'text' => $row->token],
                        ]],
                    ]);
                    self::finish($row, $now, 'sent', 'template', $wamid);
                    $spent += $rowcost;
                    if ($today !== null) {
                        $today[(string)$contact->phone] = true;
                    }
                }
                $counts['sent']++;
                self::unblock('whatsapp', $wasblocked);
            } catch (api_exception $e) {
                self::failed($row, $e, $now, $free ? 'text' : 'template', $contact, $template, $counts, $paused);
            }
        }
        return $counts;
    }

    /**
     * What happens to a notice that the channel refused.
     *
     * @param \stdClass $row The row of the queue.
     * @param api_exception $e The error.
     * @param int $now The time.
     * @param string $kind template, text or telegram.
     * @param \stdClass $contact The contact.
     * @param \stdClass|null $template The template it went with, if any.
     * @param array $counts The counts of the run, updated.
     * @param array $paused The channels paused by a problem of their account, updated.
     */
    private static function failed(
        \stdClass $row,
        api_exception $e,
        int $now,
        string $kind,
        \stdClass $contact,
        ?\stdClass $template,
        array &$counts,
        array &$paused
    ): void {
        global $DB;
        $channel = $kind === 'telegram' ? 'telegram' : 'whatsapp';
        $what = self::failure($e);
        if ($what === 'account') {
            // Nothing else would go out by this channel either: its notices wait, untouched, until it is fixed.
            self::block($e, $now, $channel);
            $paused[$channel] = true;
            $counts['waiting']++;
        } else if ($what === 'blocked') {
            // The person blocked the bot of Telegram: the same as opting out.
            consent::optout($contact->phone, 'blocked', 'telegram');
            self::finish($row, $now, 'failed', $kind, null, $e->getMessage(), $e->metacode);
            $counts['failed']++;
        } else if ($what === 'window') {
            $DB->set_field(self::TABLE, 'kind', 'template', ['id' => $row->id]);
            $counts['waiting']++;
        } else if ($what === 'nomarketing') {
            // The person told WhatsApp they want no marketing from the centre: their «no» to those templates.
            consent::set_optout($contact, self::MARKETING, true, 'meta:' . self::NO_MARKETING);
            self::finish($row, $now, 'skipped', $kind, null, 'nomarketing', $e->metacode);
            $counts['skipped']++;
        } else if ($what === 'template' && $template) {
            templates::set_status($template, self::TEMPLATE[$e->metacode]);
            $counts['waiting']++;
        } else if ($what === 'transient' && $row->attempts < count(self::RETRY)) {
            $DB->update_record(self::TABLE, (object)[
                'id' => $row->id,
                'attempts' => $row->attempts + 1,
                'timeretry' => $now + self::RETRY[$row->attempts],
                'error' => \core_text::substr(strip_tags($e->getMessage()), 0, 255),
            ]);
            $counts['waiting']++;
        } else {
            self::finish($row, $now, 'failed', $kind, null, $e->getMessage(), $e->metacode);
            $counts['failed']++;
        }
    }

    /**
     * Sends a notice by Telegram: its card if it has one, or else its text. A card that Telegram refuses for what it has
     * inside goes as its text, so that a notice is never lost; any other problem would stop the text as well.
     *
     * @param telegram_api $telegram The client.
     * @param \stdClass $row The row of the queue.
     * @param \stdClass $notice The notice.
     * @param string[] $params Its values.
     * @param string $chat The chat.
     * @param string|null $lang The language of the person.
     * @param bool $silent Without sound.
     * @return array The id of the message, and why the card did not go, if it did not.
     */
    private static function send_telegram(
        telegram_api $telegram,
        \stdClass $row,
        \stdClass $notice,
        array $params,
        string $chat,
        ?string $lang,
        bool $silent
    ): array {
        $url = $row->url ? templates::button_url($row->token) : null;
        $label = notices::text($notice, 'button', $lang);
        if (!empty($row->ref) && $notice->options) {
            $choices = answers::telegram_choices($row, $notice, $lang);
            return [$telegram->send_question($chat, self::render($notice, $params, $lang), $choices, $url, $label, $silent), null];
        }
        $rich = empty($row->rich) ? [] : json_decode($row->rich, true);
        $refused = null;
        if ($rich) {
            try {
                return [$telegram->send_rich($chat, $rich, $url, $label, $silent), null];
            } catch (api_exception $e) {
                if ($e->metacode !== self::TELEGRAM_REFUSED) {
                    throw $e;
                }
                $refused = $e->getMessage();
            }
        }
        return [$telegram->send_text($chat, self::render($notice, $params, $lang), $url, $label, $silent), $refused];
    }

    /**
     * Sends a message made to be forwarded by WhatsApp: its video, or else its image, with its text below (a text longer
     * than a caption goes in a message of its own after it); the text alone if neither goes. A video or an image that
     * Meta refuses for itself gives way to the next one; any other problem (the 24 hours over, the account, a limit) stops
     * it as it would stop the text.
     *
     * @param cloud_api $api The number of the site.
     * @param string $phone Digits with the country code.
     * @param array $rich The parts of the notice: caption, and video or image.
     * @return string The id Meta gives to the first message.
     * @throws api_exception If Meta does not take the text either, or the problem is not of the video or the image.
     */
    private static function send_forwardable(cloud_api $api, string $phone, array $rich): string {
        $caption = (string)$rich['caption'];
        $long = \core_text::strlen($caption) > self::CAPTION;
        foreach (['video', 'image'] as $type) {
            $bytes = empty($rich[$type]) ? null : ($type === 'video' ? rich::video($rich[$type]) : rich::image($rich[$type]));
            if ($bytes === null) {
                continue;
            }
            try {
                $id = self::media_id($api, $bytes);
                $wamid = $type === 'video' ? $api->send_video($phone, $id, $long ? '' : $caption)
                    : $api->send_image($phone, $id, $long ? '' : $caption);
            } catch (api_exception $e) {
                self::forget_media($bytes);
                if (self::failure($e) !== 'final') {
                    throw $e;
                }
                debugging('Marandu: WhatsApp did not take the ' . $type . ' of a message: ' . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            // The video or the image went: a text too long for it, after it, on its own.
            if ($long) {
                $api->send_text($phone, $caption);
            }
            return $wamid;
        }
        return $api->send_text($phone, $caption);
    }

    /**
     * The id of an image or a video on the number of the site: it goes up to Meta once and is used again for 25 of the
     * 30 days Meta keeps it.
     *
     * @param cloud_api $api The number of the site.
     * @param string $bytes The image or the video.
     * @return string
     * @throws api_exception If Meta does not take it.
     */
    private static function media_id(cloud_api $api, string $bytes): string {
        $cache = \cache::make('local_oksigeniamarandu', 'confirmcards');
        $key = self::media_key($bytes);
        $media = $cache->get($key);
        if (!$media || $media['time'] < time() - self::MEDIA_DAYS * DAYSECS) {
            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $names = ['image/png' => 'post.png', 'video/mp4' => 'post.mp4'];
            $media = ['id' => $api->upload_media($bytes, $names[$type] ?? 'post.jpg'), 'time' => time()];
            $cache->set($key, $media);
        }
        return (string)$media['id'];
    }

    /**
     * Forgets the id of an image or a video: perhaps Meta no longer has it, and the next one uploads it again.
     *
     * @param string $bytes The image or the video.
     */
    private static function forget_media(string $bytes): void {
        \cache::make('local_oksigeniamarandu', 'confirmcards')->delete(self::media_key($bytes));
    }

    /**
     * Where the id of an image or a video is kept: by the number of the site, which owns it, and the file.
     *
     * @param string $bytes The image or the video.
     * @return string
     */
    private static function media_key(string $bytes): string {
        return 'wa_' . sha1((string)get_config('local_oksigeniamarandu', 'phonenumberid') . ':' . sha1($bytes));
    }

    /**
     * A channel works again: its problem is forgotten.
     *
     * @param string $channel whatsapp or telegram.
     * @param array $wasblocked Whether each channel had a problem, updated.
     */
    private static function unblock(string $channel, array &$wasblocked): void {
        if ($wasblocked[$channel]) {
            unset_config(self::BLOCKED_SETTING[$channel], 'local_oksigeniamarandu');
            $wasblocked[$channel] = false;
        }
    }

    /**
     * What an error of Meta or of Telegram means for the queue.
     *
     * @param api_exception $e The error.
     * @return string transient, account, window, template, blocked, nomarketing or final.
     */
    public static function failure(api_exception $e): string {
        $code = $e->metacode;
        if ($code === null) {
            // The network, or an answer that is not Meta's: it usually passes.
            return 'transient';
        }
        if ($code < 0) {
            // Telegram: too many messages or a server that fails pass; a token that is not valid stops the bot; a person
            // who blocked the bot has opted out.
            if ($code === -429 || $code <= -500) {
                return 'transient';
            }
            if ($code === -401 || $code === -404) {
                return 'account';
            }
            return $code === -403 ? 'blocked' : 'final';
        }
        if (in_array($code, self::TRANSIENT, true)) {
            return 'transient';
        }
        if (in_array($code, self::ACCOUNT, true) || ($code >= 200 && $code <= 299)) {
            return 'account';
        }
        if ($code === self::WINDOW_CLOSED) {
            return 'window';
        }
        if ($code === self::NO_MARKETING) {
            return 'nomarketing';
        }
        if (isset(self::TEMPLATE[$code])) {
            return 'template';
        }
        return 'final';
    }

    /**
     * The problem of the account of a channel that stops its notices, if there is one.
     *
     * @param string $channel whatsapp or telegram.
     * @return \stdClass|null With code, message and time.
     */
    public static function blocked(string $channel = 'whatsapp'): ?\stdClass {
        $blocked = json_decode((string)get_config('local_oksigeniamarandu', self::BLOCKED_SETTING[$channel]));
        return is_object($blocked) ? $blocked : null;
    }

    /**
     * Stops a channel for a problem of its account, and tells whom the site said once a day.
     *
     * @param api_exception $e The error.
     * @param int $now The time.
     * @param string $channel whatsapp or telegram.
     */
    private static function block(api_exception $e, int $now, string $channel = 'whatsapp'): void {
        $message = \core_text::substr(strip_tags($e->getMessage()), 0, 255);
        set_config(
            self::BLOCKED_SETTING[$channel],
            json_encode(['code' => $e->metacode, 'message' => $message, 'time' => $now]),
            'local_oksigeniamarandu'
        );
        $emails = limits::emails();
        $day = userdate($now, '%Y-%m-%d', \core_date::get_server_timezone());
        $warned = $channel === 'telegram' ? 'telegramwarned' : 'blockwarned';
        if (!$emails || get_config('local_oksigeniamarandu', $warned) === $day) {
            return;
        }
        set_config($warned, $day, 'local_oksigeniamarandu');
        $a = (object)[
            'site' => format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]),
            'problem' => $message,
            'url' => $channel === 'telegram' ? (new \moodle_url('/local/oksigeniamarandu/telegram.php'))->out(false)
                : (new \moodle_url('/local/oksigeniamarandu/wizard.php', ['step' => 'account']))->out(false),
        ];
        $prefix = $channel === 'telegram' ? 'blockmail:telegram' : 'blockmail:';
        foreach ($emails as $email) {
            $to = \core_user::get_noreply_user();
            $to->email = $email;
            $to->emailstop = 0;
            email_to_user(
                $to,
                \core_user::get_noreply_user(),
                get_string($prefix . 'subject', 'local_oksigeniamarandu', $a),
                get_string($prefix . 'body', 'local_oksigeniamarandu', $a)
            );
        }
    }

    /**
     * A status Meta sends for a message of the queue.
     *
     * @param string $wamid The id of the message.
     * @param string $status sent, delivered, read or failed.
     * @param string $error What Meta says when it fails.
     * @param int|null $code The error code of Meta, when it fails.
     */
    public static function status(string $wamid, string $status, string $error = '', ?int $code = null): void {
        global $DB;
        if ($wamid === '' || !($row = $DB->get_record(self::TABLE, ['wamid' => $wamid]))) {
            return;
        }
        if (in_array($status, ['delivered', 'read'], true)) {
            $change = ['status' => $status === 'read' || $row->status === 'read' ? 'read' : 'delivered',
                'timedelivered' => $row->timedelivered ?: time()];
            if ($status === 'read' && empty($row->timeread)) {
                $change['timeread'] = time();
            }
        } else if ($status === 'failed') {
            $change = ['status' => 'failed', 'error' => \core_text::substr($error === '' ? 'failed' : $error, 0, 255),
                'errorcode' => $code];
        } else {
            return;
        }
        // Only what changed: a question closed meanwhile stays closed.
        $DB->update_record(self::TABLE, (object)(['id' => $row->id] + $change));
        if ($change['status'] === 'failed' && $row->status !== 'failed') {
            self::tell_plugin($row, 'failed', (string)$change['error']);
        }
    }

    /**
     * The text of a notice with its values, for a free message inside the 24 hours.
     *
     * @param \stdClass $notice The notice.
     * @param string[] $params Values.
     * @param string|null $lang Language.
     * @return string
     */
    public static function render(\stdClass $notice, array $params, ?string $lang = null): string {
        $text = notices::text($notice, 'template', $lang);
        foreach (array_values($params) as $i => $value) {
            $text = str_replace('{{' . ($i + 1) . '}}', $value, $text);
        }
        return $text;
    }

    /**
     * Leaves a notice that has to wait out of the next passes for a while.
     *
     * @param \stdClass $row The notice.
     * @param int $now The time.
     * @param int $seconds For how long.
     * @param string $why Why, when the plugin may want to know: quiet, limit or quality.
     */
    private static function later(\stdClass $row, int $now, int $seconds, string $why = ''): void {
        global $DB;
        $change = ['id' => $row->id, 'timeretry' => $now + $seconds];
        if ($why !== '') {
            // Why it waits, for the plugin that asks (api::bulk_items()); what it sends later says what happened then.
            $change['error'] = $why;
        }
        $DB->update_record(self::TABLE, (object)$change);
    }

    /**
     * Closes a question: it takes no more answers, what has not gone does not go, and on Telegram its message says that it
     * was answered or that its time is over, without its buttons. WhatsApp cannot change a message that went.
     *
     * @param \stdClass $row The question, in the queue.
     * @param string $how answered or expired; a question that got an answer in its time says answered anyway.
     * @param telegram_api|null $telegram The bot, to change its message; null not to.
     * @param int|null $now The time.
     */
    public static function close(\stdClass $row, string $how, ?telegram_api $telegram, ?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        if ($row->status === 'queued') {
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => 'expired', 'timeclosed' => $now]);
            return;
        }
        $DB->set_field(self::TABLE, 'timeclosed', $now, ['id' => $row->id]);
        if (!$telegram || strpos((string)$row->wamid, 'tg.') !== 0) {
            return;
        }
        $contact = $DB->get_record(consent::CONTACTS, ['id' => $row->contactid]);
        $notice = notices::get($row->component, $row->notice);
        if (!$contact || !$notice || !telegram::is_chat($contact->phone)) {
            return;
        }
        if ($DB->record_exists(answers::TABLE, ['queueid' => $row->id])) {
            $how = 'answered';
        }
        $lang = $row->lang ?: ($contact->lang ?: null);
        $text = self::render($notice, (array)json_decode((string)$row->params, true), $lang) . "\n\n" .
            get_string_manager()->get_string(
                'answer:closed:' . ($how === 'answered' ? 'answered' : 'expired'),
                'local_oksigeniamarandu',
                null,
                $lang
            );
        try {
            $telegram->edit_text(telegram::chat($contact->phone), (int)substr((string)$row->wamid, 3), $text);
        } catch (api_exception $e) {
            // An old message, or one the person deleted: the question is closed all the same.
            debugging('Marandu: a question was not changed on Telegram: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Whether the quiet hours and the notices a day hold a notice back: not if it is urgent, by the plugin or by the site,
     * nor if each person asks for it at a time they choose. A notice that cannot wait does not count for the notices a day
     * either: a family that asked for three menus still hears at once that a dish changed.
     *
     * @param \stdClass $row The row of the queue.
     * @param \stdClass|null $notice The notice, if it is still declared.
     * @param string[] $urgent The notices the site marked as urgent.
     * @return bool
     */
    public static function can_wait(\stdClass $row, ?\stdClass $notice, array $urgent): bool {
        $key = $row->component . '/' . $row->notice;
        return !$row->urgent && !in_array($key, $urgent, true) && empty($notice->askedfor);
    }

    /**
     * Whether a time falls in the quiet hours or the quiet days of the site, in its time zone.
     *
     * @param int $time The time.
     * @return bool
     */
    public static function is_quiet(int $time): bool {
        $config = get_config('local_oksigeniamarandu');
        $date = new \DateTime('@' . $time);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $days = array_filter(explode(',', (string)($config->quietdays ?? '')), 'strlen');
        if (in_array($date->format('w'), $days, true)) {
            return true;
        }
        $from = (int)str_replace(':', '', (string)($config->quietfrom ?? ''));
        $to = (int)str_replace(':', '', (string)($config->quietto ?? ''));
        if ($from === $to) {
            return false;
        }
        $now = (int)$date->format('Hi');
        return $from > $to ? ($now >= $from || $now < $to) : ($now >= $from && $now < $to);
    }

    /**
     * When the quiet hours or days that hold a time end, in steps of five minutes; the time itself if it is not quiet.
     *
     * @param int $time The time.
     * @return int
     */
    public static function quiet_end(int $time): int {
        $end = $time;
        // A week and a day is longer than any quiet the site can set.
        while (self::is_quiet($end) && $end < $time + 8 * DAYSECS) {
            $end += 5 * MINSECS;
        }
        if ($end === $time) {
            return $time;
        }
        // On the minute the quiet ends, not five minutes after it.
        while ($end - MINSECS > $time && !self::is_quiet($end - MINSECS)) {
            $end -= MINSECS;
        }
        return $end;
    }

    /**
     * What WhatsApp has cost this month, roughly: each template at the price of its category, and the plain texts inside
     * the 24 hours past the ones Meta gives free each month to a number (since October 2026). If Meta already said
     * more for the month (its figures, which count also the replies of the team), that.
     *
     * @param int $now The time.
     * @return float Euros.
     */
    public static function spent(int $now): float {
        global $DB;
        $start = new \DateTime('@' . $now);
        $start->setTimezone(\core_date::get_server_timezone_object());
        $start->modify('first day of this month midnight');
        $prices = chat\templates::prices();
        $rows = $DB->get_records_sql(
            "SELECT t.category, COUNT(1) AS sent
               FROM {" . self::TABLE . "} q
          LEFT JOIN {" . templates::TABLE . "} t ON t.component = q.component AND t.notice = q.notice AND t.lang = :lang
              WHERE q.kind = 'template' AND q.timesent >= :start AND q.status <> 'failed'
           GROUP BY t.category",
            ['lang' => $GLOBALS['CFG']->lang, 'start' => $start->getTimestamp()]
        );
        $cost = joinask::sent_since($start->getTimestamp()) * (float)(chat\templates::prices()['utility'] ?? 0);
        foreach ($rows as $row) {
            $cost += $row->sent * (float)($prices[strtolower((string)$row->category)] ?? self::cost());
        }
        $texts = $DB->count_records_select(
            self::TABLE,
            "kind = 'text' AND timesent >= ? AND status <> 'failed'",
            [$start->getTimestamp()]
        );
        $cost += max(0, $texts - self::FREE_TEXTS) * (float)($prices['service'] ?? $prices['utility']);
        $month = spend::month();
        return max($cost, $month['month'] === userdate($now, '%Y-%m', 99, false, false) ? (float)$month['cost'] : 0.0);
    }

    /**
     * The price of one template, as the site set it.
     *
     * @return float Euros.
     */
    public static function cost(): float {
        $cost = get_config('local_oksigeniamarandu', 'costpermessage');
        return $cost === false || $cost === '' ? self::DEFAULT_COST : (float)$cost;
    }

    /**
     * How many notices a number has received today, of those that count for the notices a day.
     *
     * @param int $contactid The contact.
     * @param int $now The time.
     * @param string[] $urgent The notices the site marked as urgent.
     * @return int
     */
    private static function sent_today(int $contactid, int $now, array $urgent): int {
        global $DB;
        $start = new \DateTime('@' . $now);
        $start->setTimezone(\core_date::get_server_timezone_object());
        $start->modify('midnight');
        $rows = $DB->get_records_select(
            self::TABLE,
            "contactid = ? AND timesent >= ? AND status <> 'failed' AND urgent = 0",
            [$contactid, $start->getTimestamp()],
            '',
            'id, component, notice, urgent'
        );
        return count(array_filter($rows, function ($row) use ($urgent) {
            $notice = notices::get($row->component, $row->notice);
            return self::can_wait($row, $notice, $urgent) && empty($notice->uncapped);
        }));
    }

    /**
     * Closes a row of the queue.
     *
     * @param \stdClass $row The row.
     * @param int $now The time.
     * @param string $status sent, failed or skipped.
     * @param string|null $kind template or text.
     * @param string|null $wamid The id of the message.
     * @param string|null $error Why, if it did not go.
     * @param int|null $code The error code of Meta, if it gave one.
     */
    private static function finish(
        \stdClass $row,
        int $now,
        string $status,
        ?string $kind,
        ?string $wamid,
        ?string $error = null,
        ?int $code = null
    ): void {
        global $DB;
        $row->status = $status;
        $row->kind = $kind;
        $row->wamid = $wamid;
        $row->error = $error === null ? null : \core_text::substr(strip_tags($error), 0, 255);
        $row->errorcode = $code;
        // When it went out or failed: the panel counts both by day.
        $row->timesent = $status === 'sent' || $status === 'failed' ? $now : null;
        $row->timeretry = null;
        // Only what changed here: a question closed meanwhile (api::close_question()) stays closed.
        $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => $row->status, 'kind' => $row->kind,
            'wamid' => $row->wamid, 'error' => $row->error, 'errorcode' => $row->errorcode, 'timesent' => $row->timesent,
            'timeretry' => null]);
        self::tell_plugin($row, $status, (string)$row->error);
    }

    /**
     * Takes out of the queue what has not gone yet for an item.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @param string[] $notices Only these notices; all of the plugin if empty.
     * @param int $contactid Only for this number or chat; 0 for all of them.
     * @return int How many will not go.
     */
    public static function cancel(string $component, int $itemid, string $area, array $notices = [], int $contactid = 0): int {
        global $DB;
        if (!$itemid) {
            return 0;
        }
        $where = "status = 'queued' AND component = :component AND itemid = :itemid AND area = :area";
        $params = ['component' => $component, 'itemid' => $itemid, 'area' => $area];
        if ($notices) {
            [$in, $more] = $DB->get_in_or_equal(array_map('strval', $notices), SQL_PARAMS_NAMED, 'n');
            $where .= " AND notice $in";
            $params += $more;
        }
        if ($contactid) {
            $where .= ' AND contactid = :contactid';
            $params['contactid'] = $contactid;
        }
        $rows = $DB->get_records_select(self::TABLE, $where, $params, 'id ASC');
        foreach ($rows as $row) {
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => 'skipped', 'error' => 'cancelled',
                'timeretry' => null]);
            self::tell_plugin($row, 'cancelled', 'cancelled');
        }
        return count($rows);
    }

    /**
     * Whether a notice would go to a number or chat: it is in, the site has it switched on for its channel and the channel
     * works; on WhatsApp, inside the 24 hours or with its template.
     *
     * @param \stdClass $notice The notice.
     * @param \stdClass $contact The number or chat.
     * @param int|null $now The time, for the tests.
     * @return bool
     */
    public static function can_go(\stdClass $notice, \stdClass $contact, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $channel = telegram::is_chat((string)$contact->phone) ? 'telegram' : 'whatsapp';
        $on = $contact->status === 'active' && !consent::out_of($contact, (string)$notice->component)
            && notices::allows($notice, $channel) && in_array($notice->key, notices::enabled($channel), true);
        if (!$on || self::blocked($channel)) {
            return false;
        }
        if ($channel === 'telegram') {
            return telegram_api::is_configured();
        }
        if (!cloud_api::is_configured()) {
            return false;
        }
        if (!empty($contact->lastinbound) && (int)$contact->lastinbound > $now - self::WINDOW) {
            return true;
        }
        $template = $notice->freeonly ? null : templates::approved($notice, $contact->lang ?: null);
        return $template && (strtolower((string)$template->category) !== 'marketing'
            || !$DB->record_exists(consent::OPTOUTS, ['contactid' => $contact->id, 'scope' => self::MARKETING]));
    }

    /**
     * The plugin of a notice hears what became of it, if it wants to: <plugin>_marandu_notice_done().
     *
     * @param \stdClass $row The notice, in the queue.
     * @param string $status sent, failed, skipped, expired or cancelled.
     * @param string $why Why it did not go.
     */
    private static function tell_plugin(\stdClass $row, string $status, string $why): void {
        global $DB;
        $itemid = (int)($row->itemid ?? 0);
        if (!$itemid || \core_component::get_component_directory((string)$row->component) === null) {
            return;
        }
        $phone = (string)$DB->get_field(consent::CONTACTS, 'phone', ['id' => $row->contactid]);
        try {
            component_callback((string)$row->component, 'marandu_notice_done', [(string)$row->notice, $itemid,
                telegram::is_chat($phone) ? 'telegram' : 'whatsapp', $status, $why, (int)$row->timequeued,
                (string)($row->area ?? '')]);
        } catch (\Throwable $e) {
            // A plugin that fails must not stop the queue.
            debugging('Marandu: a plugin failed hearing of a notice: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Writes once a month to whom the site said that the limit of spending has been reached.
     *
     * @param int $now The time.
     */
    private static function warn_cap(int $now): void {
        $month = userdate($now, '%Y-%m', \core_date::get_server_timezone());
        $emails = limits::emails();
        if (!$emails || get_config('local_oksigeniamarandu', 'capwarned') === $month) {
            return;
        }
        set_config('capwarned', $month, 'local_oksigeniamarandu');
        $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
        $a = (object)['site' => $site, 'cap' => format_float((float)get_config('local_oksigeniamarandu', 'capmonth'), 2)];
        foreach ($emails as $email) {
            $to = \core_user::get_noreply_user();
            $to->email = $email;
            $to->emailstop = 0;
            email_to_user(
                $to,
                \core_user::get_noreply_user(),
                get_string('capmail:subject', 'local_oksigeniamarandu', $a),
                get_string('capmail:body', 'local_oksigeniamarandu', $a)
            );
        }
    }
}
