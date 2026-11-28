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

use local_oksigeniamarandu\local\gestures;

/**
 * The page «Mail», without the look of a form: where each mail goes out (home and out of home), Mailjet (its keys, who
 * sends, who is answered, what Mailjet says of them, a test and its events), the month against the quota, the last
 * mails, and the addresses that do not receive. Each card saves what it shows, and comes back with its «Saved».
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page {
    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /** The cards that save. */
    public const PARTS = ['route', 'mailjet', 'month', 'microsoft', 'bad'];

    /** The most mails in a month the quota can be. */
    private const MOSTQUOTA = 1000000;

    /**
     * Saves what a card sent.
     *
     * @param string $part One of PARTS.
     * @return array The errors, by field; empty if saved.
     */
    public static function save(string $part): array {
        $errors = [];
        $do = optional_param('do', '', PARAM_ALPHA);
        if ($part === 'route') {
            $remove = optional_param('removehome', '', PARAM_RAW_TRIMMED);
            if ($remove !== '') {
                $gone = \core_text::strtolower($remove);
                set_config('mailhome', implode(', ', array_diff(settings::home_domains(), [$gone])), self::C);
            } else if ($do === 'addhome') {
                $text = optional_param('addhome', '', PARAM_RAW_TRIMMED);
                $domains = settings::domains($text);
                if ($text !== '' && !$domains) {
                    $errors['mailhome'] = get_string('mail:home:error', self::C);
                } else if ($domains) {
                    set_config('mailhome', implode(', ', array_unique(array_merge(settings::home_domains(), $domains))), self::C);
                }
            } else if ($do === 'site' || $do === 'mailjet') {
                if ($do === 'mailjet' && !check::ready()) {
                    $errors['way'] = get_string('mail:way:notready', self::C);
                } else {
                    set_config('mailway', $do, self::C);
                }
            }
        } else if ($part === 'mailjet') {
            if ($do === 'keys') {
                $key = optional_param('mjkey', '', PARAM_RAW_TRIMMED);
                $secret = optional_param('mjsecret', '', PARAM_RAW_TRIMMED);
                if ($key === '' || $secret === '') {
                    $errors['keys'] = get_string('mail:keys:error', self::C);
                } else {
                    settings::set_keys($key, $secret);
                    check::run();
                }
            } else if ($do === 'sender') {
                $from = optional_param('mjfrom', '', PARAM_RAW_TRIMMED);
                $replyto = optional_param('mjreplyto', '', PARAM_RAW_TRIMMED);
                if ($from !== '' && !validate_email($from)) {
                    $errors['mjfrom'] = get_string('invalidemail');
                }
                if ($replyto !== '' && !validate_email($replyto)) {
                    $errors['mjreplyto'] = get_string('invalidemail');
                }
                if (!$errors) {
                    set_config('mjfrom', $from, self::C);
                    set_config('mjfromname', optional_param('mjfromname', '', PARAM_TEXT), self::C);
                    set_config('mjreplyto', $replyto, self::C);
                    if (settings::keys()) {
                        check::run();
                    }
                }
            } else if ($do === 'check') {
                check::run();
            } else if ($do === 'test') {
                $email = optional_param('mailtestto', '', PARAM_RAW_TRIMMED);
                if (!validate_email($email)) {
                    $errors['mailtestto'] = get_string('invalidemail');
                } else {
                    check::test($email);
                }
            } else if ($do === 'events') {
                if (!self::listen()) {
                    $errors['events'] = get_string('mail:events:error', self::C);
                }
            }
        } else if ($part === 'month') {
            if ($do === 'release') {
                mailer::release();
            } else {
                foreach (['mailquota' => self::MOSTQUOTA, 'mailroutine' => 100] as $name => $most) {
                    $value = optional_param($name, null, PARAM_INT);
                    if ($value !== null) {
                        set_config($name, max(0, min($most, $value)), self::C);
                    }
                }
            }
        } else if ($part === 'microsoft') {
            if ($do === 'mssite' || $do === 'msmailjet') {
                set_config('mailmsway', $do === 'mssite' ? 'site' : 'mailjet', self::C);
            }
            foreach (['mailmswarm' => 500, 'mailsitecap' => 5000] as $name => $most) {
                $value = optional_param($name, null, PARAM_INT);
                if ($value !== null) {
                    set_config($name, max(0, min($most, $value)), self::C);
                }
            }
        } else if ($part === 'bad') {
            events::forget(optional_param('forget', '', PARAM_RAW_TRIMMED));
        }
        return $errors;
    }

    /**
     * What the page shows.
     *
     * @param string $saved The card just saved.
     * @param array $errors
     * @param \moodle_url $baseurl
     * @param int|null $now
     * @return array The context of local_oksigeniamarandu/mail.
     */
    public static function context(string $saved, array $errors, \moodle_url $baseurl, ?int $now = null): array {
        global $CFG, $DB;
        $now = $now ?? time();
        $action = function (string $part) use ($baseurl): string {
            return (new \moodle_url($baseurl, ['part' => $part]))->out(false) . '#mrd-' . $part;
        };
        $mailjet = settings::mailjet_chosen();
        $checked = check::last();
        $ready = check::ready();
        $paused = mailer::paused($now);
        $state = quota::state($now);
        [$from, $fromname] = settings::from();
        $keystime = (int)get_config(self::C, 'mjkeystime');
        $test = json_decode((string)get_config(self::C, 'mailtest'), true);

        $checks = [];
        foreach (check::PARTS as $part) {
            $value = $checked[$part] ?? 'unknown';
            // SPF does not stop Mailjet: DKIM alone aligns the mail.
            $checks[] = ['label' => get_string('mail:check:' . $part, self::C), 'ok' => $value === 'ok',
                'bad' => $value === 'bad' && $part !== 'spf', 'warn' => $value === 'bad' && $part === 'spf',
                'unknown' => $value === 'unknown', 'note' => get_string('mail:check:' . $part . ':' . $value, self::C)];
        }
        $events = self::events_here($checked);

        $quota = $state['quota'];
        $month = [
            'saved' => $saved === 'month',
            'action' => $action('month'),
            'used' => self::number($state['used']),
            'hasquota' => $quota > 0,
            'fill' => $quota ? min(100, round($state['used'] * 100 / $quota, 1)) : 0,
            'mark' => settings::routine_share(),
            'over' => $quota && $state['used'] >= $quota,
            'near' => $quota && $state['used'] >= (int)$state['routine'] && $state['used'] < $quota,
            'caption' => $quota ? get_string('mail:month:caption', self::C, (object)['used' => self::number($state['used']),
                'quota' => self::number($quota), 'routine' => self::number((int)$state['routine'])])
                : get_string('mail:month:noquota', self::C, self::number($state['used'])),
            'counted' => $state['counted'] ? get_string('mail:month:counted', self::C, (object)[
                'sent' => self::number((int)$state['counted']['sent']),
                'time' => self::when((int)$state['counted']['time'], $now)]) : '',
            'quotastepper' => gestures::stepper(
                'mailquota',
                $quota,
                self::MOSTQUOTA,
                get_string('mail:month:unit', self::C),
                1000
            ),
            'sharestepper' => gestures::stepper(
                'mailroutine',
                settings::routine_share(),
                100,
                get_string('mail:month:shareunit', self::C),
                5
            ),
            'waiting' => $state['waiting'] ? get_string('mail:month:waiting', self::C, self::number($state['waiting'])) : '',
        ];

        $recent = [];
        $last = $DB->get_records_select(mailer::TABLE, 'timesent IS NOT NULL', null, 'timesent DESC, id DESC', '*', 0, 8);
        foreach ($last as $row) {
            [$note, $code] = self::what_happened($row);
            $recent[] = [
                'when' => self::when((int)$row->timesent, $now, true),
                'plugin' => self::plugin_name((string)$row->component),
                'email' => $row->email,
                'way' => $row->way && $row->status !== 'failed' ? get_string('mail:way:' . $row->way, self::C) : '',
                'failed' => $row->status === 'failed',
                'note' => $note,
                'code' => $code,
            ];
        }
        $today = $DB->get_records_sql(
            "SELECT CASE WHEN status = 'failed' THEN 'failed' ELSE way END AS what, COUNT(1) AS n
               FROM {" . mailer::TABLE . "}
              WHERE timesent >= :start
           GROUP BY CASE WHEN status = 'failed' THEN 'failed' ELSE way END",
            ['start' => usergetmidnight($now)]
        );
        $todayline = [];
        foreach (['site', 'mailjet', 'failed'] as $what) {
            if (!empty($today[$what])) {
                $todayline[] = get_string('mail:today:' . $what, self::C, self::number((int)$today[$what]->n));
            }
        }

        $bad = [];
        foreach ($DB->get_records(mailer::BAD, null, 'timemodified DESC', '*', 0, 30) as $row) {
            $bad[] = ['email' => $row->email, 'why' => get_string('mail:bad:' . $row->event, self::C),
                'reason' => (string)$row->reason, 'when' => self::when((int)$row->timemodified, $now),
                'plugin' => $row->component ? self::plugin_name((string)$row->component) : ''];
        }

        return [
            'saved' => $saved,
            'route' => [
                'saved' => $saved === 'route',
                'action' => $action('route'),
                'home' => array_map(fn($domain) => ['domain' => $domain], settings::home_domains()),
                'homedefault' => (string)get_config(self::C, 'mailhome') === '',
                'homeerror' => $errors['mailhome'] ?? '',
                'sitefrom' => $CFG->noreplyaddress ?? '',
                'mailjet' => $mailjet,
                'ready' => $ready,
                'wayerror' => $errors['way'] ?? '',
                'canmailjet' => $ready || $mailjet,
                'outsite' => get_string('mail:out:site', self::C),
                'outmailjet' => $from !== '' ? get_string('mail:out:mailjet', self::C, $from) : get_string('mail:out:mailjet:nofrom', self::C),
                'paused' => $paused ? get_string('mail:paused', self::C, (object)['error' => s($paused['error']),
                    'time' => self::when((int)($paused['since'] ?? $paused['time']), $now)]) : '',
            ],
            'mailjet' => [
                'saved' => $saved === 'mailjet',
                'action' => $action('mailjet'),
                'keys' => $keystime ? get_string('mail:keys:set', self::C, self::when($keystime, $now)) : '',
                'keyserror' => $errors['keys'] ?? '',
                'from' => (string)get_config(self::C, 'mjfrom'),
                'fromerror' => $errors['mjfrom'] ?? '',
                'fromname' => (string)get_config(self::C, 'mjfromname'),
                'fromnamehint' => $fromname,
                'replyto' => (string)get_config(self::C, 'mjreplyto'),
                'replytoerror' => $errors['mjreplyto'] ?? '',
                'checks' => $checks,
                'checked' => $checked ? get_string('mail:check:when', self::C, self::when((int)$checked['time'], $now)) : '',
                'checkerror' => $checked['error'] ?? '',
                'cancheck' => settings::keys() !== null,
                'testto' => is_array($test) ? (string)$test['to'] : '',
                'testerror' => $errors['mailtestto'] ?? '',
                'test' => is_array($test) ? get_string($test['ok'] ? 'mail:test:ok' : 'mail:test:bad', self::C, (object)[
                    'to' => s($test['to']), 'time' => self::when((int)$test['time'], $now), 'error' => s($test['error'])]) : '',
                'testok' => is_array($test) && !empty($test['ok']),
                'events' => $events,
                'eventserror' => $errors['events'] ?? '',
            ],
            'month' => $month,
            'microsoft' => $mailjet ? self::microsoft($saved === 'microsoft', $action('microsoft'), $now) : false,
            'today' => $todayline ? get_string('mail:today', self::C, implode(' · ', $todayline))
                : get_string('mail:today:none', self::C),
            'recent' => $recent,
            'hasrecent' => !empty($recent),
            'bad' => $bad,
            'hasbad' => !empty($bad),
            'badsaved' => $saved === 'bad',
            'badaction' => $action('bad'),
        ];
    }

    /**
     * The card of Microsoft: its way, its warming up, its cap of the day, and the month by lane.
     *
     * @param bool $saved
     * @param string $action
     * @param int $now
     * @return array
     */
    private static function microsoft(bool $saved, string $action, int $now): array {
        global $DB;
        $lanes = ['home' => 0, 'mssite' => 0, 'msmailjet' => 0, 'rest' => 0];
        $rows = $DB->get_records_select(
            mailer::TABLE,
            "status = 'sent' AND timesent >= :start AND way IN ('site', 'mailjet')",
            ['start' => quota::month_start($now)],
            '',
            'id, email, way'
        );
        foreach ($rows as $row) {
            $ms = settings::is_microsoft((string)$row->email);
            $lane = settings::is_home((string)$row->email) ? 'home'
                : ($ms ? ($row->way === 'site' ? 'mssite' : 'msmailjet') : ($row->way === 'site' ? 'home' : 'rest'));
            $lanes[$lane]++;
        }
        $site = settings::microsoft_way() === 'site';
        return [
            'saved' => $saved,
            'action' => $action,
            'site' => $site,
            'domains' => get_string('mail:ms:domains', self::C, implode(', ', settings::microsoft_domains())),
            'warmstepper' => gestures::stepper('mailmswarm', settings::warm(), 500, get_string('mail:ms:warmunit', self::C), 5),
            'capstepper' => gestures::stepper(
                'mailsitecap',
                settings::site_cap(),
                5000,
                get_string('mail:ms:capunit', self::C),
                100
            ),
            'lanes' => array_map(fn($lane, $count) => ['label' => get_string('mail:ms:lane:' . $lane, self::C),
                'count' => self::number($count)], array_keys($lanes), $lanes),
        ];
    }

    /**
     * Where Mailjet tells the events of the account now: here, somewhere else, or nowhere; asked to Mailjet only when the
     * keys open the account.
     *
     * @param array|null $checked
     * @return array here, other (the address it goes to, without its key), none, canset, unknown (Mailjet did not say).
     */
    private static function events_here(?array $checked): array {
        $out = ['here' => false, 'other' => '', 'none' => false, 'canset' => false, 'unknown' => false];
        if (!$checked || $checked['keys'] !== 'ok') {
            return $out;
        }
        $api = mailjet_api::from_config();
        $list = $api ? $api->callbacks() : null;
        if ($list === null) {
            $out['unknown'] = true;
            return $out;
        }
        $mine = settings::hook_url();
        $urls = [];
        foreach ($list as $callback) {
            if (in_array($callback['EventType'] ?? '', mailjet_api::EVENTS, true)) {
                $urls[$callback['EventType']] = (string)($callback['Url'] ?? '');
            }
        }
        $out['here'] = count($urls) === count(mailjet_api::EVENTS) && count(array_unique($urls)) === 1 && reset($urls) === $mine;
        $others = array_filter($urls, fn($url) => $url !== $mine);
        $out['other'] = $others ? preg_replace('/([?&](k|key|token)=)[^&]+/i', '$1…', reset($others)) : '';
        $out['none'] = !$urls;
        $out['canset'] = !$out['here'];
        return $out;
    }

    /**
     * Makes Mailjet tell the events of the account here: bounce, blocked and spam.
     *
     * @return bool Whether Mailjet took the three.
     */
    public static function listen(): bool {
        $api = mailjet_api::from_config();
        $list = $api ? $api->callbacks() : null;
        if ($list === null) {
            return false;
        }
        $ids = [];
        foreach ($list as $callback) {
            $ids[(string)($callback['EventType'] ?? '')] = (int)($callback['ID'] ?? 0);
        }
        $ok = true;
        foreach (mailjet_api::EVENTS as $event) {
            $ok = $api->set_callback($event, settings::hook_url(), $ids[$event] ?? 0) && $ok;
        }
        return $ok;
    }

    /**
     * The name of a plugin, for a person; also of a tool of the house that this site does not have (Oksigenia Meals).
     *
     * @param string $component
     * @return string
     */
    private static function plugin_name(string $component): string {
        if ($component === '') {
            return '';
        }
        $manager = get_string_manager();
        if ($manager->string_exists('pluginname', $component)) {
            return get_string('pluginname', $component);
        }
        if (preg_match('/^[a-z]+_oksigenia([a-z]+)$/', $component, $match)) {
            return 'Oksigenia ' . ucfirst($match[1]);
        }
        return $component;
    }

    /**
     * What happened to a mail, in words, and what the other side said, for whoever wants to look further.
     *
     * @param \stdClass $row
     * @return string[] [words, code]
     */
    private static function what_happened(\stdClass $row): array {
        $error = (string)$row->error;
        if ($row->status === 'failed') {
            foreach (['bounce', 'blocked'] as $event) {
                if (strpos($error, $event . ':') === 0) {
                    return [get_string('mail:bad:' . $event . ':long', self::C), trim(substr($error, strlen($event) + 1))];
                }
            }
            return [get_string('mail:row:failed', self::C), $error];
        }
        if ($row->way === 'site' && strpos($error, 'mailjet:') === 0) {
            return [get_string('mail:row:fellback', self::C), trim(substr($error, 8))];
        }
        return ['', ''];
    }

    /**
     * When something happened, always in the same way: «today at 15:58», «yesterday at 16:08», «on 24 November at 16:08».
     *
     * @param int $time
     * @param int $now
     * @param bool $first Whether it starts a line, with its capital.
     * @return string
     */
    public static function when(int $time, int $now, bool $first = false): string {
        $hour = userdate($time, get_string('strftimetime', 'langconfig'));
        $midnight = usergetmidnight($now);
        if ($time >= $midnight) {
            $said = get_string('mail:when:today', self::C, $hour);
        } else if ($time >= usergetmidnight($midnight - HOURSECS)) {
            $said = get_string('mail:when:yesterday', self::C, $hour);
        } else {
            $said = get_string('mail:when:day', self::C, (object)['day' => userdate(
                $time,
                get_string('strftimedateshort', 'langconfig')
            ), 'time' => $hour]);
        }
        return $first ? \core_text::strtoupper(\core_text::substr($said, 0, 1)) . \core_text::substr($said, 1) : $said;
    }

    /**
     * A whole number with the separator of thousands of the language.
     *
     * @param int $number
     * @return string
     */
    private static function number(int $number): string {
        return number_format($number, 0, get_string('decsep', 'langconfig'), get_string('thousandssep', 'langconfig'));
    }
}
