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
 * The sky over the place of the site, for the channel of Telegram: the evenings the International Space Station passes
 * bright, high and long enough to see it without anything (one a week at most), the full moon with the time it rises, and
 * the nights of the great meteor showers of its hemisphere. Everything is worked out on the server: the station from its
 * elements (sgp4), downloaded once a day; the Sun and the Moon with the formulas of low precision of the Astronomical
 * Almanac, to a few minutes, more than enough to look up.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sky {
    /** The kinds of post. */
    public const ISS = 'sky_iss';

    /** The full moon. */
    public const MOON = 'sky_moon';

    /** The meteor showers. */
    public const METEORS = 'sky_meteors';

    /** Where the elements of the station come from, until the site says another. */
    public const DEFAULTTLE = 'https://celestrak.org/NORAD/elements/gp.php?CATNR=25544&FORMAT=TLE';

    /**
     * The great meteor showers: the night of their maximum (month and day it starts), how many an hour at most, and the
     * hemisphere they are seen from (N, S or both). The maximum moves a day or so from one year to another.
     */
    public const SHOWERS = [
        'quadrantids' => [1, 3, 110, 'N'],
        'etaaquariids' => [5, 5, 50, 'S'],
        'perseids' => [8, 12, 100, 'N'],
        'orionids' => [10, 21, 20, 'both'],
        'geminids' => [12, 13, 150, 'both'],
    ];

    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** The evening a pass may fall in, local hours: when a family can go out to see it. */
    private const EVENING = [18, 23];

    /** How high a pass has to go, degrees, to be worth going out. */
    private const HIGH = 30;

    /** How long it has to be seen, seconds. */
    private const LONG = 3 * MINSECS;

    /** How many days ahead it looks for passes. */
    private const AHEAD = 3;

    /** The least time between two posts of the station. */
    private const APART = 6 * DAYSECS;

    /** When the posts of an evening go, local time. */
    private const POSTHOUR = 17;

    /** The step of the search of passes, seconds. */
    private const STEP = 20;

    /** @var callable|null What answers for the addresses in the tests. */
    private static $fortests = null;

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
     * Prepares the posts of the sky: the next good pass of the station, the full moon and the meteor showers.
     *
     * @param int|null $now The time, for the tests.
     * @return array Counts: iss, moon and meteors.
     */
    public static function prepare(?int $now = null): array {
        $now = $now ?? time();
        $counts = ['iss' => 0, 'moon' => 0, 'meteors' => 0];
        $place = weather::place();
        if (!channel::is_ready() || !$place) {
            return $counts;
        }
        if (channel::policy(self::COMPONENT . '/' . self::ISS) !== 'off') {
            $counts['iss'] = self::prepare_iss($place, $now);
        }
        if (channel::policy(self::COMPONENT . '/' . self::MOON) !== 'off') {
            $counts['moon'] = self::prepare_moon($place, $now);
        }
        if (channel::policy(self::COMPONENT . '/' . self::METEORS) !== 'off') {
            $counts['meteors'] = self::prepare_meteors($place, $now);
        }
        return $counts;
    }

    /**
     * The visible passes of the station over a place: high enough over the horizon, lit by the Sun while the sky of the
     * place is dark.
     *
     * @param sgp4 $station The station.
     * @param array $place Latitude and longitude, degrees.
     * @param int $from From this time.
     * @param int $to Until this one.
     * @return array Each with start and end (of the part that is seen), maxelevation (degrees), from and to (azimuths,
     *               degrees from the north) and peak (the time it is highest).
     */
    public static function passes(sgp4 $station, array $place, int $from, int $to): array {
        $passes = [];
        $pass = null;
        for ($time = $from; $time <= $to; $time += self::STEP) {
            $position = $station->position($time);
            if ($position === null) {
                break;
            }
            $jd = sgp4::julian($time);
            [$azimuth, $elevation] = self::look($position, $jd, $place);
            $seen = $elevation >= 10 && self::sun_altitude($jd, $place) <= -6 && self::sunlit($position, $jd);
            if ($seen) {
                if ($pass === null) {
                    $pass = ['start' => $time, 'from' => $azimuth, 'maxelevation' => $elevation, 'peak' => $time];
                }
                $pass['end'] = $time;
                $pass['to'] = $azimuth;
                if ($elevation > $pass['maxelevation']) {
                    $pass['maxelevation'] = $elevation;
                    $pass['peak'] = $time;
                }
            } else if ($pass !== null) {
                $passes[] = $pass;
                $pass = null;
            }
        }
        if ($pass !== null) {
            $passes[] = $pass;
        }
        return $passes;
    }

    /**
     * The Moon on a night: its phase as the cards draw it (0 new, 2 first quarter, 4 full, 6 last quarter), how much of
     * it is lit and its elongation from the Sun.
     *
     * @param int $time The time.
     * @return array With phase (0 to 7), lit (0 to 100) and elongation (degrees, 0 to 360, growing from the new moon).
     */
    public static function moon_phase(int $time): array {
        $jd = sgp4::julian($time);
        $elongation = fmod(self::moon_ecliptic($jd)[0] - self::sun_longitude($jd) + 720, 360);
        return [
            'phase' => (int)round($elongation / 45) % 8,
            'lit' => (int)round((1 - cos(deg2rad($elongation))) / 2 * 100),
            'elongation' => $elongation,
        ];
    }

    /**
     * The next full moon from a time, to a few minutes.
     *
     * @param int $time The time.
     * @return int
     */
    public static function next_full_moon(int $time): int {
        // Its elongation grows some 12° a day, more or less: a few steps towards 180°, and then halving around it.
        $rate = 12.19 / DAYSECS;
        $guess = $time + fmod(180 - self::moon_phase($time)['elongation'] + 360, 360) / $rate;
        for ($i = 0; $i < 4; $i++) {
            $delta = fmod(180 - self::moon_phase((int)$guess)['elongation'] + 540, 360) - 180;
            $guess += $delta / $rate;
        }
        $low = (int)$guess - 6 * HOURSECS;
        $high = (int)$guess + 6 * HOURSECS;
        while ($high - $low > MINSECS) {
            $middle = intdiv($low + $high, 2);
            if (self::moon_phase($middle)['elongation'] < 180) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }
        return $low < $time ? self::next_full_moon($time + 2 * DAYSECS) : $low;
    }

    /**
     * When the Moon rises over a place, between two times.
     *
     * @param array $place Latitude and longitude, degrees.
     * @param int $from From this time.
     * @param int $to Until this one.
     * @return array|null With time and azimuth (degrees from the north); null if it does not rise then.
     */
    public static function moonrise(array $place, int $from, int $to): ?array {
        $previous = null;
        for ($time = $from; $time <= $to; $time += MINSECS) {
            [$azimuth, $altitude] = self::moon_horizontal(sgp4::julian($time), $place);
            // The centre of the Moon, with its parallax, its radius and the refraction: 0.125° up as seen from the centre.
            if ($previous !== null && $previous < 0.125 && $altitude >= 0.125) {
                return ['time' => $time, 'azimuth' => $azimuth];
            }
            $previous = $altitude;
        }
        return null;
    }

    /**
     * The good passes of the station between two times: in the evening, high and long enough, the earliest first.
     *
     * @param sgp4 $station The station.
     * @param array $place Latitude and longitude.
     * @param int $from From this time.
     * @param int $to Until this one.
     * @return array As passes() gives them.
     */
    public static function good_passes(sgp4 $station, array $place, int $from, int $to): array {
        return array_values(array_filter(self::passes($station, $place, $from, $to), function (array $pass): bool {
            $hour = (int)userdate($pass['start'], '%H', self::tz(), false);
            return $pass['maxelevation'] >= self::HIGH && $pass['end'] - $pass['start'] >= self::LONG
                && $hour >= self::EVENING[0] && $hour < self::EVENING[1];
        }));
    }

    /**
     * The best pass of the station between two times: the highest of the good ones.
     *
     * @param sgp4 $station The station.
     * @param array $place Latitude and longitude.
     * @param int $from From this time.
     * @param int $to Until this one.
     * @return array|null As passes() gives them.
     */
    public static function best_pass(sgp4 $station, array $place, int $from, int $to): ?array {
        $best = null;
        foreach (self::good_passes($station, $place, $from, $to) as $pass) {
            if (!$best || $pass['maxelevation'] > $best['maxelevation']) {
                $best = $pass;
            }
        }
        return $best;
    }

    /**
     * What the page of the channel says of the sky: the next full moon and, with the elements of the station already
     * downloaded, its next good pass this week.
     *
     * @param int $now The time.
     * @return string[] Its lines; none without the place of the site.
     */
    public static function summary(int $now): array {
        $place = weather::place();
        if (!$place) {
            return [];
        }
        $format = get_string('strftimedaydatetime', 'langconfig');
        $lines = [get_string(
            'channel:sky:nextmoon',
            self::COMPONENT,
            userdate(self::next_full_moon($now), $format, self::tz())
        )];
        // The pass it posts: the one already prepared, if any (it ends when the pass starts); or the best of the next days,
        // as prepare_iss() chooses it; or else the first good one of the week.
        global $DB;
        $prepared = $DB->get_field_select(
            channel::TABLE,
            'MIN(timeexpires)',
            "component = :component AND kind = :kind AND status IN ('review', 'ready') AND timeexpires > :now",
            ['component' => self::COMPONENT, 'kind' => self::ISS, 'now' => $now]
        );
        $start = $prepared ? (int)$prepared : null;
        $station = $start ? null : self::station($now, false);
        if ($station) {
            $pass = self::best_pass($station, $place, $now + HOURSECS, $now + self::AHEAD * DAYSECS)
                ?? (self::good_passes($station, $place, $now, $now + 7 * DAYSECS)[0] ?? null);
            $start = $pass['start'] ?? null;
        }
        if ($start) {
            $lines[] = get_string('channel:sky:nextpass', self::COMPONENT, userdate($start, $format, self::tz()));
        }
        return $lines;
    }

    /**
     * The next good pass of the station: the highest in the next days, in the evening; it waits to be reviewed some
     * hours before, and is changed while it waits. One a week at most.
     *
     * @param array $place Latitude and longitude.
     * @param int $now The time.
     * @return int 1 if it prepared one.
     */
    private static function prepare_iss(array $place, int $now): int {
        global $DB;
        $station = self::station($now);
        if (!$station) {
            return 0;
        }
        $best = self::best_pass($station, $place, $now + HOURSECS, $now + self::AHEAD * DAYSECS);
        if (!$best) {
            return 0;
        }
        $key = self::ISS . '-' . userdate($best['start'], '%Y%m%d', self::tz(), false);
        // One a week: not if another evening went, or is going, a few days from this one.
        $others = $DB->record_exists_select(
            channel::TABLE,
            "component = :component AND kind = :kind AND sourcekey <> :key AND status IN ('review', 'ready', 'published', 'skipped')
                AND timeplanned > :from AND timeplanned < :to",
            ['component' => self::COMPONENT, 'kind' => self::ISS, 'key' => $key, 'from' => $best['start'] - self::APART,
                'to' => $best['start'] + self::APART]
        );
        // Said yes already: it stays as it was said, a minute up or down.
        $ready = $DB->record_exists(channel::TABLE, ['component' => self::COMPONENT, 'kind' => self::ISS, 'sourcekey' => $key,
            'status' => 'ready']);
        if ($others || $ready) {
            return 0;
        }
        $moon = self::moon_phase($best['start']);
        $time = userdate($best['start'], get_string('strftimetime24', 'langconfig'), self::tz());
        $minutes = max(1, (int)round(($best['end'] - $best['start']) / MINSECS));
        $detail = get_string('sky:iss:detail', self::COMPONENT, (object)['from' => self::direction($best['from']),
            'to' => self::direction($best['to']), 'minutes' => $minutes]);
        $content = [
            'title' => get_string('sky:iss:title', self::COMPONENT),
            'lines' => [get_string('sky:iss:at', self::COMPONENT, $time), $detail],
            'card' => ['type' => 'sky', 'zones' => [
                'kicker' => get_string('sky:tonight', self::COMPONENT),
                'title' => get_string('sky:iss:cardtitle', self::COMPONENT),
                'time' => $time,
                'detail' => $detail,
                'moon' => ['phase' => $moon['phase']],
                'moon_label' => self::moon_label($moon),
            ]],
        ];
        return (int)(bool)channel::post(
            self::COMPONENT,
            self::ISS,
            $content,
            self::evening($best['start'], $now),
            false,
            $key,
            $best['start'],
            $now
        );
    }

    /**
     * The full moon of tonight, if it is tonight: with the time it rises and where.
     *
     * @param array $place Latitude and longitude.
     * @param int $now The time.
     * @return int 1 if it prepared one.
     */
    private static function prepare_moon(array $place, int $now): int {
        $full = self::next_full_moon($now - DAYSECS);
        // Tonight: the full moon falls between noon today and noon tomorrow, local time.
        $noon = usergetmidnight($now, self::tz()) + 12 * HOURSECS;
        if ($full < $noon || $full >= $noon + DAYSECS || $now >= $noon + 12 * HOURSECS) {
            return 0;
        }
        $rise = self::moonrise($place, $noon, $noon + 12 * HOURSECS);
        if (!$rise) {
            return 0;
        }
        $time = userdate($rise['time'], get_string('strftimetime24', 'langconfig'), self::tz());
        $detail = get_string('sky:moon:detail', self::COMPONENT, (object)['time' => $time,
            'from' => self::direction($rise['azimuth'])]);
        $moon = self::moon_phase($full);
        $content = [
            'title' => get_string('sky:moon:title', self::COMPONENT),
            'lines' => [$detail],
            'card' => ['type' => 'sky', 'zones' => [
                'kicker' => get_string('sky:tonight', self::COMPONENT),
                'title' => get_string('sky:moon:title', self::COMPONENT),
                'time' => $time,
                'detail' => $detail,
                'moon' => ['phase' => 4],
                'moon_label' => self::moon_label($moon),
            ]],
        ];
        return (int)(bool)channel::post(
            self::COMPONENT,
            self::MOON,
            $content,
            self::evening($rise['time'], $now),
            false,
            self::MOON . '-' . userdate($noon, '%Y%m%d', self::tz(), false),
            $noon + 18 * HOURSECS,
            $now
        );
    }

    /**
     * The meteor shower of tonight, if its maximum is tonight and it is seen from the hemisphere of the site.
     *
     * @param array $place Latitude and longitude.
     * @param int $now The time.
     * @return int 1 if it prepared one.
     */
    private static function prepare_meteors(array $place, int $now): int {
        $month = (int)userdate($now, '%m', self::tz(), false);
        $day = (int)userdate($now, '%d', self::tz(), false);
        foreach (self::SHOWERS as $name => [$showermonth, $showerday, $rate, $hemisphere]) {
            if ($month !== $showermonth || $day !== $showerday) {
                continue;
            }
            if (($hemisphere === 'N' && $place[0] < -15) || ($hemisphere === 'S' && $place[0] > 25)) {
                continue;
            }
            $night = usergetmidnight($now, self::tz()) + 22 * HOURSECS;
            $moon = self::moon_phase($night);
            $shower = get_string('sky:shower:' . $name, self::COMPONENT);
            $detail = get_string('sky:meteors:detail', self::COMPONENT, $rate);
            $content = [
                'title' => get_string('sky:meteors:title', self::COMPONENT, $shower),
                'lines' => [$detail, get_string(
                    $moon['lit'] > 60 ? 'sky:meteors:moonbright' : 'sky:meteors:moondark',
                    self::COMPONENT
                )],
                'card' => ['type' => 'sky', 'zones' => [
                    'kicker' => get_string('sky:tonight', self::COMPONENT),
                    'title' => get_string('sky:meteors:title', self::COMPONENT, $shower),
                    'time' => get_string('sky:meteors:rate', self::COMPONENT, $rate),
                    'detail' => $detail,
                    'moon' => ['phase' => $moon['phase']],
                    'moon_label' => self::moon_label($moon),
                ]],
            ];
            $year = userdate($now, '%Y', self::tz(), false);
            return (int)(bool)channel::post(
                self::COMPONENT,
                self::METEORS,
                $content,
                self::evening(usergetmidnight($now, self::tz()) + self::POSTHOUR * HOURSECS, $now),
                false,
                self::METEORS . '-' . $name . '-' . $year,
                usergetmidnight($now, self::tz()) + DAYSECS + 6 * HOURSECS,
                $now
            );
        }
        return 0;
    }

    /**
     * The station, from its elements: downloaded once a day, and kept for the days the download fails.
     *
     * @param int $now The time.
     * @param bool $download Whether to download them if they are a day old.
     * @return sgp4|null Null without elements, or with elements older than a week.
     */
    private static function station(int $now, bool $download = true): ?sgp4 {
        $kept = json_decode((string)get_config(self::COMPONENT, 'skystation'), true);
        if ($download && (!is_array($kept) || ($kept['time'] ?? 0) < $now - DAYSECS)) {
            $url = trim((string)get_config(self::COMPONENT, 'skytle')) ?: self::DEFAULTTLE;
            $lines = self::tle((string)self::fetch($url));
            if ($lines) {
                $kept = ['time' => $now, 'lines' => $lines];
                set_config('skystation', json_encode($kept), self::COMPONENT);
            }
        }
        if (!is_array($kept) || empty($kept['lines'])) {
            return null;
        }
        try {
            $station = new sgp4($kept['lines'][0], $kept['lines'][1]);
        } catch (\moodle_exception $e) {
            return null;
        }
        return $station->epoch < sgp4::julian($now) - 7 ? null : $station;
    }

    /**
     * The two lines of elements of a download, with or without the line of the name.
     *
     * @param string $body The download.
     * @return array|null The two lines.
     */
    private static function tle(string $body): ?array {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $body)), 'strlen'));
        foreach ($lines as $i => $line) {
            if ($line[0] === '1' && isset($lines[$i + 1]) && $lines[$i + 1][0] === '2' && strlen($line) >= 64) {
                return [$line, $lines[$i + 1]];
            }
        }
        return null;
    }

    /**
     * Where a position of the model is seen from a place.
     *
     * @param array $position The position, TEME, km.
     * @param float $jd The Julian date.
     * @param array $place Latitude and longitude, degrees.
     * @return array Azimuth (degrees from the north, to the east) and elevation (degrees).
     */
    private static function look(array $position, float $jd, array $place): array {
        $gmst = sgp4::gmst($jd);
        $x = cos($gmst) * $position[0] + sin($gmst) * $position[1];
        $y = -sin($gmst) * $position[0] + cos($gmst) * $position[1];
        $z = $position[2];
        $lat = deg2rad($place[0]);
        $lon = deg2rad($place[1]);
        // The place on the ellipsoid of WGS-84, at sea level.
        $a = 6378.137;
        $e2 = (1 / 298.257223563) * (2 - 1 / 298.257223563);
        $n = $a / sqrt(1 - $e2 * sin($lat) ** 2);
        $dx = $x - $n * cos($lat) * cos($lon);
        $dy = $y - $n * cos($lat) * sin($lon);
        $dz = $z - $n * (1 - $e2) * sin($lat);
        $east = -sin($lon) * $dx + cos($lon) * $dy;
        $north = -sin($lat) * cos($lon) * $dx - sin($lat) * sin($lon) * $dy + cos($lat) * $dz;
        $up = cos($lat) * cos($lon) * $dx + cos($lat) * sin($lon) * $dy + sin($lat) * $dz;
        $azimuth = fmod(rad2deg(atan2($east, $north)) + 360, 360);
        return [$azimuth, rad2deg(atan2($up, sqrt($east * $east + $north * $north)))];
    }

    /**
     * The ecliptic longitude of the Sun, degrees.
     *
     * @param float $jd The Julian date.
     * @return float
     */
    private static function sun_longitude(float $jd): float {
        $n = $jd - 2451545.0;
        $g = deg2rad(357.528 + 0.9856003 * $n);
        return fmod(280.460 + 0.9856474 * $n + 1.915 * sin($g) + 0.020 * sin(2 * $g), 360);
    }

    /**
     * Where the Sun is in the sky of the Earth, as a unit vector of the equator of the date.
     *
     * @param float $jd The Julian date.
     * @return array
     */
    private static function sun_vector(float $jd): array {
        $lambda = deg2rad(self::sun_longitude($jd));
        $epsilon = deg2rad(23.439 - 0.0000004 * ($jd - 2451545.0));
        return [cos($lambda), cos($epsilon) * sin($lambda), sin($epsilon) * sin($lambda)];
    }

    /**
     * How high the Sun is over a place, degrees.
     *
     * @param float $jd The Julian date.
     * @param array $place Latitude and longitude, degrees.
     * @return float
     */
    private static function sun_altitude(float $jd, array $place): float {
        [$x, $y, $z] = self::sun_vector($jd);
        return self::altitude(atan2($y, $x), asin($z), $jd, $place)[1];
    }

    /**
     * Whether a position of the model is lit by the Sun: out of the cylinder of the shadow of the Earth.
     *
     * @param array $position The position, km.
     * @param float $jd The Julian date.
     * @return bool
     */
    private static function sunlit(array $position, float $jd): bool {
        $sun = self::sun_vector($jd);
        $along = $position[0] * $sun[0] + $position[1] * $sun[1] + $position[2] * $sun[2];
        if ($along >= 0) {
            return true;
        }
        $square = $position[0] ** 2 + $position[1] ** 2 + $position[2] ** 2 - $along * $along;
        return sqrt(max(0, $square)) > 6371;
    }

    /**
     * The ecliptic longitude and latitude of the Moon, degrees.
     *
     * @param float $jd The Julian date.
     * @return array
     */
    private static function moon_ecliptic(float $jd): array {
        $t = ($jd - 2451545.0) / 36525;
        $sin = function (float $degrees): float {
            return sin(deg2rad($degrees));
        };
        $longitude = 218.32 + 481267.881 * $t + 6.29 * $sin(135.0 + 477198.87 * $t) - 1.27 * $sin(259.3 - 413335.36 * $t)
            + 0.66 * $sin(235.7 + 890534.22 * $t) + 0.21 * $sin(269.9 + 954397.74 * $t) - 0.19 * $sin(357.5 + 35999.05 * $t)
            - 0.11 * $sin(186.5 + 966404.03 * $t);
        $latitude = 5.13 * $sin(93.3 + 483202.03 * $t) + 0.28 * $sin(228.2 + 960400.87 * $t)
            - 0.28 * $sin(318.3 + 6003.18 * $t) - 0.17 * $sin(217.6 - 407332.20 * $t);
        return [fmod(fmod($longitude, 360) + 360, 360), $latitude];
    }

    /**
     * Where the Moon is in the sky of a place, as seen from the centre of the Earth.
     *
     * @param float $jd The Julian date.
     * @param array $place Latitude and longitude, degrees.
     * @return array Azimuth and altitude, degrees.
     */
    private static function moon_horizontal(float $jd, array $place): array {
        [$longitude, $latitude] = array_map('deg2rad', self::moon_ecliptic($jd));
        $epsilon = deg2rad(23.439 - 0.0000004 * ($jd - 2451545.0));
        $x = cos($latitude) * cos($longitude);
        $y = cos($epsilon) * cos($latitude) * sin($longitude) - sin($epsilon) * sin($latitude);
        $z = sin($epsilon) * cos($latitude) * sin($longitude) + cos($epsilon) * sin($latitude);
        return self::altitude(atan2($y, $x), asin($z), $jd, $place);
    }

    /**
     * Where a point of the sky is seen from a place.
     *
     * @param float $ra Its right ascension, radians.
     * @param float $dec Its declination, radians.
     * @param float $jd The Julian date.
     * @param array $place Latitude and longitude, degrees.
     * @return array Azimuth (degrees from the north, to the east) and altitude (degrees).
     */
    private static function altitude(float $ra, float $dec, float $jd, array $place): array {
        $lat = deg2rad($place[0]);
        $hour = sgp4::gmst($jd) + deg2rad($place[1]) - $ra;
        $altitude = asin(sin($lat) * sin($dec) + cos($lat) * cos($dec) * cos($hour));
        $azimuth = atan2(-cos($dec) * sin($hour), cos($lat) * sin($dec) - sin($lat) * cos($dec) * cos($hour));
        return [fmod(rad2deg($azimuth) + 360, 360), rad2deg($altitude)];
    }

    /**
     * The point of the compass of an azimuth, as it is said: «el noreste».
     *
     * @param float $azimuth Degrees from the north.
     * @return string
     */
    private static function direction(float $azimuth): string {
        return get_string('sky:dir' . ((int)round($azimuth / 45) % 8), self::COMPONENT);
    }

    /**
     * The name of a phase of the Moon and how much of it is lit: «Creciente · 62 %».
     *
     * @param array $moon As moon_phase() gives it.
     * @return string
     */
    private static function moon_label(array $moon): string {
        return get_string('sky:moon' . $moon['phase'], self::COMPONENT) . ' · ' . $moon['lit'] . ' %';
    }

    /**
     * When a post of an evening goes: that day at five in the afternoon, or now if it is later; never after the thing.
     *
     * @param int $time The time of the thing.
     * @param int $now Now.
     * @return int
     */
    private static function evening(int $time, int $now): int {
        $at = usergetmidnight($time, self::tz()) + self::POSTHOUR * HOURSECS;
        return max($now, min($at, $time - HOURSECS));
    }

    /**
     * The time zone of the site: the posts are for the place of the site, whoever prepares them.
     *
     * @return string
     */
    private static function tz(): string {
        return \core_date::get_server_timezone();
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
        $curl->setHeader(['User-Agent: Moodle local_oksigeniamarandu (' . $CFG->wwwroot . ')']);
        $curl->setopt(['CURLOPT_FOLLOWLOCATION' => 1, 'CURLOPT_MAXREDIRS' => 5, 'CURLOPT_TIMEOUT' => 20]);
        $body = $curl->get($url);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code !== 200) {
            debugging('Marandu: the elements of the station did not come: ' . s($url), DEBUG_DEVELOPER);
            return null;
        }
        return (string)$body;
    }
}
