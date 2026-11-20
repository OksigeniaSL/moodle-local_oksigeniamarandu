/* Marandu, the conversations: the screen where the staff of a centre answer WhatsApp, over Chatwoot.
   A classic script (no modules, no build step, ES2019). chat.php loads bichito.js and this file, and calls
   window.MaranduChat.start(config, transport). Every datum comes from the server through transport.call(method, args);
   the contract is in Marandu's docs (08-conversaciones.md). Every text comes from config.strings. */
(function (root) {
    'use strict';

    var M = {
        poll: 'local_oksigeniamarandu_chat_poll',
        messages: 'local_oksigeniamarandu_chat_messages',
        send: 'local_oksigeniamarandu_chat_send',
        template: 'local_oksigeniamarandu_chat_template',
        action: 'local_oksigeniamarandu_chat_action',
        extras: 'local_oksigeniamarandu_chat_extras',
        contact: 'local_oksigeniamarandu_chat_contact',
        prefs: 'local_oksigeniamarandu_chat_prefs'
    };

    /* ---------------------------------------------------------------------------------------------------------------
       The transport for Moodle: lib/ajax/service.php with the session key.
       --------------------------------------------------------------------------------------------------------------- */
    function moodleTransport(config) {
        return {
            call: function (method, args) {
                var url = config.wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(config.sesskey) +
                    '&info=' + encodeURIComponent(method);
                return fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify([{ index: 0, methodname: method, args: args || {} }])
                }).then(function (r) {
                    return r.json().catch(function () {
                        throw { errorcode: 'badresponse', message: 'HTTP ' + r.status };
                    });
                }).then(function (d) {
                    if (d && !Array.isArray(d) && d.error) {
                        throw { errorcode: d.errorcode || 'error', message: d.error };
                    }
                    var one = Array.isArray(d) ? d[0] : d;
                    if (!one) {
                        throw { errorcode: 'badresponse', message: '' };
                    }
                    if (one.error) {
                        var ex = one.exception || {};
                        throw { errorcode: ex.errorcode || 'error', message: ex.message || '' };
                    }
                    return one.data;
                }).catch(function (e) {
                    if (e && e.errorcode) {
                        throw e;
                    }
                    throw { errorcode: 'network', message: (e && e.message) || '' };
                });
            }
        };
    }

    /* ---------------------------------------------------------------------------------------------------------------
       Icons: one sprite, our own strokes.
       --------------------------------------------------------------------------------------------------------------- */
    var ICONS = {
        buscar: '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        yo: '<circle cx="12" cy="8" r="3.8"/><path d="M4.5 20c1.4-3.8 4.3-5.8 7.5-5.8s6.1 2 7.5 5.8"/>',
        nadie: '<circle cx="12" cy="8" r="3.8" stroke-dasharray="2.4 2.2"/>' +
            '<path d="M4.5 20c1.4-3.8 4.3-5.8 7.5-5.8s6.1 2 7.5 5.8" stroke-dasharray="2.4 2.2"/>',
        sello: '<path d="M9.5 3.5h5v4.2c0 1.8 3.5 2.5 3.5 5.8H6c0-3.3 3.5-4 3.5-5.8z"/><path d="M5 16.5h14v2.5H5z"/>' +
            '<path d="M7.5 21.5h9"/>',
        puerta: '<path d="M6 21V4.5A1.5 1.5 0 0 1 7.5 3h9A1.5 1.5 0 0 1 18 4.5V21"/><path d="M3.5 21h17"/>' +
            '<path d="M14.5 12.5v.01" stroke-width="2.6"/>',
        recibo: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        plato: '<circle cx="13" cy="12" r="6.5"/><circle cx="13" cy="12" r="3"/>' +
            '<path d="M3 3.5v4.5a1.6 1.6 0 0 0 1.5 1.6V20.5"/><path d="M6 3.5v4.5a1.6 1.6 0 0 1-1.5 1.6"/>' +
            '<path d="M21.5 3.5c-1.4.8-2 2.6-2 5.5h2v11.5"/>',
        edificio: '<path d="M4.5 21V9.2L12 4l7.5 5.2V21"/><path d="M9.8 21v-5.5h4.4V21"/><path d="M3 21h18"/>' +
            '<path d="M12 9.5v.01" stroke-width="2.6"/>',
        clip: '<path d="M20.5 11.2l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4l7.8-7.8"/>',
        enviar: '<path d="M4 11.8L20 4.5l-5.8 15.5-2.9-6.3z"/><path d="M11.3 13.7L20 4.5"/>',
        guardadas: '<path d="M7 3.5h10a1 1 0 0 1 1 1V21l-6-3.8L6 21V4.5a1 1 0 0 1 1-1z"/><path d="M9.5 8h5M9.5 11.5h3.5"/>',
        arroba: '<circle cx="12" cy="12" r="3.8"/><path d="M15.8 8.5v4.4a2.6 2.6 0 0 0 5.2 0V12a9 9 0 1 0-3.6 7.2"/>',
        nota: '<path d="M5 4h14v10.5L13.5 20H5z"/><path d="M13.5 20v-5.5H19"/><path d="M8.5 8.5h7M8.5 12h4"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        checks: '<path d="M2.5 12.5l4 4L15 8"/><path d="M10.5 15.5l1 1L20 8"/>',
        reloj: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        tel: '<path d="M5.5 3.5h3l2 5-2.5 1.6a11 11 0 0 0 5.9 5.9l1.6-2.5 5 2v3a2 2 0 0 1-2 2A16.5 16.5 0 0 1 3.5 5.5a2 2 0 0 1 2-2z"/>',
        izq: '<path d="M15 5l-7 7 7 7"/>',
        der: '<path d="M9 5l7 7-7 7"/>',
        abajo: '<path d="M6 9.5l6 6 6-6"/>',
        play: '<path d="M7 4.5v15l12.5-7.5z"/>',
        pausa: '<path d="M7 4.5h3.5v15H7zM13.5 4.5H17v15h-3.5z"/>',
        gente: '<circle cx="9" cy="8.5" r="3.4"/><path d="M2.8 19.5c1-3.4 3.4-5.2 6.2-5.2s5.2 1.8 6.2 5.2"/>' +
            '<circle cx="16.8" cy="9.5" r="2.7"/><path d="M16.4 14.4c2.4.2 4.1 1.8 4.8 4.6"/>',
        luna: '<path d="M19.5 14.5A8 8 0 1 1 9.5 4.5a6.4 6.4 0 0 0 10 10z"/>',
        bus: '<rect x="4.5" y="3.5" width="15" height="14" rx="3"/><path d="M4.5 11h15M7.5 20.5v-3M16.5 20.5v-3"/>' +
            '<path d="M8 14.5v.01M16 14.5v.01" stroke-width="2.6"/>',
        cerrar: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
        ficha: '<rect x="4" y="3.5" width="16" height="17" rx="2.5"/><circle cx="12" cy="9.5" r="2.6"/>' +
            '<path d="M8 16.5c.8-1.9 2.2-2.8 4-2.8s3.2.9 4 2.8"/>',
        mensaje: '<path d="M4 5h16v11H9.5L5 19.5V16H4z"/><path d="M8.5 10.5l2.2 2.2 4.6-4.6"/>',
        flecha: '<path d="M5 12h14M13 6l6 6-6 6"/>',
        caja: '<path d="M3.5 7.5L12 3.5l8.5 4v9L12 20.5l-8.5-4z"/><path d="M3.5 7.5L12 11.5l8.5-4M12 11.5v9"/>',
        birrete: '<path d="M2.5 9L12 4.5 21.5 9 12 13.5z"/><path d="M6.5 11v4.5c1.5 1.3 3.4 2 5.5 2s4-.7 5.5-2V11"/>' +
            '<path d="M21.5 9v5"/>',
        gafete: '<rect x="4.5" y="6" width="15" height="14.5" rx="2.5"/><path d="M9.5 3.5h5v4h-5z"/>' +
            '<circle cx="12" cy="12.3" r="2.2"/><path d="M8.5 17.5c.7-1.3 2-2 3.5-2s2.8.7 3.5 2"/>',
        casa: '<path d="M4 11.5L12 4.5l8 7"/><path d="M6 10v10h12V10"/><path d="M10 20v-5h4v5"/>',
        camisa: '<path d="M8.5 3.5L3.5 6.5l2 4 2-1V20.5h9V9.5l2 1 2-4-5-3c-.4 1.5-1.8 2.5-3.5 2.5S8.9 5 8.5 3.5z"/>',
        candado: '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>' +
            '<path d="M12 14.5v2.5"/>',
        fondo: '<rect x="3.5" y="4.5" width="17" height="15" rx="3"/><path d="M3.5 15l4.5-4 4 3.5 3-2.5 5.5 4.5"/>' +
            '<circle cx="15.5" cy="9" r="1.6"/>',
        bandeja: '<path d="M3.5 13.5l2.6-7.2A1.5 1.5 0 0 1 7.5 5.3h9a1.5 1.5 0 0 1 1.4 1l2.6 7.2v5a1.5 1.5 0 0 1-1.5 1.5h-14' +
            'A1.5 1.5 0 0 1 3.5 18.5z"/><path d="M3.5 13.5h4.5l1.5 2.5h5l1.5-2.5h4.5"/>',
        libro: '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H11v16H5.5A1.5 1.5 0 0 1 4 18.5z"/>' +
            '<path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H13v16h5.5a1.5 1.5 0 0 0 1.5-1.5z"/>',
        lapiz: '<path d="M4 20h4L19.2 8.8a2.3 2.3 0 0 0-3.3-3.3L4.5 16.7z"/><path d="M14 7.5l3 3"/>',
        alerta: '<path d="M12 4l9 15.5H3z"/><path d="M12 10v4M12 17v.01"/>',
        ojo: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
        punto: '<circle cx="12" cy="12" r="3"/>',
        video: '<rect x="3.5" y="6" width="12.5" height="12" rx="2.5"/><path d="M16 10.5l4.5-2.5v8l-4.5-2.5"/>',
        ubicacion: '<path d="M12 21s-6.5-6.2-6.5-11a6.5 6.5 0 0 1 13 0c0 4.8-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/>'
    };

    /* Icons of the departments: Chatwoot teams have only a name, so a few common words choose a picture. */
    var TEAM_ICONS = [
        [/secretar|office|oficina|recep|front/i, 'sello'],
        [/admis|matr[ií]cul|enrol|plaza|inscrip/i, 'puerta'],
        [/comedor|cocina|men[uú]|canteen|kitchen|cafeter|lunch|meal|catering/i, 'plato'],
        [/recibo|contab|cobro|pago|factur|tesor|administraci|billing|account|payment|fee|financ|cuota/i, 'recibo'],
        [/uniform|ropa|extraescolar|actividad|club|sport|deport/i, 'camisa'],
        [/acceso|contrase|password|login|inform[aá]tic|tecnol|soporte|support|digital|\bit\b|\btic\b/i, 'candado'],
        [/direcci|director|jefatura|management|head|principal|gerencia|titular/i, 'edificio'],
        [/transport|ruta|bus\b|guagua|autob/i, 'bus'],
        [/orientaci|psicol|counsel|tutor|bienestar|wellbeing/i, 'gente'],
        [/bibliotec|library|libro/i, 'libro']
    ];

    /* ---------------------------------------------------------------------------------------------------------------
       Small helpers
       --------------------------------------------------------------------------------------------------------------- */
    function esc(x) {
        return String(x === null || x === undefined ? '' : x).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function ico(n, cls) {
        return '<svg class="mrd-i' + (cls ? ' ' + cls : '') + '" aria-hidden="true" focusable="false"><use href="#mrd-i-' +
            n + '"></use></svg>';
    }
    function cap(x) {
        x = String(x || '');
        return x.charAt(0).toUpperCase() + x.slice(1);
    }
    function digits(x) {
        return String(x || '').replace(/\D/g, '');
    }
    function firstName(p) {
        if (!p) {
            return '';
        }
        return p.firstname || String(p.name || '').split(' ')[0];
    }
    function hexToRgb(c) {
        c = String(c || '').trim();
        var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(c);
        if (m) {
            var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1];
            return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
        }
        m = /^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i.exec(c);
        return m ? [+m[1], +m[2], +m[3]] : null;
    }
    function mix(a, b, t) {
        return [0, 1, 2].map(function (i) {
            return Math.round(a[i] * (1 - t) + b[i] * t);
        });
    }
    function lum(c) {
        var v = c.map(function (x) {
            x /= 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    }
    function contrast(a, b) {
        var x = lum(a), y = lum(b);
        return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
    }
    function rgb(c, alpha) {
        return alpha === undefined ? 'rgb(' + c.join(', ') + ')' : 'rgba(' + c.join(', ') + ', ' + alpha + ')';
    }
    function fmtPhone(x) {
        var d = digits(x);
        if (!d) {
            return '';
        }
        // The country prefix: one digit for 1 and 7, two for the common ones, three for the rest.
        var two = /^(2[07]|3[0-469]|4[013-9]|5[1-8]|6[0-6]|8[1246]|9[0-58])/;
        var cc = /^[17]/.test(d) ? 1 : two.test(d) ? 2 : 3;
        var rest = d.slice(cc), groups = [];
        while (rest.length > 4) {
            groups.push(rest.slice(0, 3));
            rest = rest.slice(3);
        }
        if (rest) {
            groups.push(rest);
        }
        return '+' + d.slice(0, cc) + ' ' + groups.join(' ');
    }
    function fileSize(b, lang) {
        if (!b) {
            return '';
        }
        var nf = function (v, dec) {
            try {
                return new Intl.NumberFormat(lang, { maximumFractionDigits: dec }).format(v);
            } catch (e) {
                return String(Math.round(v));
            }
        };
        if (b < 1024 * 1024) {
            return nf(Math.max(1, Math.round(b / 1024)), 0) + ' KB';
        }
        return nf(b / 1048576, 1) + ' MB';
    }

    /* ---------------------------------------------------------------------------------------------------------------
       The screen
       --------------------------------------------------------------------------------------------------------------- */
    function start(config, transport) {
        var cfg = config || {};
        var tr = transport || moodleTransport(cfg);
        var STR = cfg.strings || {};
        var me = cfg.me || {};
        var brand = cfg.brand || {};
        var hours = cfg.hours || {};
        var LANG = cfg.lang || document.documentElement.lang || 'es';
        var TZ = cfg.timezone || hours.timezone || undefined;
        var READONLY = !!me.readonly;
        var POLLMS = Math.max(1500, +cfg.pollms || 4000);

        function s(key, a) {
            var t = STR[key];
            if (t === undefined) {
                return '[[' + key + ']]';
            }
            if (a !== undefined && a !== null) {
                if (typeof a === 'object') {
                    t = t.replace(/\{\$a->(\w+)\}/g, function (m, k) {
                        return a[k] !== undefined ? a[k] : m;
                    });
                } else {
                    t = t.split('{$a}').join(String(a));
                }
            }
            return t;
        }
        function joinList(list) {
            if (list.length < 2) {
                return list[0] || '';
            }
            return list.slice(0, -1).join(', ') + s('and') + list[list.length - 1];
        }

        /* -------- Time, in the centre's zone -------- */
        var skew = 0;
        function nowMs() {
            return Date.now() + skew;
        }
        var fParts, fTime, fWeekday, fDayMonth, fShort, fYear;
        function makeFormats(tz) {
            var base = tz ? { timeZone: tz } : {};
            var o = function (x) {
                return Object.assign({}, base, x);
            };
            fParts = new Intl.DateTimeFormat('en-US', o({ hour12: false, year: 'numeric', month: '2-digit',
                day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short' }));
            fTime = new Intl.DateTimeFormat(LANG, o({ hour: 'numeric', minute: '2-digit' }));
            fWeekday = new Intl.DateTimeFormat(LANG, o({ weekday: 'long' }));
            fDayMonth = new Intl.DateTimeFormat(LANG, o({ day: 'numeric', month: 'long' }));
            fShort = new Intl.DateTimeFormat(LANG, o({ day: 'numeric', month: 'short' }));
            fYear = new Intl.DateTimeFormat(LANG, o({ year: 'numeric' }));
        }
        try {
            makeFormats(TZ);
        } catch (e) {
            TZ = undefined;
            makeFormats();
        }
        var WD = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
        function zp(ms) {
            var o = {};
            fParts.formatToParts(new Date(ms)).forEach(function (p) {
                o[p.type] = p.value;
            });
            return { y: +o.year, mo: +o.month, d: +o.day, h: (+o.hour) % 24, mi: +o.minute, s: +o.second, wd: WD[o.weekday] };
        }
        function zoneOffset(ms) {
            var p = zp(ms);
            return Date.UTC(p.y, p.mo - 1, p.d, p.h, p.mi, p.s) - Math.floor(ms / 1000) * 1000;
        }
        function zonedMs(y, mo, d, h, mi) {
            var guess = Date.UTC(y, mo - 1, d, h, mi);
            var off = zoneOffset(guess);
            var ms = guess - off;
            var off2 = zoneOffset(ms);
            return off2 === off ? ms : guess - off2;
        }
        function dayKey(ms) {
            var p = zp(ms);
            return p.y * 10000 + p.mo * 100 + p.d;
        }
        function shiftKey(key, n) {
            var d = new Date(Date.UTC(Math.floor(key / 10000), Math.floor(key / 100) % 100 - 1, key % 100 + n));
            return d.getUTCFullYear() * 10000 + (d.getUTCMonth() + 1) * 100 + d.getUTCDate();
        }
        function fmtTime(ms) {
            return fTime.format(new Date(ms));
        }
        function dayText(ms) {
            var d = new Date(ms);
            var t = fWeekday.format(d) + ' ' + fDayMonth.format(d);
            if (zp(ms).y !== zp(nowMs()).y) {
                t += ' ' + fYear.format(d);
            }
            return t;
        }
        function dayLabel(ms) {
            var k = dayKey(ms), today = dayKey(nowMs());
            if (k === today) {
                return s('daytoday', dayText(ms));
            }
            if (k === shiftKey(today, -1)) {
                return s('dayyesterday', dayText(ms));
            }
            return cap(dayText(ms));
        }
        function listTime(ms) {
            var k = dayKey(ms), today = dayKey(nowMs());
            if (k === today) {
                return fmtTime(ms);
            }
            if (k === shiftKey(today, -1)) {
                return s('yesterday');
            }
            if (k > shiftKey(today, -7)) {
                return fWeekday.format(new Date(ms));
            }
            return fShort.format(new Date(ms));
        }
        // «hoy a las 10:17», «mañana a las 7:00», «el lunes a las 18:40».
        function whenPhrase(ms) {
            var k = dayKey(ms), today = dayKey(nowMs()), t = fmtTime(ms);
            if (k === today) {
                return s('whentoday', t);
            }
            if (k === shiftKey(today, 1)) {
                return s('whentomorrow', t);
            }
            if (k === shiftKey(today, -1)) {
                return s('whenyesterday', t);
            }
            var d = new Date(ms);
            var near = k < shiftKey(today, 7) && k > shiftKey(today, -7);
            return s('whenday', { day: near ? fWeekday.format(d) : fDayMonth.format(d), time: t });
        }

        /* -------- Working hours: the wait counts only while the centre is open -------- */
        var HOURS_ON = !!(hours.enabled && hours.week && hours.week.length);
        var WEEK = {};
        (hours.week || []).forEach(function (w) {
            WEEK[w.day] = w;
        });
        var CLOSED = {};
        (hours.closed || []).forEach(function (d) {
            CLOSED[String(d)] = true;
        });
        function hm(x) {
            var p = String(x || '0:0').split(':');
            return [+p[0] || 0, +p[1] || 0];
        }
        function windowOfKey(key) {
            var y = Math.floor(key / 10000), mo = Math.floor(key / 100) % 100, d = key % 100;
            var iso = y + '-' + (mo < 10 ? '0' : '') + mo + '-' + (d < 10 ? '0' : '') + d;
            var wd = new Date(Date.UTC(y, mo - 1, d)).getUTCDay();
            var w = WEEK[wd];
            if (!w || w.closed || CLOSED[iso]) {
                return null;
            }
            if (w.allday) {
                var n = shiftKey(key, 1);
                return [zonedMs(y, mo, d, 0, 0), zonedMs(Math.floor(n / 10000), Math.floor(n / 100) % 100, n % 100, 0, 0)];
            }
            var o = hm(w.open), c = hm(w.close);
            return [zonedMs(y, mo, d, o[0], o[1]), zonedMs(y, mo, d, c[0], c[1])];
        }
        function openAt(ms) {
            if (!HOURS_ON) {
                return true;
            }
            var w = windowOfKey(dayKey(ms));
            return !!(w && ms >= w[0] && ms < w[1]);
        }
        function businessMs(from, to) {
            if (!HOURS_ON) {
                return Math.max(0, to - from);
            }
            var total = 0, key = dayKey(from), last = dayKey(to);
            for (var i = 0; i < 62 && key <= last; i++) {
                var w = windowOfKey(key);
                if (w) {
                    var a = Math.max(w[0], from), b = Math.min(w[1], to);
                    if (b > a) {
                        total += b - a;
                    }
                }
                key = shiftKey(key, 1);
            }
            return total;
        }
        function nextOpening(ms) {
            if (!HOURS_ON) {
                return 0;
            }
            var key = dayKey(ms);
            for (var i = 0; i < 21; i++) {
                var w = windowOfKey(key);
                if (w && w[0] > ms) {
                    return w[0];
                }
                key = shiftKey(key, 1);
            }
            return 0;
        }
        function todayWindow() {
            return HOURS_ON ? windowOfKey(dayKey(nowMs())) : null;
        }
        // First thing in the day: open, and opened less than two hours ago.
        function isMorning() {
            var w = todayWindow(), n = nowMs();
            return !!(w && n >= w[0] && n < w[1] && n - w[0] < 2 * 3600000);
        }
        // It arrived after the last closing time and before today's opening.
        function arrivedAtNight(c) {
            var w = todayWindow();
            var at = (c.waitingsince || 0) * 1000;
            return !!(w && at && at < w[0] && !openAt(at) && businessMs(at, w[0]) === 0);
        }
        function waitMs(c) {
            if (!c.waitingsince) {
                return 0;
            }
            return businessMs(c.waitingsince * 1000, nowMs());
        }
        function waitText(c) {
            var ms = waitMs(c), min = Math.floor(ms / 60000);
            if (min < 1) {
                return s('waitnow');
            }
            if (min < 60) {
                return s('waitmin', min);
            }
            if (min < 600) {
                var h = Math.floor(min / 60), m = min % 60;
                return m ? s('waithmin', { h: h, m: m }) : s('waith', h);
            }
            var since = c.waitingsince * 1000, k = dayKey(since), today = dayKey(nowMs());
            if (k === shiftKey(today, -1)) {
                return s('waitsince', s('yesterday'));
            }
            if (k > shiftKey(today, -7)) {
                return s('waitsince', s('theday', fWeekday.format(new Date(since))));
            }
            return s('waitsince', s('theday', fShort.format(new Date(since))));
        }
        function waitTier(c) {
            var min = waitMs(c) / 60000;
            return min >= 240 ? 'mrd-larga' : min >= 60 ? 'mrd-media' : '';
        }
        function leftText(ms) {
            var min = Math.max(1, Math.round(ms / 60000));
            if (min < 60) {
                return s('waitmin', min);
            }
            var h = Math.floor(min / 60), m = min % 60;
            return h >= 2 || m < 5 ? s('waith', Math.round(min / 60)) : s('waithmin', { h: h, m: m });
        }

        /* ---------------------------------------------------------------------------------------------------------------
           State
           --------------------------------------------------------------------------------------------------------------- */
        var st = {
            cursor: 0, convs: {}, inboxes: null, extras: { canned: [], templates: [], people: [], teams: [] }, files: {},
            inbox: 'mine', open: 0, msgs: [], loaded: false, more: false, older: false, readby: [],
            ficha: null, fichaFor: 0, fichaState: '', fichaKind: '', joined: '',
            mode: 'reply', pop: null, popIndex: 0, popFilter: '', search: '', tpl: -1, pickAgain: false,
            showResolved: false, otros: false, capa: 'lista', fichaOpen: false, drafts: {}, dark: false,
            bg: { mode: (cfg.backgrounds && cfg.backgrounds.mode) || 'family', fixed: (cfg.backgrounds && cfg.backgrounds.fixed) || 'cuaderno' },
            ready: false, failed: 0, expired: false, composerSig: '', busy: {}
        };
        var seq = 0;

        /* ---------------------------------------------------------------------------------------------------------------
           The box
           --------------------------------------------------------------------------------------------------------------- */
        var box = document.getElementById('mrd-chat');
        if (!box) {
            box = document.createElement('div');
            box.id = 'mrd-chat';
            document.body.appendChild(box);
        }
        box.classList.add('mrd-app');
        if (cfg.inapp) {
            box.classList.add('mrd-inapp');
        }
        box.setAttribute('data-capa', 'lista');
        var sprite = '<svg class="mrd-sprite" aria-hidden="true" focusable="false">' + Object.keys(ICONS).map(function (k) {
            return '<symbol id="mrd-i-' + k + '" viewBox="0 0 24 24">' + ICONS[k] + '</symbol>';
        }).join('') + '</svg>';
        box.innerHTML = sprite +
            '<div class="mrd-franja" aria-hidden="true"></div>' +
            '<nav class="mrd-rail" aria-label="' + esc(s('trays')) + '"></nav>' +
            '<section class="mrd-lista" aria-label="' + esc(s('conversations')) + '"></section>' +
            '<section class="mrd-chat" aria-label="' + esc(s('conversation')) + '"></section>' +
            '<aside class="mrd-ficha" aria-label="' + esc(s('contactfile')) + '"></aside>' +
            '<nav class="mrd-pie-movil" aria-label="' + esc(s('trays')) + '"></nav>' +
            '<div class="mrd-red" hidden role="status"></div>' +
            '<div class="mrd-toast" role="status" aria-live="polite"></div>' +
            '<div class="mrd-visor" hidden role="dialog" aria-modal="true"></div>';
        var el = {
            franja: box.querySelector('.mrd-franja'),
            rail: box.querySelector('.mrd-rail'),
            lista: box.querySelector('.mrd-lista'),
            chat: box.querySelector('.mrd-chat'),
            ficha: box.querySelector('.mrd-ficha'),
            pie: box.querySelector('.mrd-pie-movil'),
            red: box.querySelector('.mrd-red'),
            toast: box.querySelector('.mrd-toast'),
            visor: box.querySelector('.mrd-visor')
        };
        document.documentElement.style.overflow = 'hidden';
        if (document.body) {
            document.body.style.overflow = 'hidden';
        }

        /* -------- Light and dark; the accent from the theme's primary colour -------- */
        var mqDark = root.matchMedia ? root.matchMedia('(prefers-color-scheme: dark)') : null;
        var mqCoarse = root.matchMedia ? root.matchMedia('(pointer: coarse)') : null;
        function computeDark() {
            if (cfg.inapp) {
                return !!(mqDark && mqDark.matches);
            }
            return document.documentElement.getAttribute('data-bs-theme') === 'dark';
        }
        function themePrimary() {
            var cs = getComputedStyle(document.documentElement);
            return hexToRgb(brand.accent) || hexToRgb(cs.getPropertyValue('--bs-primary')) ||
                hexToRgb(cs.getPropertyValue('--primary')) || [15, 108, 191];
        }
        function brandColors() {
            var list = (brand.colors || []).map(hexToRgb).filter(Boolean);
            return list.length ? list : [themePrimary()];
        }
        function setColours() {
            var base = themePrimary(), white = [255, 255, 255], black = [0, 0, 0], ink = [28, 26, 25], deep = [28, 27, 26];
            var v = {}, i, text, btn;
            if (!st.dark) {
                text = base;
                for (i = 0; i < 12 && contrast(text, white) < 4.8; i++) {
                    text = mix(text, black, 0.12);
                }
                btn = base;
                for (i = 0; i < 12 && contrast(btn, white) < 4.5 && lum(btn) < 0.4; i++) {
                    btn = mix(btn, black, 0.1);
                }
                v['--acento-texto'] = rgb(text);
                v['--acento-boton'] = rgb(btn);
                v['--acento-boton-2'] = rgb(mix(btn, black, 0.2));
                v['--acento-tinta'] = contrast(btn, white) >= 4.5 ? '#fff' : rgb(ink);
                v['--acento-suave'] = rgb(mix(base, white, 0.9));
                v['--acento-anillo'] = rgb(base, 0.18);
                v['--burbuja-nos'] = rgb(mix(base, white, 0.885));
                v['--scroll'] = rgb(base, 0.32);
                v['--scroll-2'] = rgb(base, 0.55);
            } else {
                text = mix(base, white, 0.55);
                for (i = 0; i < 12 && contrast(text, deep) < 7; i++) {
                    text = mix(text, white, 0.15);
                }
                btn = mix(base, white, 0.15);
                for (i = 0; i < 12 && contrast(btn, white) < 4.5; i++) {
                    btn = mix(btn, black, 0.1);
                }
                v['--acento-texto'] = rgb(text);
                v['--acento-boton'] = rgb(btn);
                v['--acento-boton-2'] = rgb(mix(btn, white, 0.12));
                v['--acento-tinta'] = '#fff';
                v['--acento-suave'] = rgb(mix(base, deep, 0.8));
                v['--acento-anillo'] = rgb(text, 0.25);
                v['--burbuja-nos'] = rgb(mix(base, deep, 0.78));
                v['--scroll'] = rgb(text, 0.3);
                v['--scroll-2'] = rgb(text, 0.5);
            }
            Object.keys(v).forEach(function (k) {
                box.style.setProperty(k, v[k]);
            });
            var cols = brandColors(), n = cols.length;
            el.franja.style.background = n === 1 ? rgb(cols[0]) : 'linear-gradient(90deg, ' + cols.map(function (c, j) {
                return rgb(c) + ' ' + (j * 100 / n).toFixed(2) + '% ' + ((j + 1) * 100 / n).toFixed(2) + '%';
            }).join(', ') + ')';
        }
        // Colours that come with the data (stages): kept, but readable on the page.
        function dataColour(c, d) {
            var y = st.dark && d ? hexToRgb(d) : null;
            if (y) {
                // The colour the organisation chose for a dark page.
                return { css: rgb(y), edge: false };
            }
            var x = hexToRgb(c);
            if (!x) {
                return { css: 'var(--tinta-3)', edge: false };
            }
            if (st.dark) {
                var deep = [28, 27, 26];
                for (var i = 0; i < 8 && contrast(x, deep) < 3; i++) {
                    x = mix(x, [255, 255, 255], 0.15);
                }
                return { css: rgb(x), edge: false };
            }
            return { css: rgb(x), edge: contrast(x, [255, 255, 255]) < 1.7 };
        }
        function applyTheme() {
            st.dark = computeDark();
            box.classList.toggle('mrd-dark', st.dark);
            setColours();
        }
        applyTheme();
        function onThemeChange() {
            var was = st.dark;
            applyTheme();
            if (was !== st.dark && st.ready) {
                renderAll();
                postPanelContext();
            }
        }
        if (mqDark) {
            if (mqDark.addEventListener) {
                mqDark.addEventListener('change', onThemeChange);
            } else if (mqDark.addListener) {
                mqDark.addListener(onThemeChange);
            }
        }
        if (root.MutationObserver) {
            new MutationObserver(onThemeChange).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
        }

        /* -------- The app window: keyboard, safe areas -------- */
        function touchy() {
            return !!(cfg.inapp || (mqCoarse && mqCoarse.matches));
        }
        function narrow() {
            return root.innerWidth <= 760;
        }
        if (cfg.inapp) {
            var vp = document.querySelector('meta[name="viewport"]');
            if (vp && !/viewport-fit/.test(vp.content)) {
                vp.content += ', viewport-fit=cover';
            }
        }
        function fitViewport() {
            var vv = root.visualViewport;
            if (!vv || !touchy()) {
                box.style.removeProperty('--mrd-vh');
                box.style.removeProperty('--mrd-vtop');
                return;
            }
            box.style.setProperty('--mrd-vh', Math.round(vv.height) + 'px');
            box.style.setProperty('--mrd-vtop', Math.round(vv.offsetTop) + 'px');
        }
        if (root.visualViewport) {
            root.visualViewport.addEventListener('resize', function () {
                var hilo = box.querySelector('.mrd-hilo');
                var atEnd = hilo && hilo.scrollHeight - hilo.scrollTop - hilo.clientHeight < 80;
                fitViewport();
                if (atEnd) {
                    hilo.scrollTop = hilo.scrollHeight;
                }
            });
            root.visualViewport.addEventListener('scroll', fitViewport);
        }
        fitViewport();

        /* ---------------------------------------------------------------------------------------------------------------
           Data helpers
           --------------------------------------------------------------------------------------------------------------- */
        function conv(id) {
            return st.convs[id] || null;
        }
        function allConvs() {
            return Object.keys(st.convs).map(function (k) {
                return st.convs[k];
            });
        }
        function teams() {
            return (st.inboxes && st.inboxes.teams) || [];
        }
        function team(id) {
            var t = null;
            teams().concat(st.extras.teams || []).some(function (x) {
                if (x.id === id) {
                    t = x;
                    return true;
                }
                return false;
            });
            return t;
        }
        function teamName(id) {
            var t = team(id);
            return t ? t.name : s('noteam');
        }
        function teamIcon(t) {
            if (!t) {
                return 'bandeja';
            }
            if (t.icon && ICONS[t.icon]) {
                return t.icon;
            }
            var found = 'bandeja';
            TEAM_ICONS.some(function (p) {
                if (p[0].test(t.name || '')) {
                    found = p[1];
                    return true;
                }
                return false;
            });
            return found;
        }
        function myTeamIds() {
            var ids = {};
            teams().forEach(function (t) {
                if (t.mine) {
                    ids[t.id] = true;
                }
            });
            (st.extras.teams || []).forEach(function (t) {
                if (t.mine) {
                    ids[t.id] = true;
                }
            });
            return ids;
        }
        function peopleIn(teamid) {
            return (st.extras.people || []).filter(function (p) {
                return (p.teams || []).some(function (t) {
                    return (t && t.id !== undefined ? t.id : t) === teamid;
                });
            });
        }
        function personName(agentid) {
            var p = null;
            (st.extras.people || []).some(function (x) {
                if (x.agentid === agentid) {
                    p = x;
                    return true;
                }
                return false;
            });
            return p;
        }
        function isMine(c) {
            return !!(c.assignee && c.assignee.me);
        }
        function inInbox(c, inbox) {
            if (inbox === 'mine') {
                return isMine(c);
            }
            if (inbox === 'unassigned') {
                if (c.assignee || c.section === 'resolved') {
                    return false;
                }
                var mine = myTeamIds(), any = Object.keys(mine).length;
                return any ? (!c.teamid || !!mine[c.teamid]) : !!me.seesall;
            }
            if (inbox === 'noteam') {
                return !c.teamid;
            }
            return c.teamid === +String(inbox).replace('team:', '');
        }
        function canReply(c) {
            return !!(c && c.canreply && (!c.replyuntil || c.replyuntil * 1000 > nowMs()));
        }
        function resolvedToday(c) {
            return c.section === 'resolved' && dayKey((c.lastactivity || 0) * 1000) === dayKey(nowMs());
        }
        function countLocal(inbox) {
            return allConvs().filter(function (c) {
                return c.section === 'reply' && inInbox(c, inbox);
            }).length;
        }
        function inboxCounts() {
            var ib = st.inboxes || {};
            return {
                mine: ib.mine && ib.mine.reply !== undefined ? ib.mine.reply : countLocal('mine'),
                unassigned: ib.unassigned && ib.unassigned.reply !== undefined ? ib.unassigned.reply : countLocal('unassigned')
            };
        }
        function contactFirst(c) {
            return firstName(c && c.contact) || s('thecontact');
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Avatars: a critter per contact (the seed, the phone in digits with its prefix), a ring per child
           --------------------------------------------------------------------------------------------------------------- */
        function seedOf(contact) {
            return digits(contact && contact.phone) || String((contact && contact.name) || '');
        }
        function bicho(seed, disc) {
            return root.BichitoHk ? root.BichitoHk.svg(seed, { bg: disc !== false, dark: st.dark }) : '';
        }
        function avContact(contact, size) {
            var k = contact.kind, seed = seedOf(contact), cls = size ? ' ' + size : '';
            if (k === 'staff') {
                return '<span class="mrd-av mrd-cuadrado mrd-personal mrd-con-bicho' + cls + '" aria-hidden="true">' +
                    bicho(seed, false) + '<span class="mrd-insignia">' + ico('gafete') + '</span></span>';
            }
            if (k === 'supplier') {
                return '<span class="mrd-av mrd-cuadrado mrd-proveedor mrd-con-bicho' + cls + '" aria-hidden="true">' +
                    bicho(seed, false) + '<span class="mrd-insignia">' + ico('caja') + '</span></span>';
            }
            if (k === 'alumni') {
                return '<span class="mrd-av mrd-con-bicho' + cls + '" aria-hidden="true">' + bicho(seed) +
                    '<span class="mrd-insignia">' + ico('birrete') + '</span></span>';
            }
            var rings = (contact.rings || []).filter(function (r) {
                return r && r.color;
            });
            if (!rings.length) {
                return '<span class="mrd-av mrd-con-bicho' + cls + '" aria-hidden="true">' + bicho(seed) + '</span>';
            }
            var edge = false;
            if (rings.every(function (r) {
                return r.dashed;
            })) {
                var c0 = dataColour(rings[0].color, rings[0].dark);
                return '<span class="mrd-av mrd-anillo-fuera mrd-con-bicho' + (c0.edge ? ' mrd-borde' : '') + cls +
                    '" style="--c: ' + c0.css + '" aria-hidden="true">' + bicho(seed) + '</span>';
            }
            var step = 360 / rings.length, gap = rings.length > 1 ? 7 : 0;
            var parts = rings.map(function (r, i) {
                var col = dataColour(r.color, r.dark);
                edge = edge || col.edge;
                var a0 = i * step + gap / 2, a1 = (i + 1) * step - gap / 2;
                if (!r.dashed) {
                    return col.css + ' ' + a0 + 'deg ' + a1 + 'deg, transparent ' + a1 + 'deg ' + (a1 + gap) + 'deg';
                }
                var out = [];
                for (var x = a0; x < a1; x += 16) {
                    var e = Math.min(x + 9, a1), f = Math.min(x + 16, a1);
                    out.push(col.css + ' ' + x + 'deg ' + e + 'deg, transparent ' + e + 'deg ' + f + 'deg');
                }
                return out.join(', ') + ', transparent ' + a1 + 'deg ' + (a1 + gap) + 'deg';
            }).join(', ');
            return '<span class="mrd-av mrd-anillo mrd-con-bicho' + (edge ? ' mrd-borde' : '') + cls +
                '" style="--anillo: conic-gradient(' + parts + ')" aria-hidden="true">' + bicho(seed) + '</span>';
        }
        function avAgent(p, size) {
            if (!p) {
                return '<span class="mrd-av mrd-nadie ' + (size || '') + '" aria-hidden="true">' + ico('nadie') + '</span>';
            }
            var mine = p.me || (me.agentid && p.agentid === me.agentid);
            return '<span class="mrd-av mrd-equipo ' + (mine ? 'mrd-yo-av ' : '') + (size || '') + '" aria-hidden="true">' +
                esc(cap(firstName(p)).charAt(0)) + '</span>';
        }
        var KIND_LABEL = { staff: 'kindstaff', supplier: 'kindsupplier', alumni: 'kindalumni' };
        // The line under the name: the kind in bold for staff, suppliers and alumni, and a dot of the stage's colour
        // before each child the server names in the rings.
        function relHtml(contact) {
            var line = contact.line || '';
            var h = esc(line);
            (contact.rings || []).forEach(function (r) {
                if (r && r.name && r.color) {
                    var n = esc(r.name), at = h.indexOf(n);
                    if (at >= 0) {
                        var col = dataColour(r.color, r.dark);
                        h = h.slice(0, at) + '<i class="mrd-pe' + (r.dashed ? ' mrd-fuera' : '') + '" style="--e: ' + col.css +
                            '" aria-hidden="true"></i>' + h.slice(at);
                    }
                }
            });
            var key = KIND_LABEL[contact.kind];
            if (key) {
                var label = s(key);
                if (line.toLowerCase().indexOf(label.toLowerCase()) === 0) {
                    h = '<b class="mrd-tipo-t">' + esc(line.slice(0, label.length)) + '</b>' + h.slice(esc(line.slice(0, label.length)).length);
                } else {
                    h = '<b class="mrd-tipo-t">' + esc(label) + '</b>' + (line ? ' · ' + h : '');
                }
            }
            if (!h) {
                h = esc(fmtPhone(contact.phone));
            }
            return h;
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Backgrounds: six, faint on purpose
           --------------------------------------------------------------------------------------------------------------- */
        function bgInk() {
            return st.dark
                ? { tinta: 'rgba(242, 239, 235, 0.055)', punto: 'rgba(242, 239, 235, 0.12)', trazo: 'rgba(242,239,235,0.09)', rojo: 0.22, color: 0.1 }
                : { tinta: 'rgba(28, 26, 25, 0.06)', punto: 'rgba(28, 26, 25, 0.16)', trazo: 'rgba(28,26,25,0.09)', rojo: 0.16, color: 0.09 };
        }
        function svgUrl(svg) {
            return 'url("data:image/svg+xml,' + encodeURIComponent(svg) + '")';
        }
        var BGS = [
            { id: 'cuaderno', css: function (t) {
                return 'linear-gradient(90deg, transparent 0 44px, rgba(206, 32, 41, ' + t.rojo + ') 44px 45.5px, transparent 45.5px), ' +
                    'repeating-linear-gradient(transparent 0 31px, ' + t.tinta + ' 31px 32px)';
            } },
            { id: 'garabatos', css: function (t) {
                return svgUrl('<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220" fill="none" stroke="' + t.trazo +
                    '" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
                    '<path d="M22 66l38-38 9 9-38 38zM22 66l-4 13 13-4M53 35l9 9"/>' +
                    '<path d="M124 26h30a5 5 0 0 1 5 5v34h-30a5 5 0 0 0-5 5zM124 26v44M139 36h12M139 44h12"/>' +
                    '<path d="M58 128c-12 0-19 10-16 22s12 17 16 14c4 3 13-2 16-14s-4-22-16-22zM58 128c0-6 3-9 9-10"/>' +
                    '<circle cx="164" cy="136" r="15"/><path d="M150 130c9 4 19 4 28 0M150 143c9-4 19-4 28 0M164 121v30"/>' +
                    '<path d="M190 84l3 7 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z"/>' +
                    '<rect x="24" y="176" width="38" height="24" rx="4"/><path d="M24 188h38M31 200v4M55 200v4M30 181h9M46 181h9"/>' +
                    '<path d="M104 112v-26l15-4v26"/><circle cx="99" cy="112" r="5"/><circle cx="114" cy="108" r="5"/>' +
                    '<path d="M118 176l40 16-6 15-40-16zM127 180l-2 5M136 184l-3 7M145 188l-2 5"/></svg>') + ' 0 0 / 220px 220px';
            } },
            { id: 'puntos', css: function (t) {
                return 'radial-gradient(circle at 1.5px 1.5px, ' + t.punto + ' 1.4px, transparent 1.6px) 0 0 / 20px 20px';
            } },
            { id: 'olas', css: function (t) {
                return svgUrl('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="36"><path d="M0 18q15-9 30 0t30 0 30 0 30 0" ' +
                    'fill="none" stroke="' + t.trazo + '" stroke-width="1.3"/></svg>') + ' 0 0 / 120px 36px';
            } },
            { id: 'cuadricula', css: function (t) {
                return 'linear-gradient(' + t.tinta + ' 1px, transparent 1px) 0 0 / 22px 22px, linear-gradient(90deg, ' + t.tinta +
                    ' 1px, transparent 1px) 0 0 / 22px 22px';
            } },
            { id: 'colores', css: function (t) {
                var cols = brandColors(), stops = [], w = 44;
                // Four places at least, as with the four colours of a logo: with fewer colours, the rest stay empty.
                while (cols.length < 4) {
                    cols = cols.concat([null]);
                }
                cols.forEach(function (c, i) {
                    if (!c) {
                        stops.push('transparent ' + (i * w) + 'px ' + ((i + 1) * w) + 'px');
                        return;
                    }
                    stops.push(rgb(c, t.color) + ' ' + (i * w) + 'px ' + (i * w + 8) + 'px', 'transparent ' + (i * w + 8) + 'px ' + ((i + 1) * w) + 'px');
                });
                return 'repeating-linear-gradient(115deg, ' + stops.join(', ') + ')';
            } }
        ];
        function bgFor(c) {
            var k = 0;
            if (st.bg.mode === 'fixed') {
                BGS.forEach(function (b, i) {
                    if (b.id === st.bg.fixed) {
                        k = i;
                    }
                });
            } else if (st.bg.mode === 'daily') {
                var p = zp(nowMs());
                k = Math.floor((Date.UTC(p.y, p.mo - 1, p.d) - Date.UTC(p.y, 0, 0)) / 86400000) % BGS.length;
            } else {
                var seed = seedOf(c ? c.contact : {}) || String(c ? c.id : 0);
                k = seed.split('').reduce(function (h, ch) {
                    return (h * 31 + ch.charCodeAt(0)) >>> 0;
                }, 7) % BGS.length;
            }
            return BGS[k];
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Toast, network notice, image viewer
           --------------------------------------------------------------------------------------------------------------- */
        var toastT = 0;
        function toast(text, error) {
            el.toast.innerHTML = ico(error ? 'alerta' : 'check') + '<span>' + esc(text) + '</span>';
            el.toast.classList.toggle('mrd-error', !!error);
            el.toast.classList.add('mrd-ver');
            clearTimeout(toastT);
            toastT = setTimeout(function () {
                el.toast.classList.remove('mrd-ver');
            }, error ? 4200 : 2800);
        }
        function failToast(e) {
            toast(s('toasterror', (e && e.message) || (e && e.errorcode) || ''), true);
        }
        function showRed(kind) {
            if (!kind) {
                el.red.hidden = true;
                return;
            }
            el.red.hidden = false;
            el.red.innerHTML = kind === 'expired'
                ? ico('alerta') + '<span>' + esc(s('sessionexpired')) + '</span><button type="button" data-a="reload">' + esc(s('signin')) + '</button>'
                : ico('alerta') + '<span>' + esc(s('offline')) + '</span>';
        }
        function openViewer(url, name) {
            el.visor.hidden = false;
            el.visor.setAttribute('aria-label', name || s('image'));
            el.visor.innerHTML = '<button type="button" class="mrd-visor-cerrar" data-a="visorclose" aria-label="' + esc(s('closeviewer')) + '">' +
                ico('cerrar') + '</button><img src="' + esc(url) + '" alt="' + esc(name || '') + '">';
            el.visor.querySelector('button').focus();
        }
        function closeViewer() {
            el.visor.hidden = true;
            el.visor.innerHTML = '';
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Rail: «Mías» and «Sin coger» always on top, then the person's departments
           --------------------------------------------------------------------------------------------------------------- */
        function brandMark() {
            if (brand.logo) {
                return '<img class="mrd-logo" src="' + esc(brand.logo) + '" alt="">';
            }
            return '<span class="mrd-inicial" aria-hidden="true">' + esc(brand.initial || cap(brand.name || '').charAt(0) || '·') + '</span>';
        }
        function trayButton(key, icon, name, lines, n, strong) {
            var on = st.inbox === key;
            return '<button type="button" class="mrd-bandeja' + (on ? ' mrd-on' : '') + (strong ? ' mrd-fuerte' : '') + (n ? '' : ' mrd-vacia') +
                '" data-a="inbox" data-v="' + esc(key) + '" title="' + esc(name + ': ' + lines.join(' · ')) + '"' +
                (on ? ' aria-current="true"' : '') + '>' + ico(icon) + '<span class="mrd-t">' + esc(name) +
                lines.map(function (l) {
                    return '<small>' + esc(l) + '</small>';
                }).join('') + '</span><span class="mrd-n' + (n ? '' : ' mrd-cero') + '">' + n + '</span></button>';
        }
        function teamLines(t) {
            var n = t.reply !== undefined ? t.reply : countLocal('team:' + t.id);
            var u = t.unassigned || 0;
            if (!n) {
                return { n: 0, lines: [s('nothingtoreply')] };
            }
            var lines = [s('toreply', n)];
            if (u) {
                lines.push(s('unassignedcount', u));
            }
            return { n: n, lines: lines };
        }
        function nightCount() {
            if (!isMorning()) {
                return 0;
            }
            return allConvs().filter(function (c) {
                return c.section === 'reply' && arrivedAtNight(c);
            }).length;
        }
        function greeting() {
            var h = zp(nowMs()).h;
            var key = h < 14 ? 'goodmorning' : h < 21 ? 'goodafternoon' : 'goodevening';
            return s(key, firstName(me) || '');
        }
        function renderRail() {
            var counts = inboxCounts();
            var mine = teams().filter(function (t) {
                return t.mine;
            });
            var others = teams().filter(function (t) {
                return !t.mine;
            });
            var where = mine.length === 1 ? mine[0].name : s('yourteamswhere');
            var unLines;
            if (!mine.length) {
                unLines = [counts.unassigned ? s('unassignedall', counts.unassigned) : s('unassignedallnone')];
            } else {
                unLines = [counts.unassigned ? s('unassignedin', { n: counts.unassigned, where: where }) : s('unassignedinnone', where)];
            }
            var h = '<div class="mrd-marca">' + brandMark() + '<div class="mrd-marca-t"><small>' + esc(brand.name || '') + '</small><strong>' +
                esc(s('apptitle')) + '</strong></div></div>';
            var night = nightCount();
            if (night) {
                h += '<div class="mrd-buenos"><b>' + esc(greeting()) + '</b>' + esc(night === 1 ? s('nightarrived1') : s('nightarrived', night)) + '</div>';
            } else if (HOURS_ON && !openAt(nowMs())) {
                var next = nextOpening(nowMs());
                h += '<div class="mrd-buenos"><b>' + ico('luna') + esc(s('closednow')) + '</b>' +
                    esc(next ? s('opensat', whenPhrase(next)) : s('closedhelp')) + '</div>';
            }
            h += trayButton('mine', 'yo', s('mine'), [counts.mine ? s('toreply', counts.mine) : s('nothingtoreply')], counts.mine, true);
            h += trayButton('unassigned', 'nadie', s('unassigned'), unLines, counts.unassigned, true);
            if (mine.length) {
                h += '<div class="mrd-rotulo">' + esc(mine.length === 1 ? s('yourteam') : s('yourteams')) + '</div><div class="mrd-rotulo-sep"></div>';
                h += mine.map(function (t) {
                    var x = teamLines(t);
                    return trayButton('team:' + t.id, teamIcon(t), t.name, x.lines, x.n);
                }).join('');
            }
            var noteam = allConvs().some(function (c) {
                return !c.teamid && c.section !== 'resolved';
            });
            if (me.seesall && (others.length || noteam)) {
                var opened = st.otros || others.some(function (t) {
                    return st.inbox === 'team:' + t.id;
                }) || st.inbox === 'noteam';
                var nOthers = others.reduce(function (a, t) {
                    return a + teamLines(t).n;
                }, 0);
                h += '<button type="button" class="mrd-bandeja mrd-plegar" data-a="otros" aria-expanded="' + opened + '">' +
                    ico(opened ? 'abajo' : 'der') + '<span class="mrd-t">' + esc(s('otherteams')) + '<small>' +
                    esc(nOthers ? s('toreply', nOthers) : s('nothingtoreply')) + '</small></span><span class="mrd-n' +
                    (nOthers ? '' : ' mrd-cero') + '">' + nOthers + '</span></button>';
                if (opened) {
                    var menu = others.filter(function (t) {
                        return !t.internal;
                    }), inside = others.filter(function (t) {
                        return t.internal;
                    });
                    h += '<div class="mrd-otros">' + menu.map(function (t) {
                        var x = teamLines(t);
                        return trayButton('team:' + t.id, teamIcon(t), t.name, x.lines, x.n);
                    }).join('');
                    if (inside.length) {
                        h += '<div class="mrd-rotulo-mini">' + esc(s('internal')) + '</div>' + inside.map(function (t) {
                            var x = teamLines(t);
                            return trayButton('team:' + t.id, teamIcon(t), t.name, x.lines, x.n);
                        }).join('');
                    }
                    if (noteam) {
                        var nn = countLocal('noteam');
                        h += trayButton('noteam', 'bandeja', s('noteam'), [nn ? s('toreply', nn) : s('nothingtoreply')], nn);
                    }
                    h += '</div>';
                }
            }
            h += legendHtml();
            var sig = me.signature ? '<small title="' + esc(s('signature', me.signature)) + '">' + ico('lapiz') + '<span>' + esc(me.signature) +
                '</span></small>' : '';
            h += '<div class="mrd-yo">' + avAgent({ name: me.name, firstname: me.firstname, me: true }, 'mrd-m') +
                '<div class="mrd-yo-t"><b>' + esc(me.name || '') + '</b>' + (READONLY ? '<small><span>' + esc(s('readonlyshort')) + '</span></small>'
                : sig) + '</div>' +
                '<button type="button" class="mrd-yo-mas" data-a="pop" data-v="perfil" aria-label="' + esc(s('backgroundsbutton')) +
                '" aria-expanded="' + (st.pop === 'perfil') + '">' + ico('fondo') + '</button>' + (st.pop === 'perfil' ? popProfile() : '') + '</div>';
            h += '<div class="mrd-oks">' + esc(s('poweredby')) + '</div>';
            var keep = el.rail.scrollTop;
            el.rail.innerHTML = h;
            el.rail.scrollTop = keep;
            var pp = el.rail.querySelector('.mrd-pop-perfil');
            if (pp) {
                var br = el.rail.querySelector('.mrd-yo-mas').getBoundingClientRect(), rr = el.rail.getBoundingClientRect();
                pp.style.bottom = Math.max(8, root.innerHeight - br.top + 8) + 'px';
                pp.style.left = Math.max(8, rr.left + 10) + 'px';
            }
            renderPie(counts);
        }
        function legendHtml() {
            var stages = (cfg.stages || []).map(function (x) {
                var col = dataColour(x.color, x.dark);
                return '<span><i class="mrd-pe" style="--e: ' + col.css + '"></i>' + esc(x.label) + '</span>';
            }).join('');
            return '<div class="mrd-leyenda" aria-label="' + esc(s('legend')) + '"><div class="mrd-leyenda-etapas">' + stages +
                (stages ? '' : '<span><i class="mrd-muestra-anillo"></i>' + esc(s('legendring')) + '</span>') + '</div>' +
                '<div class="mrd-leyenda-formas"><span><i class="mrd-muestra-anillo mrd-discontinuo"></i>' + esc(s('legenddashed')) + '</span>' +
                '<span>' + ico('gafete') + esc(s('kindstaff')) + '</span><span>' + ico('caja') + esc(s('kindsupplier')) + '</span>' +
                '<span>' + ico('birrete') + esc(s('kindalumni')) + '</span></div></div>';
        }
        function renderPie(counts) {
            var inTeam = st.inbox !== 'mine' && st.inbox !== 'unassigned';
            var tab = function (key, icon, label, n) {
                var on = st.inbox === key && st.capa === 'lista';
                return '<button type="button" class="' + (on ? 'mrd-on' : '') + '" data-a="inbox" data-v="' + key + '">' + ico(icon) +
                    esc(label) + (n ? '<span class="mrd-n">' + n + '</span>' : '') + '</button>';
            };
            el.pie.innerHTML = tab('mine', 'yo', s('mine'), counts.mine) + tab('unassigned', 'nadie', s('unassigned'), counts.unassigned) +
                '<button type="button" class="' + (st.capa === 'bandejas' || (inTeam && st.capa === 'lista') ? 'mrd-on' : '') +
                '" data-a="capa" data-v="bandejas">' + ico('edificio') + esc(s('tabteams')) + '</button>';
        }
        function popProfile() {
            var t = bgInk();
            var modes = [['family', 'bgfamily', 'bgfamilyhelp'], ['daily', 'bgdaily', 'bgdailyhelp'], ['fixed', 'bgfixed', 'bgfixedhelp']];
            return '<div class="mrd-pop mrd-pop-perfil" role="dialog" aria-label="' + esc(s('bgtitle')) + '"><h5>' + esc(s('bgtitle')) + '</h5>' +
                '<div class="mrd-fondo-modos">' + modes.map(function (m) {
                    var on = st.bg.mode === m[0];
                    return '<button type="button" class="mrd-fondo-modo' + (on ? ' mrd-on' : '') + '" data-a="bgmode" data-v="' + m[0] +
                        '" aria-pressed="' + on + '"><b>' + esc(s(m[1])) + '</b><small>' + esc(s(m[2])) + '</small></button>';
                }).join('') + '</div><div class="mrd-fondo-muestras">' + BGS.map(function (b) {
                    var on = st.bg.mode === 'fixed' && st.bg.fixed === b.id;
                    return '<button type="button" class="mrd-fondo-muestra' + (on ? ' mrd-on' : '') + '" data-a="bgfixed" data-v="' + b.id +
                        '" aria-pressed="' + on + '"><span class="mrd-fondo-tela" style="background: ' + esc(b.css(t)) +
                        ', var(--fondo)"><i></i><i></i></span><small>' + esc(s('bg' + b.id)) + '</small></button>';
                }).join('') + '</div><p class="mrd-fondo-nota">' + esc(s('bgnote')) + '</p></div>';
        }

        /* ---------------------------------------------------------------------------------------------------------------
           List: what waits for an answer first, with who has it and how long it has waited (in working hours)
           --------------------------------------------------------------------------------------------------------------- */
        function inboxTitle() {
            if (st.inbox === 'mine') {
                return s('mine');
            }
            if (st.inbox === 'unassigned') {
                return s('unassigned');
            }
            if (st.inbox === 'noteam') {
                return s('noteam');
            }
            return teamName(+st.inbox.replace('team:', ''));
        }
        function inboxSub() {
            var reply = allConvs().filter(function (c) {
                return c.section === 'reply' && inInbox(c, st.inbox);
            }).length;
            var r = reply ? s('toreply', reply) : s('nothingtoreply');
            if (st.inbox === 'mine') {
                return s('minesub', r);
            }
            if (st.inbox === 'unassigned') {
                var names = teams().filter(function (t) {
                    return t.mine;
                }).map(function (t) {
                    return t.name;
                });
                return names.length ? s('unassignedsub', joinList(names)) : s('unassignedsuball');
            }
            if (st.inbox === 'noteam') {
                return r;
            }
            var id = +st.inbox.replace('team:', ''), t = team(id), parts = [r];
            var ppl = peopleIn(id).map(function (p) {
                return p.agentid === me.agentid ? s('you') : firstName(p);
            });
            if (ppl.length) {
                parts.push(s('teampeople', joinList(ppl)));
            } else if (st.extras.people && st.extras.people.length) {
                parts.push(s('teamnobody'));
            }
            if (t && t.internal) {
                parts.push(s('teaminternal'));
            }
            return parts.join(' · ');
        }
        function renderListHead() {
            el.lista.innerHTML = '<div class="mrd-lista-cab"><div class="mrd-titulo"><span class="mrd-marca-movil">' + brandMark() +
                '</span><h1></h1></div><p></p><label class="mrd-buscar">' + ico('buscar') +
                '<input type="search" data-role="buscar" autocomplete="off" placeholder="' + esc(s('search')) + '" aria-label="' +
                esc(s('search')) + '" value="' + esc(st.search) + '"><button type="button" class="mrd-limpiar" data-a="clearsearch" aria-label="' +
                esc(s('searchclear')) + '"' + (st.search ? '' : ' hidden') + '>' + ico('cerrar') + '</button></label></div>' +
                '<div class="mrd-lista-cuerpo"></div>';
            updateListTitle();
        }
        function updateListTitle() {
            var h1 = el.lista.querySelector('h1'), p = el.lista.querySelector('.mrd-lista-cab p');
            if (h1) {
                h1.textContent = inboxTitle();
                p.textContent = inboxSub();
            }
        }
        function convItem(c, opts) {
            var ct = c.contact || {};
            var tags = [];
            if (c.assignee) {
                tags.push('<span class="mrd-etq mrd-quien">' + avAgent(c.assignee, 'mrd-s') + esc(isMine(c) ? s('You') : firstName(c.assignee)) + '</span>');
            } else if (c.section !== 'resolved') {
                tags.push('<span class="mrd-etq mrd-sin">' + esc(s('unassigned')) + '</span>');
            }
            if (opts.night) {
                tags.push('<span class="mrd-etq mrd-de-noche">' + ico('luna') + esc(s('wrotenight', fmtTime(c.waitingsince * 1000))) + '</span>');
            }
            var right;
            if (c.section === 'reply' && c.waitingsince && !opts.night) {
                var wt = waitText(c);
                right = '<span class="mrd-conv-espera ' + waitTier(c) + '" title="' + esc(s('waits', wt)) + '">' + ico('reloj') + esc(wt) + '</span>';
            } else {
                var at = (opts.night ? c.waitingsince : (c.lastactivity || (c.preview && c.preview.at) || 0)) * 1000;
                right = '<span class="mrd-conv-hora">' + esc(at ? (opts.night ? fmtTime(at) : listTime(at)) : '') + '</span>';
            }
            if (c.section !== 'resolved') {
                if (!canReply(c)) {
                    tags.push('<span class="mrd-etq mrd-retomar">' + ico('mensaje') + esc(s('retake')) + '</span>');
                } else if (c.replyuntil && c.replyuntil * 1000 - nowMs() < 4 * 3600000) {
                    tags.push('<span class="mrd-etq mrd-espera">' + esc(s('windowleft', leftText(c.replyuntil * 1000 - nowMs()))) + '</span>');
                }
            }
            if (ct.changed && ct.changed.text && (!ct.changed.until || ct.changed.until * 1000 > nowMs())) {
                tags.push('<span class="mrd-etq mrd-cambio">' + ico('check') + esc(ct.changed.text) + '</span>');
            }
            if (c.passed && (isMine(c) || c.passed.toteam)) {
                tags.push('<span class="mrd-etq">' + ico('flecha') + esc(c.passed.toteam ? s('passedtoteam', { by: c.passed.by, team: teamName(c.teamid) })
                    : s('passedtoyou', c.passed.by)) + '</span>');
            }
            if (opts.withTeam) {
                var t = team(c.teamid);
                tags.push('<span class="mrd-etq mrd-dep">' + ico(teamIcon(t)) + esc(t ? t.name : s('noteam')) + '</span>');
            }
            var pv = c.preview || {}, txt = esc(pv.text || '');
            if (pv.private) {
                txt = esc(s('previewnote', pv.text || ''));
            } else if (pv.text && !pv.incoming) {
                txt = ico('checks') + (pv.author ? esc((pv.author.me ? s('You') : firstName(pv.author)) + ': ') : '') + txt;
            }
            return '<button type="button" class="mrd-conv' + (st.open === c.id ? ' mrd-on' : '') + (c.unread ? ' mrd-nuevo' : '') +
                '" data-a="conv" data-v="' + c.id + '"' + (st.open === c.id ? ' aria-current="true"' : '') + '>' + avContact(ct) +
                '<span class="mrd-conv-cuerpo"><span class="mrd-conv-l1"><span class="mrd-conv-nombre">' + esc(ct.name || fmtPhone(ct.phone)) +
                '</span>' + right + '</span><span class="mrd-conv-rel">' + relHtml(ct) + '</span>' +
                '<span class="mrd-conv-l1"><span class="mrd-conv-txt">' + txt + '</span>' +
                (c.unread ? '<span class="mrd-nuevo-punto" role="img" aria-label="' + esc(s('unread')) + '"></span>' : '') + '</span>' +
                (tags.length ? '<span class="mrd-conv-pie">' + tags.join('') + '</span>' : '') + '</span></button>';
        }
        function byWait(a, b) {
            return (waitMs(b) - waitMs(a)) || ((a.waitingsince || 0) - (b.waitingsince || 0));
        }
        function byActivity(a, b) {
            return (b.lastactivity || 0) - (a.lastactivity || 0);
        }
        function searchMatch(c, q) {
            var ct = c.contact || {};
            var hay = [ct.name, ct.line, c.preview && c.preview.text, teamName(c.teamid)].join(' ').toLowerCase();
            var d = digits(q);
            return hay.indexOf(q.toLowerCase()) >= 0 || (d.length >= 3 && digits(ct.phone).indexOf(d) >= 0);
        }
        function renderListBody() {
            var body = el.lista.querySelector('.mrd-lista-cuerpo');
            if (!body) {
                return;
            }
            var keep = body.scrollTop, h = '';
            if (!st.ready) {
                body.innerHTML = '<div class="mrd-vacio">' + esc(s('loading')) + '</div>';
                return;
            }
            var q = st.search.trim();
            var withTeam = q || st.inbox === 'mine' || st.inbox === 'unassigned';
            var all = allConvs().filter(function (c) {
                return q ? searchMatch(c, q) : inInbox(c, st.inbox);
            });
            if (q) {
                all.sort(byActivity);
                h = all.length ? '<div class="mrd-grupo-t">' + esc(s('searchresults')) + ' <span>' + all.length + '</span></div>' +
                    all.map(function (c) {
                        return convItem(c, { withTeam: true });
                    }).join('') : '<div class="mrd-vacio">' + esc(s('searchnone', q)) + '</div>';
                body.innerHTML = h;
                return;
            }
            var reply = all.filter(function (c) {
                return c.section === 'reply';
            }).sort(byWait);
            var waiting = all.filter(function (c) {
                return c.section === 'waiting';
            }).sort(byActivity);
            var done = all.filter(resolvedToday).sort(byActivity);
            var night = isMorning() ? reply.filter(arrivedAtNight).sort(function (a, b) {
                return a.waitingsince - b.waitingsince;
            }) : [];
            var rest = reply.filter(function (c) {
                return night.indexOf(c) < 0;
            });
            var item = function (night) {
                return function (c) {
                    return convItem(c, { withTeam: withTeam, night: night });
                };
            };
            if (night.length) {
                h += '<div class="mrd-grupo-t">' + ico('luna') + esc(s('groupnight')) + ' <span>' + night.length + '</span></div>' + night.map(item(true)).join('');
                if (rest.length) {
                    h += '<div class="mrd-grupo-t">' + esc(s('grouptoreply')) + ' <span>' + rest.length + '</span></div>' + rest.map(item(false)).join('');
                }
            } else if (rest.length) {
                h += '<div class="mrd-grupo-t">' + esc(s('grouptoreply')) + ' <span>' + rest.length + '</span></div>' + rest.map(item(false)).join('');
            } else {
                h += '<div class="mrd-grupo-t mrd-suave">' + esc(s('alldone')) + '</div>';
            }
            if (waiting.length) {
                h += '<div class="mrd-grupo-t mrd-suave">' + esc(s('groupwaiting')) + ' <span>' + waiting.length + '</span></div>' +
                    waiting.map(item(false)).join('');
            }
            if (done.length) {
                h += st.showResolved ? '<div class="mrd-grupo-t mrd-suave">' + esc(s('groupresolved')) + ' <span>' + done.length + '</span></div>' +
                    done.map(item(false)).join('')
                    : '<button type="button" class="mrd-ver-resueltas" data-a="resolvedlist">' +
                    esc(done.length === 1 ? s('showresolved1') : s('showresolved', done.length)) + '</button>';
            }
            body.innerHTML = h;
            body.scrollTop = keep;
        }
        function firstOf(inbox) {
            var list = allConvs().filter(function (c) {
                return inInbox(c, inbox) && c.section !== 'resolved';
            });
            var reply = list.filter(function (c) {
                return c.section === 'reply';
            }).sort(byWait);
            return reply[0] || list.sort(byActivity)[0] || null;
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Chat: header, thread and composer
           --------------------------------------------------------------------------------------------------------------- */
        function renderChat() {
            var c = conv(st.open);
            if (!c) {
                el.chat.innerHTML = '<div class="mrd-hilo"><div class="mrd-hilo-nada">' + (st.ready ? '<b>' + esc(s('pickone')) + '</b>' : esc(s('loading'))) +
                    '</div></div>';
                st.composerSig = '';
                return;
            }
            el.chat.innerHTML = '<div class="mrd-chat-cab"></div><div class="mrd-hilo" tabindex="-1"></div><div class="mrd-escribir"></div>';
            renderHead();
            renderThread();
            st.composerSig = '';
            renderComposer();
        }
        function renderHead() {
            var c = conv(st.open), head = el.chat.querySelector('.mrd-chat-cab');
            if (!c || !head) {
                return;
            }
            var ct = c.contact || {}, t = team(c.teamid);
            var owner = c.assignee ? avAgent(c.assignee, 'mrd-s') + '<span>' + esc(isMine(c) ? s('takenbyyou') : s('takenby', firstName(c.assignee))) + '</span>'
                : avAgent(null, 'mrd-s') + '<span>' + esc(s('unassigned')) + '</span>';
            var dis = READONLY ? ' disabled' : '';
            var resolved = c.section === 'resolved';
            head.innerHTML = '<button type="button" class="mrd-volver" data-a="back" data-v="lista" aria-label="' + esc(s('back')) + '">' + ico('izq') + '</button>' +
                '<h2>' + esc(ct.name || fmtPhone(ct.phone)) + '<span class="mrd-rel">' + relHtml(ct) + '</span></h2>' +
                '<div class="mrd-mandos"><button type="button" class="mrd-mando" data-a="pop" data-v="dep"' + dis + ' aria-expanded="' + (st.pop === 'dep') + '">' +
                ico(teamIcon(t)) + '<span>' + esc(t ? t.name : s('noteam')) + '</span>' + (READONLY ? '' : ico('abajo', 'mrd-flecha')) + '</button>' +
                '<button type="button" class="mrd-mando mrd-quien" data-a="pop" data-v="dueno"' + dis + ' aria-expanded="' + (st.pop === 'dueno') + '">' +
                owner + (READONLY ? '' : ico('abajo', 'mrd-flecha')) + '</button>' + (st.pop === 'dep' ? popTeams(c) : st.pop === 'dueno' ? popPeople(c) : '') + '</div>' +
                '<div class="mrd-cab-der"><button type="button" class="mrd-btn mrd-ver-ficha" data-a="ficha">' + ico('ficha') + esc(s('file')) + '</button>' +
                (READONLY ? '' : resolved
                    ? '<button type="button" class="mrd-btn mrd-hecho mrd-resolver" data-a="reopen" title="' + esc(s('reopen')) + '">' + ico('check') +
                    '<span class="mrd-t">' + esc(s('resolved')) + '</span></button>'
                    : '<button type="button" class="mrd-btn mrd-resolver" data-a="resolve">' + ico('check') + '<span class="mrd-t">' + esc(s('resolve')) +
                    '</span></button>') + '</div>';
            var pm = head.querySelector('.mrd-pop-menu');
            if (pm && st.pop === 'dueno') {
                var b = head.querySelector('.mrd-mando.mrd-quien');
                pm.style.setProperty('--x', b.offsetLeft + 'px');
            }
        }
        function popTeams(c) {
            var all = (st.extras.teams && st.extras.teams.length ? st.extras.teams : teams());
            var row = function (t) {
                var ppl = peopleIn(t.id).map(firstName);
                return '<button type="button" class="mrd-pop-fila' + (t.id === c.teamid ? ' mrd-on' : '') + '" data-a="team" data-v="' + t.id + '">' +
                    ico(teamIcon(t)) + '<span><b>' + esc(t.name) + '</b><small>' + esc(ppl.length ? ppl.join(', ') : s('teamnobody')) + '</small></span></button>';
            };
            var menu = all.filter(function (t) {
                return !t.internal;
            }), inside = all.filter(function (t) {
                return t.internal;
            });
            return '<div class="mrd-pop mrd-pop-menu" role="dialog" aria-label="' + esc(s('passtitle')) + '"><h5>' + esc(s('passtitle')) + '</h5>' +
                '<div class="mrd-pop-lista">' + menu.map(row).join('') + (inside.length ? '<div class="mrd-rotulo-mini">' + esc(s('internalshort')) + '</div>' +
                inside.map(row).join('') : '') + '</div></div>';
        }
        function popPeople(c) {
            var inTeam = peopleIn(c.teamid), ids = {};
            inTeam.forEach(function (p) {
                ids[p.agentid] = true;
            });
            var rest = (st.extras.people || []).filter(function (p) {
                return !ids[p.agentid];
            });
            var meFirst = function (a, b) {
                return (b.agentid === me.agentid) - (a.agentid === me.agentid) || String(a.name).localeCompare(String(b.name), LANG);
            };
            var row = function (p) {
                var mine = p.agentid === me.agentid;
                var on = c.assignee && c.assignee.agentid === p.agentid;
                var tn = (p.teams || []).map(function (t) {
                    return teamName(t && t.id !== undefined ? t.id : t);
                });
                return '<button type="button" class="mrd-pop-fila' + (on ? ' mrd-on' : '') + '" data-a="person" data-v="' + p.agentid + '">' +
                    avAgent(Object.assign({ me: mine }, p), 'mrd-m') + '<span><b>' + esc(mine ? s('me') : p.name) + '</b><small>' + esc(joinList(tn)) +
                    '</small></span></button>';
            };
            return '<div class="mrd-pop mrd-pop-menu" role="dialog" aria-label="' + esc(s('whotitle')) + '"><h5>' + esc(s('whotitle')) + '</h5>' +
                '<div class="mrd-pop-lista">' + inTeam.sort(meFirst).map(row).join('') +
                (rest.length ? '<div class="mrd-rotulo-mini">' + esc(inTeam.length ? s('otherpeople') : s('everyone')) + '</div>' + rest.sort(meFirst).map(row).join('') : '') +
                '<button type="button" class="mrd-pop-fila' + (c.assignee ? '' : ' mrd-on') + '" data-a="person" data-v="0">' + avAgent(null, 'mrd-m') +
                '<span><b>' + esc(s('nobodyyet')) + '</b><small>' + esc(s('backtounassigned')) + '</small></span></button></div></div>';
        }

        /* -------- Messages -------- */

        function textHtml(text, withMentions) {
            var h = esc(text || '');
            if (withMentions) {
                h = h.replace(/\[@([^\]]+)\]\(mention:\/\/(?:user|team)\/(\d+)\/[^)]*\)/g, function (m, name) {
                    return '<span class="mrd-mencion">@' + name + '</span>';
                });
                var names = (st.extras.people || []).map(function (p) {
                    return esc(firstName(p));
                }).filter(Boolean).sort(function (a, b) {
                    return b.length - a.length;
                });
                if (names.length) {
                    var re = new RegExp('(^|[\\s(])@(' + names.map(function (n) {
                        return n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    }).join('|') + ')(?![\\w\\u00C0-\\u024F])', 'g');
                    h = h.replace(re, '$1<span class="mrd-mencion">@$2</span>');
                }
            }
            // Links, and WhatsApp's own *bold* and _italics_.
            h = h.replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)»"']/g, function (u) {
                return '<a href="' + u + '"' + (cfg.inapp ? '' : ' target="_blank" rel="noopener"') + '>' + u + '</a>';
            });
            h = h.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,;:!?]|$)/g, '$1<b>$2</b>');
            h = h.replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,;:!?]|$)/g, '$1<i>$2</i>');
            return h;
        }
        function waves(seed, n) {
            var out = '', v = 7;
            String(seed).split('').forEach(function (ch) {
                v = (v * 31 + ch.charCodeAt(0)) % 233280;
            });
            for (var i = 0; i < n; i++) {
                v = (v * 9301 + 49297) % 233280;
                var hgt = 6 + (v / 233280) * 22 * (0.55 + 0.45 * Math.sin(i / 3));
                out += '<i style="height:' + Math.max(5, hgt).toFixed(0) + 'px"></i>';
            }
            return out;
        }
        function attachmentsHtml(m) {
            return (m.attachments || []).map(function (a, i) {
                var url = a.url || '', name = a.name || '';
                if (a.type === 'image') {
                    return '<button type="button" class="mrd-foto" data-a="foto" data-v="' + esc(url) + '" data-n="' + esc(name) + '" aria-label="' +
                        esc(s('image') + (name ? ': ' + name : '')) + '"><img src="' + esc(url) + '" alt="" loading="lazy"></button>';
                }
                if (a.type === 'video') {
                    return '<span class="mrd-foto"><video src="' + esc(url) + '" controls preload="metadata" playsinline></video></span>';
                }
                if (a.type === 'audio') {
                    return '<div class="mrd-audio" data-audio="' + esc(m.id + ':' + i) + '"><button type="button" class="mrd-play" data-a="play" data-v="' +
                        esc(url) + '" aria-label="' + esc(s('play')) + '">' + ico('play') + '</button><span class="mrd-ondas" aria-hidden="true">' +
                        waves(m.id + ':' + i, 34) + '</span><small></small></div>';
                }
                var ext = (/\.([a-z0-9]{1,4})(?:$|\?)/i.exec(name || url) || [])[1] || '';
                return '<a class="mrd-doc" href="' + esc(url) + '"' + (cfg.inapp ? '' : ' target="_blank" rel="noopener"') + '>' +
                    '<span class="mrd-hojita">' + esc(ext.toUpperCase().slice(0, 4)) + '</span><span class="mrd-doc-t"><b>' + esc(name || s('file')) +
                    '</b><small>' + esc([ext.toUpperCase(), fileSize(a.size, LANG)].filter(Boolean).join(' · ')) + '</small></span>' +
                    '<span class="mrd-abrir">' + esc(s('open')) + '</span></a>';
            }).join('');
        }
        function statusIcon(m) {
            if (m.pending) {
                return ico('reloj');
            }
            if (m.failed || m.status === 'failed') {
                return ico('alerta', 'mrd-mal');
            }
            if (m.status === 'read') {
                return ico('checks', 'mrd-leido');
            }
            if (m.status === 'delivered') {
                return ico('checks');
            }
            return ico('check');
        }
        function msgHtml(m) {
            var at = (m.at || 0) * 1000;
            var time = at ? fmtTime(at) : '';
            var id = ' data-id="' + esc(m.id) + '"';
            if (m.kind === 'activity') {
                return '<div class="mrd-sistema"' + id + '>' + ico('flecha') + '<span>' + esc(m.text) + (time ? ' · ' + esc(time) : '') + '</span></div>';
            }
            if (m.kind === 'note') {
                var who = m.author && m.author.me ? me.firstname || firstName(m.author) : firstName(m.author);
                return '<div class="mrd-nota' + (m.pending ? ' mrd-pendiente' : '') + '"' + id + '><div class="mrd-de">' + ico('nota') +
                    esc(s('internalnote', who || '')) + ' <span>· ' + esc(s('notehidden')) + (time ? ' · ' + esc(time) : '') + '</span></div>' +
                    attachmentsHtml(m) + (m.text ? '<p>' + textHtml(m.text, true) + '</p>' : '') + failHtml(m) + '</div>';
            }
            var body = attachmentsHtml(m) + (m.text ? '<p>' + textHtml(m.text, false) + '</p>' : '');
            if (m.kind === 'incoming') {
                return '<div class="mrd-msg mrd-fam"' + id + '>' + body + '<div class="mrd-msg-pie">' + esc(time) + '</div></div>';
            }
            var de, cls = 'mrd-msg mrd-nos';
            if (m.kind === 'bot') {
                cls += ' mrd-auto';
                var night = HOURS_ON && at && !openAt(at);
                de = '<div class="mrd-de">' + ico(night ? 'luna' : 'mensaje') + esc(night ? s('automaticnight') : s('automatic')) + '</div>';
            } else {
                var sig = m.signature || (m.author && m.author.name) || '';
                if (m.template) {
                    cls += ' mrd-plantilla';
                    de = '<div class="mrd-de">' + ico('mensaje') + esc(sig ? sig + ' · ' + s('approved') : cap(s('approved'))) + '</div>';
                } else {
                    de = sig ? '<div class="mrd-de">' + esc(sig) + '</div>' : '';
                }
            }
            if (m.pending) {
                cls += ' mrd-pendiente';
            }
            if (m.failed || m.status === 'failed') {
                cls += ' mrd-fallo';
            }
            return '<div class="' + cls + '"' + id + '>' + de + body + '<div class="mrd-msg-pie">' + esc(time) + statusIcon(m) + '</div>' + failHtml(m) + '</div>';
        }
        function failHtml(m) {
            if (m.failed) {
                return '<div class="mrd-fallo-l">' + esc(s('notsent')) + '<button type="button" data-a="retry" data-v="' + esc(m.id) + '">' +
                    esc(s('retry')) + '</button></div>';
            }
            if (m.status === 'failed') {
                return '<div class="mrd-fallo-l">' + esc(m.error ? s('failed', m.error) : s('failedplain')) + '</div>';
            }
            return '';
        }
        function sortMsgs() {
            st.msgs.sort(function (a, b) {
                return (a.at || 0) - (b.at || 0) || (a.pending ? 1 : 0) - (b.pending ? 1 : 0) || (+a.id || 0) - (+b.id || 0);
            });
        }
        function threadHtml() {
            var h = '', last = 0;
            if (st.more) {
                h += '<button type="button" class="mrd-mas-antiguos" data-a="older">' + esc(st.older ? s('loading') : s('olderload')) + '</button>';
            }
            st.msgs.forEach(function (m) {
                var k = dayKey((m.at || 0) * 1000);
                if (k !== last) {
                    h += '<span class="mrd-dia" data-day="' + k + '">' + esc(dayLabel((m.at || 0) * 1000)) + '</span>';
                    last = k;
                }
                h += msgHtml(m);
            });
            return h;
        }
        var durations = {};
        function fillDurations() {
            var hilo = hiloEl();
            if (!hilo) {
                return;
            }
            Array.prototype.forEach.call(hilo.querySelectorAll('.mrd-audio'), function (w) {
                var small = w.querySelector('small'), url = w.querySelector('.mrd-play').getAttribute('data-v');
                var show = function (d) {
                    if (d && isFinite(d) && !small.textContent) {
                        var t = Math.round(d);
                        small.textContent = Math.floor(t / 60) + ':' + ('0' + (t % 60)).slice(-2);
                    }
                };
                if (durations[url]) {
                    show(durations[url]);
                    return;
                }
                var a = new Audio();
                a.preload = 'metadata';
                a.addEventListener('loadedmetadata', function () {
                    durations[url] = a.duration;
                    show(a.duration);
                });
                a.src = url;
            });
        }
        function hiloEl() {
            return el.chat.querySelector('.mrd-hilo');
        }
        // Pictures and fonts arrive after the thread is drawn: it stays at the end unless the person has scrolled up.
        var stuck = true;
        function stickToBottom(hilo) {
            stuck = true;
            hilo.scrollTop = hilo.scrollHeight;
            var again = function () {
                if (stuck && hilo.isConnected) {
                    hilo.scrollTop = hilo.scrollHeight;
                }
            };
            Array.prototype.forEach.call(hilo.querySelectorAll('img, video'), function (m) {
                m.addEventListener('load', again);
                m.addEventListener('loadedmetadata', again);
            });
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(again);
            }
            setTimeout(again, 300);
        }
        function atBottom(hilo) {
            return hilo.scrollHeight - hilo.scrollTop - hilo.clientHeight < 120;
        }
        function paintBackground(hilo) {
            var c = conv(st.open);
            hilo.style.background = bgFor(c).css(bgInk()) + ', var(--fondo)';
        }
        function renderThread(keepFromBottom) {
            var hilo = hiloEl();
            if (!hilo) {
                return;
            }
            paintBackground(hilo);
            if (!st.loaded) {
                hilo.innerHTML = '<div class="mrd-hilo-nada">' + esc(s('loading')) + '</div>';
                return;
            }
            var stick = keepFromBottom === undefined ? true : false;
            var fromBottom = hilo.scrollHeight - hilo.scrollTop;
            hilo.innerHTML = st.msgs.length ? threadHtml() : '<div class="mrd-hilo-nada">' + esc(s('nothread')) + '</div>';
            placeReadBy();
            fillDurations();
            if (stick) {
                stickToBottom(hilo);
            } else {
                hilo.scrollTop = hilo.scrollHeight - fromBottom;
            }
        }
        // New messages go at the end without redrawing the rest (an audio that plays keeps playing).
        function addMessages(list) {
            var hilo = hiloEl();
            var fresh = [];
            list.forEach(function (m) {
                var i = indexOfMsg(m.id);
                if (i >= 0) {
                    st.msgs[i] = m;
                    var node = hilo && hilo.querySelector('[data-id="' + cssEsc(m.id) + '"]');
                    if (node) {
                        node.outerHTML = msgHtml(m);
                    }
                } else {
                    // A pending one of ours that the server already has: replace it.
                    var j = pendingTwin(m);
                    if (j >= 0) {
                        var old = st.msgs[j];
                        st.msgs[j] = m;
                        var n2 = hilo && hilo.querySelector('[data-id="' + cssEsc(old.id) + '"]');
                        if (n2) {
                            n2.outerHTML = msgHtml(m);
                        }
                    } else {
                        st.msgs.push(m);
                        fresh.push(m);
                    }
                }
            });
            if (!fresh.length || !hilo) {
                placeReadBy();
                return;
            }
            var stick = atBottom(hilo);
            var empty = hilo.querySelector('.mrd-hilo-nada');
            if (empty) {
                empty.remove();
            }
            var lastDay = hilo.querySelectorAll('.mrd-dia');
            var day = lastDay.length ? +lastDay[lastDay.length - 1].getAttribute('data-day') : 0;
            var h = '';
            fresh.sort(function (a, b) {
                return (a.at || 0) - (b.at || 0);
            }).forEach(function (m) {
                var k = dayKey((m.at || 0) * 1000);
                if (k !== day) {
                    h += '<span class="mrd-dia" data-day="' + k + '">' + esc(dayLabel((m.at || 0) * 1000)) + '</span>';
                    day = k;
                }
                h += msgHtml(m);
            });
            hilo.insertAdjacentHTML('beforeend', h);
            placeReadBy();
            fillDurations();
            if (stick) {
                stickToBottom(hilo);
                hidePill();
            } else if (fresh.some(function (m) {
                return m.kind === 'incoming';
            })) {
                showPill();
            }
        }
        function cssEsc(x) {
            return String(x).replace(/["\\]/g, '\\$&');
        }
        function indexOfMsg(id) {
            for (var i = 0; i < st.msgs.length; i++) {
                if (String(st.msgs[i].id) === String(id)) {
                    return i;
                }
            }
            return -1;
        }
        function pendingTwin(m) {
            for (var i = 0; i < st.msgs.length; i++) {
                var p = st.msgs[i];
                if (p.pending && !p.failed && p.kind === m.kind && String(p.text).trim() === String(m.text || '').trim()) {
                    return i;
                }
            }
            return -1;
        }
        function placeReadBy() {
            var hilo = hiloEl();
            if (!hilo) {
                return;
            }
            var old = hilo.querySelector('.mrd-leidopor');
            if (old) {
                old.remove();
            }
            var names = (st.readby || []).map(function (r) {
                return firstName(r);
            }).filter(Boolean);
            var fams = hilo.querySelectorAll('.mrd-msg.mrd-fam');
            if (!names.length || !fams.length) {
                return;
            }
            var title = (st.readby || []).map(function (r) {
                return r.name + (r.at ? ' · ' + whenPhrase(r.at * 1000) : '');
            }).join('\n');
            fams[fams.length - 1].insertAdjacentHTML('afterend', '<div class="mrd-leidopor" title="' + esc(title) + '">' +
                esc(names.length === 1 ? s('readby1', names[0]) : s('readbyn', joinList(names))) + '</div>');
            if (stuck) {
                hilo.scrollTop = hilo.scrollHeight;
            }
        }
        function showPill() {
            if (el.chat.querySelector('.mrd-nuevos-pill')) {
                return;
            }
            var esc2 = el.chat.querySelector('.mrd-escribir');
            el.chat.insertAdjacentHTML('beforeend', '<button type="button" class="mrd-nuevos-pill" data-a="newpill">' + ico('abajo') + esc(s('newmessages')) +
                '</button>');
            var pill = el.chat.querySelector('.mrd-nuevos-pill');
            pill.style.bottom = ((esc2 ? esc2.offsetHeight : 0) + 12) + 'px';
        }
        function hidePill() {
            var p = el.chat.querySelector('.mrd-nuevos-pill');
            if (p) {
                p.remove();
            }
        }

        /* -------- The composer -------- */
        function lastTemplateWaiting(c) {
            if (canReply(c) || st.pickAgain) {
                return false;
            }
            for (var i = st.msgs.length - 1; i >= 0; i--) {
                var m = st.msgs[i];
                if (m.kind === 'incoming') {
                    return false;
                }
                if (m.kind === 'outgoing' && m.template && !m.failed) {
                    return true;
                }
            }
            return false;
        }
        function composerState(c) {
            if (READONLY) {
                return 'readonly';
            }
            if (st.mode === 'note') {
                return 'note';
            }
            if (canReply(c)) {
                return 'reply';
            }
            if (st.tpl >= 0) {
                return 'preview';
            }
            return lastTemplateWaiting(c) ? 'waiting' : 'pick';
        }
        function composerSig(c) {
            return [st.open, composerState(c), c.assignee ? 1 : 0, c.section, st.tpl, (st.extras.templates || []).length, st.pop].join('|');
        }
        function saveDraft() {
            var ed = el.chat.querySelector('.mrd-editor');
            if (ed && st.open) {
                var d = st.drafts[st.open] || (st.drafts[st.open] = {});
                d[st.mode] = ed.innerHTML;
            }
        }
        function draftOf(mode) {
            var d = st.drafts[st.open];
            return (d && d[mode]) || '';
        }
        function plazoHtml(c) {
            if (!c.replyuntil) {
                return '';
            }
            var left = c.replyuntil * 1000 - nowMs();
            if (left < 4 * 3600000) {
                return '<span class="mrd-plazo mrd-poco">' + ico('reloj') + esc(s('windowleftlong', leftText(left))) + '</span>';
            }
            return '<span class="mrd-plazo">' + ico('reloj') + esc(s('canwriteuntil', whenPhrase(c.replyuntil * 1000))) + '</span>';
        }
        function renderComposer(force) {
            var hilo0 = hiloEl(), wasEnd = hilo0 ? atBottom(hilo0) : false;
            renderComposerNow(force);
            if (wasEnd && hilo0 && hilo0.isConnected) {
                stickToBottom(hilo0);
            }
        }
        function renderComposerNow(force) {
            var c = conv(st.open), box2 = el.chat.querySelector('.mrd-escribir');
            if (!c || !box2) {
                return;
            }
            var sig = composerSig(c);
            if (!force && sig === st.composerSig) {
                var pl = box2.querySelector('.mrd-plazo');
                if (pl) {
                    pl.outerHTML = plazoHtml(c);
                }
                return;
            }
            saveDraft();
            st.composerSig = sig;
            var state = composerState(c), name = contactFirst(c), h = '';
            if (state === 'readonly') {
                box2.innerHTML = '<div class="mrd-solo-leer">' + ico('ojo') + '<span>' + esc(s('readonly')) + '</span></div>';
                return;
            }
            if (!c.assignee && c.section !== 'resolved' && (state === 'reply' || state === 'note')) {
                h += '<div class="mrd-nadie-aviso">' + avAgent(null, 'mrd-m') + '<span>' + esc(s('nobodyhas')) + '</span>' +
                    '<button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="take">' + esc(s('takeit')) + '</button></div>';
            }
            if (state === 'reply' || state === 'note') {
                var note = state === 'note', ph = note ? s('writenote') : s('writeto', name);
                if (note && !canReply(c)) {
                    h += '<div class="mrd-retomar-pie" style="margin: 0 0 8px"><button type="button" data-a="mode" data-v="reply">' + ico('izq') +
                        esc(s('backtopick', name)) + '</button></div>';
                }
                h += '<div class="mrd-escribir-cab"><button type="button" class="mrd-pestana' + (note ? '' : ' mrd-on') + '" data-a="mode" data-v="reply"' +
                    (canReply(c) ? '' : ' hidden') + '>' + esc(s('tabreply')) + '</button><button type="button" class="mrd-pestana mrd-nota-p' +
                    (note ? ' mrd-on' : '') + '" data-a="mode" data-v="note">' + esc(s('tabnote')) + '</button>' + (note ? '' : plazoHtml(c)) + '</div>' +
                    '<div class="mrd-caja' + (note ? ' mrd-nota-c' : '') + '">' + (note ? '<div class="mrd-aviso-nota">' + ico('nota') + esc(s('notehiddenbox')) +
                    '</div>' : '') + '<div class="mrd-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' + esc(ph) +
                    '" data-vacio="' + esc(ph) + '">' + draftOf(state) + '</div>' + chosenFileHtml() + '<div class="mrd-herr">' +
                    '<button type="button" class="mrd-herr-b" data-a="attach">' + ico('clip') + '<span class="mrd-t">' + esc(s('attach')) +
                    '</span></button><input type="file" class="mrd-archivo" hidden>' +
                    (note ? '<button type="button" class="mrd-herr-b' + (st.pop === 'mencion' ? ' mrd-on' : '') + '" data-a="pop" data-v="mencion">' +
                    ico('arroba') + '<span class="mrd-t">' + esc(s('mention')) + '</span></button>'
                        : '<button type="button" class="mrd-herr-b' + (st.pop === 'guardadas' ? ' mrd-on' : '') + '" data-a="pop" data-v="guardadas">' +
                    ico('guardadas') + '<span class="mrd-t">' + esc(s('canned')) + '</span></button>') +
                    '<button type="button" class="mrd-btn mrd-primario" data-a="send">' + (note ? ico('nota') + esc(s('savenote')) : ico('enviar') + esc(s('send'))) +
                    '</button></div></div>';
                h += st.pop === 'guardadas' ? popCanned(c) : st.pop === 'mencion' ? popMention() : '';
                box2.innerHTML = h;
                var ed = box2.querySelector('.mrd-editor');
                if (ed.innerHTML === '<br>') {
                    ed.innerHTML = '';
                }
                return;
            }
            if (state === 'waiting') {
                box2.innerHTML = '<div class="mrd-esperando"><span class="mrd-reloj">' + ico('mensaje') + '</span><div><b>' + esc(s('sentwait', name)) +
                    '</b><p>' + esc(s('sentwaithelp')) + '</p></div><div class="mrd-esperando-b"><button type="button" class="mrd-btn mrd-chico" data-a="mode" data-v="note">' +
                    ico('nota') + esc(s('tabnote')) + '</button><button type="button" class="mrd-btn mrd-chico" data-a="pickagain">' + ico('mensaje') +
                    esc(s('sendanother')) + '</button></div></div>';
                return;
            }
            var tpls = st.extras.templates || [];
            if (state === 'preview' && tpls[st.tpl]) {
                box2.innerHTML = previewHtml(c, tpls[st.tpl]);
                var first = box2.querySelector('.mrd-hueco');
                if (first && !touchy()) {
                    first.focus();
                }
                return;
            }
            var lastIn = c.lastincoming ? c.lastincoming * 1000 : 0;
            h = '<div class="mrd-retomar-cab"><span class="mrd-reloj">' + ico('reloj') + '</span><div><b>' + esc(lastIn ? s('closedtitle') : s('starttitle')) + '</b>' +
                '<p>' + esc(lastIn ? s('closedlast', { name: name, when: whenPhrase(lastIn) }) : s('closednever', name)) + '</p></div></div>';
            if (tpls.length) {
                var langs = {};
                tpls.forEach(function (t) {
                    langs[t.language] = true;
                });
                var showLang = Object.keys(langs).length > 1;
                h += '<div class="mrd-plantillas">' + tpls.map(function (t, i) {
                    return '<button type="button" class="mrd-plantilla" data-a="tpl" data-v="' + i + '"><b>' + ico('mensaje') + esc(tplTitle(t)) +
                        (showLang ? ' · ' + esc(t.language) : '') + '</b><span>' + esc(t.description || tplPlain(t, c)) + '</span></button>';
                }).join('') + '</div>';
            } else {
                h += '<p class="mrd-ayuda">' + esc(s('notemplates')) + '</p>';
            }
            h += '<div class="mrd-retomar-pie">' + esc(s('teamnolimit')) + ' <button type="button" data-a="mode" data-v="note">' + ico('nota') +
                esc(s('writenotebtn')) + '</button></div>';
            box2.innerHTML = h;
        }
        function tplTitle(t) {
            if (t.title) {
                return t.title;
            }
            return cap(String(t.name || '').replace(/_+/g, ' ').trim());
        }
        function tplValue(p, c) {
            if (/name|nombre|nom\b/i.test(p.key || '')) {
                return contactFirst(c);
            }
            return p.example || '';
        }
        function tplPlain(t, c) {
            var params = {};
            (t.params || []).forEach(function (p) {
                params[p.key] = tplValue(p, c);
            });
            return String(t.body || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, k) {
                return params[k] !== undefined ? params[k] : m;
            });
        }
        function previewHtml(c, t) {
            var params = {};
            (t.params || []).forEach(function (p) {
                params[p.key] = p;
            });
            var body = esc(t.body || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, k) {
                var p = params[k] || { key: k, example: '' };
                return '<span class="mrd-hueco" contenteditable="true" role="textbox" data-k="' + esc(k) + '" aria-label="' + esc(s('gap', k)) + '">' +
                    esc(tplValue(p, c)) + '</span>';
            });
            var btns = (t.buttons || []).map(function (b) {
                return '<span>' + esc(b && b.text !== undefined ? b.text : b) + '</span>';
            }).join('');
            return '<div class="mrd-previa"><div class="mrd-previa-t">' + ico('mensaje') + esc(s('preview', contactFirst(c))) +
                '<button type="button" data-a="tplback">' + esc(s('pickother')) + '</button></div>' +
                '<div class="mrd-msg mrd-nos mrd-plantilla">' + (t.header ? '<span class="mrd-cabecera-wa">' + esc(t.header) + '</span>' : '') +
                '<p>' + body + '</p>' + (t.footer ? '<span class="mrd-pie-wa">' + esc(t.footer) + '</span>' : '') +
                (btns ? '<div class="mrd-botones-wa">' + btns + '</div>' : '') + '</div>' +
                '<div class="mrd-previa-pie"><button type="button" class="mrd-btn mrd-primario" data-a="tplsend">' + ico('enviar') + esc(s('sendtemplate')) +
                '</button><small>' + esc(s('yellowhelp')) + '</small></div></div>';
        }

        /* -------- Canned answers («/») and mentions («@») -------- */
        function cannedFill(text, c) {
            var ct = (c && c.contact) || {};
            return String(text || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, k) {
                var map = {
                    'contact.name': ct.name, 'contact.first_name': firstName(ct), 'contact.phone': fmtPhone(ct.phone),
                    'agent.name': me.name, 'agent.first_name': me.firstname || firstName(me)
                };
                return map[k] !== undefined && map[k] !== null ? map[k] : m;
            });
        }
        function cannedList() {
            // Words that start with what is typed, and the ones whose name matches first.
            var f = st.popFilter.toLowerCase();
            var starts = function (x) {
                return String(x || '').toLowerCase().split(/[\s.,;:¿?¡!«»()"'\/-]+/).some(function (w) {
                    return w.indexOf(f) === 0;
                });
            };
            var list = (st.extras.canned || []).filter(function (g) {
                return !f || starts(g.short) || starts(g.text);
            });
            return f ? list.sort(function (a, b) {
                return (starts(b.short) ? 1 : 0) - (starts(a.short) ? 1 : 0);
            }) : list;
        }
        function popCanned(c) {
            var list = cannedList();
            st.popIndex = Math.min(st.popIndex, Math.max(0, list.length - 1));
            return '<div class="mrd-pop mrd-pop-escribir" role="dialog" aria-label="' + esc(s('canned')) + '"><h5>' + esc(s('canned')) +
                ' <span>' + esc(s('cannedby')) + '</span></h5><div class="mrd-pop-lista" role="listbox">' + (list.length ? list.map(function (g, i) {
                    return '<button type="button" role="option" class="mrd-pop-fila' + (i === st.popIndex ? ' mrd-on' : '') + '" data-a="canned" data-v="' +
                        (st.extras.canned.indexOf(g)) + '" aria-selected="' + (i === st.popIndex) + '">' + ico('guardadas') + '<span><b>' + esc(g.short) +
                        '</b><small>' + esc(cannedFill(g.text, c)) + '</small></span></button>';
                }).join('') : '<p class="mrd-pop-nada">' + esc(s('cannednone')) + '</p>') + '</div><div class="mrd-pop-pie"><span>' +
                esc(s('cannedslash')) + '</span></div></div>';
        }
        function mentionList() {
            var f = st.popFilter.toLowerCase();
            return (st.extras.people || []).filter(function (p) {
                if (p.agentid === me.agentid) {
                    return false;
                }
                return !f || String(p.name).toLowerCase().split(/\s+/).some(function (w) {
                    return w.indexOf(f) === 0;
                });
            });
        }
        function popMention() {
            var list = mentionList();
            st.popIndex = Math.min(st.popIndex, Math.max(0, list.length - 1));
            return '<div class="mrd-pop mrd-pop-escribir" role="dialog" aria-label="' + esc(s('mention')) + '"><h5>' + esc(s('mention')) + ' <span>' +
                esc(s('mentionhelp')) + '</span></h5><div class="mrd-pop-lista" role="listbox">' + (list.length ? list.map(function (p, i) {
                    var tn = (p.teams || []).map(function (t) {
                        return teamName(t && t.id !== undefined ? t.id : t);
                    });
                    return '<button type="button" role="option" class="mrd-pop-fila' + (i === st.popIndex ? ' mrd-on' : '') + '" data-a="mention" data-v="' +
                        p.agentid + '" aria-selected="' + (i === st.popIndex) + '">' + avAgent(p, 'mrd-m') + '<span><b>' + esc(p.name) + '</b><small>' +
                        esc(joinList(tn)) + '</small></span></button>';
                }).join('') : '<p class="mrd-pop-nada">' + esc(s('mentionnone')) + '</p>') + '</div></div>';
        }
        function refreshWritePop() {
            var box2 = el.chat.querySelector('.mrd-escribir');
            if (!box2) {
                return;
            }
            var old = box2.querySelector('.mrd-pop');
            if (old) {
                old.remove();
            }
            box2.querySelectorAll('.mrd-herr-b').forEach(function (b) {
                b.classList.toggle('mrd-on', b.getAttribute('data-v') === st.pop);
            });
            if (st.pop === 'guardadas') {
                box2.insertAdjacentHTML('beforeend', popCanned(conv(st.open)));
            } else if (st.pop === 'mencion') {
                box2.insertAdjacentHTML('beforeend', popMention());
            }
        }
        function textBeforeCaret(ed) {
            var sel = root.getSelection();
            if (!sel || !sel.rangeCount || !ed.contains(sel.anchorNode)) {
                return null;
            }
            var r = sel.getRangeAt(0).cloneRange();
            r.selectNodeContents(ed);
            r.setEnd(sel.anchorNode, sel.anchorOffset);
            return r.toString();
        }
        function caretToEnd(ed) {
            ed.focus();
            var r = document.createRange();
            r.selectNodeContents(ed);
            r.collapse(false);
            var sel = root.getSelection();
            sel.removeAllRanges();
            sel.addRange(r);
        }
        function insertText(text) {
            if (!document.execCommand || !document.execCommand('insertText', false, text)) {
                var sel = root.getSelection();
                if (!sel || !sel.rangeCount) {
                    return;
                }
                var r = sel.getRangeAt(0);
                r.deleteContents();
                var n = document.createTextNode(text);
                r.insertNode(n);
                r.setStartAfter(n);
                r.collapse(true);
                sel.removeAllRanges();
                sel.addRange(r);
            }
        }
        function editorText(ed, wire) {
            var out = '';
            var walk = function (node) {
                Array.prototype.forEach.call(node.childNodes, function (ch) {
                    if (ch.nodeType === 3) {
                        out += ch.nodeValue;
                    } else if (ch.nodeName === 'BR') {
                        out += '\n';
                    } else if (ch.classList && ch.classList.contains('mrd-mencion')) {
                        var name = ch.getAttribute('data-name') || ch.textContent.replace(/^@/, '');
                        out += wire ? '[@' + name + '](mention://user/' + ch.getAttribute('data-agent') + '/' + encodeURIComponent(name) + ')' : '@' + name;
                    } else {
                        if (/^(DIV|P)$/.test(ch.nodeName) && out && out.slice(-1) !== '\n') {
                            out += '\n';
                        }
                        walk(ch);
                    }
                });
            };
            walk(ed);
            return out.replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
        }
        function onEditorInput(ed) {
            saveDraft();
            var before = textBeforeCaret(ed);
            var prev = st.pop;
            if (st.mode === 'note') {
                var m = before !== null ? /(^|\s)@([^\s@]*)$/.exec(before) : null;
                if (m) {
                    st.pop = 'mencion';
                    st.popFilter = m[2];
                } else if (st.pop === 'mencion') {
                    st.pop = null;
                }
            } else {
                var all = editorText(ed, false);
                var b = /^\/(\S*)$/.exec(all);
                if (b) {
                    st.pop = 'guardadas';
                    st.popFilter = b[1];
                } else if (st.pop === 'guardadas' && /^\//.test(all) === false && st.popTyped) {
                    st.pop = null;
                }
                st.popTyped = !!b;
            }
            if (st.pop !== prev) {
                st.popIndex = 0;
            }
            if (st.pop === 'mencion' || st.pop === 'guardadas' || prev) {
                refreshWritePop();
            }
        }
        function chooseCanned(i) {
            var g = (st.extras.canned || [])[i], ed = el.chat.querySelector('.mrd-editor');
            if (!g || !ed) {
                return;
            }
            st.pop = null;
            st.popTyped = false;
            ed.textContent = cannedFill(g.text, conv(st.open));
            refreshWritePop();
            caretToEnd(ed);
            saveDraft();
        }
        function chooseMention(agentid) {
            var p = personName(+agentid), ed = el.chat.querySelector('.mrd-editor');
            if (!p || !ed) {
                return;
            }
            var label = firstName(p);
            var span = document.createElement('span');
            span.className = 'mrd-mencion';
            span.contentEditable = 'false';
            span.setAttribute('data-agent', p.agentid);
            span.setAttribute('data-name', p.name);
            span.textContent = '@' + label;
            var sel = root.getSelection(), placed = false;
            if (sel && sel.rangeCount && ed.contains(sel.anchorNode) && sel.anchorNode.nodeType === 3) {
                var node = sel.anchorNode, off = sel.anchorOffset;
                var m = /@([^\s@]*)$/.exec(node.nodeValue.slice(0, off));
                if (m) {
                    var r = document.createRange();
                    r.setStart(node, off - m[0].length);
                    r.setEnd(node, off);
                    r.deleteContents();
                    r.insertNode(span);
                    placed = true;
                }
            }
            if (!placed) {
                caretToEnd(ed);
                var r2 = root.getSelection().getRangeAt(0);
                r2.insertNode(span);
            }
            var space = document.createTextNode('\u00a0');
            span.parentNode.insertBefore(space, span.nextSibling);
            var r3 = document.createRange();
            r3.setStartAfter(space);
            r3.collapse(true);
            var sel2 = root.getSelection();
            sel2.removeAllRanges();
            sel2.addRange(r3);
            ed.focus();
            st.pop = null;
            refreshWritePop();
            saveDraft();
        }

        /* ---------------------------------------------------------------------------------------------------------------
           The contact file
           --------------------------------------------------------------------------------------------------------------- */
        var panelFrame = null, panelData = null;
        function callButton(phone) {
            var d = digits(phone);
            if (!d) {
                return '';
            }
            return '<a class="mrd-btn mrd-chico mrd-llamar" href="tel:+' + d + '" data-a="tel" data-v="' + esc(fmtPhone(d)) + '">' + ico('tel') + esc(s('call')) + '</a>';
        }
        function origin(icon, text) {
            return '<span class="mrd-origen">' + ico(icon) + esc(text) + '</span>';
        }
        var KIND_FILE = {
            family: ['casa', 'kindfamily'], applicant: ['puerta', 'kindapplicant'], staff: ['gafete', 'kindstafffull'],
            supplier: ['caja', 'kindsupplier'], alumni: ['birrete', 'kindalumni'], unknown: ['nadie', 'kindunknown']
        };
        function fichaHead(f, c) {
            var kind = f.kind || 'unknown', o;
            if (kind === 'both') {
                o = origin('casa', s('kindfamily')) + origin('puerta', s('kindboth'));
            } else {
                var k = KIND_FILE[kind] || KIND_FILE.unknown;
                o = origin(k[0], s(k[1]));
            }
            var phoneRow = (kind !== 'family' && kind !== 'both' && f.phone) ? '<div class="mrd-tel-fila"><span>' + esc(fmtPhone(f.phone)) + '</span>' +
                callButton(f.phone) + '</div>' : '';
            var changed = f.changed && f.changed.text && (!f.changed.until || f.changed.until * 1000 > nowMs())
                ? '<p class="mrd-recien">' + ico('check') + esc(f.changed.text) + '</p>' : '';
            return '<div class="mrd-ficha-cab"><button type="button" class="mrd-volver" data-a="back" data-v="chat">' + ico('izq') + esc(s('backtochat')) +
                '</button><span class="mrd-origenes">' + o + '</span><h3>' + esc(f.title || (c.contact && c.contact.name) || '') + '</h3>' +
                (f.line && kind !== 'family' && kind !== 'both' ? '<p>' + esc(f.line) + '</p>' : '') + phoneRow + changed + '</div>';
        }
        function maybeHtml(f, c) {
            if (st.joined) {
                return '<div class="mrd-misma mrd-unida"><div class="mrd-misma-t">' + ico('check') + esc(s('joined')) + '</div><p>' + esc(s('joinedtext', st.joined)) +
                    '</p></div>';
            }
            return (f.maybe || []).map(function (x) {
                var reason = String(x.reason || '').replace(/[.\s]+$/, '');
                return '<div class="mrd-misma"><div class="mrd-misma-t">' + ico('gente') + esc(s('maybesame')) + '</div><p>' +
                    esc(reason ? s('maybetext', { name: x.name, reason: reason }) : s('maybetextplain', x.name)) + '</p>' +
                    (READONLY ? '' : '<div class="mrd-botones"><button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="same" data-v="' + x.conversation +
                    '" data-n="' + esc(x.name) + '">' + esc(s('same')) + '</button><button type="button" class="mrd-btn mrd-chico" data-a="notsame" data-v="' +
                    x.conversation + '">' + esc(s('notsame')) + '</button></div>') + (conv(x.conversation) ? '<button type="button" class="mrd-enlace mrd-ver-otra" data-a="conv" data-v="' +
                    x.conversation + '">' + esc(s('readfirst', firstName(x))) + ' ' + ico('flecha') + '</button>' : '') + '</div>';
            }).join('');
        }
        function stepsHtml(steps) {
            if (!steps || !steps.length) {
                return '';
            }
            var now = -1;
            steps.forEach(function (p, i) {
                if (now < 0 && !(p.done || p.hecho)) {
                    now = i;
                }
            });
            return '<ol class="mrd-pista" style="--n: ' + steps.length + '">' + steps.map(function (p, i) {
                var done = p.done || p.hecho;
                return '<li class="mrd-paso' + (done ? ' mrd-hecho' : '') + (i === now ? ' mrd-ahora' : '') + '"><span class="mrd-punto">' + (done ? ico('check') : '') +
                    '</span><b>' + esc(p.label || p.t || '') + '</b><small>' + esc(p.date || p.c || (i === now ? s('next') : '—')) + '</small></li>';
            }).join('') + '</ol>';
        }
        function childrenHtml(f) {
            var kids = (f.children || []).filter(function (k) {
                return !k.applying;
            });
            var applying = (f.children || []).filter(function (k) {
                return k.applying;
            });
            var h = '';
            if (kids.length) {
                h += '<div class="mrd-seccion"><h4>' + esc(kids.length > 1 ? s('children') : s('child')) + (kids.length > 1 ? ' <small>' +
                    esc(s('childrencount', kids.length)) + '</small>' : '') + '</h4>' + kids.map(function (k) {
                    var col = k.stage && k.stage.color ? dataColour(k.stage.color, k.stage.dark).css : '';
                    return '<div class="mrd-hijo"><span class="mrd-etapa"' + (col ? ' style="--e: ' + col + '"' : '') + ' aria-hidden="true"></span><span><b>' +
                        esc(k.name) + '</b>' + (k.group || (k.stage && k.stage.label) ? '<span class="mrd-curso">' +
                        esc([k.stage && k.stage.label, k.group].filter(Boolean).join(' · ')) + '</span>' : '') +
                        (k.tutor ? '<small>' + esc(s('tutor', k.tutor)) + '</small>' : '') + '</span></div>';
                }).join('') + '</div>';
            }
            if (applying.length && !f.panel) {
                h += '<div class="mrd-plaza-en-familia">' + applying.map(function (k) {
                    return '<div class="mrd-seccion"><h4>' + esc(f.kind === 'applicant' ? s('applying') : s('applyingfor')) + '</h4><div class="mrd-pide">' +
                        '<span class="mrd-curso-pide">' + esc(k.name) + '</span><small>' + esc([k.stage && k.stage.label, k.group].filter(Boolean).join(' · ')) +
                        '</small></div>' + stepsHtml(k.steps) + '</div>';
                }).join('') + '</div>';
            }
            return h;
        }
        function rowsHtml(rows, title) {
            if (!rows || !rows.length) {
                return '';
            }
            return '<div class="mrd-seccion">' + (title ? '<h4>' + esc(title) + '</h4>' : '') + rows.map(function (r) {
                return '<div class="mrd-servicio"><span class="mrd-ico">' + ico(ICONS[r.icon] ? r.icon : 'punto') + '</span><span><b>' + esc(r.title || r.label || '') +
                    '</b>' + (r.detail ? '<small>' + esc(r.detail) + '</small>' : '') + '</span></div>';
            }).join('') + '</div>';
        }
        function contactsHtml(f) {
            if (!f.contacts || !f.contacts.length) {
                return '';
            }
            return '<div class="mrd-seccion"><h4>' + esc(s('whowrites')) + '</h4>' + f.contacts.map(function (k) {
                return '<div class="mrd-contacto"><span class="mrd-av mrd-m mrd-con-bicho" aria-hidden="true">' + bicho(digits(k.phone) || k.name) + '</span><span><b>' +
                    esc(k.name) + (f.contacts.length > 1 && k.conversation && k.conversation === st.open ? ' <span class="mrd-marca-este">' + esc(s('thischat')) + '</span>' : '') +
                    '</b><small>' + esc([k.role, fmtPhone(k.phone)].filter(Boolean).join(' · ')) + '</small></span>' + callButton(k.phone) + '</div>';
            }).join('') + '</div>';
        }
        function otherConvsHtml(f) {
            var list = (f.conversations || []).filter(function (x) {
                return x.id !== st.open;
            });
            if (!list.length) {
                return '';
            }
            return '<div class="mrd-seccion"><h4>' + esc(s('otherconvs')) + '</h4>' + list.map(function (x) {
                var o = conv(x.id), when = x.at ? listTime(x.at * 1000) : '';
                var head = [o ? teamName(o.teamid) : '', x.status === 'resolved' ? s('convresolved') : s('convopen'), when].filter(Boolean).join(' · ');
                return '<button type="button" class="mrd-antes" data-a="otherconv" data-v="' + x.id + '"><span class="mrd-dep-t">' + esc(head) + '</span><b>' +
                    esc(x.preview || '') + '</b>' + (o && o.assignee ? '<small>' + esc(s('takenby', firstName(o.assignee))) + '</small>' : '') + '</button>';
            }).join('') + '</div>';
        }
        function markHtml(f) {
            if (READONLY) {
                return '';
            }
            var k = f.kind || 'unknown';
            if (k === 'unknown') {
                return '<div class="mrd-seccion"><h4>' + esc(s('whoisq')) + '</h4><div class="mrd-chips">' + ['staff', 'supplier', 'alumni'].map(function (x) {
                    return '<button type="button" class="mrd-chip" data-a="kind" data-v="' + x + '">' + esc(s(KIND_LABEL[x])) + '</button>';
                }).join('') + '</div><p class="mrd-ayuda">' + esc(s('unknownhelp')) + '</p></div>';
            }
            if (KIND_LABEL[k]) {
                return '<div class="mrd-seccion"><h4>' + esc(s('markas')) + '</h4><div class="mrd-chips">' + ['staff', 'supplier', 'alumni'].filter(function (x) {
                    return x !== k;
                }).map(function (x) {
                    return '<button type="button" class="mrd-chip" data-a="kind" data-v="' + x + '">' + esc(s(KIND_LABEL[x])) + '</button>';
                }).join('') + '<button type="button" class="mrd-chip" data-a="kind" data-v="none">' + esc(s('marknone')) + '</button></div></div>';
            }
            return '';
        }
        function renderFicha() {
            var c = conv(st.open);
            panelFrame = null;
            panelData = null;
            if (!c) {
                el.ficha.innerHTML = '';
                box.classList.add('mrd-sin-ficha');
                return;
            }
            if (st.fichaState === 'loading' && !st.ficha) {
                el.ficha.innerHTML = '<div class="mrd-ficha-vacia">' + esc(s('fileloading')) + '</div>';
                return;
            }
            if (st.fichaState === 'error' || !st.ficha) {
                el.ficha.innerHTML = '<div class="mrd-ficha-cab"><button type="button" class="mrd-volver" data-a="back" data-v="chat">' + ico('izq') + esc(s('backtochat')) +
                    '</button></div><div class="mrd-ficha-vacia">' + esc(s('fileerror')) + ' <button type="button" class="mrd-enlace" data-a="fichaagain">' +
                    esc(s('tryagain')) + '</button></div>';
                return;
            }
            var f = st.ficha, k = f.kind || 'unknown', h = fichaHead(f, c);
            h += maybeHtml(f, c);
            if (k === 'family' || k === 'both') {
                h += contactsHtml(f) + childrenHtml(f) + rowsHtml(f.services, s('services')) + rowsHtml(f.rows, '');
            } else if (k === 'applicant') {
                h += childrenHtml(f) + rowsHtml(f.rows, '');
            } else if (KIND_LABEL[k]) {
                h += rowsHtml(f.rows, s('whois'));
            } else {
                h += rowsHtml(f.rows, '');
            }
            // «Who is it?» first: the card of another plugin below it, as one more card.
            h += markHtml(f);
            if (f.panel && f.panel.url) {
                h += '<div class="mrd-seccion mrd-panel"><iframe src="' + esc(f.panel.url) + '" title="' + esc(s('panel')) + '"></iframe></div>';
            }
            h += otherConvsHtml(f);
            if (f.source) {
                h += '<div class="mrd-fuente">' + esc(f.source) + '</div>';
            }
            var keep = el.ficha.scrollTop;
            el.ficha.innerHTML = h;
            el.ficha.scrollTop = keep;
            if (f.panel && f.panel.url) {
                panelFrame = el.ficha.querySelector('.mrd-panel iframe');
                panelData = f.panel;
                panelFrame.addEventListener('load', function () {
                    postPanelContext();
                    fitPanel(panelFrame);
                });
            }
        }
        // The card of another plugin measures what its content: of the same site, its page can be measured as it fills.
        function fitPanel(frame) {
            var doc;
            try {
                doc = frame.contentDocument;
            } catch (e) {
                return;
            }
            if (!doc || !doc.body) {
                return;
            }
            var fit = function () {
                var bottom = 0;
                Array.prototype.forEach.call(doc.body.children, function (child) {
                    var r = child.getBoundingClientRect();
                    if (r.height) {
                        bottom = Math.max(bottom, r.bottom);
                    }
                });
                if (bottom) {
                    frame.style.height = Math.ceil(bottom + 12) + 'px';
                }
            };
            frame.style.height = '120px';
            fit();
            var win = frame.contentWindow;
            if (win && win.ResizeObserver) {
                var ro = new win.ResizeObserver(fit);
                Array.prototype.forEach.call(doc.body.children, function (child) {
                    ro.observe(child);
                });
            }
            if (win && win.MutationObserver) {
                new win.MutationObserver(fit).observe(doc.body, { childList: true, subtree: true });
            }
        }
        // The panel of another plugin (as Chatwoot's Dashboard Apps): it asks for its context and gets the contact.
        function panelOrigin() {
            try {
                return new URL(panelData.url, location.href).origin;
            } catch (e) {
                return location.origin;
            }
        }
        function postPanelContext() {
            if (!panelFrame || !panelData || !panelFrame.contentWindow) {
                return;
            }
            var msg = JSON.stringify({ event: 'appContext', data: {
                contact: { name: panelData.name || '', email: panelData.email || '', phone_number: panelData.phone || '' },
                theme: st.dark ? 'dark' : 'light'
            } });
            try {
                panelFrame.contentWindow.postMessage(msg, panelOrigin());
            } catch (e) {
                // The frame is gone.
            }
        }
        root.addEventListener('message', function (ev) {
            if (panelFrame && ev.source === panelFrame.contentWindow && ev.data === 'chatwoot-dashboard-app:fetch-info') {
                postPanelContext();
            }
        });
        function loadFicha(id) {
            if (!id) {
                return;
            }
            if (st.fichaFor !== id) {
                st.ficha = null;
            }
            st.fichaFor = id;
            st.fichaState = 'loading';
            if (!st.ficha) {
                renderFicha();
            }
            tr.call(M.contact, { conversation: id }).then(function (f) {
                if (st.fichaFor !== id) {
                    return;
                }
                st.ficha = f || null;
                st.fichaState = f ? 'ok' : 'error';
                var c = conv(id);
                st.fichaKind = c && c.contact ? c.contact.kind : '';
                box.classList.toggle('mrd-sin-ficha', !f);
                renderFicha();
            }).catch(function () {
                if (st.fichaFor !== id) {
                    return;
                }
                st.fichaState = 'error';
                st.ficha = null;
                box.classList.add('mrd-sin-ficha');
                renderFicha();
            });
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Layers on the phone, and the whole
           --------------------------------------------------------------------------------------------------------------- */
        function setLayer(capa, push) {
            var was = st.capa;
            st.capa = capa;
            box.setAttribute('data-capa', capa);
            if (push && narrow() && was !== capa && root.history && history.pushState) {
                try {
                    history.pushState({ mrdcapa: capa }, '');
                } catch (e) {
                    // Some app windows do not keep a history.
                }
            }
            renderPie(inboxCounts());
        }
        root.addEventListener('popstate', function (e) {
            if (!narrow()) {
                return;
            }
            var c = e.state && e.state.mrdcapa;
            st.capa = c || (st.capa === 'ficha' ? 'chat' : 'lista');
            box.setAttribute('data-capa', st.capa);
            renderPie(inboxCounts());
        });
        function goBack(target) {
            if (narrow() && history.state && history.state.mrdcapa) {
                history.back();
                return;
            }
            if (target === 'chat' && !narrow()) {
                st.fichaOpen = false;
                box.classList.remove('mrd-ficha-abierta');
                return;
            }
            setLayer(target || 'lista');
        }
        function renderAll() {
            renderRail();
            renderListHead();
            renderListBody();
            renderChat();
            renderFicha();
            box.classList.toggle('mrd-ficha-abierta', st.fichaOpen);
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Opening a conversation, and talking to the server
           --------------------------------------------------------------------------------------------------------------- */
        function openConv(id, opts) {
            opts = opts || {};
            var c = conv(id);
            if (!c) {
                toast(s('toastnotfound'), true);
                return;
            }
            if (st.open !== id) {
                saveDraft();
                st.open = id;
                st.msgs = [];
                st.loaded = false;
                st.more = false;
                st.readby = [];
                st.mode = canReply(c) || READONLY ? 'reply' : (st.drafts[id] && st.drafts[id].note ? 'note' : 'reply');
                st.pop = null;
                st.tpl = -1;
                st.pickAgain = false;
                st.joined = '';
                renderChat();
                loadMessages(id);
                loadFicha(id);
            }
            if (c.unread && !READONLY) {
                c.unread = false;
                act(id, 'read', 0, '', true);
            } else if (c.unread) {
                c.unread = false;
            }
            renderListBody();
            var item = el.lista.querySelector('.mrd-conv.mrd-on');
            if (item && item.scrollIntoView) {
                item.scrollIntoView({ block: 'nearest' });
            }
            syncUrl();
            if (!opts.keepLayer) {
                setLayer('chat', true);
            }
        }
        function loadMessages(id) {
            tr.call(M.messages, { conversation: id, before: 0 }).then(function (r) {
                if (st.open !== id) {
                    return;
                }
                var pending = st.msgs.filter(function (m) {
                    return m.pending || m.failed;
                });
                st.msgs = ((r && r.messages) || []).concat(pending);
                sortMsgs();
                st.more = !!(r && r.more);
                st.loaded = true;
                renderThread();
                renderComposer(true);
            }).catch(function (e) {
                if (st.open !== id) {
                    return;
                }
                st.loaded = true;
                renderThread();
                failToast(e);
            });
        }
        function loadOlder() {
            if (st.older || !st.more) {
                return;
            }
            var first = null;
            st.msgs.some(function (m) {
                if (!m.pending && !m.failed) {
                    first = m;
                    return true;
                }
                return false;
            });
            if (!first) {
                return;
            }
            var id = st.open;
            st.older = true;
            var btn = el.chat.querySelector('.mrd-mas-antiguos');
            if (btn) {
                btn.textContent = s('loading');
            }
            tr.call(M.messages, { conversation: id, before: first.id }).then(function (r) {
                st.older = false;
                if (st.open !== id) {
                    return;
                }
                var known = {};
                st.msgs.forEach(function (m) {
                    known[m.id] = true;
                });
                ((r && r.messages) || []).forEach(function (m) {
                    if (!known[m.id]) {
                        st.msgs.push(m);
                    }
                });
                sortMsgs();
                st.more = !!(r && r.more);
                renderThread(true);
            }).catch(function (e) {
                st.older = false;
                failToast(e);
            });
        }
        function lastRealId() {
            var max = 0;
            st.msgs.forEach(function (m) {
                if (!m.pending && !m.failed && +m.id > max) {
                    max = +m.id;
                }
            });
            return max;
        }
        function mergeConv(c) {
            if (!c || !c.id) {
                return;
            }
            if (c.status === 'gone') {
                removeConv(c.id);
                return;
            }
            var old = st.convs[c.id];
            // A poll that left before an action can come back after it: an older row does not undo what was just done.
            if (old && old.rev !== undefined && c.rev !== undefined && c.rev < old.rev) {
                return;
            }
            st.convs[c.id] = c;
        }
        function removeConv(id) {
            delete st.convs[id];
            if (st.open === id) {
                st.open = 0;
                st.msgs = [];
                st.ficha = null;
                st.fichaFor = 0;
                renderChat();
                renderFicha();
                setLayer('lista');
                syncUrl();
            }
        }
        function refreshAfterData() {
            renderRail();
            updateListTitle();
            renderListBody();
            var c = conv(st.open);
            if (c) {
                renderHead();
                renderComposer();
                if (c.contact && st.fichaKind && c.contact.kind !== st.fichaKind && st.fichaState === 'ok') {
                    loadFicha(st.open);
                }
            }
        }

        /* -------- Polling -------- */
        var pollT = 0, polling = false, pollAgain = false;
        function interval() {
            return document.hidden ? 30000 : POLLMS;
        }
        function schedule(ms) {
            clearTimeout(pollT);
            if (st.expired) {
                return;
            }
            pollT = setTimeout(poll, ms);
        }
        function poll() {
            if (polling) {
                pollAgain = true;
                return;
            }
            polling = true;
            var id = st.open && st.loaded ? st.open : 0;
            var args = { since: st.cursor, conversation: id, after: id ? lastRealId() : 0 };
            tr.call(M.poll, args).then(function (r) {
                st.failed = 0;
                showRed(null);
                applyPoll(r || {}, id);
            }).catch(function (e) {
                st.failed++;
                var code = e && e.errorcode;
                if (code === 'invalidsesskey' || code === 'servicerequireslogin' || code === 'requireloginerror' || code === 'sessionexpired') {
                    st.expired = true;
                    showRed('expired');
                } else if (st.failed >= 2) {
                    showRed('offline');
                }
            }).then(function () {
                polling = false;
                var wait = pollAgain ? 50 : st.failed ? Math.min(60000, interval() * Math.pow(2, Math.min(st.failed, 4))) : interval();
                pollAgain = false;
                schedule(wait);
            });
        }
        function pollSoon() {
            schedule(250);
        }
        function applyPoll(r, id) {
            if (r.now) {
                skew = r.now * 1000 - Date.now();
            }
            if (r.inboxes) {
                st.inboxes = r.inboxes;
            }
            (r.conversations || []).forEach(mergeConv);
            var goneOpen = false;
            (r.removed || []).forEach(function (rid) {
                if (rid === st.open) {
                    goneOpen = true;
                }
                removeConv(rid);
            });
            if (goneOpen) {
                toast(s('toastgoneaway'));
            }
            if (r.cursor !== undefined && r.cursor !== null) {
                st.cursor = r.cursor;
            }
            if (id && id === st.open) {
                if (r.readby) {
                    st.readby = r.readby;
                }
                if (r.messages && r.messages.length) {
                    addMessages(r.messages);
                    var c = conv(id);
                    if (c && c.unread && !document.hidden && !READONLY) {
                        c.unread = false;
                        act(id, 'read', 0, '', true);
                    }
                } else if (r.readby) {
                    placeReadBy();
                }
            }
            refreshAfterData();
        }
        document.addEventListener('visibilitychange', function () {
            schedule(document.hidden ? interval() : 0);
        });

        /* -------- Actions -------- */
        function act(id, action, target, value, quiet) {
            return tr.call(M.action, { conversation: id, action: action, target: target || 0, value: value || '' }).then(function (r) {
                var c = r && (r.conversation || r);
                if (c && c.id) {
                    if (c.status === 'gone') {
                        removeConv(c.id);
                        refreshAfterData();
                        return { gone: true };
                    }
                    mergeConv(c);
                    if (!quiet) {
                        refreshAfterData();
                    }
                }
                pollSoon();
                return { conversation: c };
            }).catch(function (e) {
                if (!quiet) {
                    failToast(e);
                }
                throw e;
            });
        }
        function busy(btn, on) {
            if (btn) {
                btn.disabled = on;
                btn.classList.toggle('mrd-ocupado', on);
            }
        }
        function runAction(btn, action, target, value, okText) {
            var id = st.open;
            busy(btn, true);
            st.pop = null;
            return act(id, action, target, value).then(function (res) {
                if (res && res.gone) {
                    toast(action === 'team' ? s('toastgone', teamName(+target)) : s('toastgoneaway'));
                } else if (okText) {
                    toast(okText);
                }
                return res;
            }).catch(function () {
                return null;
            }).then(function (res) {
                busy(btn, false);
                if (conv(id)) {
                    renderHead();
                    renderComposer(true);
                }
                return res;
            });
        }

        /* -------- Sending -------- */
        var MAXFILE = 16 * 1024 * 1024;
        function fileType(f) {
            var t = String(f.type || '');
            return /^image\//.test(t) ? 'image' : /^audio\//.test(t) ? 'audio' : /^video\//.test(t) ? 'video' : 'file';
        }
        // The file chosen for the next message, with a way to take it out.
        function chosenFileHtml() {
            var f = st.files[st.open];
            if (!f) {
                return '';
            }
            var kb = f.size < 1024 * 1024 ? Math.max(1, Math.round(f.size / 1024)) + ' KB' : (f.size / 1048576).toFixed(1).replace('.', ',') + ' MB';
            return '<div class="mrd-elegido">' + ico('clip') + '<span>' + esc(f.name) + ' · ' + esc(kb) + '</span>' +
                '<button type="button" class="mrd-enlace" data-a="unattach" aria-label="' + esc(s('removefile')) + '">' + ico('cerrar') + '</button></div>';
        }
        function send() {
            var c = conv(st.open), ed = el.chat.querySelector('.mrd-editor');
            if (!c || !ed) {
                return;
            }
            var shown = editorText(ed, false), wire = editorText(ed, true);
            var file = st.files[c.id] || null;
            if (!shown && !file) {
                ed.focus();
                return;
            }
            var note = st.mode === 'note';
            var m = {
                id: 'tmp' + (++seq), kind: note ? 'note' : 'outgoing', text: shown, wire: wire, at: Math.floor(nowMs() / 1000),
                author: { name: me.name, firstname: me.firstname, me: true }, signature: note ? '' : (me.signature || ''),
                status: 'pending', pending: true, attachments: [], template: '', conv: c.id
            };
            if (file) {
                m.file = file;
                m.attachments = [{ type: fileType(file), url: /^image\//.test(file.type) ? URL.createObjectURL(file) : '', thumb: '',
                    name: file.name, size: file.size }];
                delete st.files[c.id];
            }
            ed.innerHTML = '';
            if (st.drafts[c.id]) {
                st.drafts[c.id][st.mode] = '';
            }
            st.pop = null;
            refreshWritePop();
            if (!note) {
                // Answering makes it yours and leaves it waiting for the family; the server confirms in its answer.
                if (!c.assignee) {
                    c.assignee = { agentid: me.agentid, name: me.name, firstname: me.firstname, me: true };
                }
                if (c.section === 'reply') {
                    c.section = 'waiting';
                }
            }
            c.preview = { text: shown || (file ? file.name : ''), incoming: false, private: note, at: m.at, author: { name: me.name, me: true } };
            c.lastactivity = m.at;
            st.msgs.push(m);
            addMessagesLocal(m);
            refreshAfterData();
            ed.focus();
            deliver(m);
        }
        function addMessagesLocal(m) {
            var hilo = hiloEl();
            if (!hilo) {
                return;
            }
            var empty = hilo.querySelector('.mrd-hilo-nada');
            if (empty) {
                empty.remove();
            }
            var days = hilo.querySelectorAll('.mrd-dia'), k = dayKey(m.at * 1000);
            var h = (!days.length || +days[days.length - 1].getAttribute('data-day') !== k)
                ? '<span class="mrd-dia" data-day="' + k + '">' + esc(dayLabel(m.at * 1000)) + '</span>' : '';
            hilo.insertAdjacentHTML('beforeend', h + msgHtml(m));
            stickToBottom(hilo);
            hidePill();
        }
        function replaceMsg(oldId, m) {
            var i = indexOfMsg(oldId);
            var hilo = hiloEl();
            var node = hilo && hilo.querySelector('[data-id="' + cssEsc(oldId) + '"]');
            if (indexOfMsg(m.id) >= 0 && String(m.id) !== String(oldId)) {
                // The poll brought it first.
                if (i >= 0) {
                    st.msgs.splice(i, 1);
                }
                if (node) {
                    node.remove();
                }
                return;
            }
            if (i >= 0) {
                st.msgs[i] = m;
            }
            if (node) {
                node.outerHTML = msgHtml(m);
            }
        }
        function upload(m) {
            var body = new FormData();
            body.append('sesskey', cfg.sesskey);
            body.append('conversation', m.conv);
            body.append('text', m.wire || '');
            body.append('private', m.kind === 'note' ? '1' : '0');
            body.append('file', m.file, m.file.name);
            return fetch(cfg.wwwroot + '/local/oksigeniamarandu/chatupload.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (res) {
                    return res.json().then(function (data) {
                        if (!res.ok || !data || data.errorcode) {
                            throw data || { message: '' };
                        }
                        return data;
                    });
                });
        }
        function deliver(m) {
            var id = m.conv;
            var call = m.file ? upload(m) : tr.call(M.send, { conversation: id, text: m.wire, private: m.kind === 'note' });
            call.then(function (r) {
                var msg = r && (r.message || (r.id ? r : null));
                if (r && r.conversation) {
                    mergeConv(r.conversation);
                    refreshAfterData();
                }
                if (st.open === id && msg) {
                    replaceMsg(m.id, msg);
                }
                pollSoon();
            }).catch(function (e) {
                m.pending = false;
                m.failed = true;
                m.error = (e && e.message) || '';
                if (st.open === id) {
                    replaceMsg(m.id, m);
                }
            });
        }
        function retry(tmpId) {
            var i = indexOfMsg(tmpId);
            if (i < 0) {
                return;
            }
            var m = st.msgs[i];
            m.failed = false;
            m.pending = true;
            m.at = Math.floor(nowMs() / 1000);
            st.msgs.splice(i, 1);
            var node = hiloEl() && hiloEl().querySelector('[data-id="' + cssEsc(tmpId) + '"]');
            if (node) {
                node.remove();
            }
            st.msgs.push(m);
            addMessagesLocal(m);
            deliver(m);
        }
        function sendTemplate(btn) {
            var c = conv(st.open), t = (st.extras.templates || [])[st.tpl];
            if (!c || !t) {
                return;
            }
            var gaps = el.chat.querySelectorAll('.mrd-hueco'), params = [], missing = false;
            Array.prototype.forEach.call(gaps, function (g) {
                var v = g.textContent.replace(/\u00a0/g, ' ').trim();
                g.classList.toggle('mrd-falta', !v);
                if (!v) {
                    missing = true;
                }
                params.push({ key: g.getAttribute('data-k'), value: v });
            });
            if (missing) {
                toast(s('fillgaps'), true);
                return;
            }
            var seen = {};
            params = params.filter(function (p) {
                if (seen[p.key]) {
                    return false;
                }
                seen[p.key] = true;
                return true;
            });
            var bubble = el.chat.querySelector('.mrd-previa .mrd-msg p');
            var shown = bubble ? bubble.textContent.trim() : '';
            var id = c.id;
            busy(btn, true);
            tr.call(M.template, { conversation: id, name: t.name, language: t.language, params: params }).then(function (r) {
                var msg = r && (r.message || (r.id ? r : null));
                if (r && r.conversation) {
                    mergeConv(r.conversation);
                }
                if (st.open === id) {
                    st.tpl = -1;
                    st.pickAgain = false;
                    var m = msg || { id: 'tmp' + (++seq), kind: 'outgoing', text: shown, at: Math.floor(nowMs() / 1000), template: t.name,
                        signature: me.signature, status: 'sent', author: { name: me.name, me: true } };
                    if (!m.template) {
                        m.template = t.name;
                    }
                    if (indexOfMsg(m.id) < 0) {
                        st.msgs.push(m);
                        addMessagesLocal(m);
                    }
                    renderComposer(true);
                }
                refreshAfterData();
                pollSoon();
            }).catch(function (e) {
                busy(btn, false);
                failToast(e);
            });
        }

        /* ---------------------------------------------------------------------------------------------------------------
           Gestures
           --------------------------------------------------------------------------------------------------------------- */
        var audio = null, audioBtn = null;
        function playAudio(btn, url) {
            if (audio && audioBtn === btn) {
                if (audio.paused) {
                    audio.play();
                } else {
                    audio.pause();
                }
                return;
            }
            if (audio) {
                audio.pause();
            }
            audio = new Audio(url);
            audioBtn = btn;
            var wrap = btn.closest('.mrd-audio'), bars = wrap.querySelectorAll('.mrd-ondas i'), small = wrap.querySelector('small');
            var paint = function () {
                var p = audio.duration ? audio.currentTime / audio.duration : 0, n = Math.round(p * bars.length);
                Array.prototype.forEach.call(bars, function (b, i) {
                    b.classList.toggle('mrd-ya', i < n);
                });
                var t = Math.round(audio.paused && !audio.currentTime ? audio.duration || 0 : audio.currentTime);
                small.textContent = Math.floor(t / 60) + ':' + ('0' + (t % 60)).slice(-2);
            };
            var setIcon = function (playing) {
                btn.innerHTML = ico(playing ? 'pausa' : 'play');
                btn.classList.toggle('mrd-sonando', playing);
                btn.setAttribute('aria-label', playing ? s('pause') : s('play'));
            };
            audio.addEventListener('timeupdate', paint);
            audio.addEventListener('loadedmetadata', paint);
            audio.addEventListener('play', function () {
                setIcon(true);
            });
            audio.addEventListener('pause', function () {
                setIcon(false);
            });
            audio.addEventListener('ended', function () {
                setIcon(false);
            });
            audio.play().catch(function () {
                setIcon(false);
            });
        }
        function closePops() {
            if (!st.pop) {
                return false;
            }
            var was = st.pop;
            st.pop = null;
            if (was === 'perfil') {
                renderRail();
            } else if (was === 'dep' || was === 'dueno') {
                renderHead();
            } else {
                refreshWritePop();
            }
            return true;
        }
        // The address keeps the tray and the conversation (b and c), so a reload stays where it was.
        function syncUrl() {
            if (!root.history || !history.replaceState || !root.URL) {
                return;
            }
            try {
                var u = new URL(location.href);
                u.searchParams.set('b', st.inbox.replace(':', '-'));
                if (st.open) {
                    u.searchParams.set('c', String(st.open));
                } else {
                    u.searchParams.delete('c');
                }
                history.replaceState(history.state, '', u.toString());
            } catch (e) {
                // An app window without an address to change.
            }
        }
        function chooseInbox(key) {
            st.inbox = key;
            st.search = '';
            st.showResolved = false;
            renderRail();
            renderListHead();
            renderListBody();
            setLayer('lista', narrow() && st.capa === 'bandejas');
            syncUrl();
            if (!narrow()) {
                var c = conv(st.open);
                if (!c || !inInbox(c, key)) {
                    var first = firstOf(key);
                    if (first) {
                        openConv(first.id, { keepLayer: true });
                    }
                }
            }
        }

        box.addEventListener('click', function (e) {
            var t = e.target.closest('[data-a]');
            if (st.pop && !e.target.closest('.mrd-pop') && !(t && t.getAttribute('data-a') === 'pop')) {
                closePops();
            }
            if (!t || !box.contains(t)) {
                return;
            }
            var a = t.getAttribute('data-a'), v = t.getAttribute('data-v');
            var c = conv(st.open);
            switch (a) {
                case 'inbox':
                    chooseInbox(v);
                    break;
                case 'otros':
                    st.otros = !st.otros;
                    renderRail();
                    break;
                case 'conv':
                    openConv(+v);
                    break;
                case 'otherconv':
                    if (conv(+v)) {
                        openConv(+v);
                    } else {
                        toast(s('toastnotfound'), true);
                    }
                    break;
                case 'capa':
                    setLayer(v, true);
                    break;
                case 'back':
                    goBack(v);
                    break;
                case 'resolvedlist':
                    st.showResolved = true;
                    renderListBody();
                    break;
                case 'clearsearch':
                    st.search = '';
                    renderListHead();
                    renderListBody();
                    break;
                case 'pop':
                    if (READONLY && v !== 'perfil') {
                        break;
                    }
                    st.pop = st.pop === v ? null : v;
                    st.popIndex = 0;
                    st.popFilter = '';
                    if (v === 'perfil') {
                        renderRail();
                    } else if (v === 'dep' || v === 'dueno') {
                        renderHead();
                    } else {
                        refreshWritePop();
                        var ed0 = el.chat.querySelector('.mrd-editor');
                        if (ed0 && st.pop && !touchy()) {
                            ed0.focus();
                        }
                    }
                    break;
                case 'mode':
                    saveDraft();
                    st.mode = v;
                    st.pop = null;
                    renderComposer(true);
                    var ed1 = el.chat.querySelector('.mrd-editor');
                    if (ed1) {
                        caretToEnd(ed1);
                    }
                    break;
                case 'team':
                    if (c && +v !== c.teamid) {
                        runAction(t, 'team', +v, '', s('toastpassed', teamName(+v)));
                    } else {
                        closePops();
                    }
                    break;
                case 'person':
                    if (!c) {
                        break;
                    }
                    var pid = +v, mine = pid && pid === me.agentid;
                    var p = personName(pid);
                    runAction(t, 'person', pid, '', !pid ? s('toastnobody') : mine ? s('toastmine') : s('toastgiven', firstName(p)));
                    break;
                case 'take':
                    runAction(t, 'take', 0, '', s('toastmine'));
                    break;
                case 'resolve':
                    runAction(t, 'resolve', 0, '', s('toastresolved'));
                    break;
                case 'reopen':
                    runAction(t, 'reopen', 0, '', s('toastreopened'));
                    break;
                case 'ficha':
                    if (narrow()) {
                        setLayer('ficha', true);
                    } else {
                        st.fichaOpen = !st.fichaOpen;
                        box.classList.toggle('mrd-ficha-abierta', st.fichaOpen);
                    }
                    break;
                case 'send':
                    send();
                    break;
                case 'attach': {
                    var input = el.chat.querySelector('.mrd-archivo');
                    if (input) {
                        input.value = '';
                        input.onchange = function () {
                            var f = input.files && input.files[0];
                            if (!f) {
                                return;
                            }
                            if (f.size > MAXFILE) {
                                toast(s('filetoobig'), true);
                                return;
                            }
                            st.files[st.open] = f;
                            renderComposer(true);
                        };
                        input.click();
                    }
                    break;
                }
                case 'unattach':
                    delete st.files[st.open];
                    renderComposer(true);
                    break;
                case 'retry':
                    retry(v);
                    break;
                case 'canned':
                    chooseCanned(+v);
                    break;
                case 'mention':
                    chooseMention(+v);
                    break;
                case 'tpl':
                    st.tpl = +v;
                    renderComposer(true);
                    break;
                case 'tplback':
                    st.tpl = -1;
                    renderComposer(true);
                    break;
                case 'tplsend':
                    sendTemplate(t);
                    break;
                case 'pickagain':
                    st.pickAgain = true;
                    renderComposer(true);
                    break;
                case 'older':
                    loadOlder();
                    break;
                case 'newpill':
                    var hl = hiloEl();
                    if (hl) {
                        hl.scrollTop = hl.scrollHeight;
                    }
                    hidePill();
                    break;
                case 'kind':
                    if (!c) {
                        break;
                    }
                    busy(t, true);
                    act(c.id, 'kind', 0, v).then(function () {
                        toast(v === 'none' ? s('toastunmarked') : s('toastmarked', s(KIND_LABEL[v])));
                        loadFicha(c.id);
                    }).catch(function () {
                        busy(t, false);
                    });
                    break;
                case 'same':
                case 'notsame':
                    if (!c) {
                        break;
                    }
                    busy(t, true);
                    var name = t.getAttribute('data-n') || '';
                    act(c.id, a, +v, '').then(function () {
                        if (a === 'same') {
                            st.joined = name;
                            toast(s('toastjoined'));
                        } else {
                            toast(s('toastnotjoined'));
                        }
                        loadFicha(c.id);
                    }).catch(function () {
                        busy(t, false);
                    });
                    break;
                case 'fichaagain':
                    loadFicha(st.open);
                    break;
                case 'bgmode':
                case 'bgfixed':
                    if (a === 'bgmode') {
                        st.bg.mode = v;
                    } else {
                        st.bg.mode = 'fixed';
                        st.bg.fixed = v;
                    }
                    renderRail();
                    var hh = hiloEl();
                    if (hh) {
                        paintBackground(hh);
                    }
                    tr.call(M.prefs, { mode: st.bg.mode, fixed: st.bg.fixed }).catch(function () {
                        return null;
                    });
                    break;
                case 'foto':
                    openViewer(v, t.getAttribute('data-n'));
                    break;
                case 'visorclose':
                    closeViewer();
                    break;
                case 'play':
                    playAudio(t, v);
                    break;
                case 'tel':
                    // On a phone it dials; on a computer it copies the number.
                    if (!touchy() && !narrow()) {
                        e.preventDefault();
                        try {
                            navigator.clipboard.writeText(v);
                        } catch (err) {
                            // No clipboard: the toast still shows the number.
                        }
                        toast(s('toastcopied', v));
                    }
                    break;
                case 'reload':
                    location.reload();
                    break;
            }
        });
        el.visor.addEventListener('click', function (e) {
            if (e.target === el.visor) {
                closeViewer();
            }
        });
        box.addEventListener('input', function (e) {
            var t = e.target;
            if (t.getAttribute && t.getAttribute('data-role') === 'buscar') {
                st.search = t.value;
                var clear = el.lista.querySelector('.mrd-limpiar');
                if (clear) {
                    clear.hidden = !st.search;
                }
                renderListBody();
                return;
            }
            if (t.classList && t.classList.contains('mrd-editor')) {
                onEditorInput(t);
                return;
            }
            if (t.classList && t.classList.contains('mrd-hueco')) {
                t.classList.remove('mrd-falta');
            }
        });
        box.addEventListener('paste', function (e) {
            var t = e.target.closest && e.target.closest('.mrd-editor, .mrd-hueco');
            if (!t) {
                return;
            }
            e.preventDefault();
            var text = (e.clipboardData || root.clipboardData).getData('text/plain') || '';
            insertText(t.classList.contains('mrd-hueco') ? text.replace(/\s+/g, ' ') : text);
        });
        box.addEventListener('keydown', function (e) {
            var t = e.target;
            if (e.key === 'Escape') {
                if (!el.visor.hidden) {
                    closeViewer();
                    return;
                }
                if (closePops()) {
                    e.preventDefault();
                    return;
                }
                if (t.getAttribute && t.getAttribute('data-role') === 'buscar' && st.search) {
                    st.search = '';
                    t.value = '';
                    renderListBody();
                }
                return;
            }
            if (t.classList && t.classList.contains('mrd-hueco') && e.key === 'Enter') {
                e.preventDefault();
                return;
            }
            if (!(t.classList && t.classList.contains('mrd-editor'))) {
                return;
            }
            if (st.pop === 'guardadas' || st.pop === 'mencion') {
                var list = st.pop === 'guardadas' ? cannedList() : mentionList();
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    st.popIndex = (st.popIndex + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % Math.max(1, list.length);
                    refreshWritePop();
                    var on = el.chat.querySelector('.mrd-pop .mrd-on');
                    if (on && on.scrollIntoView) {
                        on.scrollIntoView({ block: 'nearest' });
                    }
                    return;
                }
                if ((e.key === 'Enter' || e.key === 'Tab') && list.length) {
                    e.preventDefault();
                    var item = list[st.popIndex] || list[0];
                    if (st.pop === 'guardadas') {
                        chooseCanned(st.extras.canned.indexOf(item));
                    } else {
                        chooseMention(item.agentid);
                    }
                    return;
                }
            }
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !touchy()) {
                e.preventDefault();
                send();
            } else if (e.key === 'Enter' && e.shiftKey) {
                e.preventDefault();
                insertText('\n');
            }
        });
        box.addEventListener('scroll', function (e) {
            var t = e.target;
            if (t.classList && t.classList.contains('mrd-hilo')) {
                if (t.scrollTop < 60 && st.more && !st.older) {
                    loadOlder();
                }
                stuck = atBottom(t);
                if (stuck) {
                    hidePill();
                }
            }
        }, true);
        root.addEventListener('resize', function () {
            fitViewport();
            if (!narrow() && (st.capa === 'bandejas' || st.capa === 'ficha')) {
                setLayer(st.open ? 'chat' : 'lista');
            }
        });

        /* -------- The clock: waits, the 24-hour window, the morning -------- */
        setInterval(function () {
            if (!st.ready) {
                return;
            }
            renderRail();
            renderListBody();
            var c = conv(st.open);
            if (c) {
                renderComposer();
            }
        }, 30000);
        setInterval(function () {
            if (!st.ready || document.hidden) {
                return;
            }
            tr.call(M.extras, {}).then(function (x) {
                if (x) {
                    st.extras = Object.assign(st.extras, x);
                }
            }).catch(function () {
                return null;
            });
        }, 600000);

        /* ---------------------------------------------------------------------------------------------------------------
           Start
           --------------------------------------------------------------------------------------------------------------- */
        var startCfg = cfg.start || {};
        if (startCfg.inbox) {
            // «mine», «unassigned» or «team-<id>» (from chat.php?b=…).
            var b0 = String(startCfg.inbox);
            st.inbox = /^\d+$/.test(b0) ? 'team:' + b0 : b0.replace(/^team[-:]/, 'team:');
        }
        renderAll();
        function begin() {
            Promise.all([
                tr.call(M.extras, {}).catch(function () {
                    return null;
                }),
                tr.call(M.poll, { since: 0, conversation: 0, after: 0 })
            ]).then(function (res) {
                if (res[0]) {
                    st.extras = Object.assign(st.extras, res[0]);
                }
                var r = res[1] || {};
                if (r.now) {
                    skew = r.now * 1000 - Date.now();
                }
                st.inboxes = r.inboxes || null;
                (r.conversations || []).forEach(mergeConv);
                st.cursor = r.cursor !== undefined ? r.cursor : 0;
                st.ready = true;
                renderAll();
                var want = +startCfg.conversation || 0;
                if (want && conv(want)) {
                    var wc = conv(want);
                    if (!inInbox(wc, st.inbox)) {
                        st.inbox = isMine(wc) ? 'mine' : wc.teamid ? 'team:' + wc.teamid : st.inbox;
                        renderRail();
                        renderListHead();
                        renderListBody();
                    }
                    openConv(want);
                } else if (!narrow()) {
                    var first = firstOf(st.inbox);
                    if (first) {
                        openConv(first.id, { keepLayer: true });
                    }
                }
                schedule(interval());
            }).catch(function (e) {
                var body = el.lista.querySelector('.mrd-lista-cuerpo');
                var code = e && e.errorcode;
                if (code === 'invalidsesskey' || code === 'servicerequireslogin' || code === 'requireloginerror') {
                    st.expired = true;
                    showRed('expired');
                }
                if (body) {
                    body.innerHTML = '<div class="mrd-vacio"><b>' + esc(s('loaderror')) + '</b>' + esc((e && e.message) || '') +
                        '<br><button type="button" class="mrd-btn mrd-chico" data-a="reload">' + esc(s('tryagain')) + '</button></div>';
                }
            });
        }
        begin();

        return {
            refresh: pollSoon,
            open: openConv
        };
    }

    root.MaranduChat = { start: start, moodleTransport: moodleTransport };
})(window);
