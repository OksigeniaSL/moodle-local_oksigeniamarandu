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

/**
 * The PNG copy of a logo of the site drawn in SVG, made by the browser of whoever administers when they open a page of
 * Marandu: the cards are drawn on the server, which cannot read an SVG. It is made once, at 1200 px wide, and again
 * only when the logo changes.
 *
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    var items = document.querySelectorAll('[data-mrd-logo]');
    var key = window.M && M.cfg ? M.cfg.sesskey : '';
    if (!items.length || !key || !window.fetch || !window.FormData || !document.createElement('canvas').toBlob) {
        return;
    }
    Array.prototype.forEach.call(items, function(item) {
        var aspect = parseFloat(item.getAttribute('data-aspect')) || 1;
        var width = 1200;
        var height = Math.max(1, Math.round(width / aspect));
        fetch(item.getAttribute('data-src'), {credentials: 'same-origin'}).then(function(reply) {
            return reply.ok ? reply.text() : '';
        }).then(function(svg) {
            if (!svg) {
                return null;
            }
            // An SVG with only its viewBox has no size of its own: it gets the one it is drawn at.
            svg = svg.replace(/<svg\b([^>]*)>/i, function(tag, attrs) {
                attrs = attrs.replace(/\s(width|height)\s*=\s*("[^"]*"|'[^']*')/gi, '');
                return '<svg' + attrs + ' width="' + width + '" height="' + height + '">';
            });
            var picture = new Image();
            picture.onload = function() {
                var canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                canvas.getContext('2d').drawImage(picture, 0, 0, width, height);
                canvas.toBlob(function(png) {
                    if (!png) {
                        return;
                    }
                    var body = new FormData();
                    body.append('sesskey', key);
                    body.append('which', item.getAttribute('data-which'));
                    body.append('png', png, 'logo.png');
                    fetch(item.getAttribute('data-to'), {method: 'POST', body: body, credentials: 'same-origin'});
                }, 'image/png');
            };
            picture.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
            return null;
        });
    });
})();
