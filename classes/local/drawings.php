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
 * The drawings of Marandu, from the kit of the house (agents/arte/kit/iconos-marandu): the small ones on 64 × 64, drawn
 * with a stroke that stays about 1.6 px at the size they are shown, and the scenes of the state of the panel, on 120 × 96.
 * Their parts carry classes that the styles colour: k, kn and knf (the solid of the state), in (the card), c1 to c3 (the
 * sparks).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class drawings {
    /** The small drawings, by name. */
    private const ART = [
        'ajustes' =>
            '<rect x="8" y="10" width="48" height="44" rx="6" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M16 22 H48 M16 32 H48 M16 42 H48"/><circle cx="24" cy="22" r="3.5" fill="currentColor"/>' .
            '<circle cx="40" cy="32" r="3.5" fill="currentColor"/>' .
            '<circle cx="30" cy="42" r="3.5" fill="currentColor"/>',
        'apuntarse' =>
            '<rect x="17" y="6" width="30" height="52" rx="5" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M29 52 H35"/><rect x="22" y="14" width="8" height="8" rx="1.5"/>' .
            '<rect x="34" y="14" width="8" height="8" rx="1.5"/>' .
            '<rect x="22" y="26" width="8" height="8" rx="1.5"/>' .
            '<path d="M35 27 H36 M40 27 H41 M35 31 H41 M38 35 H41 M23 41 H41"/>',
        'biblioteca' =>
            '<path d="M32 18 C26 13 16 13 8 16 V50 C16 47 26 47 32 51 C38 47 48 47 56 50 V16 C48 13 38 13 32 18' .
            ' Z" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M32 18 V51 M14 24 C18 23 22 23 26 24 M38 24 C42 23 46 23 50 24"/>',
        'campana' =>
            '<path d="M19 44 V30 C19 22 25 15 32 15 C39 15 45 22 45 30 V44 L49 48 H15 Z" fill="currentColor"' .
            ' fill-opacity=".12"/><path d="M27 53 C28 57 36 57 37 53 M32 10 V15"/>',
        'canales' =>
            '<g transform="translate(1 3) scale(.56)" stroke-width="3.6">' .
            '<path d="M13 12 H51 C54 12 56 14 56 17 V39 C56 42 54 44 51 44 H31 L20 53 V44 H13 C10 44 8 42 8 39' .
            ' V17 C8 14 10 12 13 12 Z" fill="currentColor" fill-opacity=".12"/></g>' .
            '<g transform="translate(28 27) scale(.58)" stroke-width="3.5">' .
            '<path d="M8 31 L56 10 L41 52 L29 39 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M56 10 L29 39 M29 39 L27 50 L35 44"/></g><path d="M50 6 V16 M45 11 H55"/>',
        'comedor' =>
            '<circle cx="32" cy="33" r="15" fill="currentColor" fill-opacity=".12"/>' .
            '<circle cx="32" cy="33" r="8"/><path d="M9 14 V24 C9 27 14 27 14 24 V14 M11.5 14 V54"/>' .
            '<path d="M55 14 C51 18 50 28 54 31 V54"/>',
        'elige' =>
            '<rect x="8" y="12" width="48" height="40" rx="6" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M15 25 H28 M15 39 H28"/>' .
            '<rect x="34" y="20" width="16" height="10" rx="5" fill="currentColor" fill-opacity=".12"/>' .
            '<circle cx="45" cy="25" r="2.6" fill="currentColor"/>' .
            '<rect x="34" y="34" width="16" height="10" rx="5"/><circle cx="39" cy="39" r="2.6"/>',
        'en-marcha' =>
            '<path d="M13 12 H51 C54 12 56 14 56 17 V39 C56 42 54 44 51 44 H31 L20 53 V44 H13 C10 44 8 42 8 39' .
            ' V17 C8 14 10 12 13 12 Z" fill="currentColor" fill-opacity=".12"/><path d="M22 28 L29 35 L43 21"/>',
        'general' =>
            '<path d="M10 27 H20 L42 15 V49 L20 37 H10 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M20 27 V37 M15 37 L19 50 H25 L22 37"/>' .
            '<path d="M49 26 C52 29 52 35 49 38 M53 21 C58 27 58 37 53 43"/>',
        'numeros' =>
            '<rect x="17" y="6" width="30" height="52" rx="5" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M28 11 H36 M29 52 H35"/><circle cx="25" cy="21" r="2.5"/><circle cx="25" cy="30" r="2.5"/>' .
            '<circle cx="25" cy="39" r="2.5"/><path d="M31 21 H40 M31 30 H40 M31 39 H37"/>',
        'parado' =>
            '<path d="M13 12 H51 C54 12 56 14 56 17 V39 C56 42 54 44 51 44 H31 L20 53 V44 H13 C10 44 8 42 8 39' .
            ' V17 C8 14 10 12 13 12 Z" fill="currentColor" fill-opacity=".12"/><path d="M27 21 V35 M37 21 V35"/>',
        'personas' =>
            '<circle cx="24" cy="22" r="7"/>' .
            '<path d="M10 50 C10 40 16 34 24 34 C32 34 38 40 38 50 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<circle cx="44" cy="24" r="6"/><path d="M41 35 C49 34 54 40 54 48 H42"/>',
        'primer-aviso' =>
            '<rect x="24" y="12" width="32" height="22" rx="4" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M30 20 H50 M30 26 H43"/><path d="M6 54 C13 54 18 47 22 38" stroke-dasharray="1 5"/>' .
            '<path d="M12 14 V24 M7 19 H17"/><circle cx="48" cy="46" r="1.6" fill="currentColor"/>',
        'reintento' =>
            '<circle cx="32" cy="32" r="18" fill="currentColor" fill-opacity=".12" stroke="none"/>' .
            '<path d="M47.6 23 A18 18 0 1 0 50 32"/><path d="M48.5 12 L47.6 23 L36.8 21.5"/>',
        'revision' =>
            '<path d="M18 9 H46 M18 55 H46"/>' .
            '<path d="M21 9 C21 22 31 27 31 32 C31 37 21 42 21 55 H43 C43 42 33 37 33 32 C33 27 43 22 43 9 Z"' .
            ' fill="currentColor" fill-opacity=".12"/><path d="M27 50 H37"/>',
        'silencio' =>
            '<path d="M38 10 C28 13 21 22 21 32 C21 44 31 54 43 54 C47 54 51 53 54 50 C42 49 33 40 33 28 C33 21' .
            ' 35 15 38 10 Z" fill="currentColor" fill-opacity=".12"/><path d="M48 14 V22 M44 18 H52"/>',
        'sin-numeros' =>
            '<rect x="17" y="6" width="30" height="52" rx="5" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M28 11 H36 M29 52 H35"/><path d="M24 24 H40 M24 32 H36" stroke-dasharray="1 5"/>' .
            '<path d="M27 42 L31 46 L38 39"/>',
        'sin-resultados' =>
            '<circle cx="27" cy="27" r="16" fill="currentColor" fill-opacity=".12"/><path d="M39 39 L54 54"/>' .
            '<path d="M20 27 H34" stroke-dasharray="1 5"/>',
        'telegram' =>
            '<path d="M8 31 L56 10 L41 52 L29 39 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M56 10 L29 39"/><path d="M29 39 L27 50 L35 44"/>' .
            '<path d="M5 47 C11 47 15 45 19 40" stroke-dasharray="1 5"/>',
        'textos' =>
            '<path d="M13 12 H51 C54 12 56 14 56 17 V39 C56 42 54 44 51 44 H31 L20 53 V44 H13 C10 44 8 42 8 39' .
            ' V17 C8 14 10 12 13 12 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M18 22 H46 M18 29 H46 M18 36 H34"/>',
        'tope' =>
            '<path d="M10 46 A22 22 0 0 1 54 46 Z" fill="currentColor" fill-opacity=".12"/><path d="M8 52 H56"/>' .
            '<path d="M14 35 L17 37 M21 27 L23 30 M32 24 V28 M43 27 L41 30 M50 35 L47 37"/>' .
            '<path d="M32 46 L51 39"/><circle cx="32" cy="46" r="3"/>',
        'whatsapp' =>
            '<path d="M13 12 H51 C54 12 56 14 56 17 V39 C56 42 54 44 51 44 H31 L20 53 V44 H13 C10 44 8 42 8 39' .
            ' V17 C8 14 10 12 13 12 Z" fill="currentColor" fill-opacity=".12"/>',
    ];

    /** The scenes of the state, by name. */
    private const SCENES = [
        'escena-apuntarse' =>
            '<rect x="16" y="14" width="36" height="70" rx="7" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M30 20 H38 M30 78 H38"/><rect x="22" y="27" width="10" height="10" rx="1.5"/>' .
            '<rect x="36" y="27" width="10" height="10" rx="1.5"/>' .
            '<rect x="22" y="41" width="10" height="10" rx="1.5"/>' .
            '<path d="M37 42 H38.5 M42.5 42 H44 M37 46.5 H44 M40 51 H44 M23 58 H45"/>' .
            '<path d="M56 50 C66 42 74 42 82 46" stroke-dasharray="1 6"/>' .
            '<circle cx="97" cy="36" r="9" stroke-dasharray="1 5"/>' .
            '<path d="M81 76 C81 63 88 55 97 55 C106 55 113 63 113 76" stroke-dasharray="1 5"/>' .
            '<path class="c1" d="M100 8 V16 M96 12 H104"/>' .
            '<circle class="c2" cx="70" cy="26" r="1.8" fill="currentColor" stroke="none"/>',
        'escena-canales' =>
            '<rect x="42" y="14" width="36" height="70" rx="7" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M56 20 H64 M56 78 H64"/>' .
            '<rect x="48" y="30" width="24" height="18" rx="4" stroke-dasharray="1 5"/>' .
            '<path d="M48 56 H66" stroke-dasharray="1 5"/>' .
            '<g transform="translate(2 18) scale(.42)" stroke-width="4.8">' .
            '<path d="M13 12 H51 C54 12 56 14 56 17 V39 C56 42 54 44 51 44 H31 L20 53 V44 H13 C10 44 8 42 8 39' .
            ' V17 C8 14 10 12 13 12 Z" fill="currentColor" fill-opacity=".12"/></g>' .
            '<path d="M18 46 C22 56 30 60 40 58" stroke-dasharray="1 6"/>' .
            '<g transform="translate(86 40) scale(.46)" stroke-width="4.4">' .
            '<path d="M8 31 L56 10 L41 52 L29 39 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M56 10 L29 39 M29 39 L27 50 L35 44"/></g>' .
            '<path d="M98 66 C96 74 90 78 80 76" stroke-dasharray="1 6"/>' .
            '<circle class="k" cx="80" cy="22" r="11" fill="currentColor"/>' .
            '<path class="kn" stroke="#fff" d="M80 16.5 V27.5 M74.5 22 H85.5"/>' .
            '<circle class="c2" cx="110" cy="24" r="1.8" fill="currentColor" stroke="none"/>' .
            '<path class="c3" d="M12 72.5 V79.5 M8.5 76 H15.5"/>',
        'escena-elige' =>
            '<rect x="22" y="8" width="74" height="78" rx="8" fill="currentColor" fill-opacity=".12"/>' .
            '<circle cx="33" cy="27" r="3.5"/><path d="M41 27 H58"/><circle cx="33" cy="47" r="3.5"/>' .
            '<path d="M41 47 H56"/><circle cx="33" cy="67" r="3.5"/><path d="M41 67 H54"/>' .
            '<rect class="k" x="66" y="20" width="22" height="14" rx="7" fill="currentColor"/>' .
            '<circle class="knf" cx="81" cy="27" r="4" fill="#fff" stroke="none"/>' .
            '<rect x="66" y="40" width="22" height="14" rx="7"/><circle cx="73" cy="47" r="4"/>' .
            '<rect x="66" y="60" width="22" height="14" rx="7"/><circle cx="73" cy="67" r="4"/>' .
            '<path d="M80 50 L80 66 L84.5 62 L88 69.5 L91.2 68 L87.8 60.8 L93.5 60.4 Z" fill="currentColor"' .
            ' fill-opacity=".12"/><path class="c1" d="M104 18 V26 M100 22 H108"/>' .
            '<circle class="c2" cx="12" cy="40" r="1.8" fill="currentColor" stroke="none"/>' .
            '<circle class="c3" cx="108" cy="76" r="1.6" fill="currentColor" stroke="none"/>',
        'escena-marcha' =>
            '<path d="M4 72 C18 72 26 56 40 48" stroke-dasharray="1 6"/><g transform="rotate(-14 17 46)">' .
            '<rect x="8" y="40" width="18" height="12" rx="3" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M12 46 H22"/></g>' .
            '<rect x="44" y="14" width="36" height="70" rx="7" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M58 20 H66 M58 78 H66"/>' .
            '<rect class="in" x="49" y="28" width="26" height="13" rx="3" fill="currentColor"' .
            ' fill-opacity=".12"/><path d="M53 34.5 H68"/>' .
            '<rect class="in" x="49" y="46" width="26" height="13" rx="3" fill="currentColor"' .
            ' fill-opacity=".12"/><path d="M53 52.5 H63"/>' .
            '<circle class="k" cx="82" cy="22" r="11" fill="currentColor"/>' .
            '<path class="kn" stroke="#fff" d="M77 22 L80.5 25.5 L87 18.5"/>' .
            '<path class="c1" d="M102 48 V56 M98 52 H106"/>' .
            '<circle class="c2" cx="106" cy="36" r="1.8" fill="currentColor" stroke="none"/>' .
            '<circle class="c3" cx="96" cy="74" r="1.6" fill="currentColor" stroke="none"/>',
        'escena-parado' =>
            '<rect x="44" y="14" width="36" height="70" rx="7" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M58 20 H66 M58 78 H66"/>' .
            '<rect x="49" y="28" width="26" height="13" rx="3" stroke-dasharray="1 5"/>' .
            '<rect x="49" y="46" width="26" height="13" rx="3" stroke-dasharray="1 5"/>' .
            '<circle class="k" cx="82" cy="22" r="11" fill="currentColor"/>' .
            '<path class="kn" stroke="#fff" d="M78.5 17.5 V26.5 M85.5 17.5 V26.5"/>' .
            '<path d="M4 72 C18 72 26 56 40 48" stroke-dasharray="1 6"/>',
        'escena-primer-aviso' =>
            '<rect x="12" y="18" width="36" height="70" rx="7" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M26 24 H34 M26 82 H34"/>' .
            '<rect class="in" x="17" y="34" width="26" height="13" rx="3" fill="currentColor"' .
            ' fill-opacity=".12"/><path d="M21 40.5 H34"/><path d="M21 54 H37" stroke-dasharray="1 5"/>' .
            '<path d="M52 40 C58 30 62 28 68 30" stroke-dasharray="1 6"/><g transform="rotate(-10 86 22)">' .
            '<rect x="68" y="10" width="36" height="24" rx="3" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M72 18 H96"/><path d="M72 25 H88"/></g>' .
            '<path class="c1" d="M108 45.5 V54.5 M103.5 50 H112.5"/>' .
            '<circle class="c2" cx="94" cy="64" r="2" fill="currentColor" stroke="none"/>' .
            '<path class="c3" d="M76 53 V59 M73 56 H79"/>' .
            '<circle class="c3" cx="62" cy="14" r="1.6" fill="currentColor" stroke="none"/>',
        'escena-primera-alta' =>
            '<circle cx="60" cy="33" r="11" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M37 82 C37 66 47 57 60 57 C73 57 83 66 83 82 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<circle class="k" cx="80" cy="26" r="11" fill="currentColor"/>' .
            '<path class="kn" stroke="#fff" d="M75 26 L78.5 29.5 L85 22.5"/>' .
            '<path class="c1" d="M22 25.5 V34.5 M17.5 30 H26.5"/><path class="c3" d="M100 54 V62 M96 58 H104"/>' .
            '<circle class="c2" cx="30" cy="58" r="2.2" fill="currentColor" stroke="none"/>' .
            '<circle class="c1" cx="102" cy="16" r="2" fill="currentColor" stroke="none"/>' .
            '<path class="c3" d="M18 48 L23 44"/><path class="c2" d="M96 78 L101 82"/>' .
            '<path class="c1" d="M40 12 L44 16"/>' .
            '<circle class="c3" cx="16" cy="76" r="1.8" fill="currentColor" stroke="none"/>',
        'escena-tope' =>
            '<path d="M22 76 A38 38 0 0 1 98 76 Z" fill="currentColor" fill-opacity=".12"/>' .
            '<path d="M14 84 H106"/>' .
            '<path d="M31 56 L35 59 M42 45 L45 49 M60 40 V45 M78 45 L75 49 M89 56 L85 59"/>' .
            '<path d="M60 76 L92 66"/><circle cx="60" cy="76" r="4"/>' .
            '<circle class="k" cx="98" cy="30" r="11" fill="currentColor"/>' .
            '<path class="kn" stroke="#fff" d="M98 24.5 V31 M98 35.5 V35.6"/>',
    ];

    /**
     * A small drawing as an SVG, hidden from screen readers (what it means is always written beside it).
     *
     * @param string $name Its name.
     * @param int $px The size it is shown at, to keep its line about 1.6 px; from 48 on, the line of the drawing.
     * @param string $class Classes for it, such as «mrd-ico wa».
     * @return string
     */
    public static function svg(string $name, int $px = 22, string $class = ''): string {
        $paths = self::ART[$name] ?? self::ART['general'];
        $stroke = $px >= 48 ? '2' : format_float(1.6 * 64 / max(1, $px), 2, false);
        return '<svg class="' . s($class) . '" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="' . $stroke
            . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /**
     * A scene of the state of the panel.
     *
     * @param string $name Its name.
     * @return string
     */
    public static function scene(string $name): string {
        $paths = self::SCENES[$name] ?? self::SCENES['escena-marcha'];
        return '<svg class="mrd-scene" viewBox="0 0 120 96" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /**
     * Whether there is a small drawing with that name.
     *
     * @param string $name The name.
     * @return bool
     */
    public static function has(string $name): bool {
        return isset(self::ART[$name]);
    }
}
