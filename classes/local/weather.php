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
 * The official weather warnings of the place of the site, for the channel of Telegram: from any source in the Common
 * Alerting Protocol (CAP 1.1 or 1.2), as a feed (Atom or RSS, such as those of MeteoAlarm or of the National Weather
 * Service) or as one alert. Each warning of the area of the site, from the level it says, goes to review: the severe and
 * the extreme as urgent, at any hour. A warning that is withdrawn before it goes out does not go.
 *
 * The area is that of the warnings, by their name or their code (EMMA_ID, SAME…), or the place of the site inside their
 * outline, when they bring it.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class weather {
    /** The kind of post. */
    public const KIND = 'weather';

    /** The levels of CAP, from the lowest. */
    public const SEVERITIES = ['Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4];

    /** The levels the site may choose from. */
    public const CHOICES = ['Moderate', 'Severe', 'Extreme'];

    /** From this level, a warning is urgent. */
    private const URGENT = 3;

    /** The level until the site says another. */
    public const DEFAULTSEVERITY = 'Severe';

    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** How many whole alerts it reads in a run, for the languages a feed does not bring. */
    private const MAXFETCH = 5;

    /** The longest description and instruction it keeps. */
    private const MAXTEXT = 700;

    /** @var callable|null What answers for the addresses in the tests. */
    private static $fortests = null;

    /**
     * Whether the site has a source of warnings.
     *
     * @return bool
     */
    public static function is_set(): bool {
        return self::$fortests !== null || trim((string)get_config(self::COMPONENT, 'weatherfeed')) !== '';
    }

    /**
     * What answers for the addresses while the tests run; null to forget it.
     *
     * @param callable|null $fetch Takes an address, gives its body or null.
     */
    public static function fake_for_tests(?callable $fetch): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Only for the tests');
        }
        self::$fortests = $fetch;
    }

    /**
     * Reads the source and prepares the warnings of the area of the site: those whose times touch go together, as one
     * episode, in one post, under the most severe. Takes back what is still waiting and the source no longer brings, and
     * what the source cancels, also from the channel.
     *
     * @param int|null $now The time, for the tests.
     * @return array Counts: source (warnings in it), area (of the site), posted (episodes) and withdrawn.
     */
    public static function prepare(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['source' => 0, 'area' => 0, 'posted' => 0, 'withdrawn' => 0];
        if (!channel::is_ready() || !self::is_set()) {
            return $counts;
        }
        $feed = trim((string)get_config(self::COMPONENT, 'weatherfeed'));
        $body = self::fetch($feed);
        if ($body === null) {
            self::status($now, null, null);
            return $counts;
        }
        $alerts = self::parse($body);
        // A feed whose items only link to their alerts: those are read, a few in each run.
        $fetched = 0;
        foreach ($alerts as $i => $alert) {
            if (!$alert['infos'] && $alert['cap'] !== null && $fetched < self::MAXFETCH) {
                $fetched++;
                $alerts[$i] = self::whole($alert) ?? $alert;
            }
        }
        $counts['source'] = count($alerts);

        $config = get_config(self::COMPONENT);
        $min = self::SEVERITIES[(string)($config->weatherseverity ?? self::DEFAULTSEVERITY)] ?? self::URGENT;
        $place = self::place();
        $areas = self::areas((string)($config->weatherareas ?? ''));
        $items = [];
        $cancelled = [];
        foreach ($alerts as $alert) {
            if (strcasecmp($alert['status'], 'Actual') !== 0) {
                continue;
            }
            if (strcasecmp($alert['msgtype'], 'Cancel') === 0) {
                array_push($cancelled, ...$alert['references']);
                continue;
            }
            $infos = array_values(array_filter($alert['infos'], function (array $info) use ($min, $now, $place, $areas) {
                return (self::SEVERITIES[$info['severity']] ?? 0) >= $min && ($info['expires'] ?? PHP_INT_MAX) > $now
                    && self::in_area($info, $place, $areas);
            }));
            if (!$infos) {
                continue;
            }
            $counts['area']++;
            // A feed that brings each warning short, or in one language: the whole alert, with its description, its
            // source and its link, in the language of the site.
            $inlanguage = self::language($infos);
            $short = $inlanguage ?? $infos[0];
            $partial = !$inlanguage || $short['description'] === '' || $short['sender'] === '';
            if ($alert['cap'] !== null && $partial && $fetched < self::MAXFETCH) {
                $fetched++;
                $whole = self::whole($alert);
                if ($whole) {
                    $more = array_values(array_filter($whole['infos'], function (array $info) use ($place, $areas) {
                        return self::in_area($info, $place, $areas);
                    }));
                    $infos = $more ?: $infos;
                }
            }
            $info = self::language($infos) ?? $infos[0];
            $from = $info['onset'] ?? $info['effective'] ?? $now;
            $items[] = ['id' => $alert['identifier'], 'info' => $info, 'rank' => self::SEVERITIES[$info['severity']] ?? 0,
                'from' => $from, 'to' => $info['expires'] ?? $from + DAYSECS];
        }

        // What the source cancels, wherever it is: in the channel too.
        $map = json_decode((string)get_config(self::COMPONENT, 'weathermap'), true) ?: [];
        foreach ($map as $key => $identifiers) {
            if (array_intersect($cancelled, (array)$identifiers)) {
                $counts['withdrawn'] += channel::cancel(self::COMPONENT, self::KIND, (string)$key);
            }
        }

        // The episodes: warnings whose times touch, the earliest first.
        usort($items, function (array $a, array $b): int {
            return [$a['from'], -$a['rank'], $a['id']] <=> [$b['from'], -$b['rank'], $b['id']];
        });
        $episodes = [];
        foreach ($items as $item) {
            $last = count($episodes) - 1;
            if ($last >= 0 && $item['from'] <= $episodes[$last]['to'] + MINSECS) {
                $episodes[$last]['items'][] = $item;
                $episodes[$last]['to'] = max($episodes[$last]['to'], $item['to']);
            } else {
                $episodes[] = ['items' => [$item], 'to' => $item['to']];
            }
        }
        $keep = [];
        foreach ($episodes as $episode) {
            $identifiers = array_column($episode['items'], 'id');
            sort($identifiers);
            // Another warning in the episode makes it another post: so a change after it went out goes out too.
            $key = self::key(implode(' ', $identifiers));
            $keep[$key] = $identifiers;
            $rank = max(array_column($episode['items'], 'rank'));
            $counts['posted'] += (int)(bool)channel::post(
                self::COMPONENT,
                self::KIND,
                self::content($episode['items']),
                null,
                $rank >= self::URGENT,
                $key,
                $episode['to'],
                $now
            );
        }

        // What it no longer brings, if it has not gone out, does not go.
        $waiting = $DB->get_fieldset_select(
            channel::TABLE,
            'sourcekey',
            "component = :component AND kind = :kind AND status IN ('review', 'ready')",
            ['component' => self::COMPONENT, 'kind' => self::KIND]
        );
        foreach ($waiting as $key) {
            if (!isset($keep[$key])) {
                $counts['withdrawn'] += channel::cancel(self::COMPONENT, self::KIND, $key, true);
            }
        }
        // Which warnings each post carries, while it is waiting or in the channel and still on: for what is cancelled.
        $live = $DB->get_fieldset_select(
            channel::TABLE,
            'sourcekey',
            "component = :component AND kind = :kind AND status = 'published' AND timeexpires > :now",
            ['component' => self::COMPONENT, 'kind' => self::KIND, 'now' => $now]
        );
        set_config('weathermap', json_encode($keep + array_intersect_key($map, array_flip($live))), self::COMPONENT);
        self::status($now, $counts['source'], $counts['area']);
        return $counts;
    }

    /**
     * What the last reading said, for the page of the channel.
     *
     * @return \stdClass|null With time, source and area (null if the source did not answer); null if it never read.
     */
    public static function last(): ?\stdClass {
        $status = json_decode((string)get_config(self::COMPONENT, 'weatherstatus'));
        return is_object($status) ? $status : null;
    }

    /**
     * The warnings of a source: a feed (Atom or RSS) or one alert.
     *
     * @param string $body The source.
     * @return array Each with identifier, status, msgtype, references (identifiers), infos and cap (the address of the
     *               whole alert, or null).
     */
    public static function parse(string $body): array {
        $xml = self::xml($body);
        if (!$xml) {
            return [];
        }
        $root = strtolower($xml->getName());
        if ($root === 'alert') {
            return [self::alert($xml)];
        }
        $alerts = [];
        if ($root === 'feed') {
            // In the namespace of the feed, as SimpleXML reads it: so the attributes of the links are read too.
            foreach ($xml->entry as $entry) {
                $alert = self::entry($entry, $xml);
                if ($alert) {
                    $alerts[$alert['identifier']] = $alert;
                }
            }
        } else if ($root === 'rss') {
            foreach ($xml->channel->item ?? [] as $item) {
                $link = trim((string)$item->link);
                foreach ($item->enclosure ?? [] as $enclosure) {
                    $link = trim((string)$enclosure['url']) ?: $link;
                }
                $id = trim((string)$item->guid) ?: $link;
                if (preg_match('~^https?://~i', $link) && $id !== '') {
                    $alerts[$id] = ['identifier' => $id, 'status' => 'Actual', 'msgtype' => 'Alert', 'references' => [],
                        'infos' => [], 'cap' => $link];
                }
            }
        }
        return array_values($alerts);
    }

    /**
     * Whether a point is inside an outline of CAP.
     *
     * @param float $lat The latitude of the point.
     * @param float $lon Its longitude.
     * @param array $polygon The outline, as pairs of latitude and longitude.
     * @return bool
     */
    public static function inside(float $lat, float $lon, array $polygon): bool {
        $inside = false;
        $count = count($polygon);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$lati, $loni] = $polygon[$i];
            [$latj, $lonj] = $polygon[$j];
            if (($loni > $lon) !== ($lonj > $lon) && $lat < ($latj - $lati) * ($lon - $loni) / ($lonj - $loni) + $lati) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /**
     * The place of the site, if it says it.
     *
     * @return array|null Latitude and longitude.
     */
    public static function place(): ?array {
        $lat = get_config(self::COMPONENT, 'latitude');
        $lon = get_config(self::COMPONENT, 'longitude');
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }
        return [(float)$lat, (float)$lon];
    }

    /**
     * The post of an episode: under the most severe of its warnings, with the times of each, its areas, its source and
     * its link.
     *
     * @param array $items Its warnings, the earliest first, each with info and rank.
     * @return array
     */
    private static function content(array $items): array {
        $main = $items[0];
        foreach ($items as $item) {
            if ($item['rank'] > $main['rank']) {
                $main = $item;
            }
        }
        $info = $main['info'];
        $format = get_string('weather:timeformat', self::COMPONENT);
        $when = function (array $info) use ($format): ?\stdClass {
            $from = $info['onset'] ?? $info['effective'] ?? null;
            return $from === null || !isset($info['expires']) ? null
                : (object)['from' => userdate($from, $format), 'to' => userdate($info['expires'], $format)];
        };
        $lines = [];
        $paragraphs = [];
        if (count($items) === 1) {
            if ($times = $when($info)) {
                $lines[] = get_string('weather:when', self::COMPONENT, $times);
            } else if (isset($info['expires'])) {
                $lines[] = get_string('weather:until', self::COMPONENT, userdate($info['expires'], $format));
            }
            $paragraphs[] = $info['description'];
        } else {
            foreach ($items as $item) {
                $times = $when($item['info']);
                $lines[] = $times ? get_string('weather:item', self::COMPONENT, (object)['event' => $item['info']['event'],
                    'from' => $times->from, 'to' => $times->to]) : $item['info']['event'];
                $paragraphs[] = $item['info']['description'];
            }
        }
        $names = [];
        foreach ($items as $item) {
            array_push($names, ...array_column($item['info']['areas'], 'desc'));
        }
        $names = array_values(array_unique(array_filter($names)));
        if ($names) {
            $lines[] = get_string('weather:area', self::COMPONENT, implode(', ', $names));
        }
        if ($info['sender'] !== '') {
            $lines[] = get_string('weather:source', self::COMPONENT, $info['sender']);
        }
        $paragraphs[] = $info['instruction'];
        $text = array_values(array_unique(array_filter(array_map([self::class, 'cut'], $paragraphs))));
        $content = [
            'title' => $info['headline'] !== '' ? $info['headline'] : $info['event'],
            'lines' => $lines,
        ];
        if ($text) {
            $content['text'] = implode("\n\n", $text);
        }
        if (preg_match('~^https?://\S+$~i', $info['web'])) {
            $content += ['url' => $info['web'], 'label' => get_string('weather:more', self::COMPONENT), 'utm' => false];
        }
        return $content;
    }

    /**
     * Whether a warning is for the area of the site: by the name or the code of one of its areas, or by the place of
     * the site inside its outline.
     *
     * @param array $info The warning.
     * @param array|null $place The place of the site.
     * @param array $areas The names and codes the site said, without accents and in small letters.
     * @return bool
     */
    private static function in_area(array $info, ?array $place, array $areas): bool {
        foreach ($info['areas'] as $area) {
            $desc = self::simple($area['desc']);
            foreach ($areas as $wanted) {
                if (in_array($wanted, $area['codes'], true) || ($desc !== '' && strpos($desc, $wanted) !== false)) {
                    return true;
                }
            }
            if ($place) {
                foreach ($area['polygons'] as $polygon) {
                    if (self::inside($place[0], $place[1], $polygon)) {
                        return true;
                    }
                }
                foreach ($area['circles'] as [$lat, $lon, $km]) {
                    if (self::distance($place[0], $place[1], $lat, $lon) <= $km) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * The warning in the language of the site, if it brings it.
     *
     * @param array $infos The warning in its languages.
     * @return array|null
     */
    private static function language(array $infos): ?array {
        global $CFG;
        $lang = strtolower(substr((string)($CFG->lang ?? 'en'), 0, 2));
        foreach ($infos as $info) {
            if (strtolower(substr($info['language'], 0, 2)) === $lang) {
                return $info;
            }
        }
        return null;
    }

    /**
     * The whole alert of an item of a feed, kept for a day.
     *
     * @param array $alert The item, with the address of its alert.
     * @return array|null Null if it did not come.
     */
    private static function whole(array $alert): ?array {
        $cache = \cache::make(self::COMPONENT, 'weather');
        $key = sha1($alert['cap']);
        $kept = $cache->get($key);
        if (is_array($kept)) {
            return $kept;
        }
        $body = self::fetch($alert['cap']);
        $parsed = $body === null ? [] : self::parse($body);
        if (!$parsed || !$parsed[0]['infos']) {
            return null;
        }
        $whole = $parsed[0];
        $whole['cap'] = $alert['cap'];
        // The identifier of the item is the one the feed knows it by.
        $whole['identifier'] = $alert['identifier'];
        $cache->set($key, $whole);
        return $whole;
    }

    /**
     * An alert of CAP.
     *
     * @param \SimpleXMLElement $xml The alert.
     * @return array
     */
    private static function alert(\SimpleXMLElement $xml): array {
        $ns = self::ns($xml);
        $cap = $ns === null ? $xml : $xml->children($ns);
        $infos = [];
        foreach ($cap->info as $info) {
            $areas = [];
            foreach ($info->area as $area) {
                $areas[] = self::area(
                    (string)$area->areaDesc,
                    iterator_to_array($area->polygon, false),
                    iterator_to_array($area->circle, false),
                    iterator_to_array($area->geocode, false),
                    $ns
                );
            }
            $infos[] = self::info($info, $areas);
        }
        return [
            'identifier' => trim((string)$cap->identifier),
            'status' => trim((string)$cap->status),
            'msgtype' => trim((string)$cap->msgType),
            'references' => self::references((string)$cap->references),
            'infos' => $infos,
            'cap' => null,
        ];
    }

    /**
     * An entry of an Atom feed of CAP: with the fields of CAP inside it (MeteoAlarm, the National Weather Service), and
     * the address of its whole alert; or with that address only.
     *
     * @param \SimpleXMLElement $entry The entry.
     * @param \SimpleXMLElement $feed The feed, for its namespaces.
     * @return array|null Null if it is not a warning.
     */
    private static function entry(\SimpleXMLElement $entry, \SimpleXMLElement $feed): ?array {
        $capurl = null;
        foreach ($entry->link as $link) {
            $href = trim((string)$link['href']);
            if (stripos((string)$link['type'], 'cap') !== false || preg_match('~\.cap(\?|$)~i', $href)) {
                $capurl = $href;
            }
        }
        $ns = self::ns($feed);
        $cap = $ns === null ? null : $entry->children($ns);
        $identifier = $cap ? trim((string)$cap->identifier) : '';
        if ($identifier === '') {
            // The id of the entry; an address ends with the identifier of its alert, as the National Weather Service
            // writes it, and that is the one other alerts refer to.
            $identifier = trim((string)$entry->id);
            $path = (string)parse_url($identifier, PHP_URL_PATH);
            if (preg_match('~^https?://~i', $identifier) && ($last = urldecode(basename($path))) !== '') {
                $identifier = $last;
            }
        }
        if ($identifier === '') {
            return null;
        }
        $infos = [];
        if ($cap && trim((string)$cap->event) !== '') {
            $area = self::area(
                (string)$cap->areaDesc,
                iterator_to_array($cap->polygon, false),
                iterator_to_array($cap->circle, false),
                iterator_to_array($cap->geocode, false),
                $ns
            );
            $info = self::info($cap, [$area]);
            $info['headline'] = $info['headline'] !== '' ? $info['headline'] : trim((string)$entry->title);
            $info['description'] = $info['description'] !== '' ? $info['description'] : trim((string)$entry->summary);
            $infos[] = $info;
        }
        if (!$infos && $capurl === null) {
            return null;
        }
        return [
            'identifier' => $identifier,
            'status' => $cap ? (trim((string)$cap->status) ?: 'Actual') : 'Actual',
            'msgtype' => $cap ? (trim((string)$cap->msgType) ?: trim((string)$cap->message_type) ?: 'Alert') : 'Alert',
            'references' => $cap ? self::references((string)$cap->references) : [],
            'infos' => $infos,
            'cap' => $capurl,
        ];
    }

    /**
     * The fields of a warning.
     *
     * @param \SimpleXMLElement $info The info of an alert, or the fields of CAP of an entry.
     * @param array $areas Its areas.
     * @return array
     */
    private static function info(\SimpleXMLElement $info, array $areas): array {
        $time = function (string $value): ?int {
            $value = trim($value);
            $time = $value === '' ? false : strtotime($value);
            return $time === false ? null : $time;
        };
        return [
            'language' => trim((string)$info->language) ?: 'en',
            'event' => self::plain((string)$info->event),
            'severity' => ucfirst(strtolower(trim((string)$info->severity))),
            'effective' => $time((string)$info->effective),
            'onset' => $time((string)$info->onset),
            'expires' => $time((string)$info->expires),
            'sender' => self::plain((string)$info->senderName),
            'headline' => self::plain((string)$info->headline),
            'description' => self::plain((string)$info->description),
            'instruction' => self::plain((string)$info->instruction),
            'web' => trim((string)$info->web),
            'areas' => $areas,
        ];
    }

    /**
     * An area of a warning.
     *
     * @param string $desc Its name.
     * @param array $polygons Its outlines, as CAP writes them.
     * @param array $circles Its circles, as CAP writes them.
     * @param array $geocodes Its codes.
     * @param string|null $ns The namespace of CAP.
     * @return array With desc, polygons (pairs of latitude and longitude), circles (latitude, longitude and radius in
     *               kilometres) and codes (in small letters).
     */
    private static function area(string $desc, array $polygons, array $circles, array $geocodes, ?string $ns): array {
        $outlines = [];
        foreach ($polygons as $polygon) {
            $points = [];
            foreach (preg_split('/\s+/', trim((string)$polygon)) as $pair) {
                $parts = explode(',', $pair);
                if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    $points[] = [(float)$parts[0], (float)$parts[1]];
                }
            }
            if (count($points) >= 3) {
                $outlines[] = $points;
            }
        }
        $rounds = [];
        foreach ($circles as $circle) {
            if (preg_match('/^\s*(-?[\d.]+),(-?[\d.]+)\s+([\d.]+)\s*$/', (string)$circle, $parts)) {
                $rounds[] = [(float)$parts[1], (float)$parts[2], (float)$parts[3]];
            }
        }
        $codes = [];
        foreach ($geocodes as $geocode) {
            // The value comes inside the namespace of CAP, or without one, as MeteoAlarm writes it.
            foreach ([$geocode->children(), $ns === null ? null : $geocode->children($ns)] as $children) {
                if ($children !== null && trim((string)$children->value) !== '') {
                    $codes[] = strtolower(trim((string)$children->value));
                }
            }
        }
        return ['desc' => self::plain($desc), 'polygons' => $outlines, 'circles' => $rounds,
            'codes' => array_values(array_unique($codes))];
    }

    /**
     * The identifiers of the alerts an alert refers to: «sender,identifier,sent», separated by spaces.
     *
     * @param string $references As CAP writes them.
     * @return string[]
     */
    private static function references(string $references): array {
        $list = [];
        foreach (preg_split('/\s+/', trim($references), -1, PREG_SPLIT_NO_EMPTY) as $reference) {
            $parts = explode(',', $reference);
            if (count($parts) >= 2 && trim($parts[1]) !== '') {
                $list[] = trim($parts[1]);
            }
        }
        return $list;
    }

    /**
     * The namespace of CAP of a document, if it has one.
     *
     * @param \SimpleXMLElement $xml The document.
     * @return string|null
     */
    private static function ns(\SimpleXMLElement $xml): ?string {
        foreach ($xml->getDocNamespaces(true) as $uri) {
            if (strpos($uri, 'urn:oasis:names:tc:emergency:cap:') === 0) {
                return $uri;
            }
        }
        return null;
    }

    /**
     * A document, read without going anywhere for what it names.
     *
     * @param string $body The document.
     * @return \SimpleXMLElement|null
     */
    private static function xml(string $body): ?\SimpleXMLElement {
        if (stripos($body, '<!DOCTYPE') !== false) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $xml === false ? null : $xml;
    }

    /**
     * The names and codes the site said, without accents and in small letters.
     *
     * @param string $setting As the site wrote them, separated by commas, semicolons or lines.
     * @return string[]
     */
    private static function areas(string $setting): array {
        return array_values(array_filter(array_map(
            [self::class, 'simple'],
            preg_split('/[,;\n]+/', $setting)
        ), 'strlen'));
    }

    /**
     * A text without accents, in small letters and with single spaces, to compare.
     *
     * @param string $text The text.
     * @return string
     */
    private static function simple(string $text): string {
        $text = \core_text::strtolower(\core_text::specialtoascii(trim($text)));
        return (string)preg_replace('/\s+/', ' ', $text);
    }

    /**
     * A text of a warning, without markup and with single spaces inside each paragraph.
     *
     * @param string $text The text.
     * @return string
     */
    private static function plain(string $text): string {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $paragraphs = preg_split('/\n\s*\n/', str_replace("\r", '', trim($text)));
        return implode("\n\n", array_map(function (string $paragraph): string {
            return trim((string)preg_replace('/\s+/', ' ', $paragraph));
        }, $paragraphs));
    }

    /**
     * A long text, cut at a word.
     *
     * @param string $text The text.
     * @return string
     */
    private static function cut(string $text): string {
        return \core_text::strlen($text) > self::MAXTEXT ? shorten_text($text, self::MAXTEXT, true) : $text;
    }

    /**
     * The distance between two points, in kilometres.
     *
     * @param float $lat1 The latitude of one.
     * @param float $lon1 Its longitude.
     * @param float $lat2 The latitude of the other.
     * @param float $lon2 Its longitude.
     * @return float
     */
    private static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * The key of the post of an alert.
     *
     * @param string $identifier The identifier of the alert.
     * @return string
     */
    private static function key(string $identifier): string {
        return self::KIND . '-' . substr(sha1($identifier), 0, 32);
    }

    /**
     * Keeps what the last reading said.
     *
     * @param int $now The time.
     * @param int|null $source The warnings in the source; null if it did not answer.
     * @param int|null $area Those of the area of the site.
     */
    private static function status(int $now, ?int $source, ?int $area): void {
        set_config('weatherstatus', json_encode(['time' => $now, 'source' => $source, 'area' => $area]), self::COMPONENT);
    }

    /**
     * Downloads an address.
     *
     * @param string $url The address.
     * @return string|null Null if it did not come.
     */
    private static function fetch(string $url): ?string {
        global $CFG;
        if (self::$fortests !== null) {
            $body = (self::$fortests)($url);
            return $body === null ? null : (string)$body;
        }
        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => true]);
        // Without a list of types: some sources answer 406 to one that does not name theirs as they write it.
        $curl->setHeader(['User-Agent: Moodle local_oksigeniamarandu (' . $CFG->wwwroot . ')']);
        $curl->setopt(['CURLOPT_FOLLOWLOCATION' => 1, 'CURLOPT_MAXREDIRS' => 5, 'CURLOPT_TIMEOUT' => 20]);
        $body = $curl->get($url);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code !== 200 || trim((string)$body) === '') {
            debugging('Marandu: the weather warnings did not come: ' . s($url), DEBUG_DEVELOPER);
            return null;
        }
        return (string)$body;
    }
}
