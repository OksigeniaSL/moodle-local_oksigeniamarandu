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
 * Where a satellite near the Earth is, from its two-line elements: the SGP4 model of the North American Aerospace Defense
 * Command, as Vallado, Crawford, Hujsak and Kelso published it in «Revisiting Spacetrack Report #3» (2006), with the
 * constants of WGS-72. Only satellites with an orbit of less than 225 minutes, such as the International Space Station:
 * those higher need the deep-space part, which this does not have.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sgp4 {
    /** The gravitational parameter of the Earth, km³/s². */
    private const MU = 398600.8;

    /** The radius of the Earth, km. */
    public const RADIUS = 6378.135;

    /** The second zonal harmonics of the Earth. */
    private const J2 = 0.001082616;

    /** The third. */
    private const J3 = -0.00000253881;

    /** The fourth. */
    private const J4 = -0.00000165597;

    /** @var array The elements and what the model works out from them once. */
    private $s = [];

    /** @var float The Julian date of the elements. */
    public $epoch;

    /**
     * The satellite of two lines of elements.
     *
     * @param string $line1 The first line.
     * @param string $line2 The second line.
     * @throws \moodle_exception If they are not two lines of elements of a satellite near the Earth.
     */
    public function __construct(string $line1, string $line2) {
        $line1 = rtrim($line1);
        $line2 = rtrim($line2);
        if (strlen($line1) < 64 || strlen($line2) < 63 || $line1[0] !== '1' || $line2[0] !== '2') {
            throw new \moodle_exception('sky:badtle', 'local_oksigeniamarandu');
        }
        $year = (int)substr($line1, 18, 2);
        $days = (float)substr($line1, 20, 12);
        $year += $year < 57 ? 2000 : 1900;
        $this->epoch = self::julian(gmmktime(0, 0, 0, 1, 1, $year)) - 1 + $days;
        $bstar = self::exponent(substr($line1, 53, 8));
        $deg = M_PI / 180;
        $inclo = (float)substr($line2, 8, 8) * $deg;
        $nodeo = (float)substr($line2, 17, 8) * $deg;
        $ecco = (float)('0.' . trim(substr($line2, 26, 7)));
        $argpo = (float)substr($line2, 34, 8) * $deg;
        $mo = (float)substr($line2, 43, 8) * $deg;
        $nokozai = (float)substr($line2, 52, 11) / (1440 / (2 * M_PI));
        if ($nokozai <= 0 || 2 * M_PI / $nokozai >= 225) {
            throw new \moodle_exception('sky:badtle', 'local_oksigeniamarandu');
        }
        $this->init($bstar, $ecco, $argpo, $inclo, $mo, $nokozai, $nodeo);
    }

    /**
     * Where the satellite is at a time, in the TEME frame of the model.
     *
     * @param int|float $time The time, in seconds since 1970.
     * @return array|null Its position, km, as x, y and z; null if the model cannot say (it fell, or its orbit broke).
     */
    public function position($time): ?array {
        return $this->propagate((self::julian($time) - $this->epoch) * 1440);
    }

    /**
     * The Julian date of a time.
     *
     * @param int|float $time The time, in seconds since 1970.
     * @return float
     */
    public static function julian($time): float {
        return $time / 86400 + 2440587.5;
    }

    /**
     * The Greenwich mean sidereal time of a Julian date.
     *
     * @param float $jd The date.
     * @return float In radians, from 0 to 2π.
     */
    public static function gmst(float $jd): float {
        $t = ($jd - 2451545.0) / 36525;
        $seconds = -6.2e-6 * $t ** 3 + 0.093104 * $t * $t + (876600 * 3600 + 8640184.812866) * $t + 67310.54841;
        $angle = fmod($seconds * M_PI / 180 / 240, 2 * M_PI);
        return $angle < 0 ? $angle + 2 * M_PI : $angle;
    }

    /**
     * A number of the elements written with an implied point and exponent, such as 23266-3 (0.23266e-3).
     *
     * @param string $field The field.
     * @return float
     */
    private static function exponent(string $field): float {
        $field = trim($field);
        if (!preg_match('/^([+-]?)(\d+)([+-]\d)$/', $field, $parts)) {
            return 0.0;
        }
        return (float)($parts[1] . '0.' . $parts[2]) * 10 ** (int)$parts[3];
    }

    /**
     * What the model works out once from the elements.
     *
     * @param float $bstar The drag term.
     * @param float $ecco The eccentricity.
     * @param float $argpo The argument of perigee, radians.
     * @param float $inclo The inclination, radians.
     * @param float $mo The mean anomaly, radians.
     * @param float $nokozai The mean motion, radians a minute, as the elements give it.
     * @param float $nodeo The right ascension of the ascending node, radians.
     */
    private function init(float $bstar, float $ecco, float $argpo, float $inclo, float $mo, float $nokozai, float $nodeo): void {
        $xke = 60 / sqrt(self::RADIUS ** 3 / self::MU);
        $j2 = self::J2;
        $j3oj2 = self::J3 / self::J2;
        $x2o3 = 2 / 3;
        $ss = 78 / self::RADIUS + 1;
        $qzms2t = ((120 - 78) / self::RADIUS) ** 4;

        $eccsq = $ecco * $ecco;
        $omeosq = 1 - $eccsq;
        $rteosq = sqrt($omeosq);
        $cosio = cos($inclo);
        $cosio2 = $cosio * $cosio;
        $ak = ($xke / $nokozai) ** $x2o3;
        $d1 = 0.75 * $j2 * (3 * $cosio2 - 1) / ($rteosq * $omeosq);
        $del = $d1 / ($ak * $ak);
        $adel = $ak * (1 - $del * $del - $del * (1 / 3 + 134 * $del * $del / 81));
        $del = $d1 / ($adel * $adel);
        $no = $nokozai / (1 + $del);
        $ao = ($xke / $no) ** $x2o3;
        $sinio = sin($inclo);
        $po = $ao * $omeosq;
        $con42 = 1 - 5 * $cosio2;
        $con41 = -$con42 - $cosio2 - $cosio2;
        $posq = $po * $po;
        $rp = $ao * (1 - $ecco);

        $isimp = $rp < 220 / self::RADIUS + 1;
        $sfour = $ss;
        $qzms24 = $qzms2t;
        $perige = ($rp - 1) * self::RADIUS;
        if ($perige < 156) {
            $sfour = $perige < 98 ? 20 : $perige - 78;
            $qzms24 = ((120 - $sfour) / self::RADIUS) ** 4;
            $sfour = $sfour / self::RADIUS + 1;
        }
        $pinvsq = 1 / $posq;
        $tsi = 1 / ($ao - $sfour);
        $eta = $ao * $ecco * $tsi;
        $etasq = $eta * $eta;
        $eeta = $ecco * $eta;
        $psisq = abs(1 - $etasq);
        $coef = $qzms24 * $tsi ** 4;
        $coef1 = $coef / $psisq ** 3.5;
        $cc2 = $coef1 * $no * ($ao * (1 + 1.5 * $etasq + $eeta * (4 + $etasq))
            + 0.375 * $j2 * $tsi / $psisq * $con41 * (8 + 3 * $etasq * (8 + $etasq)));
        $cc1 = $bstar * $cc2;
        $cc3 = $ecco > 1e-4 ? -2 * $coef * $tsi * $j3oj2 * $no * $sinio / $ecco : 0;
        $x1mth2 = 1 - $cosio2;
        $cc4 = 2 * $no * $coef1 * $ao * $omeosq * ($eta * (2 + 0.5 * $etasq) + $ecco * (0.5 + 2 * $etasq)
            - $j2 * $tsi / ($ao * $psisq) * (-3 * $con41 * (1 - 2 * $eeta + $etasq * (1.5 - 0.5 * $eeta))
            + 0.75 * $x1mth2 * (2 * $etasq - $eeta * (1 + $etasq)) * cos(2 * $argpo)));
        $cc5 = 2 * $coef1 * $ao * $omeosq * (1 + 2.75 * ($etasq + $eeta) + $eeta * $etasq);
        $cosio4 = $cosio2 * $cosio2;
        $temp1 = 1.5 * $j2 * $pinvsq * $no;
        $temp2 = 0.5 * $temp1 * $j2 * $pinvsq;
        $temp3 = -0.46875 * self::J4 * $pinvsq * $pinvsq * $no;
        $mdot = $no + 0.5 * $temp1 * $rteosq * $con41 + 0.0625 * $temp2 * $rteosq * (13 - 78 * $cosio2 + 137 * $cosio4);
        $argpdot = -0.5 * $temp1 * $con42 + 0.0625 * $temp2 * (7 - 114 * $cosio2 + 395 * $cosio4)
            + $temp3 * (3 - 36 * $cosio2 + 49 * $cosio4);
        $xhdot1 = -$temp1 * $cosio;
        $nodedot = $xhdot1 + (0.5 * $temp2 * (4 - 19 * $cosio2) + 2 * $temp3 * (3 - 7 * $cosio2)) * $cosio;
        $omgcof = $bstar * $cc3 * cos($argpo);
        $xmcof = $ecco > 1e-4 ? -$x2o3 * $coef * $bstar / $eeta : 0;
        $nodecf = 3.5 * $omeosq * $xhdot1 * $cc1;
        $t2cof = 1.5 * $cc1;
        $xlcof = -0.25 * $j3oj2 * $sinio * (3 + 5 * $cosio) / (abs($cosio + 1) > 1.5e-12 ? 1 + $cosio : 1.5e-12);
        $aycof = -0.5 * $j3oj2 * $sinio;
        $delmo = (1 + $eta * cos($mo)) ** 3;
        $sinmao = sin($mo);
        $x7thm1 = 7 * $cosio2 - 1;
        $d2 = $d3 = $d4 = $t3cof = $t4cof = $t5cof = 0;
        if (!$isimp) {
            $cc1sq = $cc1 * $cc1;
            $d2 = 4 * $ao * $tsi * $cc1sq;
            $temp = $d2 * $tsi * $cc1 / 3;
            $d3 = (17 * $ao + $sfour) * $temp;
            $d4 = 0.5 * $temp * $ao * $tsi * (221 * $ao + 31 * $sfour) * $cc1;
            $t3cof = $d2 + 2 * $cc1sq;
            $t4cof = 0.25 * (3 * $d3 + $cc1 * (12 * $d2 + 10 * $cc1sq));
            $t5cof = 0.2 * (3 * $d4 + 12 * $cc1 * $d3 + 6 * $d2 * $d2 + 15 * $cc1sq * (2 * $d2 + $cc1sq));
        }
        $this->s = compact(
            'xke',
            'bstar',
            'ecco',
            'argpo',
            'inclo',
            'mo',
            'no',
            'nodeo',
            'isimp',
            'eta',
            'cc1',
            'cc4',
            'cc5',
            'mdot',
            'argpdot',
            'nodedot',
            'omgcof',
            'xmcof',
            'nodecf',
            't2cof',
            'xlcof',
            'aycof',
            'delmo',
            'sinmao',
            'x7thm1',
            'x1mth2',
            'con41',
            'd2',
            'd3',
            'd4',
            't3cof',
            't4cof',
            't5cof'
        );
    }

    /**
     * Where the satellite is some minutes after its elements.
     *
     * @param float $t The minutes since the epoch of the elements.
     * @return array|null Its position, km; null if the model cannot say.
     */
    private function propagate(float $t): ?array {
        // What init() worked out.
        ['xke' => $xke, 'bstar' => $bstar, 'ecco' => $ecco, 'argpo' => $argpo, 'inclo' => $inclo, 'mo' => $mo,
            'no' => $no, 'nodeo' => $nodeo, 'isimp' => $isimp, 'eta' => $eta, 'cc1' => $cc1, 'cc4' => $cc4,
            'cc5' => $cc5, 'mdot' => $mdot, 'argpdot' => $argpdot, 'nodedot' => $nodedot, 'omgcof' => $omgcof,
            'xmcof' => $xmcof, 'nodecf' => $nodecf, 't2cof' => $t2cof, 'xlcof' => $xlcof, 'aycof' => $aycof,
            'delmo' => $delmo, 'sinmao' => $sinmao, 'x7thm1' => $x7thm1, 'x1mth2' => $x1mth2, 'con41' => $con41,
            'd2' => $d2, 'd3' => $d3, 'd4' => $d4, 't3cof' => $t3cof, 't4cof' => $t4cof, 't5cof' => $t5cof] = $this->s;
        $twopi = 2 * M_PI;
        $xmdf = $mo + $mdot * $t;
        $argpdf = $argpo + $argpdot * $t;
        $nodedf = $nodeo + $nodedot * $t;
        $argpm = $argpdf;
        $mm = $xmdf;
        $t2 = $t * $t;
        $nodem = $nodedf + $nodecf * $t2;
        $tempa = 1 - $cc1 * $t;
        $tempe = $bstar * $cc4 * $t;
        $templ = $t2cof * $t2;
        if (!$isimp) {
            $delomg = $omgcof * $t;
            $delm = $xmcof * ((1 + $eta * cos($xmdf)) ** 3 - $delmo);
            $mm = $xmdf + $delomg + $delm;
            $argpm = $argpdf - $delomg - $delm;
            $t3 = $t2 * $t;
            $t4 = $t3 * $t;
            $tempa = $tempa - $d2 * $t2 - $d3 * $t3 - $d4 * $t4;
            $tempe = $tempe + $bstar * $cc5 * (sin($mm) - $sinmao);
            $templ = $templ + $t3cof * $t3 + $t4 * ($t4cof + $t * $t5cof);
        }
        $am = ($xke / $no) ** (2 / 3) * $tempa * $tempa;
        $nm = $xke / $am ** 1.5;
        $em = max(1e-6, $ecco - $tempe);
        if ($am <= 0 || $em >= 1) {
            return null;
        }
        $mm = $mm + $no * $templ;
        $xlm = $mm + $argpm + $nodem;
        $nodem = fmod($nodem, $twopi);
        $argpm = fmod($argpm, $twopi);
        $xlm = fmod($xlm, $twopi);
        $mm = fmod($xlm - $argpm - $nodem, $twopi);

        $sinip = sin($inclo);
        $cosip = cos($inclo);
        $axnl = $em * cos($argpm);
        $temp = 1 / ($am * (1 - $em * $em));
        $aynl = $em * sin($argpm) + $temp * $aycof;
        $xl = $mm + $argpm + $nodem + $temp * $xlcof * $axnl;

        // Kepler's equation.
        $u = fmod($xl - $nodem, $twopi);
        $eo1 = $u;
        $tem5 = 9999.9;
        $sineo1 = $coseo1 = 0;
        for ($ktr = 1; abs($tem5) >= 1e-12 && $ktr <= 10; $ktr++) {
            $sineo1 = sin($eo1);
            $coseo1 = cos($eo1);
            $tem5 = 1 - $coseo1 * $axnl - $sineo1 * $aynl;
            $tem5 = ($u - $aynl * $coseo1 + $axnl * $sineo1 - $eo1) / $tem5;
            $tem5 = max(-0.95, min(0.95, $tem5));
            $eo1 += $tem5;
        }

        // The short periodic terms.
        $ecose = $axnl * $coseo1 + $aynl * $sineo1;
        $esine = $axnl * $sineo1 - $aynl * $coseo1;
        $el2 = $axnl * $axnl + $aynl * $aynl;
        $pl = $am * (1 - $el2);
        if ($pl < 0) {
            return null;
        }
        $rl = $am * (1 - $ecose);
        $betal = sqrt(1 - $el2);
        $temp = $esine / (1 + $betal);
        $sinu = $am / $rl * ($sineo1 - $aynl - $axnl * $temp);
        $cosu = $am / $rl * ($coseo1 - $axnl + $aynl * $temp);
        $su = atan2($sinu, $cosu);
        $sin2u = ($cosu + $cosu) * $sinu;
        $cos2u = 1 - 2 * $sinu * $sinu;
        $temp = 1 / $pl;
        $temp1 = 0.5 * self::J2 * $temp;
        $temp2 = $temp1 * $temp;
        $mrt = $rl * (1 - 1.5 * $temp2 * $betal * $con41) + 0.5 * $temp1 * $x1mth2 * $cos2u;
        if ($mrt < 1) {
            return null;
        }
        $su = $su - 0.25 * $temp2 * $x7thm1 * $sin2u;
        $xnode = $nodem + 1.5 * $temp2 * $cosip * $sin2u;
        $xinc = $inclo + 1.5 * $temp2 * $cosip * $sinip * $cos2u;

        $sinsu = sin($su);
        $cossu = cos($su);
        $snod = sin($xnode);
        $cnod = cos($xnode);
        $sini = sin($xinc);
        $cosi = cos($xinc);
        $xmx = -$snod * $cosi;
        $xmy = $cnod * $cosi;
        return [
            $mrt * ($xmx * $sinsu + $cnod * $cossu) * self::RADIUS,
            $mrt * ($xmy * $sinsu + $snod * $cossu) * self::RADIUS,
            $mrt * $sini * $sinsu * self::RADIUS,
        ];
    }
}
