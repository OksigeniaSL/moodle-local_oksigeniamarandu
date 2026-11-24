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
        prefs: 'local_oksigeniamarandu_chat_prefs',
        find: 'local_oksigeniamarandu_chat_find',
        resolved: 'local_oksigeniamarandu_chat_resolved',
        remind: 'local_oksigeniamarandu_chat_remind',
        schedule: 'local_oksigeniamarandu_chat_schedule',
        agenda: 'local_oksigeniamarandu_chat_agenda',
        board: 'local_oksigeniamarandu_chat_board',
        team: 'local_oksigeniamarandu_chat_team',
        spend: 'local_oksigeniamarandu_chat_spend',
        flag: 'local_oksigeniamarandu_chat_flag',
        start: 'local_oksigeniamarandu_chat_start',
        call: 'local_oksigeniamarandu_chat_call',
        draft: 'local_oksigeniamarandu_chat_draft'
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
        proponer: '<path d="M4 17.5V20h2.5L17.8 8.7a1.8 1.8 0 0 0-2.5-2.5z"/><path d="M13.8 7.7l2.5 2.5"/><path d="M12 20h8"/>',
        bombilla: '<path d="M9.5 18h5M10.5 21h3"/><path d="M12 3a6 6 0 0 0-3.6 10.8c.7.5 1.1 1.3 1.1 2.2h5c0-.9.4-1.7 1.1-2.2A6 6 0 0 0 12 3z"/>',
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
        'eq-sala': '<path d="M3.5 6.5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2z"/>' +
            '<path d="M15.5 9h3a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2v3l-3.5-3h-3.5a2 2 0 0 1-2-2v-1.5"/>',
        'eq-pin': '<path d="M9 3.5h6l-1 5 3.5 3.5h-11L10 8.5z"/><path d="M12 12v8.5"/>',
        'eq-mas-p': '<circle cx="10" cy="8" r="3.5"/><path d="M3.5 19.5c1.2-3.4 3.8-5.2 6.5-5.2 1.4 0 2.7.4 3.8 1.3M18 14v6M15 17h6"/>',
        'eq-tablon': '<rect x="3.5" y="4.5" width="17" height="14" rx="2"/><path d="M7.5 9h6M7.5 12.5h9M7.5 16h4"/>' +
            '<path d="M8 20.5l1.5-2M16 20.5l-1.5-2"/>',
        estrella: '<path d="M12 3.6l2.5 5.2 5.7.8-4.1 4 1 5.7L12 16.6l-5.1 2.7 1-5.7-4.1-4 5.7-.8z"/>',
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
        prog: '<circle cx="11" cy="13" r="7.5"/><path d="M11 9v4h3.2"/><path d="M15.5 3.5h5v5"/><path d="M20.5 3.5L16 8"/>',
        alerta: '<path d="M12 4l9 15.5H3z"/><path d="M12 10v4M12 17v.01"/>',
        'gs-wa': '<path d="M4.5 19.5l1.2-3.6A7.8 7.8 0 1 1 8.8 19z"/><path d="M9 9.2c.2 2.6 2.2 4.6 4.8 4.8l.9-1.2 1.6.8-.4 1.5c-3.6.4-7.4-3.4-7-7l1.5-.4.8 1.6z"/>',
        'gs-tg': '<path d="M20.5 4.5L3.5 11l5.5 2 2 6 3-4 4.5 3.5z"/><path d="M9 13l8-6"/>',
        'gs-sin': '<circle cx="12" cy="12" r="8.5"/><path d="M6.5 17.5l11-11"/>',
        'gs-euro': '<path d="M17.5 6.5a6.5 6.5 0 1 0 0 11"/><path d="M5 10.5h8M5 13.5h8"/>',
        'gs-firma': '<path d="M4 19.5h16"/><path d="M6 15.5l8.5-8.5 2.5 2.5L8.5 18H6z"/>',
        'gs-x': '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
        'gs-menos': '<path d="M6 12h12"/>',
        'gs-mas': '<path d="M6 12h12M12 6v12"/>',
        ayuda: '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.6a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1.1.8-1.1 1.5v.4M12 16.6v.01"/>',
        campana: '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 1.5h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
        sonido: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>',
        mudo: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M16 9.5l5 5M21 9.5l-5 5"/>',
        ojo: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
        punto: '<circle cx="12" cy="12" r="3"/>',
        video: '<rect x="3.5" y="6" width="12.5" height="12" rx="2.5"/><path d="M16 10.5l4.5-2.5v8l-4.5-2.5"/>',
        ubicacion: '<path d="M12 21s-6.5-6.2-6.5-11a6.5 6.5 0 0 1 13 0c0 4.8-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/>',
        menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
        deshacer: '<path d="M9 7l-4 4 4 4"/><path d="M5 11h9a5 5 0 0 1 0 10h-2"/>',
        pasar: '<path d="M4 12h13"/><path d="M13 7l5 5-5 5"/><path d="M20 5v14"/>',
        // A WhatsApp that did not arrive, and a call that was answered or not.
        nollego: '<path d="M4 5h16v11H9.5L5 19.5V16H4z"/><path d="M12 8.2v3.4"/><path d="M12 13.9v.01" stroke-width="2.6"/>',
        telsi: '<path d="M5.5 3.5h3l2 5-2.5 1.6a11 11 0 0 0 5.9 5.9l1.6-2.5 5 2v3a2 2 0 0 1-2 2' +
            'A16.5 16.5 0 0 1 3.5 5.5a2 2 0 0 1 2-2z"/>' +
            '<path d="M14.5 6.2l2 2 4-4.2"/>',
        telno: '<path d="M5.5 3.5h3l2 5-2.5 1.6a11 11 0 0 0 5.9 5.9l1.6-2.5 5 2v3a2 2 0 0 1-2 2' +
            'A16.5 16.5 0 0 1 3.5 5.5a2 2 0 0 1 2-2z"/>' +
            '<path d="M15.5 3.5l5 5M20.5 3.5l-5 5"/>'
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
        // Small choices of the person, kept on the site: the tour seen, the sound of the notices, notices on this computer.
        var FLAGS = cfg.flags || { tour: '', sound: 'on', desktop: '' };
        // The contacts the person starred: their address book starts with them.
        var FAVS = {};
        (cfg.favs || []).forEach(function (id) {
            FAVS[id] = true;
        });
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
        var fParts, fTime, fWeekday, fDayMonth, fShort, fYear, fMonth;
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
            fMonth = new Intl.DateTimeFormat(LANG, o({ month: 'long', year: 'numeric' }));
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

        /* -------- «Remind me»: the days a person can choose, and when each one starts -------- */
        function keyIso(k) {
            var y = Math.floor(k / 10000), mo = Math.floor(k / 100) % 100, d = k % 100;
            return y + '-' + (mo < 10 ? '0' : '') + mo + '-' + (d < 10 ? '0' : '') + d;
        }
        function isoKey(iso) {
            return +String(iso || '').replace(/-/g, '') || 0;
        }
        // «mañana», «el lunes», «el 3 de diciembre».
        function remShort(ms) {
            var k = dayKey(ms), today = dayKey(nowMs()), d = new Date(ms);
            if (k === shiftKey(today, 1)) {
                return s('remtomorrowlow');
            }
            return s('remonday', k < shiftKey(today, 7) ? fWeekday.format(d) : fDayMonth.format(d));
        }


        /* -------- Working hours: the wait counts only while the department of a conversation is open --------
           The hours of the inbox (cfg.hours) and those of each department that has its own week, or days of the calendar
           only for it (cfg.teamhours): each day, its spans; a closed day, none; a day of its own, its own spans. */
        function hm(x) {
            var p = String(x || '0:0').split(':');
            return [+p[0] || 0, +p[1] || 0];
        }
        function makeHours(h) {
            h = h || {};
            var on = !!(h.enabled && h.week && h.week.length);
            var week = {}, closed = {}, special = {};
            (h.week || []).forEach(function (w) {
                week[w.day] = w.closed ? [] : w.spans && w.spans.length ? w.spans
                    : w.allday ? [{ open: '00:00', close: '24:00' }] : [{ open: w.open, close: w.close }];
            });
            (h.closed || []).forEach(function (d) {
                closed[String(d)] = true;
            });
            (h.special || []).forEach(function (x) {
                special[String(x.day)] = x.spans || [];
            });
            // The spans of a day, as moments.
            function windowsOfKey(key) {
                var y = Math.floor(key / 10000), mo = Math.floor(key / 100) % 100, d = key % 100, iso = keyIso(key);
                if (closed[iso]) {
                    return [];
                }
                var spans = special[iso] || week[new Date(Date.UTC(y, mo - 1, d)).getUTCDay()] || [];
                return spans.map(function (sp) {
                    var o = hm(sp.open), c = hm(sp.close);
                    return [zonedMs(y, mo, d, o[0], o[1]), zonedMs(y, mo, d, c[0], c[1])];
                });
            }
            function openAt(ms) {
                return !on || windowsOfKey(dayKey(ms)).some(function (w) {
                    return ms >= w[0] && ms < w[1];
                });
            }
            function businessMs(from, to) {
                if (!on) {
                    return Math.max(0, to - from);
                }
                var total = 0, key = dayKey(from), last = dayKey(to);
                for (var i = 0; i < 62 && key <= last; i++) {
                    windowsOfKey(key).forEach(function (w) {
                        total += Math.max(0, Math.min(w[1], to) - Math.max(w[0], from));
                    });
                    key = shiftKey(key, 1);
                }
                return total;
            }
            // When it opens next (0 if never, or without hours); a summer closed can be long.
            function nextOpening(ms) {
                if (!on) {
                    return 0;
                }
                var key = dayKey(ms);
                for (var i = 0; i < 120; i++) {
                    var ws = windowsOfKey(key);
                    for (var j = 0; j < ws.length; j++) {
                        if (ws[j][0] > ms) {
                            return ws[j][0];
                        }
                    }
                    key = shiftKey(key, 1);
                }
                return 0;
            }
            // When it closes, if it is open: the end of the span it is in (0 if not open, or without hours).
            function closingAt(ms) {
                var w = on ? windowsOfKey(dayKey(ms)).filter(function (x) {
                    return ms >= x[0] && ms < x[1];
                })[0] : null;
                return w ? w[1] : 0;
            }
            // First thing in the day: open, and opened less than two hours ago.
            function isMorning() {
                var w = on ? windowsOfKey(dayKey(nowMs()))[0] : null, n = nowMs();
                return !!(w && n >= w[0] && n < w[1] && n - w[0] < 2 * 3600000);
            }
            // It arrived after the last closing time and before today's opening.
            function arrivedAtNight(c) {
                var w = on ? windowsOfKey(dayKey(nowMs()))[0] : null;
                var at = (c.waitingsince || 0) * 1000;
                return !!(w && at && at < w[0] && !openAt(at) && businessMs(at, w[0]) === 0);
            }
            // The team works that day (without hours, every day).
            function openDay(k) {
                return !on || windowsOfKey(k).length > 0;
            }
            // When a day starts for a reminder: the opening of the team, or 8:00 (as the server does).
            function remAt(k) {
                var y = Math.floor(k / 10000), mo = Math.floor(k / 100) % 100, d = k % 100;
                var w = on ? windowsOfKey(k)[0] : null;
                return w && w[0] > zonedMs(y, mo, d, 0, 0) ? w[0] : zonedMs(y, mo, d, 8, 0);
            }
            return { on: on, openAt: openAt, businessMs: businessMs, nextOpening: nextOpening, closingAt: closingAt,
                isMorning: isMorning, arrivedAtNight: arrivedAtNight, openDay: openDay, remAt: remAt, windows: windowsOfKey };
        }
        var SITE_HOURS = makeHours(hours);
        var TEAM_HOURS = {};
        (cfg.teamhours || []).forEach(function (h) {
            TEAM_HOURS[h.id] = makeHours(h);
        });
        // Whether some department keeps hours of its own: then each one says how it is.
        var BYTEAM = (cfg.teamhours || []).length > 0;
        function hoursOf(teamid) {
            return (teamid && TEAM_HOURS[teamid]) || SITE_HOURS;
        }
        function hoursConv(c) {
            return hoursOf(c && c.teamid);
        }
        // It arrived out of the hours of its department, which opened a moment ago.
        function nightArrival(c) {
            var hh = hoursConv(c);
            return hh.isMorning() && hh.arrivedAtNight(c);
        }
        /* The departments of the person, for what is said of them all («out of hours»): theirs; without any, every
           department and the inbox for whoever sees everything, the inbox for the rest. */
        function myHours() {
            var mine = teams().filter(function (t) {
                return t.mine;
            });
            if (mine.length) {
                return mine.map(function (t) {
                    return hoursOf(t.id);
                });
            }
            return me.seesall ? [SITE_HOURS].concat(teams().map(function (t) {
                return hoursOf(t.id);
            })) : [SITE_HOURS];
        }
        // All of them closed now: when the first opens (-1 if none ever does); 0 if one of them is open.
        function firstOpening(list) {
            var n = nowMs(), first = -1;
            for (var i = 0; i < list.length; i++) {
                if (!list[i].on || list[i].openAt(n)) {
                    return 0;
                }
                var next = list[i].nextOpening(n);
                if (next && (first < 0 || next < first)) {
                    first = next;
                }
            }
            return first;
        }
        // How a department is now, in a few words: «Abierto hasta las 14:00», or closed and «Abre mañana a las 7:30».
        function teamState(teamid) {
            var hh = hoursOf(teamid), n = nowMs();
            if (!hh.on || hh.openAt(n)) {
                // Open until midnight, or always: just open.
                var until = hh.on ? hh.closingAt(n) : 0, end = until ? zp(until) : null;
                return { open: true, text: end && end.h + end.mi > 0 ? s('teamopenuntil', fmtTime(until)) : s('teamopen') };
            }
            var next = hh.nextOpening(n);
            return { open: false, next: next, text: next ? s('teamopensat', whenPhrase(next)) : s('closednow') };
        }
        function waitMs(c) {
            if (!c.waitingsince) {
                return 0;
            }
            return hoursConv(c).businessMs(c.waitingsince * 1000, nowMs());
        }
        function waitText(c) {
            return waitTextLoose(c).replace(/ (?=\d|h\b|min\b)/g, '\u00a0');
        }
        function waitTextLoose(c) {
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
            // What WhatsApp costs and the sends that wait (chat_spend); a send whose text is open; one being rejected; the
            // people being chosen.
            spend: null,
            // The line of «Who is it?» just kept, to say so for a moment.
            qfSaved: '',
            gsOpen: 0,
            gsReject: null,
            gsPick: null,
            cursor: 0, convs: {}, inboxes: null, extras: { canned: [], templates: [], people: [], teams: [] }, files: {},
            inbox: 'mine', open: 0, msgs: [], loaded: false, more: false, older: false, readby: [],
            ficha: null, fichaFor: 0, fichaState: '', fichaKind: '', joined: '',
            mode: 'reply', pop: null, popIndex: 0, popFilter: '', search: '', tpl: -1, pickAgain: false,
            rem: { day: '', reason: '' }, sched: null, schedPick: 0, agenda: null, optoutOk: {}, board: null, boardOpen: 0, boardNew: null, boardEdit: null, boardRev: -1,
            boardDone: false, showResolved: false, resolved: '', resolvedMore: false, resolvedDays: 30, history: '', historyMore: false, otros: (function () {
                try {
                    var v = root.localStorage.getItem('mrd-chat-otros');
                    return v === 'abierto' ? true : v === 'plegado' ? false : null;
                } catch (e) {
                    return null;
                }
            })(), otrosNow: false,
            hf: { mine: false, team: 0, period: '' }, hits: {}, capa: 'lista', fichaOpen: false, drafts: {}, dark: false,
            bg: { mode: (cfg.backgrounds && cfg.backgrounds.mode) || 'family', fixed: (cfg.backgrounds && cfg.backgrounds.fixed) || 'cuaderno' },
            ready: false, failed: 0, lastGood: 0, unchecked: null, expired: false, composerSig: '', busy: {}, nueva: null, justStarted: 0,
            // Passing on, second step: the department chosen and a line for it. What was just passed on or resolved, to
            // undo it. The conversations whose approved messages were unfolded to write. Where the search looked. The
            // sends decided on this page, on top of what was decided.
            pass: null, undo: null, unfold: {}, searchFor: '', searchScope: '', gsJust: {},
            // Messages between the team (chat_team): the lists, how many wait unread and the poll's mark of change; the
            // conversation open; «Write to…» and what is searched in it; what each conversation has half written and the
            // conversation with a family about to go as a card; the search of the cards; the last half of «Equipo» on a
            // phone and the last tray with families.
            team: null, teamUnread: 0, teamRev: -1, room: null, rooms: {}, teamPick: false, teamQ: '', teamDrafts: {}, teamCards: {},
            passQ: '', passFound: {}, passFor: '', teamHalf: 'team', lastTray: '',
            // A call being written down (its line, and the approved message offered after one nobody answered); the
            // conversations where the person said «I'll handle it» although someone else has another of the family.
            call: { line: '', offer: 0 }, heldOk: (function () {
                var kept = {}, key = 'mrd-chat-lleva-' + ((cfg.me && cfg.me.userid) || 0);
                try {
                    JSON.parse(root.sessionStorage.getItem(key) || '[]').forEach(function (id) {
                        kept[id] = true;
                    });
                } catch (e) {
                    // Without storage, nothing put off yet.
                }
                return kept;
            })()
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
            if (inbox === 'history') {
                return c.section === 'resolved';
            }
            if (inbox === 'mine') {
                // Also what the person asked to have back on a day, though it was resolved.
                return isMine(c) || !!c.remind;
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
            if (k === 'other') {
                return '<span class="mrd-av mrd-cuadrado mrd-con-bicho' + cls + '" aria-hidden="true">' + bicho(seed, false) + '</span>';
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
        // A person of the team: the picture of their profile, or else their own critter, always in a rounded square, as the
        // families are always in a circle: who is who, from afar.
        function avAgent(p, size) {
            if (!p) {
                return '<span class="mrd-av mrd-nadie ' + (size || '') + '" aria-hidden="true">' + ico('nadie') + '</span>';
            }
            var mine = p.me || (me.agentid && p.agentid === me.agentid);
            var id = p.agentid || (mine ? me.agentid : 0);
            var person = (st.extras.people || []).filter(function (x) {
                return id && x.agentid === id;
            })[0];
            var picture = (person && person.picture) || (mine ? me.picture : '') || '';
            var cls = 'mrd-av mrd-equipo ' + (mine ? 'mrd-yo-av ' : '') + (size || '');
            if (picture) {
                return '<span class="' + cls + ' mrd-con-foto" aria-hidden="true"><img src="' + esc(picture) + '" alt="" loading="lazy"></span>';
            }
            if (id && root.BichitoHk) {
                return '<span class="' + cls + ' mrd-con-bicho" aria-hidden="true">' + bicho('equipo-' + id, false) + '</span>';
            }
            return '<span class="' + cls + '" aria-hidden="true">' + esc(cap(firstName(p)).charAt(0)) + '</span>';
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
                        var whole = h.slice(at).match(/^[^(<]*?(?:\s\([^)<]*\))?(?=$|[,;]|\sy\s|\sand\s|\s·)/);
                        var len = whole ? whole[0].length : n.length;
                        h = h.slice(0, at) + '<span class="mrd-hijo-l"><i class="mrd-pe' + (r.dashed ? ' mrd-fuera' : '') + '" style="--e: ' + col.css +
                            '" aria-hidden="true"></i>' + h.slice(at, at + len) + '</span>' + h.slice(at + len);
                    }
                }
            });
            // What the team marked, in bold, when it is more than a family; then the lines of the marks; then the family.
            var marks = contact.marks || [];
            if (marks.some(function (m) {
                return m !== 'family';
            })) {
                var words = marks.map(function (m) {
                    return s('mark' + m);
                }).join(' · ');
                h = '<b class="mrd-tipo-t">' + esc(words) + '</b>' + (contact.note ? ' · ' + esc(contact.note) : '') + (h ? ' · ' + h : '');
            } else if (KIND_LABEL[contact.kind]) {
                h = '<b class="mrd-tipo-t">' + esc(s(KIND_LABEL[contact.kind])) + '</b>' + (h ? ' · ' + h : '');
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
        /* ---------------------------------------------------------------------------------------------------------------
           Notices while the page is open: the number in the title of the tab, a short sound that can be switched off and,
           with the page behind other windows, a notice of the system. The same notices as the bell and the phone (the
           poll brings them), asked for with a button on the screen, never when the page opens.
           --------------------------------------------------------------------------------------------------------------- */
        var baseTitle = document.title, seenNotice = {}, audio = null;
        function setFlag(name, value) {
            FLAGS[name] = value;
            tr.call(M.flag, { name: name, value: value }).catch(function () {
                // Kept for this visit at least.
            });
        }
        // The tab says what waits, with a dot of its colour on its icon: for whoever sees everything, all of the centre.
        var favicon = { base: null, img: null, drawn: '' };
        function updateTitle() {
            var n, long;
            if (me.seesall) {
                var all = allConvs().filter(function (c) {
                    return c.section === 'reply';
                });
                n = all.length;
                long = all.some(function (c) {
                    return waitMs(c) >= 4 * 3600000;
                });
            } else {
                var c = inboxCounts();
                n = (c.mine || 0) + (c.unassigned || 0);
                long = longestIn(['mine', 'unassigned']) >= 4 * 3600000;
            }
            document.title = n ? '(' + n + ') ' + baseTitle : baseTitle;
            markFavicon(n ? (long ? '#d42c2c' : '#f2a900') : '');
        }
        function markFavicon(colour) {
            var link = document.querySelector('link[rel~="icon"]');
            if (!link) {
                if (!colour) {
                    return;
                }
                link = document.createElement('link');
                link.rel = 'icon';
                document.head.appendChild(link);
            }
            if (favicon.base === null) {
                favicon.base = link.getAttribute('href') || '';
            }
            if (favicon.drawn === colour) {
                return;
            }
            favicon.drawn = colour;
            if (!colour) {
                if (favicon.base) {
                    link.href = favicon.base;
                }
                return;
            }
            var draw = function (img) {
                try {
                    var cv = document.createElement('canvas'), g = cv.getContext('2d');
                    cv.width = cv.height = 64;
                    if (img) {
                        g.drawImage(img, 0, 0, 64, 64);
                    } else {
                        g.fillStyle = (brand.colors && brand.colors[0]) || '#1b4d8f';
                        g.fillRect(0, 0, 64, 64);
                    }
                    g.beginPath();
                    g.arc(45, 19, 17, 0, 2 * Math.PI);
                    g.fillStyle = colour;
                    g.fill();
                    g.lineWidth = 5;
                    g.strokeStyle = '#ffffff';
                    g.stroke();
                    link.href = cv.toDataURL('image/png');
                } catch (e) {
                    // An icon of another site cannot be drawn on: a plain one with the dot.
                    if (img) {
                        draw(null);
                    }
                }
            };
            if (!favicon.base) {
                draw(null);
            } else if (favicon.img) {
                draw(favicon.img);
            } else {
                var img = new Image();
                img.onload = function () {
                    favicon.img = img;
                    draw(img);
                };
                img.onerror = function () {
                    draw(null);
                };
                img.src = favicon.base;
            }
        }
        function canDesktop() {
            return !cfg.inapp && !READONLY && 'Notification' in root;
        }
        function desktopState() {
            if (!canDesktop()) {
                return 'none';
            }
            if (Notification.permission === 'denied') {
                return 'blocked';
            }
            return Notification.permission === 'granted' && FLAGS.desktop !== 'no' ? 'on' : 'off';
        }
        // Asked once, in one line: on a computer at the head of the list, always in sight; on a phone, on the start.
        function offerDesktop() {
            return canDesktop() && Notification.permission === 'default' && FLAGS.desktop === '';
        }
        function askDesktop() {
            var done = function (p) {
                setFlag('desktop', p === 'granted' ? 'yes' : 'no');
                renderListBody();
                renderHome();
                if (p === 'granted') {
                    toast(s('desktopready'));
                } else if (p === 'denied') {
                    toast(s('desktopblocked'), true);
                }
                if (st.pop === 'avisos') {
                    renderRail();
                }
            };
            try {
                var asked = Notification.requestPermission(done);
                if (asked && asked.then) {
                    asked.then(done);
                }
            } catch (e) {
                done('default');
            }
        }
        // The browsers only let a page sound after a tap or a key: the sound is made ready with the first one.
        function wakeAudio() {
            if (audio || !(root.AudioContext || root.webkitAudioContext)) {
                return;
            }
            try {
                audio = new (root.AudioContext || root.webkitAudioContext)();
            } catch (e) {
                audio = null;
            }
        }
        function chime() {
            if (FLAGS.sound === 'off' || !audio) {
                return;
            }
            try {
                if (audio.state === 'suspended') {
                    audio.resume();
                }
                var t = audio.currentTime;
                [[880, 0], [1318.5, 0.13]].forEach(function (n) {
                    var o = audio.createOscillator(), g = audio.createGain();
                    o.type = 'sine';
                    o.frequency.value = n[0];
                    g.gain.setValueAtTime(0.0001, t + n[1]);
                    g.gain.exponentialRampToValueAtTime(0.16, t + n[1] + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.4);
                    o.connect(g);
                    g.connect(audio.destination);
                    o.start(t + n[1]);
                    o.stop(t + n[1] + 0.45);
                });
            } catch (e) {
                // Without sound, the notice still shows.
            }
        }
        function onNotices(list) {
            var fresh = (list || []).filter(function (n) {
                if (seenNotice[n.id]) {
                    return false;
                }
                seenNotice[n.id] = true;
                return true;
            });
            if (!fresh.length) {
                return;
            }
            chime();
            var here = document.visibilityState === 'visible' && document.hasFocus();
            fresh.slice(-3).forEach(function (n) {
                // A message of the team says so with «team», its conversation between the team.
                var reading = n.team ? st.inbox === 'team' && !!st.room && st.room.id === n.team : n.conversation === st.open;
                if (here) {
                    if (!reading) {
                        toast(n.text);
                    }
                    return;
                }
                if (desktopState() !== 'on') {
                    return;
                }
                try {
                    var x = new Notification(brand.name || s('apptitle'), { body: n.text, tag: 'mrd-' + (n.conversation || n.id),
                        icon: brand.logo || undefined });
                    x.onclick = function () {
                        root.focus();
                        if (n.team) {
                            openRoom('c' + n.team);
                        } else if (n.conversation && conv(n.conversation)) {
                            openConv(n.conversation);
                        }
                        x.close();
                    };
                } catch (e) {
                    // A browser that does not show notices from a page.
                }
            });
        }
        // The same words on every screen: «¿Te avisamos cuando te escriban? Sí · Ahora no».
        function offerHtml() {
            return '<div class="mrd-avisar">' + ico('campana') + '<span class="mrd-avisar-t">' + esc(s('desktopoffer')) + '</span>' +
                '<button type="button" class="mrd-avisar-si" data-a="desktopon">' + esc(s('desktopyes')) + '</button>' +
                '<span class="mrd-avisar-punto" aria-hidden="true">·</span>' +
                '<button type="button" class="mrd-avisar-no" data-a="desktopno">' + esc(s('desktopnotnow')) + '</button></div>';
        }
        function popNotices() {
            var d = desktopState();
            var line = d === 'on' ? s('desktopison') : d === 'blocked' ? s('desktopisblocked') : s('desktopisoff');
            var button = d === 'on' ? '<button type="button" class="mrd-btn mrd-chico" data-a="desktopno">' + esc(s('desktopturnoff')) + '</button>'
                : d === 'blocked' ? '' : '<button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="desktopon">' + esc(s('desktopon')) + '</button>';
            var sound = FLAGS.sound !== 'off';
            return '<div class="mrd-pop mrd-pop-avisos" role="dialog" aria-label="' + esc(s('noticestitle')) + '"><h5>' + esc(s('noticestitle')) + '</h5>' +
                (d === 'none' ? '' : '<div class="mrd-avisos-fila">' + ico('campana') + '<div><b>' + esc(s('desktoptitle')) + '</b><small>' + esc(line) +
                    '</small></div>' + button + '</div>') +
                '<div class="mrd-avisos-fila">' + ico(sound ? 'sonido' : 'mudo') + '<div><b>' + esc(s('soundtitle')) + '</b><small>' +
                esc(sound ? s('soundison') : s('soundisoff')) + '</small></div><button type="button" class="mrd-btn mrd-chico" data-a="sound" aria-pressed="' +
                sound + '">' + esc(sound ? s('soundturnoff') : s('soundturnon')) + '</button></div>' +
                '<p class="mrd-fondo-nota">' + esc(s('noticeswhat')) + '</p></div>';
        }
        /* ---------------------------------------------------------------------------------------------------------------
           Help: a short tour over the real screen the first time (the trays, «New», the list, the notices, the help) and
           another the first time a conversation opens (who has it, its department, the internal note, the 24 hours,
           resolving it). «Help», always at hand, shows them again and opens the guides. Not on a phone.
           --------------------------------------------------------------------------------------------------------------- */
        var TOURS = {
            inicio: [
                ['.mrd-bandeja[data-v="mine"]', 'tourmine'],
                ['.mrd-bandeja[data-v="unassigned"]', 'tourfree'],
                ['.mrd-bandeja[data-v^="team:"]', 'tourteams'],
                ['.mrd-nueva-b', 'tournew'],
                ['.mrd-lista-cuerpo .mrd-conv', 'tourlist'],
                ['.mrd-rail-b[data-v="avisos"]', 'tournotices'],
                ['.mrd-rail-b[data-v="ayuda"]', 'tourhelp']
            ],
            conv: [
                ['.mrd-nadie-aviso [data-a="take"], .mrd-mando[data-v="dueno"]', 'tourwho'],
                ['.mrd-pasar-b', 'tourdep'],
                ['.mrd-pestana.mrd-nota-p', 'tournote'],
                ['.mrd-plazo', 'tour24'],
                ['.mrd-resolver', 'tourresolve']
            ]
        };
        var tour = null;
        function canTour() {
            return !narrow() && !READONLY && !cfg.inapp;
        }
        function tourSeen(part) {
            return String(FLAGS.tour || '').split(',').indexOf(part) >= 0;
        }
        function startTour(part) {
            if (!canTour()) {
                return;
            }
            closePops();
            var steps = TOURS[part].filter(function (x) {
                return !!box.querySelector(x[0]);
            });
            if (!steps.length) {
                return;
            }
            tour = { part: part, steps: steps, i: 0 };
            drawTour();
        }
        function endTour() {
            var layer = box.querySelector('.mrd-recorrido');
            if (layer) {
                layer.remove();
            }
            if (tour && !tourSeen(tour.part)) {
                setFlag('tour', String(FLAGS.tour || '').split(',').filter(Boolean).concat([tour.part]).join(','));
            }
            tour = null;
        }
        function drawTour() {
            if (!tour) {
                return;
            }
            var step = tour.steps[tour.i], target = box.querySelector(step[0]);
            if (!target || narrow()) {
                endTour();
                return;
            }
            var layer = box.querySelector('.mrd-recorrido');
            if (!layer) {
                layer = document.createElement('div');
                layer.className = 'mrd-recorrido';
                layer.setAttribute('role', 'dialog');
                layer.setAttribute('aria-modal', 'true');
                box.appendChild(layer);
            }
            target.scrollIntoView({ block: 'nearest' });
            var r = target.getBoundingClientRect(), pad = 6, last = tour.i === tour.steps.length - 1;
            layer.setAttribute('aria-label', s(step[1] + 't'));
            layer.innerHTML = '<div class="mrd-recorrido-foco" style="left:' + (r.left - pad) + 'px;top:' + (r.top - pad) + 'px;width:' +
                (r.width + 2 * pad) + 'px;height:' + (r.height + 2 * pad) + 'px"></div><div class="mrd-recorrido-globo">' +
                '<small>' + esc(s('tourstep', { n: tour.i + 1, total: tour.steps.length })) + '</small><b>' + esc(s(step[1] + 't')) + '</b><p>' +
                esc(s(step[1] + 'b')) + '</p><div class="mrd-recorrido-botones"><button type="button" class="mrd-recorrido-saltar" data-a="tourend">' +
                esc(last ? '' : s('tourskip')) + '</button>' + (tour.i ? '<button type="button" class="mrd-btn mrd-chico" data-a="tourprev">' +
                esc(s('tourprev')) + '</button>' : '') + '<button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="' + (last ? 'tourend' : 'tournext') +
                '">' + esc(last ? s('tourdone') : s('tournext')) + '</button></div></div>';
            var bubble = layer.querySelector('.mrd-recorrido-globo'), bw = bubble.offsetWidth, bh = bubble.offsetHeight;
            var vw = root.innerWidth, vh = root.innerHeight, x, y;
            if (r.right + 16 + bw < vw - 8) {
                x = r.right + 16;
                y = r.top + r.height / 2 - bh / 2;
            } else if (r.left - 16 - bw > 8) {
                x = r.left - 16 - bw;
                y = r.top + r.height / 2 - bh / 2;
            } else {
                x = r.left + r.width / 2 - bw / 2;
                y = r.bottom + 16 + bh < vh - 8 ? r.bottom + 16 : r.top - 16 - bh;
            }
            bubble.style.left = Math.max(8, Math.min(vw - bw - 8, x)) + 'px';
            bubble.style.top = Math.max(8, Math.min(vh - bh - 8, y)) + 'px';
            var go = bubble.querySelector('.mrd-primario');
            if (go) {
                go.focus({ preventScroll: true });
            }
        }
        function popHelp() {
            return '<div class="mrd-pop mrd-pop-ayuda" role="dialog" aria-label="' + esc(s('helptitle')) + '"><h5>' + esc(s('helptitle')) + '</h5>' +
                (canTour() ? '<button type="button" class="mrd-ayuda-fila" data-a="tour" data-v="inicio">' + ico('ojo') + '<span><b>' + esc(s('helptour')) +
                    '</b><small>' + esc(s('helptourhelp')) + '</small></span></button>' + (conv(st.open) ? '<button type="button" class="mrd-ayuda-fila" data-a="tour" data-v="conv">' +
                    ico('mensaje') + '<span><b>' + esc(s('helptourconv')) + '</b><small>' + esc(s('helptourconvhelp')) + '</small></span></button>' : '') : '') +
                (cfg.guides || []).map(function (g) {
                    return '<a class="mrd-ayuda-fila" href="' + esc(g.url) + '" target="_blank" rel="noopener">' + ico('libro') + '<span><b>' +
                        esc(s(g.kind === 'coordinate' ? 'guidecoordinate' : 'guideattend')) + '</b><small>' + esc(s('guidepdf')) + '</small></span></a>';
                }).join('') + '</div>';
        }
        // A toast; with «undo», it says what was done and lets it be undone while it shows (the line in the thread stays).
        function toast(text, error, undo) {
            el.toast.innerHTML = ico(error ? 'alerta' : 'check') + '<span>' + esc(text) + '</span>' +
                (undo ? '<button type="button" class="mrd-toast-deshacer" data-a="undo">' + esc(s('undo')) + '</button>' : '');
            el.toast.classList.toggle('mrd-error', !!error);
            el.toast.classList.toggle('mrd-con-boton', !!undo);
            el.toast.classList.add('mrd-ver');
            clearTimeout(toastT);
            toastT = setTimeout(function () {
                el.toast.classList.remove('mrd-ver', 'mrd-con-boton');
            }, error ? 4200 : undo ? 8000 : 2800);
        }
        function hideToast() {
            clearTimeout(toastT);
            el.toast.classList.remove('mrd-ver', 'mrd-con-boton');
        }
        function failToast(e) {
            toast(s('toasterror', (e && e.message) || (e && e.errorcode) || ''), true);
        }
        /* -------- Nothing and not known are not the same: the copy of the conversations may be old, or this page may not
           be getting answers. Then no figure of zero says «nothing», and the band on top says since when. -------- */
        function unknownSince() {
            // In milliseconds; 0 if never read; null if what the page shows is up to date.
            if (st.failed >= 2 && st.lastGood) {
                return st.unchecked === null ? st.lastGood : Math.min(st.unchecked, st.lastGood);
            }
            return st.unchecked;
        }
        // The page got an answer: when, and what the server knows of its copy.
        function asked(r) {
            st.lastGood = nowMs();
            st.unchecked = r && r.unchecked ? (r.uncheckedsince || 0) * 1000 : null;
        }
        function nothingText() {
            return unknownSince() === null ? s('nothingtoreply') : s('unchecked');
        }
        function uncheckedText() {
            var since = unknownSince();
            return since ? s('uncheckedsince', whenPhrase(since)) : s('uncheckednever');
        }
        // What lasts is a row above everything, never over the buttons; what passes soon floats.
        function redInFlow(on) {
            el.red.classList.toggle('mrd-red-larga', on);
            el.red.parentNode.classList.toggle('mrd-sin-comprobar', on);
        }
        function showRed(kind) {
            if (!kind) {
                el.red.hidden = true;
                redInFlow(false);
                return;
            }
            el.red.hidden = false;
            el.red.innerHTML = kind === 'expired'
                ? ico('alerta') + '<span>' + esc(s('sessionexpired')) + '</span><button type="button" data-a="reload">' + esc(s('signin')) + '</button>'
                : ico('alerta') + '<span>' + esc(kind === 'unchecked' ? uncheckedText()
                    : s('offline') + (unknownSince() === null ? '' : ' ' + uncheckedText())) + '</span>';
            redInFlow(kind !== 'expired' && unknownSince() !== null);
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
           Rail: «Mías» and «Sin asignar» always on top, then the person's departments
           --------------------------------------------------------------------------------------------------------------- */
        function brandMark() {
            if (brand.logo) {
                return '<img class="mrd-logo" src="' + esc(brand.logo) + '" alt="">';
            }
            return '<span class="mrd-inicial" aria-hidden="true">' + esc(brand.initial || cap(brand.name || '').charAt(0) || '·') + '</span>';
        }
        /* What waits, seen from afar: a solid badge with the figure, amber, and red when the one that waits longest has
           waited four hours of the team, as its clock. */
        function longestIn(keys) {
            var most = 0;
            allConvs().forEach(function (c) {
                if (c.section === 'reply' && keys.some(function (k) {
                    return inInbox(c, k);
                })) {
                    most = Math.max(most, waitMs(c));
                }
            });
            return most;
        }
        function pendClass(n, keys) {
            if (!n) {
                return '';
            }
            return longestIn(keys) >= 4 * 3600000 ? ' mrd-pend mrd-larga' : ' mrd-pend';
        }
        // shut: a department out of its hours while the person's are open says when it opens, under its moon.
        function trayButton(key, icon, name, lines, n, strong, shut) {
            var on = st.inbox === key, pend = pendClass(n, [key]);
            return '<button type="button" class="mrd-bandeja' + (on ? ' mrd-on' : '') + (strong ? ' mrd-fuerte' : '') + (n ? '' : ' mrd-vacia') +
                (pend ? ' mrd-con-pend' + (pend.indexOf('mrd-larga') >= 0 ? ' mrd-con-larga' : '') : '') +
                '" data-a="inbox" data-v="' + esc(key) + '" title="' + esc(name + ': ' + lines.concat(shut ? [shut] : []).join(' · ')) + '"' +
                (on ? ' aria-current="true"' : '') + '>' + ico(icon) + '<span class="mrd-t">' + esc(name) +
                lines.map(function (l) {
                    return '<small>' + esc(l) + '</small>';
                }).join('') + (shut ? '<small class="mrd-t-cerrado">' + ico('luna') + '<span>' + esc(shut) + '</span></small>' : '') +
                '</span><span class="mrd-n' + (n ? pend : ' mrd-cero') + '">' + n + '</span></button>';
        }
        function teamLines(t) {
            var n = t.reply !== undefined ? t.reply : countLocal('team:' + t.id);
            var u = t.unassigned || 0;
            if (!n) {
                return { n: 0, lines: [nothingText()] };
            }
            var lines = [s('toreply', n)];
            if (u) {
                lines.push(s('unassignedcount', u));
            }
            return { n: n, lines: lines };
        }
        function nightCount() {
            return allConvs().filter(function (c) {
                return c.section === 'reply' && nightArrival(c);
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
            // The name of the screen takes back to its start: what is yours and how each department is doing.
            var h = '<button type="button" class="mrd-marca" data-a="home" title="' + esc(s('hometitle')) + '">' + brandMark() +
                '<span class="mrd-marca-t"><small>' + esc(brand.name || '') + '</small><strong>' + esc(s('apptitle')) + '</strong></span></button>';
            var night = nightCount(), opens = firstOpening(myHours());
            // With departments of their own hours, while the person's are open, each closed one of theirs says when it opens
            // (the others, in the table of the start).
            var shut = function (t) {
                var x = BYTEAM && !opens ? teamState(t.id) : null;
                return x && !x.open ? x.text : '';
            };
            if (night) {
                h += '<div class="mrd-buenos"><b>' + esc(greeting()) + '</b>' + esc(night === 1 ? s('nightarrived1') : s('nightarrived', night)) + '</div>';
            } else if (opens) {
                // Every department of the person is closed: when the first opens.
                h += '<div class="mrd-buenos"><b>' + ico('luna') + esc(s('closednow')) + '</b>' +
                    esc(opens > 0 ? s('opensat', whenPhrase(opens)) : s('closedhelp')) + '</div>';
            }
            h += trayButton('mine', 'yo', s('mine'), [counts.mine ? s('toreply', counts.mine) : nothingText()], counts.mine, true);
            h += trayButton('unassigned', 'nadie', s('unassigned'), unLines, counts.unassigned, true);
            if (mine.length) {
                h += '<div class="mrd-rotulo">' + esc(mine.length === 1 ? s('yourteam') : s('yourteams')) + '</div><div class="mrd-rotulo-sep"></div>';
                h += mine.map(function (t) {
                    var x = teamLines(t);
                    return trayButton('team:' + t.id, teamIcon(t), t.name, x.lines, x.n, false, shut(t));
                }).join('');
            }
            var noteam = allConvs().some(function (c) {
                return !c.teamid && c.section !== 'resolved';
            });
            if (me.seesall && (others.length || noteam)) {
                var opened = st.otros !== null ? st.otros : others.some(function (t) {
                    return st.inbox === 'team:' + t.id;
                }) || st.inbox === 'noteam';
                st.otrosNow = opened;
                var nOthers = others.reduce(function (a, t) {
                    return a + teamLines(t).n;
                }, 0);
                var pendOthers = pendClass(nOthers, others.map(function (t) {
                    return 'team:' + t.id;
                }));
                h += '<button type="button" class="mrd-bandeja mrd-plegar' + (pendOthers ? ' mrd-con-pend' +
                    (pendOthers.indexOf('mrd-larga') >= 0 ? ' mrd-con-larga' : '') : '') + '" data-a="otros" aria-expanded="' + opened + '">' +
                    ico(opened ? 'abajo' : 'der') + '<span class="mrd-t">' + esc(s('otherteams')) + '<small>' +
                    esc(nOthers ? s('toreply', nOthers) : nothingText()) + '</small></span><span class="mrd-n' +
                    (nOthers ? pendOthers : ' mrd-cero') + '">' + nOthers + '</span></button>';
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
                        h += trayButton('noteam', 'bandeja', s('noteam'), [nn ? s('toreply', nn) : nothingText()], nn);
                    }
                    h += '</div>';
                }
            }
            // Between the team: the messages and the board (the team's violet, never the amber of waiting families).
            if (!READONLY) {
                var tosee = boardToSee().length, unread = teamUnread();
                var zoneTray = function (key, icon, name, line, n) {
                    var on = st.inbox === key;
                    return '<button type="button" class="mrd-bandeja' + (on ? ' eq-on' : '') + (n ? ' mrd-con-pend' : ' mrd-vacia') + '" data-a="inbox" data-v="' +
                        key + '" title="' + esc(name + ': ' + line) + '"' + (on ? ' aria-current="true"' : '') + '>' + ico(icon) + '<span class="mrd-t">' +
                        esc(name) + '<small>' + esc(line) + '</small></span><span class="mrd-n eq-n' + (n ? ' mrd-pend' : ' mrd-cero') + '">' + n + '</span></button>';
                };
                h += '<div class="mrd-rail-equipo"><div class="mrd-rotulo eq-rotulo">' + ico('candado') + esc(s('teamzone')) + '</div>' +
                    zoneTray('team', 'eq-sala', s('eqmessages'), unread ? s('eqrailunread', unread) : s('eqrailnone'), unread) +
                    zoneTray('board', 'eq-tablon', s('board'), tosee ? s('boardtosee', tosee) : s('boardnone'), tosee) + '</div>';
            }
            // The history: everything resolved, whenever it was, also what was said on WhatsApp before the chat.
            h += '<div class="mrd-rail-historia">' + trayButton('history', 'libro', s('history'), [s('historyline')], 0) +
                trayButton('agenda', 'estrella', s('agenda'), [s('agendaline')], 0) + '</div>';
            h += legendHtml();
            var sig = me.signature ? '<small title="' + esc(s('signature', me.signature)) + '">' + ico('lapiz') + '<span>' + esc(me.signature) +
                '</span></small>' : '';
            // The foot stays in sight at the bottom of the rail, whatever its height: the notices, the help, the person and
            // the way back to the platform.
            // In the rail of icons their words hide: the title names them.
            h += '<div class="mrd-rail-fijo"><div class="mrd-rail-pie">' + (!READONLY && !cfg.inapp ? '<button type="button" class="mrd-rail-b" data-a="pop" data-v="avisos" title="' +
                esc(s('noticestitle')) + '" aria-expanded="' + (st.pop === 'avisos') + '">' + ico(FLAGS.sound === 'off' ? 'mudo' : 'campana') + '<span>' +
                esc(s('noticestitle')) + '</span></button>' : '') +
                '<button type="button" class="mrd-rail-b" data-a="pop" data-v="ayuda" title="' + esc(s('helptitle')) + '" aria-expanded="' + (st.pop === 'ayuda') + '">' +
                ico('ayuda') +
                '<span>' + esc(s('helptitle')) + '</span></button>' + (cfg.proposal ? '<button type="button" class="mrd-rail-b mrd-rail-mejora" ' +
                'data-marandu-proposal="local_oksigeniamarandu" data-screen="' + esc(s('chatscreen')) + '" title="' + esc(s('proposal')) + '">' + ico('bombilla') + '<span>' +
                esc(s('proposal')) + '</span></button>' : '') +
                (st.pop === 'avisos' ? popNotices() : '') + (st.pop === 'ayuda' ? popHelp() : '') + '</div>';
            h += '<div class="mrd-yo">' + avAgent({ name: me.name, firstname: me.firstname, me: true }, 'mrd-m') +
                '<div class="mrd-yo-t"><b>' + esc(me.name || '') + '</b>' + (READONLY ? '<small><span>' + esc(s('readonlyshort')) + '</span></small>'
                : sig) + '</div>' +
                '<button type="button" class="mrd-yo-mas" data-a="pop" data-v="perfil" aria-label="' + esc(s('backgroundsbutton')) +
                '" aria-expanded="' + (st.pop === 'perfil') + '">' + ico('fondo') + '</button>' + (st.pop === 'perfil' ? popProfile() : '') + '</div>';
            h += '<div class="mrd-oks">' + backHtml() + '<span>' + esc(s('poweredby')) + '</span></div></div>';
            var keep = el.rail.scrollTop;
            el.rail.innerHTML = h;
            el.rail.scrollTop = keep;
            var pp = el.rail.querySelector('.mrd-pop-perfil') || el.rail.querySelector('.mrd-pop-avisos') || el.rail.querySelector('.mrd-pop-ayuda');
            if (pp) {
                var anchor = el.rail.querySelector(st.pop === 'perfil' ? '.mrd-yo-mas' : '.mrd-rail-b[data-v="' + st.pop + '"]');
                var br = anchor.getBoundingClientRect(), rr = el.rail.getBoundingClientRect();
                pp.style.bottom = Math.max(8, root.innerHeight - br.top + 8) + 'px';
                pp.style.left = Math.max(8, rr.left + 10) + 'px';
            }
            renderPie(counts);
            // The two halves of «Equipo» on a phone keep their figures with each poll.
            var seg = el.lista.querySelector('.eq-seg');
            if (seg && inZone() && seg.getAttribute('data-sig') !== zoneSig()) {
                seg.outerHTML = zoneSeg();
            }
        }
        // What each colour and shape marks, folded in one line that leaves room for the trays; it stays as the person left it.
        function legendHtml() {
            var stages = (cfg.stages || []).map(function (x) {
                var col = dataColour(x.color, x.dark);
                return '<span><i class="mrd-pe" style="--e: ' + col.css + '"></i>' + esc(x.label) + '</span>';
            }).join('');
            var open = false;
            try {
                open = root.localStorage.getItem('mrd-chat-leyenda') === 'abierta';
            } catch (e) {
                open = false;
            }
            return '<details class="mrd-leyenda-caja"' + (open ? ' open' : '') + '><summary>' + ico('abajo') + esc(s('legendfold')) + '</summary>' +
                '<div class="mrd-leyenda" aria-label="' + esc(s('legend')) + '"><div class="mrd-leyenda-etapas">' + stages +
                (stages ? '' : '<span><i class="mrd-muestra-anillo"></i>' + esc(s('legendring')) + '</span>') + '</div>' +
                '<div class="mrd-leyenda-formas"><span><i class="mrd-muestra-anillo mrd-discontinuo"></i>' + esc(s('legenddashed')) + '</span>' +
                '<span>' + ico('gafete') + esc(s('kindstaff')) + '</span><span>' + ico('caja') + esc(s('kindsupplier')) + '</span>' +
                '<span>' + ico('birrete') + esc(s('kindalumni')) + '</span><span><i class="mrd-muestra-punto"></i>' + esc(s('unread')) + '</span></div></div></details>';
        }
        /* Back to the platform: the start of the site (the person's dashboard), discreet at the foot of the rail. The server
           says where and how the centre calls it («Volver al aula»); without it, the dashboard and «Volver a la plataforma».
           Not inside the app, which has its own way back. */
        function backHtml() {
            if (cfg.inapp) {
                return '';
            }
            var home = cfg.home || {};
            var url = home.url || (cfg.wwwroot ? cfg.wwwroot + '/my/' : '');
            if (!url) {
                return '';
            }
            var label = home.kind === 'aula' ? s('backtoaula') : s('backtoplatform');
            return '<a class="mrd-volver-sitio" href="' + esc(url) + '" title="' + esc(label) + '">' + ico('izq') + '<span>' + esc(label) + '</span></a>';
        }
        // The phone's bar, also on the start: «Inicio», «Mías», «Sin asignar», «Menú» (the departments, the history, the
        // address book, the notices and the help) and the team.
        function atHome() {
            return st.capa === 'chat' && !st.open && !st.nueva && !inZone();
        }
        // Between the team: the messages and the board, apart from the trays of the families.
        function inZone() {
            return st.inbox === 'team' || st.inbox === 'board';
        }
        function renderPie(counts) {
            // «Menú» stays lit for a department, the history or the address book; not for what is between the team.
            var inMenu = st.inbox !== 'mine' && st.inbox !== 'unassigned' && !inZone();
            var home = atHome();
            box.classList.toggle('mrd-en-inicio', home);
            var tab = function (key, icon, label, n) {
                var on = st.inbox === key && st.capa === 'lista';
                return '<button type="button" class="' + (on ? 'mrd-on' : '') + '" data-a="inbox" data-v="' + key + '"' + (on ? ' aria-current="true"' : '') + '>' +
                    ico(icon) + esc(label) + (n ? '<span class="mrd-n">' + n + '</span>' : '') + '</button>';
            };
            var menuOn = st.capa === 'bandejas' || (inMenu && st.capa === 'lista');
            // «Equipo»: the messages unread and the notices to see, together.
            var zoneOn = inZone() && st.capa === 'lista', nTeam = teamUnread() + boardToSee().length;
            el.pie.innerHTML = '<button type="button" class="' + (home ? 'mrd-on' : '') + '" data-a="home"' + (home ? ' aria-current="true"' : '') + '>' +
                ico('casa') + esc(s('tabhome')) + '</button>' +
                tab('mine', 'yo', s('mine'), counts.mine) + tab('unassigned', 'nadie', s('unassigned'), counts.unassigned) +
                '<button type="button" class="' + (menuOn ? 'mrd-on' : '') + '" data-a="capa" data-v="bandejas"' + (menuOn ? ' aria-current="true"' : '') + '>' +
                ico('menu') + esc(s('tabmenu')) + '</button>' +
                (READONLY ? '' : '<button type="button" class="eq-pie' + (zoneOn ? ' mrd-on' : '') + '" data-a="inbox" data-v="equipo"' +
                    (zoneOn ? ' aria-current="true"' : '') + '>' + ico('eq-sala') + esc(s('tabteam')) + (nTeam ? '<span class="mrd-n">' + nTeam + '</span>' : '') +
                    '</button>');
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
            if (st.inbox === 'history') {
                return s('history');
            }
            if (st.inbox === 'agenda') {
                return s('agenda');
            }
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
            if (st.inbox === 'history') {
                return s('historysub');
            }
            if (st.inbox === 'agenda') {
                return s('agendasub');
            }
            var reply = allConvs().filter(function (c) {
                return c.section === 'reply' && inInbox(c, st.inbox);
            }).length;
            var r = reply ? s('toreply', reply) : nothingText();
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
        // On a phone, «Equipo» is one tab with its two halves, «Mensajes | Tablón»; on a computer each has its own tray.
        function zoneSig() {
            return st.inbox + '|' + teamUnread() + '|' + boardToSee().length;
        }
        function zoneSeg() {
            var half = function (key, icon, label, n) {
                return '<button type="button" data-a="inbox" data-v="' + key + '" aria-pressed="' + (st.inbox === key) + '">' + ico(icon) + esc(label) +
                    (n ? ' <span class="eq-n">' + n + '</span>' : '') + '</button>';
            };
            return '<div class="eq-seg" role="group" aria-label="' + esc(s('teamzone')) + '" data-sig="' + esc(zoneSig()) + '">' +
                half('team', 'eq-sala', s('eqmessages'), teamUnread()) + half('board', 'eq-tablon', s('board'), boardToSee().length) + '</div>';
        }
        function zoneTitle(label) {
            return '<span class="eq-h-ancho">' + esc(label) + '</span><span class="eq-h-movil">' + esc(s('tabteam')) + '</span>';
        }
        function renderListHead() {
            el.lista.classList.toggle('eq-lista', inZone());
            el.lista.classList.toggle('eq-tablon', st.inbox === 'board');
            if (st.inbox === 'board') {
                el.lista.innerHTML = '<div class="mrd-lista-cab"><div class="mrd-titulo"><span class="mrd-marca-movil">' + brandMark() +
                    '</span><h1>' + zoneTitle(s('board')) + '</h1><button type="button" class="mrd-btn mrd-chico mrd-nueva-b eq-btn" data-a="boardnew">' +
                    ico('eq-pin') + '<span>' + esc(s('boardnewbtn')) + '</span></button></div><p class="eq-solo">' + ico('candado') + '<span>' +
                    esc(s('boardsolo')) + '</span></p>' + zoneSeg() + '</div><div class="mrd-lista-cuerpo"></div>';
                return;
            }
            if (st.inbox === 'team') {
                // «Write to…» is the same list turned into the whole team: no form.
                var picking = st.teamPick;
                el.lista.innerHTML = '<div class="mrd-lista-cab"><div class="mrd-titulo"><span class="mrd-marca-movil">' + brandMark() + '</span><h1>' +
                    (picking ? esc(s('eqpicktitle')) : zoneTitle(s('eqmessages'))) + '</h1><button type="button" class="mrd-btn mrd-chico mrd-nueva-b eq-btn" ' +
                    'data-a="eqpick" aria-pressed="' + picking + '">' + ico('eq-mas-p') + '<span>' + esc(s('eqnew')) + '</span></button></div>' +
                    '<p class="eq-solo">' + ico('candado') + '<span>' + esc(picking ? s('eqpicknote') : s('eqsolo')) + '</span></p>' +
                    '<label class="mrd-buscar eq-buscar-equipo">' + ico('buscar') + '<input type="search" data-role="eqbuscar" autocomplete="off" placeholder="' +
                    esc(s('eqsearch')) + '" aria-label="' + esc(s('eqsearch')) + '" value="' + esc(st.teamQ) + '"><button type="button" class="mrd-limpiar" ' +
                    'data-a="eqclear" aria-label="' + esc(s('searchclear')) + '"' + (st.teamQ ? '' : ' hidden') + '>' + ico('cerrar') + '</button></label>' +
                    zoneSeg() + '</div><div class="mrd-lista-cuerpo"></div>';
                return;
            }
            el.lista.innerHTML = '<div class="mrd-lista-cab"><div class="mrd-titulo"><span class="mrd-marca-movil">' + brandMark() +
                '</span><h1></h1>' + (READONLY ? '' : '<button type="button" class="mrd-btn mrd-chico mrd-nueva-b" data-a="new" aria-pressed="' +
                !!st.nueva + '">' + ico('lapiz') + '<span>' + esc(s('newconv')) + '</span></button>') + '</div><p></p><label class="mrd-buscar">' + ico('buscar') +
                '<input type="search" data-role="buscar" autocomplete="off" placeholder="' + esc(s('search')) + '" aria-label="' +
                esc(s('search')) + '" value="' + esc(st.search) + '"><button type="button" class="mrd-limpiar" data-a="clearsearch" aria-label="' +
                esc(s('searchclear')) + '"' + (st.search ? '' : ' hidden') + '>' + ico('cerrar') + '</button></label></div>' +
                '<div class="mrd-lista-cuerpo"></div>';
            updateListTitle();
        }
        function updateListTitle() {
            var h1 = el.lista.querySelector('h1'), p = el.lista.querySelector('.mrd-lista-cab p');
            if (h1 && !inZone()) {
                h1.textContent = inboxTitle();
                p.textContent = inboxSub();
            }
        }
        // The text, escaped, with each word searched marked wherever it is (without minding accents or capitals).
        function markWords(text, query) {
            text = String(text || '');
            var folded = '';
            for (var i = 0; i < text.length; i++) {
                folded += (text[i].normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase() || text[i])[0];
            }
            var words = String(query || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().split(/[^\p{L}\p{N}]+/u)
                .filter(function (w) {
                    return w.length >= 2;
                });
            var on = [];
            words.forEach(function (w) {
                for (var at = folded.indexOf(w); at >= 0; at = folded.indexOf(w, at + w.length)) {
                    for (var k = at; k < at + w.length; k++) {
                        on[k] = true;
                    }
                }
            });
            var h = '', open = false;
            for (var j = 0; j < text.length; j++) {
                if (!!on[j] !== open) {
                    h += open ? '</mark>' : '<mark class="mrd-marca">';
                    open = !open;
                }
                h += esc(text[j]);
            }
            return h + (open ? '</mark>' : '');
        }
        function convItem(c, opts) {
            var ct = c.contact || {};
            var tags = [];
            // A WhatsApp that did not arrive goes first, seen from afar: someone has to call or write another way.
            if (c.failedat) {
                tags.push('<span class="mrd-etq mrd-etq-nollego" title="' + esc(c.failreason || s('notarrived')) + '">' +
                    ico('nollego') + esc(s('notarrived')) + '</span>');
            }
            if (c.assignee) {
                tags.push('<span class="mrd-etq mrd-quien">' + avAgent(c.assignee, 'mrd-s') + esc(isMine(c) ? s('You') : firstName(c.assignee)) + '</span>');
            } else if (c.section !== 'resolved' && c.section !== 'later') {
                tags.push('<span class="mrd-etq mrd-sin">' + esc(s('unassigned')) + '</span>');
            }
            if (opts.withTeam) {
                var t = team(c.teamid);
                tags.push('<span class="mrd-etq mrd-dep">' + ico(teamIcon(t)) + esc(t ? t.name : s('noteam')) + '</span>');
            }
            if (opts.night) {
                tags.push('<span class="mrd-etq mrd-de-noche">' + ico('luna') + esc(s('wrotenight', fmtTime(c.waitingsince * 1000))) + '</span>');
            }
            var right;
            if (opts.later) {
                // The day it comes back is in its line: no other date beside it.
                right = '';
            } else if (c.section === 'reply' && c.waitingsince && !opts.night) {
                var wt = waitText(c);
                right = '<span class="mrd-conv-espera ' + waitTier(c) + '" title="' + esc(s('waits', wt)) + '">' + ico('reloj') + esc(wt) + '</span>';
            } else {
                var at = (opts.night ? c.waitingsince : (c.lastactivity || (c.preview && c.preview.at) || 0)) * 1000;
                right = '<span class="mrd-conv-hora">' + esc(at ? (opts.night ? fmtTime(at) : listTime(at)) : '') + '</span>';
            }
            if (c.section !== 'resolved' && c.section !== 'later') {
                if (!canReply(c)) {
                    tags.push('<span class="mrd-etq mrd-retomar">' + ico('mensaje') + esc(s('retake')) + '</span>');
                } else if (c.replyuntil && c.replyuntil * 1000 - nowMs() < 4 * 3600000) {
                    tags.push('<span class="mrd-etq mrd-espera">' + esc(s('windowleft', leftText(c.replyuntil * 1000 - nowMs()))) + '</span>');
                }
            }
            if (c.scheduled && c.scheduled.length) {
                var stopped = c.scheduled.some(function (x) {
                    return x.status === 'held';
                });
                tags.push('<span class="mrd-etq mrd-etq-prog' + (stopped ? ' mrd-parado' : '') + '">' + ico(stopped ? 'alerta' : 'prog') +
                    esc(stopped ? s('schedlook') : s('schedline', whenPhrase(c.scheduled[0].at * 1000))) + '</span>');
            }
            if (ct.changed && ct.changed.text && (!ct.changed.until || ct.changed.until * 1000 > nowMs())) {
                tags.push('<span class="mrd-etq mrd-cambio">' + ico('check') + esc(ct.changed.text) + '</span>');
            }
            if (c.passed && (isMine(c) || c.passed.toteam)) {
                tags.push('<span class="mrd-etq">' + ico('flecha') + esc(c.passed.toteam ? s('passedtoteam', { by: c.passed.by, team: teamName(c.teamid) })
                    : s('passedtoyou', c.passed.by)) + '</span>');
            }
            var pv = c.preview || {}, txt = esc(pv.text || ''), hit = opts.found && st.search.trim() ? st.hits[c.id] : null;
            if (opts.later && c.remind) {
                // When it comes back, and what for.
                txt = ico('campana') + '<b class="mrd-hallado-cuando">' + esc(s('remline', whenPhrase(c.remind.at * 1000))) + '</b>' +
                    (c.remind.reason ? ' · ' + esc(c.remind.reason) : '');
            } else if (hit) {
                // Where the search found the words: the sentence, and when it was said.
                txt = ico(hit.note ? 'nota' : 'mensaje') + '<b class="mrd-hallado-cuando">' + esc(listTime(hit.at * 1000)) + '</b> · ' +
                    '<span class="mrd-hallado">' + markWords(hit.text, st.search) + '</span>';
            } else if (pv.private) {
                txt = esc(s('previewnote', pv.text || ''));
            } else if (pv.text && !pv.incoming) {
                txt = ico('checks') + (pv.author ? esc((pv.author.me ? s('You') : firstName(pv.author)) + ': ') : '') + txt;
            }
            return '<button type="button" class="mrd-conv' + (st.open === c.id ? ' mrd-on' : '') + (c.unread ? ' mrd-nuevo' : '') +
                (c.failedat ? ' mrd-con-fallo' : '') +
                '" data-a="conv" data-v="' + c.id + '"' + (st.open === c.id ? ' aria-current="true"' : '') + '>' + avContact(ct) +
                '<span class="mrd-conv-cuerpo"><span class="mrd-conv-l1"><span class="mrd-conv-nombre">' +
                (opts.found && st.search.trim() ? markWords(ct.name || fmtPhone(ct.phone), st.search) : esc(ct.name || fmtPhone(ct.phone))) +
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
        // Small letters and no accents, as the server compares names: «martin» finds «Martín».
        function folded(text) {
            text = String(text || '').toLowerCase();
            return text.normalize ? text.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : text;
        }
        function searchMatch(c, q) {
            var ct = c.contact || {};
            var hay = folded([ct.name, ct.line, c.preview && c.preview.text, teamName(c.teamid), digits(ct.phone)].join(' '));
            var d = digits(q);
            return folded(q).split(/\s+/).filter(Boolean).every(function (word) {
                return hay.indexOf(word) >= 0;
            }) || (d.length >= 3 && digits(ct.phone).indexOf(d) >= 0);
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
            if (st.inbox === 'team') {
                body.innerHTML = teamListHtml();
                body.scrollTop = keep;
                return;
            }
            var q = st.search.trim();
            var withTeam = q || st.inbox === 'mine' || st.inbox === 'unassigned';
            var all = allConvs().filter(function (c) {
                return q ? searchMatch(c, q) || !!st.hits[c.id] : inInbox(c, st.inbox);
            });
            if (q) {
                body.innerHTML = searchHtml(all.sort(byActivity), q);
                return;
            }
            if (st.inbox === 'history') {
                body.innerHTML = historyHtml(all.sort(byActivity));
                body.scrollTop = keep;
                return;
            }
            if (st.inbox === 'agenda') {
                body.innerHTML = agendaHtml();
                body.scrollTop = keep;
                return;
            }
            if (st.inbox === 'board') {
                body.innerHTML = boardListHtml();
                body.scrollTop = keep;
                return;
            }
            // On a phone, with no start page, what the board asks of the person goes on top of each tray.
            if (narrow() && !READONLY) {
                h += boardToSee().map(function (b) {
                    return boardCard(b, { movil: true });
                }).join('');
            }
            // On a computer, the notices of the browser are asked for at the head of the list, which is always in sight.
            if (!narrow() && offerDesktop()) {
                h += offerHtml();
            }
            var reply = all.filter(function (c) {
                return c.section === 'reply';
            }).sort(byWait);
            var waiting = all.filter(function (c) {
                return c.section === 'waiting';
            }).sort(byActivity);
            var later = all.filter(function (c) {
                return c.section === 'later' || !!c.remind;
            }).sort(function (a, b) {
                return ((a.remind && a.remind.at) || 4e9) - ((b.remind && b.remind.at) || 4e9);
            });
            var done = all.filter(function (c) {
                return c.section === 'resolved' && later.indexOf(c) < 0;
            }).sort(byActivity);
            var night = reply.filter(nightArrival).sort(function (a, b) {
                return a.waitingsince - b.waitingsince;
            });
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
                h += unknownSince() === null ? '<div class="mrd-grupo-t mrd-suave">' + esc(s('alldone')) + '</div>'
                    : '<div class="mrd-grupo-t mrd-sin-saber">' + ico('alerta') + '<span>' + esc(uncheckedText()) + '</span></div>';
            }
            if (waiting.length) {
                h += '<div class="mrd-grupo-t mrd-suave">' + esc(s('groupwaiting')) + ' <span>' + waiting.length + '</span></div>' +
                    waiting.map(item(false)).join('');
            }
            if (later.length) {
                h += '<div class="mrd-grupo-t mrd-suave">' + ico('campana') + esc(s('grouplater')) + ' <span>' + later.length + '</span></div>' +
                    later.map(function (c) {
                        return convItem(c, { withTeam: withTeam, later: true });
                    }).join('');
            }
            h += resolvedHtml(done, item(false));
            body.innerHTML = h;
            body.scrollTop = keep;
        }
        /* A search says what it found and where it looked (all that the person sees, open and resolved, in names, pupils,
           phones and what was said; or only what is on the page, while the server has not answered), and groups what it
           found by where it is. Nothing found says so, and what to try. */
        function searchHtml(all, q) {
            var scope = q.trim().length < 2 ? 'searchwhereshort' : st.searchFor !== q.trim() || st.searchScope === 'loading' ? 'searchwhereloading'
                : st.searchScope === 'server' ? 'searchwhere' : 'searchwherepage';
            var h = '<div class="mrd-buscado" role="status"><b>' + esc(s(all.length ? 'searchfound' : 'searchnothing', { n: all.length, q: q })) + '</b>' +
                '<p>' + ico('buscar') + esc(s(scope)) + '</p>' + (all.length ? '' : '<p class="mrd-buscado-prueba">' + esc(s('searchtry')) + '</p>') + '</div>';
            var open = all.filter(function (c) {
                return c.section !== 'resolved';
            }), done = all.filter(function (c) {
                return c.section === 'resolved';
            });
            var group = function (key, list) {
                return list.length ? '<div class="mrd-grupo-t">' + esc(s(key)) + ' <span>' + list.length + '</span></div>' + list.map(function (c) {
                    return convItem(c, { withTeam: true, found: true });
                }).join('') : '';
            };
            return h + group('searchopen', open) + group('searchdone', done);
        }
        /* What was resolved: the page keeps what was resolved today; «See the resolved ones» brings the last days too,
           by day, so that what someone resolved yesterday is a tap away and not only a search. */
        function resolvedHtml(done, item) {
            var today = done.filter(resolvedToday).length;
            if (!st.showResolved) {
                return '<button type="button" class="mrd-ver-resueltas" data-a="resolvedlist">' + esc(s('showresolvedall')) +
                    (today ? '<span>' + esc(s('showresolvedtoday', today)) + '</span>' : '') + '</button>';
            }
            var h = '<div class="mrd-grupo-t mrd-suave">' + esc(s('groupresolvedall')) + (done.length ? ' <span>' + done.length + '</span>' : '') + '</div>';
            var lastKey = 0;
            done.forEach(function (c) {
                var ms = (c.lastactivity || 0) * 1000, k = dayKey(ms);
                if (k !== lastKey) {
                    lastKey = k;
                    h += '<div class="mrd-grupo-dia">' + esc(dayLabel(ms)) + '</div>';
                }
                h += item(c);
            });
            if (st.resolved === 'loading') {
                h += '<div class="mrd-resueltas-estado">' + esc(s('resolvedloading')) + '</div>';
            } else if (st.resolved === 'error') {
                h += '<div class="mrd-resueltas-estado">' + esc(s('resolvederror')) +
                    ' <button type="button" class="mrd-ver-resueltas" data-a="resolvedlist">' + esc(s('retry')) + '</button></div>';
            } else if (st.resolvedMore) {
                h += '<button type="button" class="mrd-ver-resueltas" data-a="resolvedmore">' + esc(s('resolvedmore')) + '</button>';
            } else if (st.resolved === 'done') {
                h += '<div class="mrd-resueltas-estado">' + esc(done.length ? s('resolvedend', st.resolvedDays) : s('resolvednone', st.resolvedDays)) +
                    '</div>';
            }
            return h;
        }
        // The days of the history: this week day by day, then «Last week», then each month.
        function weekStart(ms) {
            var key = dayKey(ms), p = zp(ms), back = (p.wd + 6) % 7;
            return shiftKey(key, -back);
        }
        function historyGroup(ms) {
            var thisWeek = weekStart(nowMs()), k = dayKey(ms);
            if (k >= thisWeek) {
                return dayLabel(ms);
            }
            if (k >= shiftKey(thisWeek, -7)) {
                return s('lastweek');
            }
            return cap(fMonth.format(new Date(ms)));
        }
        // The start of a day of the site (its time zone), in seconds.
        function keyTime(key) {
            var utc = Date.UTC(Math.floor(key / 10000), Math.floor(key / 100) % 100 - 1, key % 100);
            return Math.floor((utc - zoneOffset(utc)) / 1000);
        }
        // The filters of the history, as the server takes them: whose, which department, from when.
        function historyRange() {
            var thisWeek = weekStart(nowMs()), p = zp(nowMs());
            switch (st.hf.period) {
                case 'week':
                    return { from: keyTime(thisWeek), before: 0 };
                case 'lastweek':
                    return { from: keyTime(shiftKey(thisWeek, -7)), before: keyTime(thisWeek) };
                case 'month':
                    return { from: keyTime(p.y * 10000 + p.mo * 100 + 1), before: 0 };
                default:
                    // An earlier month, «m:YYYYMM»: from its first day to the first of the next.
                    var m = /^m:(\d{4})(\d{2})$/.exec(st.hf.period || '');
                    if (m) {
                        var y = +m[1], mo = +m[2], ny = mo === 12 ? y + 1 : y, nmo = mo === 12 ? 1 : mo + 1;
                        return { from: keyTime(y * 10000 + mo * 100 + 1), before: keyTime(ny * 10000 + nmo * 100 + 1) };
                    }
                    return { from: 0, before: 0 };
            }
        }
        // The months before this one, back to a year: «octubre», «septiembre»… with the year when it is another.
        function historyMonths() {
            var p = zp(nowMs()), y = p.y, mo = p.mo, list = [];
            for (var i = 0; i < 11; i++) {
                mo--;
                if (!mo) {
                    mo = 12;
                    y--;
                }
                var ms = keyTime(y * 10000 + mo * 100 + 15) * 1000;
                var raw = y === p.y ? new Intl.DateTimeFormat(LANG, { month: 'long', timeZone: TZ }).format(new Date(ms)) : fMonth.format(new Date(ms));
                list.push(['m:' + y + (mo < 10 ? '0' : '') + mo, cap(raw), raw]);
            }
            return list;
        }
        function historyMatch(c) {
            var r = historyRange(), at = c.lastactivity || 0;
            return (!st.hf.mine || (c.assignee && c.assignee.me)) && (!st.hf.team || c.teamid === st.hf.team) &&
                (!r.from || at >= r.from) && (!r.before || at < r.before);
        }
        /* The filters: who and which department in one row, and when, with the months before this one behind «Antes»
           (October, September…), so that the history reaches back as far as it goes. */
        function historyFiltersHtml() {
            var chip = function (a, v, label, on) {
                return '<button type="button" class="mrd-chip' + (on ? ' mrd-on' : '') + '" data-a="' + a + '" data-v="' + v + '" aria-pressed="' + on + '">' +
                    esc(label) + '</button>';
            };
            var periods = [['', 'periodall'], ['week', 'periodweek'], ['lastweek', 'lastweek'], ['month', 'periodmonth']];
            var months = historyMonths(), chosen = months.filter(function (x) {
                return x[0] === st.hf.period;
            })[0];
            var deps = teams().filter(function (t) {
                return me.seesall || t.mine;
            });
            var row = function (label, inner, cls) {
                return '<div class="mrd-hist-fila' + (cls ? ' ' + cls : '') + '"><span class="mrd-hist-rot">' + esc(s(label)) + '</span><div>' + inner + '</div></div>';
            };
            var fold = function (key, open, on, label, inner) {
                return '<details class="mrd-hist-dep"' + (open ? ' open' : '') + '><summary data-a="' + key + '" class="mrd-chip' + (on ? ' mrd-on' : '') +
                    '" aria-expanded="' + !!open + '">' + esc(label) + ico('abajo', 'mrd-flecha') + '</summary><div>' + inner + '</div></details>';
            };
            return '<div class="mrd-hist-filtros"><div class="mrd-hist-dos">' + row('histwho', chip('histmine', '0', s('histall'), !st.hf.mine) +
                chip('histmine', '1', s('histmine'), st.hf.mine)) + (deps.length > 1 ? row('histteamrot', fold('histdep', st.hfDep, !!st.hf.team,
                st.hf.team ? teamName(st.hf.team) : s('histallteams'), chip('histteam', '0', s('histallteams'), !st.hf.team) + deps.map(function (t) {
                    return chip('histteam', String(t.id), t.name, st.hf.team === t.id);
                }).join(''))) : '') + '</div>' + row('histwhen', periods.map(function (x) {
                    return chip('histperiod', x[0], s(x[1]), st.hf.period === x[0]);
                }).join('') + fold('histmonths', st.hfMonths, !!chosen, chosen ? chosen[1] : s('periodbefore'), months.map(function (x) {
                    return chip('histperiod', x[0], x[1], st.hf.period === x[0]);
                }).join(''))) + '</div>';
        }
        // What the filters leave out, said as it is: «Nada de lo que llevaste tú la semana pasada en Secretaría.»
        function historyNoneText() {
            var parts = [s(st.hf.mine ? 'histnonemine' : 'histnoneall')];
            if (st.hf.period) {
                var key = { week: 'histinweek', lastweek: 'histinlastweek', month: 'histinmonth' }[st.hf.period];
                var month = historyMonths().filter(function (x) {
                    return x[0] === st.hf.period;
                })[0];
                parts.push(key ? s(key) : s('histinmonthname', month ? month[2] : ''));
            }
            if (st.hf.team) {
                parts.push(s('histinteam', teamName(st.hf.team)));
            }
            return parts.join(' ') + '.';
        }
        function historyHtml(done) {
            if (st.history === '') {
                loadHistory(0);
            }
            var h = historyFiltersHtml(), last = '';
            done.filter(historyMatch).forEach(function (c) {
                var label = historyGroup((c.lastactivity || 0) * 1000);
                if (label !== last) {
                    last = label;
                    h += '<div class="mrd-grupo-dia">' + esc(label) + '</div>';
                }
                h += convItem(c, { withTeam: true });
            });
            if (st.history === 'loading') {
                h += '<div class="mrd-resueltas-estado">' + esc(s('resolvedloading')) + '</div>';
            } else if (st.history === 'error') {
                h += '<div class="mrd-resueltas-estado">' + esc(s('resolvederror')) +
                    ' <button type="button" class="mrd-ver-resueltas" data-a="historymore">' + esc(s('retry')) + '</button></div>';
            } else if (st.historyMore) {
                h += '<button type="button" class="mrd-ver-resueltas" data-a="historymore">' + esc(s('resolvedmore')) + '</button>';
            } else if (st.history === 'done') {
                var none = !done.filter(historyMatch).length, filtered = st.hf.mine || st.hf.period || st.hf.team;
                h += '<div class="mrd-resueltas-estado">' + esc(!none ? s('historyend') : filtered ? historyNoneText() : s('historynone')) +
                    (none && filtered ? '<div class="mrd-hist-quitar"><button type="button" class="mrd-btn mrd-chico" data-a="histclear">' +
                        esc(s('histclear')) + '</button></div>' : '') +
                    '</div>';
            }
            return h;
        }
        /* The address book: the favourites of the person, then the contacts of the centre by what they are (as anyone of
           the team marks them in their file). Each opens its latest conversation, or «New» with its number. */
        function loadAgenda(star, on) {
            if (st.agenda === 'loading' && !star) {
                return;
            }
            if (!st.agenda || typeof st.agenda !== 'object') {
                st.agenda = 'loading';
            }
            tr.call(M.agenda, star ? { star: star, on: on } : {}).then(function (r) {
                st.agenda = r;
                FAVS = {};
                (r.favourites || []).forEach(function (x) {
                    FAVS[x.contactid] = true;
                });
                if (star) {
                    // A star put on someone with no conversation in the index stays, as the person said.
                    if (on) {
                        FAVS[star] = true;
                    }
                }
                if (st.inbox === 'agenda') {
                    renderListBody();
                }
                return r;
            }).catch(function (e) {
                if (typeof st.agenda !== 'object') {
                    st.agenda = 'error';
                }
                if (st.inbox === 'agenda') {
                    renderListBody();
                }
                if (star) {
                    failToast(e);
                }
            });
        }
        // A contact with the same face and line as in the trays; on the right, when it last spoke, as in the lists.
        function agendaRow() {
            return function (x) {
                var ct = { id: x.contactid, name: x.name, phone: x.phone, kind: x.kind || 'unknown', line: x.line || '', rings: x.rings || [] };
                var rel = relHtml(ct);
                return '<div class="mrd-agenda-fila"><button type="button" class="mrd-agenda-c" data-a="agendaopen" data-v="' + x.contactid + '">' +
                    avContact(ct) + '<span class="mrd-agenda-t"><b>' + esc(x.name || fmtPhone(x.phone)) + '</b>' +
                    (rel ? '<span class="mrd-conv-rel">' + rel + '</span>' : '') +
                    (x.conversation ? '' : '<small class="mrd-agenda-escribir">' + esc(s('agendawrite')) + '</small>') + '</span>' +
                    (x.lastactivity ? '<span class="mrd-conv-hora">' + esc(listTime(x.lastactivity * 1000)) + '</span>' : '') + '</button>' +
                    starButton(x.contactid) + '</div>';
            };
        }
        function starButton(id) {
            if (!id) {
                return '';
            }
            var on = !!FAVS[id];
            return '<button type="button" class="mrd-estrella' + (on ? ' mrd-on' : '') + '" data-a="fav" data-v="' + id + '" aria-pressed="' + on +
                '" aria-label="' + esc(s(on ? 'favoff' : 'favon')) + '" title="' + esc(s(on ? 'favoff' : 'favon')) + '">' + ico('estrella') + '</button>';
        }
        function agendaHtml() {
            if (st.agenda === null) {
                loadAgenda();
            }
            if (st.agenda === null || st.agenda === 'loading') {
                return '<div class="mrd-resueltas-estado">' + esc(s('resolvedloading')) + '</div>';
            }
            if (st.agenda === 'error') {
                return '<div class="mrd-resueltas-estado">' + esc(s('resolvederror')) + ' <button type="button" class="mrd-ver-resueltas" data-a="agendaagain">' +
                    esc(s('retry')) + '</button></div>';
            }
            var a = st.agenda, h = '<div class="mrd-grupo-t">' + ico('estrella') + esc(s('agendafav')) +
                (a.favourites.length ? ' <span>' + a.favourites.length + '</span>' : '') + '</div>';
            h += a.favourites.length ? a.favourites.map(agendaRow(false)).join('') : '<p class="mrd-agenda-vacio">' + esc(s('agendafavnone')) + '</p>';
            a.groups.forEach(function (g) {
                h += '<div class="mrd-grupo-t">' + esc(s('agendakind' + g.kind)) + ' <span>' + g.contacts.length + '</span></div>' +
                    g.contacts.map(agendaRow(true)).join('');
            });
            if (!a.groups.length) {
                h += '<p class="mrd-agenda-vacio">' + esc(s('agendanone')) + '</p>';
            }
            return h;
        }
        function agendaContact(id) {
            var a = st.agenda && typeof st.agenda === 'object' ? st.agenda : { favourites: [], groups: [] };
            var all = a.favourites.slice();
            a.groups.forEach(function (g) {
                all = all.concat(g.contacts);
            });
            return all.filter(function (x) {
                return x.contactid === id;
            })[0] || null;
        }
        function openAnyConv(id) {
            if (conv(id)) {
                openConv(id);
                return;
            }
            // An old one, resolved long ago: the page asks for it.
            tr.call(M.resolved, { id: id }).then(function (r) {
                (r.conversations || []).forEach(mergeConv);
                if (conv(id)) {
                    openConv(id);
                } else {
                    toast(s('toastnotfound'), true);
                }
                return r;
            }).catch(failToast);
        }
        /* -------- The board of the team: notices for a department or the whole team, each person marks «Seen» -------- */
        function boardItems() {
            return st.board && typeof st.board === 'object' ? st.board.items : [];
        }
        function boardToSee() {
            return boardItems().filter(function (b) {
                return !b.mine && !b.seen;
            });
        }
        function boardItem(id) {
            return boardItems().filter(function (b) {
                return b.id === id;
            })[0] || null;
        }
        function loadBoard(args, then) {
            if (READONLY) {
                return Promise.resolve(null);
            }
            if (!args && !st.board) {
                st.board = 'loading';
            }
            return tr.call(M.board, args || {}).then(function (r) {
                st.board = r;
                st.boardRev = r.rev;
                if (then) {
                    then(r);
                }
                // On the board with nothing open on the right: the first notice to see, or the newest.
                if (st.inbox === 'board' && !st.boardNew && !boardItem(st.boardOpen)) {
                    var first = boardToSee()[0] || boardItems()[0];
                    st.boardOpen = first ? first.id : 0;
                }
                renderRail();
                renderPie(inboxCounts());
                if (st.inbox === 'board') {
                    renderListBody();
                    renderBoardPane();
                } else {
                    renderListBody();
                    renderHome();
                }
                return r;
            }).catch(function (e) {
                if (!st.board || typeof st.board !== 'object') {
                    st.board = 'error';
                }
                if (args) {
                    failToast(e);
                }
                if (st.inbox === 'board') {
                    renderListBody();
                }
            });
        }
        function boardFace(p, size) {
            var mine = me.userid ? p.userid === me.userid : p.agentid === me.agentid;
            return avAgent({ agentid: p.agentid, name: p.name, me: mine }, size || 'mrd-m');
        }
        function boardFor(b) {
            return '<span class="eq-para">' + ico(b.all ? 'gente' : 'bandeja') + esc(b.all ? s('boardforall') : s('boardfor', joinList(b.teams))) + '</span>';
        }
        function boardHead(b) {
            return '<div class="eq-aviso-de">' + ico('eq-pin', 'eq-pin') + '<b>' + esc(b.mine ? s('You') : b.author.name) + '</b>' + boardFor(b) +
                esc(whenPhrase(b.at * 1000)) + '</div>';
        }
        function boardCount(b) {
            return '<span class="eq-cuantos">' + esc(s('boardseenby')) + ' <b>' + esc(s('boardnoft', { n: b.seencount, t: b.total })) + '</b></span>';
        }
        function boardMissing(b) {
            var names = b.people.filter(function (p) {
                return !p.seenat;
            }).map(function (p) {
                return p.firstname || p.name;
            });
            return names.length ? s(names.length === 1 ? 'boardmissing' : 'boardmissingmany', joinList(names)) : '';
        }
        function boardBar(b) {
            return '<span class="eq-barra"><i style="width: ' + Math.round(100 * b.seencount / Math.max(1, b.total)) + '%"></i></span>';
        }
        // A notice as a card: in the list, on the start page, on top of a tray on a phone, or open on the right.
        function boardCard(b, o) {
            o = o || {};
            var done = b.seencount >= b.total, pending = !b.mine && !b.seen;
            var seenBtn = '<button type="button" class="mrd-btn' + (o.grande ? '' : ' mrd-chico') + ' eq-visto-b" data-a="boardseen" data-v="' + b.id + '">' +
                ico('ojo') + esc(s('boardseen')) + '</button>';
            var foot;
            if (done) {
                foot = '<span class="eq-visto-ya">' + ico('checks') + esc(s('boardseenbyall')) + '</span>';
            } else if (pending) {
                foot = seenBtn + boardCount(b);
            } else if (b.mine) {
                var miss = boardMissing(b);
                foot = boardCount(b) + (miss ? '<span class="eq-cuantos">· ' + esc(miss) + '</span>' : '');
            } else {
                foot = '<span class="eq-visto-ya">' + ico('check') + esc(s('boardseenbyyou')) + '</span>' + boardCount(b);
            }
            var text = st.boardEdit && st.boardEdit.id === b.id && o.grande
                ? '<div class="eq-escribe" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' + esc(s('boardph')) + '"></div>'
                : '<div class="eq-aviso-txt">' + textHtml(b.text) + '</div>';
            var open = o.grande ? '' : ' data-a="boardopen" data-v="' + b.id + '" role="button" tabindex="0"';
            if (o.inicio) {
                return '<div class="eq-aviso"' + open + '>' + boardHead(b) + text + '<div class="eq-lado">' + seenBtn + boardCount(b) + '</div></div>';
            }
            return '<div class="eq-aviso' + (o.on ? ' mrd-on' : '') + (done ? ' eq-hecho' : '') + (o.movil ? ' eq-movil-aviso' : '') + '"' + open + '>' +
                boardHead(b) + text + '<div class="eq-aviso-pie">' + foot + (o.grande && !done ? boardBar(b) : '') + '</div>' +
                (!o.grande && !o.movil && !done ? '<div class="eq-aviso-pie">' + boardBar(b) + '</div>' : '') + '</div>';
        }
        function boardListHtml() {
            if (st.board === null) {
                loadBoard();
            }
            if (st.board === null || st.board === 'loading') {
                return '<div class="mrd-resueltas-estado">' + esc(s('resolvedloading')) + '</div>';
            }
            if (st.board === 'error') {
                return '<div class="mrd-resueltas-estado">' + esc(s('resolvederror')) + ' <button type="button" class="mrd-ver-resueltas" data-a="boardagain">' +
                    esc(s('retry')) + '</button></div>';
            }
            var items = boardItems(), h = '';
            var group = function (key, list, soft) {
                if (!list.length) {
                    return '';
                }
                return '<div class="mrd-grupo-t' + (soft ? ' mrd-suave' : '') + '">' + esc(s(key)) + ' <span>' + list.length + '</span></div>' +
                    list.map(function (b) {
                        return boardCard(b, { on: st.boardOpen === b.id });
                    }).join('');
            };
            var done = items.filter(function (b) {
                return b.seencount >= b.total;
            });
            var open = items.filter(function (b) {
                return b.seencount < b.total;
            });
            h += group('boardgrouptosee', open.filter(function (b) {
                return !b.mine && !b.seen;
            }));
            h += group('boardgroupmine', open.filter(function (b) {
                return b.mine;
            }));
            h += group('boardgroupseen', open.filter(function (b) {
                return !b.mine && b.seen;
            }), true);
            if (done.length) {
                h += '<div class="mrd-grupo-t mrd-suave">' + esc(s('boardgroupdone')) + '</div>' + (st.boardDone ? done.map(function (b) {
                    return boardCard(b, { on: st.boardOpen === b.id });
                }).join('') : '<button type="button" class="eq-plegado" data-a="boarddone">' + ico('der') +
                    esc(s(done.length === 1 ? 'boarddonefold1' : 'boarddonefold', done.length)) + '</button>');
            }
            return h || '<p class="mrd-agenda-vacio">' + esc(s('boardempty')) + '</p>';
        }
        // The right side of the board: the notice open (who has seen it, and what whoever put it can do), or a new one.
        function renderBoardPane() {
            if (st.inbox !== 'board' || conv(st.open)) {
                return;
            }
            var ed = el.chat.querySelector('.eq-escribe'), typing = ed && document.activeElement === ed;
            var head = '<div class="mrd-chat-cab"><button type="button" class="mrd-volver" data-a="back" data-v="lista" aria-label="' + esc(s('back')) + '">' +
                ico('izq') + '</button><div class="eq-cab-quien"><span class="eq-tile">' + ico('eq-tablon') + '</span><h2>' + esc(s('board')) +
                '<small>' + esc(s('boardsub')) + '</small></h2></div></div>';
            var body;
            if (st.boardNew) {
                body = boardNewHtml();
            } else {
                var b = boardItem(st.boardOpen);
                body = b ? boardOpenHtml(b) : '<div class="mrd-hilo-nada">' + esc(boardItems().length ? s('boardpick') : s('boardempty')) + '</div>';
            }
            el.chat.innerHTML = head + '<div class="eq-tablon-hoja">' + body + '</div>';
            var box2 = el.chat.querySelector('.eq-escribe');
            if (box2) {
                box2.textContent = st.boardNew ? st.boardNew.text : st.boardEdit ? st.boardEdit.text : '';
                if (typing || (st.boardEdit && !st.boardEdit.shown) || (st.boardNew && !st.boardNew.shown)) {
                    caretToEnd(box2);
                    if (st.boardEdit) {
                        st.boardEdit.shown = true;
                    }
                    if (st.boardNew) {
                        st.boardNew.shown = true;
                    }
                }
            }
        }
        function boardOpenHtml(b) {
            var seen = b.people.filter(function (p) {
                return p.seenat;
            }), missing = b.people.filter(function (p) {
                return !p.seenat;
            });
            var people = seen.map(function (p) {
                return '<div><span class="eq-cara">' + boardFace(p) + '<span class="eq-ok">' + ico('check') + '</span></span><span><b>' +
                    esc(p.userid === me.userid ? s('You') : p.name) + '</b><small class="eq-visto-t">' + esc(s('boardseenat', listTime(p.seenat * 1000))) +
                    '</small></span></div>';
            }).join('') + missing.map(function (p) {
                return '<div><span class="eq-cara eq-falta">' + boardFace(p) + '</span><span><b>' + esc(p.userid === me.userid ? s('You') : p.name) +
                    '</b><small>' + esc(s('boardyet')) + '</small></span></div>';
            }).join('');
            var h = boardCard(b, { grande: true }) + '<div class="eq-bloque"><h3>' + esc(s('boardwho')) + '</h3><div class="eq-gente">' + people + '</div>';
            if (b.mine && st.boardEdit && st.boardEdit.id === b.id) {
                h += '<div class="eq-acciones"><button type="button" class="mrd-btn eq-btn" data-a="boardeditsave" data-v="' + b.id + '">' + ico('check') +
                    esc(s('boardsave')) + '</button><button type="button" class="mrd-btn" data-a="boardeditcancel">' + esc(s('boardcancel')) + '</button></div>';
            } else if (b.mine) {
                var names = missing.map(function (p) {
                    return p.firstname || p.name;
                });
                h += '<div class="eq-acciones">' + (missing.length ? '<button type="button" class="mrd-btn eq-btn" data-a="boardremind" data-v="' + b.id + '">' +
                    ico('campana') + esc(missing.length === 1 ? s('boardremindone', names[0]) : s('boardremindall')) + '</button>' : '') +
                    '<button type="button" class="mrd-btn" data-a="boardedit" data-v="' + b.id + '">' + ico('lapiz') + esc(s('boardedit')) + '</button>' +
                    '<button type="button" class="mrd-btn" data-a="boardremove" data-v="' + b.id + '">' + ico('cerrar') + esc(s('boardremove')) + '</button></div>' +
                    (missing.length ? '<p class="eq-cuantos">' + esc(s('boardstays', joinList(names))) + '</p>' : '');
            }
            return h + '</div>';
        }
        // Who a new notice reaches: the people of the departments chosen, or the whole team, and whoever puts it.
        function boardAudience() {
            var n = st.boardNew, seen = {}, list = [];
            var add = function (p) {
                if (p && p.agentid && !seen[p.agentid]) {
                    seen[p.agentid] = true;
                    list.push(p);
                }
            };
            add({ agentid: me.agentid, name: me.name, userid: me.userid });
            if (n.all) {
                (st.extras.people || []).forEach(add);
            } else {
                Object.keys(n.teams).forEach(function (id) {
                    if (n.teams[id]) {
                        peopleIn(+id).forEach(add);
                    }
                });
            }
            return list;
        }
        function boardNewHtml() {
            var n = st.boardNew;
            var chip = function (a, v, icon, label, on) {
                return '<button type="button" class="eq-chip" data-a="' + a + '" data-v="' + v + '" aria-pressed="' + on + '">' + ico(icon) + esc(label) + '</button>';
            };
            var chips = teams().filter(function (t) {
                return !t.internal || me.seesall || t.mine;
            }).map(function (t) {
                return chip('boardteam', t.id, teamIcon(t), t.name, !n.all && !!n.teams[t.id]);
            }).join('') + chip('boardall', '1', 'gente', s('boardallteam'), n.all);
            var who = boardAudience(), any = n.all || Object.keys(n.teams).some(function (id) {
                return n.teams[id];
            });
            var ready = any && n.text.trim() !== '';
            var teamNames = n.all ? [] : teams().filter(function (t) {
                return n.teams[t.id];
            }).map(function (t) {
                return t.name;
            });
            return '<div class="eq-bloque"><h3>' + esc(s('boardforwhom')) + '</h3><div class="eq-chips">' + chips + '</div></div>' +
                '<div class="eq-aviso"><div class="eq-aviso-de">' + ico('eq-pin', 'eq-pin') + '<b>' + esc(s('You')) + '</b>' +
                (any ? boardFor({ all: n.all, teams: teamNames }) : '') + '</div>' +
                '<div class="eq-escribe" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' + esc(s('boardph')) + '" data-ph="' +
                esc(s('boardph')) + '"></div>' +
                (any ? '<div class="eq-aviso-pie"><span class="eq-cuantos">' + esc(s('boardwillsee')) + ' <b>' + esc(s('boardpeople', who.length)) + '</b></span>' +
                    '<span class="eq-caras">' + who.map(function (p) {
                        return boardFace(p, 'mrd-m');
                    }).join('') + '</span></div>' : '') +
                '<div class="eq-acciones"><button type="button" class="mrd-btn eq-btn" data-a="boardpost"' + (ready ? '' : ' disabled') + '>' + ico('eq-pin') +
                esc(s('boardpost')) + '</button><button type="button" class="mrd-btn" data-a="boardnewcancel">' + esc(s('boardcancel')) + '</button>' +
                '<span class="eq-cuantos">' + esc(s('boardpostnote')) + '</span></div></div>';
        }

        /* -------- Messages between the team (chat_team): person to person, and one conversation per department, which
           whoever is in it sees. Four signs say it is not a family, whatever each person chose for those: the team's
           violet, a plain surface, a band that says who sees it and the faces in squares. A conversation with a family
           can go inside, as a card that opens it. Kept by Marandu, not in Chatwoot nor in the messaging of the platform. */
        function teamData() {
            return st.team && typeof st.team === 'object' ? st.team : null;
        }
        function teamList() {
            var d = teamData();
            return d ? d.list || [] : [];
        }
        function teamPeople() {
            var d = teamData();
            return d ? d.people || [] : [];
        }
        function teamUnread() {
            return st.teamUnread || 0;
        }
        function isMe(p) {
            return !!p && (me.userid ? p.userid === me.userid : !!p.agentid && p.agentid === me.agentid);
        }
        // Someone of the team in their rounded square: the picture of their profile, or else their own critter.
        function avTeam(p, size) {
            if (!p) {
                return avAgent(null, size);
            }
            if (isMe(p)) {
                return avAgent({ agentid: me.agentid, name: me.name, firstname: me.firstname, me: true }, size);
            }
            var cls = 'mrd-av mrd-equipo ' + (size || '');
            if (p.picture) {
                return '<span class="' + cls + ' mrd-con-foto" aria-hidden="true"><img src="' + esc(p.picture) + '" alt="" loading="lazy"></span>';
            }
            if (root.BichitoHk) {
                return '<span class="' + cls + ' mrd-con-bicho" aria-hidden="true">' + bicho(p.agentid ? 'equipo-' + p.agentid : 'equipo-u' + p.userid, false) +
                    '</span>';
            }
            return '<span class="' + cls + '" aria-hidden="true">' + esc(cap(firstName(p)).charAt(0)) + '</span>';
        }
        function teamFaces(people, size) {
            return '<span class="eq-caras">' + people.slice(0, 5).map(function (p) {
                return avTeam(p, size || 'mrd-s');
            }).join('') + '</span>';
        }
        // A department in its square tile, with the picture of its name.
        function teamTile(teamid, name) {
            return '<span class="eq-tile" aria-hidden="true">' + ico(teamIcon(team(teamid) || { name: name })) + '</span>';
        }
        function teamCount(n) {
            return n === 1 ? s('eqnpeople1') : s('eqnpeople', n);
        }
        // The departments of someone of the team, by their names.
        function teamDeps(p) {
            var one = p ? teamPeople().filter(function (x) {
                return x.userid === p.userid;
            })[0] : null;
            return ((one && one.teamids) || (p && p.teamids) || []).map(function (id) {
                var t = team(id);
                return t ? t.name : '';
            }).filter(Boolean);
        }
        // Who is in a department: the others by their first names, and «tú» at the end.
        function teamNames(people) {
            var others = people.filter(function (p) {
                return !isMe(p);
            }).map(firstName);
            return joinList(people.length > others.length ? others.concat([s('you')]) : others);
        }
        function loadTeam(args, quiet) {
            if (READONLY) {
                return Promise.resolve(null);
            }
            if (st.team === null) {
                st.team = 'loading';
            }
            var asked = st.room ? st.room.key : '';
            var full = { action: '', id: 0, userid: 0, teamid: 0, text: '', cwid: 0, before: 0 };
            Object.keys(args || {}).forEach(function (k) {
                full[k] = args[k];
            });
            return tr.call(M.team, full).then(function (r) {
                takeTeam(r || {}, asked, full.action === 'older');
                return r;
            }).catch(function (e) {
                if (!teamData()) {
                    st.team = 'error';
                }
                // A message that does not go says so on itself.
                if (full.action && full.action !== 'send' && !quiet) {
                    failToast(e);
                }
                if (st.inbox === 'team') {
                    renderListBody();
                }
                throw e;
            });
        }
        function takeTeam(r, asked, older) {
            st.team = { list: r.list || [], people: r.people || [], teams: r.teams || [], unread: +r.unread || 0 };
            st.teamUnread = st.team.unread;
            var room = st.room;
            if (r.conversation && room && room.key === asked) {
                takeRoom(r.conversation, older);
                if (st.inbox === 'team' && !conv(st.open) && el.chat.classList.contains('eq-sala')) {
                    renderRoomHead();
                    renderRoomComposer();
                    renderRoomThread(older ? 'older' : room.drawn ? '' : 'end');
                    room.drawn = true;
                }
            }
            renderRail();
            if (st.inbox === 'team') {
                renderListBody();
                if (!st.room && !st.teamPick) {
                    openFirstRoom();
                }
            }
        }
        // On a computer something is always open on the right: what waits unread, or the newest.
        function openFirstRoom() {
            if (narrow() || !teamData()) {
                return;
            }
            var first = teamList().filter(function (x) {
                return x.unread;
            })[0] || teamList()[0];
            if (first) {
                openRoom('c' + first.id, { keepLayer: true });
            } else {
                renderTeamPane();
            }
        }
        // What the server says of the conversation open: its people and its last messages (or those before, with «older»).
        function takeRoom(c, older) {
            var r = st.room;
            if (c.id && !r.id) {
                r.id = c.id;
                r.key = 'c' + c.id;
                r.userid = 0;
                r.teamid = 0;
                syncUrl();
            }
            st.rooms[r.key] = r;
            var incoming = c.messages || [];
            if (older || r.olderDone) {
                var seen = {};
                r.msgs = incoming.concat(r.msgs || []).filter(function (m) {
                    if (seen[m.id]) {
                        return false;
                    }
                    seen[m.id] = true;
                    return true;
                }).sort(function (a, b) {
                    return (a.at - b.at) || (a.id - b.id);
                });
                if (older) {
                    r.more = !!c.more;
                    r.olderDone = true;
                }
            } else {
                r.msgs = incoming.slice();
                r.more = !!c.more;
            }
            r.conv = c;
            r.loaded = true;
        }
        // The conversation open, as soon as it is chosen: what the list or the team already says of it.
        function roomItem() {
            var r = st.room;
            if (!r) {
                return null;
            }
            return teamList().filter(function (x) {
                return r.id ? x.id === r.id : r.userid ? x.kind === 'dm' && !!x.other && x.other.userid === r.userid : x.kind === 'team' && x.teamid === r.teamid;
            })[0] || null;
        }
        function roomConv() {
            var r = st.room;
            if (!r) {
                return null;
            }
            if (r.conv) {
                return r.conv;
            }
            var it = roomItem();
            if (it) {
                return it;
            }
            if (r.id) {
                // Opened from a notice before the list came: nothing to say of it until the server answers.
                return { id: r.id, kind: '', teamid: 0, teamname: '', other: null, people: [] };
            }
            if (r.userid) {
                var p = teamPeople().filter(function (x) {
                    return x.userid === r.userid;
                })[0];
                return { id: 0, kind: 'dm', teamid: 0, teamname: '', other: p || { userid: r.userid, name: '', firstname: '' }, people: [] };
            }
            var t = (teamData() ? teamData().teams : []).filter(function (x) {
                return x.id === r.teamid;
            })[0];
            return { id: 0, kind: 'team', teamid: r.teamid, teamname: t ? t.name : teamName(r.teamid), other: null, people: [] };
        }
        // How many see a department's conversation: its people, or what the list of departments says.
        function roomSize(c) {
            var t = (teamData() ? teamData().teams : []).filter(function (x) {
                return x.id === c.teamid;
            })[0];
            return (c.people || []).length || (t ? t.count : 0);
        }
        function roomArgs(action) {
            var r = st.room;
            return { action: action, id: r.id || 0, userid: r.id ? 0 : r.userid || 0, teamid: r.id ? 0 : r.teamid || 0 };
        }
        /* Opens a conversation of the team: «c<id>», or before it exists «u<userid>» (with a person) or «t<teamid>» (a
           department). The page reads it at once if it is in sight. */
        function openRoom(key, opts) {
            opts = opts || {};
            var m = /^([cut])(\d+)$/.exec(String(key || ''));
            if (!m || READONLY) {
                return;
            }
            if (m[1] === 'u') {
                var p = teamPeople().filter(function (x) {
                    return x.userid === +m[2];
                })[0];
                if (p && p.dm) {
                    m = ['', 'c', String(p.dm)];
                }
            } else if (m[1] === 't') {
                var dep = teamList().filter(function (x) {
                    return x.kind === 'team' && x.teamid === +m[2];
                })[0];
                if (dep) {
                    m = ['', 'c', String(dep.id)];
                }
            }
            key = m[1] + m[2];
            if (st.inbox !== 'team') {
                enterZone('team');
                renderRail();
                renderListHead();
            }
            if (!st.room || st.room.key !== key) {
                saveTeamDraft();
                // Each conversation keeps what it had on this page (its messages, one that did not go), shown at once.
                st.room = st.rooms[key] || (st.rooms[key] = { key: key, id: m[1] === 'c' ? +m[2] : 0, userid: m[1] === 'u' ? +m[2] : 0,
                    teamid: m[1] === 't' ? +m[2] : 0, conv: null, msgs: [], pending: [], loaded: false, more: false, older: false, olderDone: false });
                st.room.drawn = false;
                st.pop = null;
            }
            var room = st.room;
            var it = roomItem();
            if (it && it.unread) {
                st.teamUnread = Math.max(0, st.teamUnread - it.unread);
                it.unread = 0;
            }
            renderTeamPane();
            renderListBody();
            renderRail();
            var item = el.lista.querySelector('.mrd-conv.mrd-on, .eq-persona.mrd-on');
            if (item && item.scrollIntoView) {
                item.scrollIntoView({ block: 'nearest' });
            }
            syncUrl();
            if (!opts.keepLayer) {
                setLayer('chat', true);
            }
            // The box takes the keys: now, or as soon as it is there (opened from a notice before the list came).
            var ed = el.chat.querySelector('.eq-editor');
            room.focus = !touchy() && !opts.keepLayer && !ed;
            if (ed && !touchy() && !opts.keepLayer) {
                caretToEnd(ed);
            }
            loadTeam(roomArgs(room.id && !document.hidden ? 'read' : 'open'), true).catch(function (e) {
                // One this person does not see any more (out of the department, from an old notice): the list instead.
                if (st.room === room && !room.loaded) {
                    st.room = null;
                    delete st.rooms[room.key];
                    syncUrl();
                    toast(s('eqgone'), true);
                    renderTeamPane();
                    renderListBody();
                    openFirstRoom();
                } else {
                    failToast(e);
                }
                return null;
            });
        }
        function saveTeamDraft() {
            var ed = el.chat.querySelector('.eq-editor');
            if (ed && st.room) {
                var html = ed.innerHTML === '<br>' ? '' : ed.innerHTML;
                if (html) {
                    st.teamDrafts[st.room.key] = html;
                } else {
                    delete st.teamDrafts[st.room.key];
                }
            }
        }
        // The list: the person's departments first, then the conversations person to person, the newest first.
        function teamListHtml() {
            if (st.team === null) {
                loadTeam().catch(function () {
                    return null;
                });
            }
            if (st.team === null || st.team === 'loading') {
                return '<div class="mrd-resueltas-estado">' + esc(s('loading')) + '</div>';
            }
            if (st.team === 'error') {
                return '<div class="mrd-resueltas-estado">' + esc(s('resolvederror')) + ' <button type="button" class="mrd-ver-resueltas" data-a="eqagain">' +
                    esc(s('retry')) + '</button></div>';
            }
            var q = st.teamQ.trim();
            if (st.teamPick || q) {
                return teamPickHtml(q);
            }
            var deps = teamList().filter(function (x) {
                return x.kind === 'team';
            }), dms = teamList().filter(function (x) {
                return x.kind === 'dm';
            });
            var h = '';
            if (deps.length) {
                h += '<div class="mrd-grupo-t">' + ico('gente') + esc(deps.length === 1 ? s('yourteam') : s('yourteams')) + '</div>' + deps.map(teamItem).join('');
            }
            h += '<div class="mrd-grupo-t">' + ico('yo') + esc(s('eqpeople')) + '</div>' +
                (dms.length ? dms.map(teamItem).join('') : '<p class="mrd-agenda-vacio">' + esc(s('eqnobodyyet')) + '</p>');
            return h + '<button type="button" class="mrd-btn eq-btn-borde eq-mas" data-a="eqpick">' + ico('eq-mas-p') + esc(s('eqwritemore')) + '</button>';
        }
        // What was said last: «Tomás: …» in a department, «Tú: …» what is yours, and the lines of who came or left.
        function teamPreview(x) {
            var l = x.last;
            if (l.kind === 'joined' || l.kind === 'left') {
                return esc(s(l.kind === 'joined' ? 'eqjoined' : 'eqleft', { name: l.firstname || '', team: x.teamname }));
            }
            var who = me.userid && l.userid === me.userid ? s('You') : x.kind === 'team' ? l.firstname : '';
            return esc((who ? who + ': ' : '') + (l.text || ''));
        }
        function teamItem(x) {
            var on = !!st.room && st.room.id === x.id, n = x.unread || 0, last = x.last, at = (last && last.at) || 0, av, name, rel;
            if (x.kind === 'team') {
                var people = x.people || [];
                av = teamTile(x.teamid, x.teamname);
                name = x.teamname;
                rel = people.length ? teamFaces(people) + ' ' + esc(teamCount(people.length)) : '';
            } else {
                av = avTeam(x.other, '');
                name = (x.other && x.other.name) || '';
                rel = esc(joinList(teamDeps(x.other)));
            }
            return '<button type="button" class="mrd-conv' + (on ? ' mrd-on' : '') + (n ? ' eq-sin-leer' : '') + '" data-a="eqopen" data-v="c' + x.id + '"' +
                (on ? ' aria-current="true"' : '') + '>' + av + '<span class="mrd-conv-cuerpo"><span class="mrd-conv-l1"><span class="mrd-conv-nombre">' + esc(name) +
                '</span><span class="mrd-conv-hora">' + esc(at ? listTime(at * 1000) : '') + '</span></span>' +
                (rel ? '<span class="mrd-conv-rel">' + rel + '</span>' : '') + '<span class="mrd-conv-l1"><span class="mrd-conv-txt">' +
                (last ? teamPreview(x) : esc(s('eqlistempty'))) + '</span>' + (n ? '<span class="eq-n" role="img" aria-label="' + esc(s('eqrailunread', n)) + '">' +
                n + '</span>' : '') + '</span></span></button>';
        }
        /* «Write to…»: the whole team, a department first, then each person under their department, with a face and what
           they do; «Ya tienes conversación» where there is one. A touch opens it, empty if it is new: no form. The search of
           the list finds here whoever it names. */
        function teamPickHtml(q) {
            var d = teamData(), words = folded(q).split(/\s+/).filter(Boolean);
            var hit = function (text) {
                var ws = folded(text).split(/[^\p{L}\p{N}]+/u);
                return words.every(function (w) {
                    return ws.some(function (x) {
                        return x.indexOf(w) === 0;
                    });
                });
            };
            var on = function (key) {
                return !!st.room && (st.room.key === key);
            };
            var h = '';
            var deps = d.teams.filter(function (t) {
                return hit(t.name);
            });
            if (deps.length) {
                h += '<div class="mrd-grupo-t">' + ico('gente') + esc(s('eqpickteams')) + '</div>' + deps.map(function (t) {
                    var it = teamList().filter(function (x) {
                        return x.kind === 'team' && x.teamid === t.id;
                    })[0];
                    var key = it ? 'c' + it.id : 't' + t.id, who = it && it.people && it.people.length ? teamNames(it.people) : teamCount(t.count);
                    return '<button type="button" class="eq-persona' + (on(key) ? ' mrd-on' : '') + '" data-a="eqopen" data-v="' + key + '">' + teamTile(t.id, t.name) +
                        '<span class="eq-persona-t"><b>' + esc(t.name) + '</b><small>' + esc(who) + '</small></span>' + (it && it.last ? '<span class="eq-ya">' + esc(s('eqalready')) + '</span>' : '') + '</button>';
                }).join('');
            }
            // Each person once, under the first of their departments: those of whoever looks first, then the others.
            var order = d.teams.map(function (t) {
                return t.id;
            });
            teamPeople().forEach(function (p) {
                (p.teamids || []).forEach(function (id) {
                    if (order.indexOf(id) < 0) {
                        order.push(id);
                    }
                });
            });
            var placed = {}, groups = [];
            order.concat([0]).forEach(function (id) {
                var list = teamPeople().filter(function (p) {
                    var ids = p.teamids || [];
                    return !placed[p.userid] && (id ? ids.indexOf(id) >= 0 : !ids.length) && hit(p.name + ' ' + teamDeps(p).join(' '));
                });
                list.forEach(function (p) {
                    placed[p.userid] = true;
                });
                if (list.length) {
                    groups.push({ name: id ? teamName(id) : s('noteam'), people: list });
                }
            });
            h += groups.map(function (g) {
                return '<div class="mrd-grupo-t">' + esc(g.name) + '</div>' + g.people.map(function (p) {
                    var key = p.dm ? 'c' + p.dm : 'u' + p.userid, deps2 = teamDeps(p);
                    return '<button type="button" class="eq-persona' + (on(key) ? ' mrd-on' : '') + '" data-a="eqopen" data-v="' + key + '">' + avTeam(p, '') +
                        '<span class="eq-persona-t"><b>' + esc(p.name) + '</b>' + (deps2.length ? '<small>' + esc(joinList(deps2)) + '</small>' : '') + '</span>' +
                        (p.dm ? '<span class="eq-ya">' + esc(s('eqalready')) + '</span>' : '') + '</button>';
                }).join('');
            }).join('');
            return h || '<p class="mrd-agenda-vacio">' + esc(s('eqpicknone')) + '</p>';
        }
        // The right side: the conversation open, with its band, its thread and its box; or what to do with none.
        function renderTeamPane() {
            if (st.inbox !== 'team' || conv(st.open) || st.nueva) {
                return;
            }
            var ed = el.chat.querySelector('.eq-editor'), typing = !!ed && document.activeElement === ed;
            saveTeamDraft();
            el.chat.classList.add('eq-sala');
            if (!st.room) {
                var none = teamData() && !teamList().length;
                el.chat.innerHTML = '<div class="mrd-hilo">' + (none ? '<div class="eq-vacio">' + ico('eq-sala', 'eq-vacio-i') + '<b>' + esc(s('eqpaneempty')) +
                    '</b><span>' + esc(s('eqpaneemptyhelp')) + '</span><button type="button" class="mrd-btn eq-btn" data-a="eqpick">' + ico('eq-mas-p') +
                    esc(s('eqnew')) + '</button></div>' : '<div class="mrd-hilo-nada">' + esc(teamData() ? s('eqpanepick') : s('loading')) + '</div>') + '</div>';
                return;
            }
            el.chat.innerHTML = '<div class="mrd-chat-cab"></div><div class="eq-banda" role="note"></div><div class="mrd-hilo" tabindex="-1"></div>' +
                '<div class="mrd-escribir"></div>';
            renderRoomHead();
            renderRoomThread('end');
            renderRoomComposer();
            st.room.drawn = st.room.loaded;
            if (typing) {
                var again = el.chat.querySelector('.eq-editor');
                if (again) {
                    caretToEnd(again);
                }
            }
        }
        function renderRoomHead() {
            var head = el.chat.querySelector('.mrd-chat-cab'), band = el.chat.querySelector('.eq-banda'), c = roomConv();
            if (!head || !band || !c) {
                return;
            }
            band.hidden = !c.kind;
            if (!c.kind) {
                head.innerHTML = '<button type="button" class="mrd-volver" data-a="back" data-v="lista" aria-label="' + esc(s('back')) + '">' + ico('izq') + '</button>';
                return;
            }
            var face, title, sub;
            if (c.kind === 'dm') {
                var o = c.other || {};
                face = avTeam(o, '');
                title = o.name || '';
                sub = esc(joinList(teamDeps(o)));
            } else {
                var people = c.people || [];
                face = teamTile(c.teamid, c.teamname);
                title = c.teamname;
                sub = people.length ? teamFaces(people) + ' ' + esc(teamNames(people)) : '';
            }
            head.innerHTML = '<button type="button" class="mrd-volver" data-a="back" data-v="lista" aria-label="' + esc(s('back')) + '">' + ico('izq') + '</button>' +
                '<div class="eq-cab-quien">' + face + '<h2>' + esc(title) + (sub ? '<small>' + sub + '</small>' : '') + '</h2></div>';
            var n = roomSize(c);
            band.innerHTML = ico('candado') + '<span>' + (c.kind === 'dm'
                ? '<b>' + esc(s('eqbanddmb', firstName(c.other))) + '</b> ' + esc(s('eqbanddm'))
                : '<b>' + esc(s('eqbandteamb', c.teamname)) + '</b>' + (n ? ' ' + esc(n === 1 ? s('eqbandteam1') : s('eqbandteam', n)) : '')) + '</span>';
        }
        // The thread; new messages at the end keep whoever reads further up where they were.
        function renderRoomThread(how) {
            var r = st.room, hilo = el.chat.querySelector('.mrd-hilo');
            if (!r || !hilo || !el.chat.classList.contains('eq-sala')) {
                return;
            }
            if (!r.loaded) {
                hilo.innerHTML = '<div class="mrd-hilo-nada">' + esc(s('loading')) + '</div>';
                return;
            }
            var c = roomConv(), msgs = (r.msgs || []).concat(r.pending || []);
            var fromBottom = hilo.scrollHeight - hilo.scrollTop, top = hilo.scrollTop, end = atBottom(hilo);
            hilo.innerHTML = msgs.length ? roomThreadHtml(c, msgs) : roomEmptyHtml(c);
            if (how === 'older') {
                hilo.scrollTop = hilo.scrollHeight - fromBottom;
            } else if (how === 'end' || end) {
                stickToBottom(hilo);
            } else {
                hilo.scrollTop = top;
            }
        }
        function roomEmptyHtml(c) {
            if (c.kind === 'dm') {
                var name = firstName(c.other);
                return '<div class="eq-vacio">' + avTeam(c.other, '') + '<b>' + esc(s('eqemptydm', name)) + '</b><span>' + esc(s('eqemptydmhelp', name)) + '</span></div>';
            }
            return '<div class="eq-vacio">' + teamTile(c.teamid, c.teamname) + '<b>' + esc(s('eqemptyteam', c.teamname)) + '</b><span>' +
                esc(s('eqemptyteamhelp', c.teamname)) + '</span></div>';
        }
        function sameRun(a, b) {
            var talk = function (m) {
                return !!m && (m.kind === 'text' || m.kind === 'conv') && !!m.author;
            };
            return talk(a) && talk(b) && a.author.userid === b.author.userid && dayKey(a.at * 1000) === dayKey(b.at * 1000) && Math.abs(b.at - a.at) < 600;
        }
        function roomThreadHtml(c, msgs) {
            var r = st.room, h = '', last = 0;
            if (r.more) {
                h += '<button type="button" class="mrd-mas-antiguos" data-a="eqolder">' + esc(r.older ? s('loading') : s('olderload')) + '</button>';
            }
            msgs.forEach(function (m, i) {
                var k = dayKey((m.at || 0) * 1000);
                if (k !== last) {
                    h += '<span class="mrd-dia" data-day="' + k + '">' + esc(dayLabel((m.at || 0) * 1000)) + '</span>';
                    last = k;
                }
                h += roomMsgHtml(c, m, msgs[i - 1], msgs[i + 1]);
            });
            return h;
        }
        // A message: yours on the right, in the team's bubble; in a department, each one's face and name beside theirs.
        function roomMsgHtml(c, m, prev, next) {
            var time = m.at ? fmtTime(m.at * 1000) : '', id = ' data-id="' + esc(m.id) + '"';
            if (m.kind === 'joined' || m.kind === 'left') {
                var who = m.author || {};
                var line = m.kind === 'joined' && isMe(who) ? s('eqjoinedyou', c.teamname) : s(m.kind === 'joined' ? 'eqjoined' : 'eqleft', { name: who.name || '',
                    team: c.teamname });
                return '<div class="eq-sistema"' + id + '>' + ico('yo') + '<span>' + esc(line) + (time ? ' · ' + esc(time) : '') + '</span></div>';
            }
            var mine = m.mine || m.pending || m.failed;
            var body = (m.kind === 'conv' && m.conv ? cardHtml(m.conv, true) : '') + (m.text ? '<p>' + textHtml(m.text, false) + '</p>' : '');
            var foot = '<div class="mrd-msg-pie">' + esc(time) + (mine ? (m.pending ? ico('reloj') : m.failed ? ico('alerta', 'mrd-mal') : ico('check')) : '') +
                '</div>';
            if (mine) {
                return '<div class="mrd-msg eq-yo' + (m.pending ? ' mrd-pendiente' : '') + (m.failed ? ' mrd-fallo' : '') + '"' + id + '>' + body + foot +
                    (m.failed ? '<div class="mrd-fallo-l">' + esc(s('notsent')) + '<button type="button" data-a="eqretry" data-v="' + esc(m.id) + '">' +
                    esc(s('retry')) + '</button></div>' : '') + '</div>';
            }
            if (c.kind === 'team') {
                var first = !sameRun(prev, m), lastOne = !sameRun(m, next);
                return '<div class="eq-fila' + (first ? '' : ' eq-sigue') + '"' + id + '>' + (lastOne ? avTeam(m.author, 'mrd-m')
                    : '<span class="eq-cara-hueco" aria-hidden="true"></span>') + '<div class="mrd-msg eq-otro">' + (first ? '<div class="mrd-de">' +
                    esc((m.author && m.author.name) || '') + '</div>' : '') + body + foot + '</div></div>';
            }
            return '<div class="mrd-msg eq-otro"' + id + '>' + body + foot + '</div>';
        }
        /* A conversation with a family passed between the team: a card with the family in its circle, what the card says
           of it and «Abrir». In the box, before it goes, the same card, still. */
        function cardOf(id) {
            var c = conv(id);
            if (!c) {
                return null;
            }
            var ct = c.contact || {};
            return { cwid: id, name: ct.name || fmtPhone(ct.phone), status: c.status, teamname: c.teamid ? teamName(c.teamid) : '' };
        }
        function cardHtml(card, live) {
            var c = conv(card.cwid), ct = c && c.contact ? c.contact : { name: card.name || '', phone: card.phone || '', kind: card.kind || 'unknown',
                line: card.line || '', rings: card.rings || [] };
            var status = card.status === 'resolved' ? s('eqcardresolved') : card.status === 'snoozed' ? s('eqcardlater') : card.status ? s('eqcardopen') : '';
            var inner = avContact(ct) + '<span class="eq-ref-t"><b>' + esc(card.name || ct.name || fmtPhone(ct.phone)) + '</b><small>' +
                esc([ct.line, card.teamname, status].filter(Boolean).join(' · ')) + '</small></span>';
            return live ? '<button type="button" class="eq-ref" data-a="eqconv" data-v="' + esc(card.cwid) + '">' + inner + '<span class="mrd-abrir">' + esc(s('open')) +
                '</span></button>' : '<div class="eq-ref">' + inner + '</div>';
        }
        // The box: no tabs, no approved messages, no 24 hours and no signature; over it, who will read it.
        function renderRoomComposer(force) {
            var r = st.room, box2 = el.chat.querySelector('.mrd-escribir'), c = roomConv();
            if (!r || !box2 || !c) {
                return;
            }
            var who = '', to = '', n = roomSize(c);
            if (c.kind === 'dm') {
                who = s('eqseesdm', firstName(c.other));
                to = firstName(c.other);
            } else if (c.kind) {
                who = n === 1 ? s('eqseesteam1') : s('eqseesteam', { n: n, team: c.teamname });
                to = c.teamname;
            }
            var card = st.teamCards[r.key] ? cardOf(st.teamCards[r.key]) : null;
            // Drawn again only when what it says changes: whoever is typing keeps the box, the caret and a menu open.
            var sig = [r.key, who, to, card ? card.cwid : 0].join('|');
            if (!force && box2.getAttribute('data-sig') === sig) {
                return;
            }
            var ed0 = box2.querySelector('.eq-editor'), typing = !!ed0 && document.activeElement === ed0;
            saveTeamDraft();
            box2.setAttribute('data-sig', sig);
            if (!c.kind) {
                box2.innerHTML = '';
                return;
            }
            box2.innerHTML ='<div class="mrd-escribir-cab"><span class="eq-privada">' + ico('candado') + esc(who) + '</span></div>' +
                '<div class="mrd-caja">' + (card ? '<div class="eq-adjunta">' + cardHtml(card, false) + '<button type="button" class="eq-adjunta-x" data-a="eqcardoff" ' +
                'aria-label="' + esc(s('eqcardoff')) + '" title="' + esc(s('eqcardoff')) + '">' + ico('cerrar') + '</button></div>' : '') +
                '<div class="mrd-editor eq-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' + esc(s('eqwriteto', to)) + '" data-vacio="' +
                esc(s('eqwriteto', to)) + '">' + (st.teamDrafts[r.key] || '') + '</div><div class="mrd-herr"><button type="button" class="mrd-herr-b' +
                (st.pop === 'eqpasar' ? ' mrd-on' : '') + '" data-a="pop" data-v="eqpasar" aria-expanded="' + (st.pop === 'eqpasar') + '">' + ico('mensaje') +
                '<span class="mrd-t">' + esc(s('eqpass')) + '</span></button><button type="button" class="mrd-btn eq-btn" data-a="eqsend">' + ico('enviar') +
                esc(s('send')) + '</button></div></div>' + (st.pop === 'eqpasar' ? popPassConv() : '');
            var busyElsewhere = document.activeElement && document.activeElement !== document.body && !box2.contains(document.activeElement);
            if (typing || (r.focus && !busyElsewhere)) {
                caretToEnd(box2.querySelector('.eq-editor'));
            }
            r.focus = false;
        }
        /* «Share a conversation»: the conversations with families, as the search of the list finds them (names, pupils,
           phones and what was said, also the resolved ones); with nothing written, those open now. */
        function popPassConv() {
            return '<div class="mrd-pop mrd-pop-escribir mrd-pop-eqpasar" role="dialog" aria-label="' + esc(s('eqpass')) + '"><h5>' + esc(s('eqpass')) +
                ' <span>' + esc(s('eqpasshelp')) + '</span></h5><label class="mrd-buscar">' + ico('buscar') + '<input type="search" data-role="eqpasarbuscar" ' +
                'autocomplete="off" placeholder="' + esc(s('eqpasssearch')) + '" aria-label="' + esc(s('eqpasssearch')) + '" value="' + esc(st.passQ) + '"></label>' +
                '<div class="mrd-pop-lista" role="listbox">' + passRows() + '</div></div>';
        }
        function passRows() {
            var q = st.passQ.trim();
            var list = allConvs().filter(function (c) {
                return q ? searchMatch(c, q) || (st.passFor === q && !!st.passFound[c.id]) : c.section !== 'resolved';
            }).sort(function (a, b) {
                return ((a.section === 'resolved') - (b.section === 'resolved')) || byActivity(a, b);
            }).slice(0, 40);
            var h = q ? '' : '<p class="mrd-pop-sub">' + esc(s('eqpassrecent')) + '</p>';
            if (!list.length) {
                return h + '<p class="mrd-pop-nada">' + esc(q && st.passFor !== q ? s('loading') : s('eqpassnone')) + '</p>';
            }
            return h + list.map(function (c, i) {
                var ct = c.contact || {}, name = ct.name || fmtPhone(ct.phone);
                var status = c.section === 'resolved' ? s('eqcardresolved') : c.section === 'later' ? s('eqcardlater') : s('eqcardopen');
                return '<button type="button" role="option" class="mrd-pop-fila' + (i === 0 && q ? ' mrd-on' : '') + '" data-a="eqcard" data-v="' + c.id +
                    '" aria-selected="' + (i === 0 && !!q) + '">' + avContact(ct, 'mrd-m') + '<span><b>' + (q ? markWords(name, q) : esc(name)) + '</b><small>' +
                    esc([ct.line, c.teamid ? teamName(c.teamid) : '', status].filter(Boolean).join(' · ')) + '</small></span></button>';
            }).join('');
        }
        var passT = 0;
        function passSearch(text) {
            st.passQ = text;
            var asked = text.trim(), list = el.chat.querySelector('.mrd-pop-eqpasar .mrd-pop-lista');
            if (list) {
                list.innerHTML = passRows();
            }
            clearTimeout(passT);
            if (asked.length < 2) {
                st.passFor = asked;
                return;
            }
            passT = setTimeout(function () {
                tr.call(M.resolved, { query: asked }).then(function (r) {
                    (r.conversations || []).forEach(mergeConv);
                    st.passFound = {};
                    (r.conversations || []).forEach(function (c) {
                        st.passFound[c.id] = true;
                    });
                    (r.hits || []).forEach(function (hit) {
                        st.passFound[hit.conversation] = true;
                    });
                    st.passFor = asked;
                    var now2 = el.chat.querySelector('.mrd-pop-eqpasar .mrd-pop-lista');
                    if (now2 && st.passQ.trim() === asked) {
                        now2.innerHTML = passRows();
                    }
                    return r;
                }).catch(function () {
                    // What is on the page is still searched.
                    st.passFor = asked;
                    var now3 = el.chat.querySelector('.mrd-pop-eqpasar .mrd-pop-lista');
                    if (now3) {
                        now3.innerHTML = passRows();
                    }
                });
            }, 300);
        }
        function chooseCard(id) {
            var r = st.room;
            if (!r || !conv(id)) {
                return;
            }
            saveTeamDraft();
            st.teamCards[r.key] = id;
            st.pop = null;
            st.passQ = '';
            renderRoomComposer();
            var ed = el.chat.querySelector('.eq-editor');
            if (ed) {
                caretToEnd(ed);
            }
        }
        function teamSend() {
            var r = st.room, ed = el.chat.querySelector('.eq-editor');
            if (!r || !ed) {
                return;
            }
            var text = editorText(ed, false), cwid = st.teamCards[r.key] || 0;
            if (!text && !cwid) {
                ed.focus();
                return;
            }
            if (text.length > 4000) {
                toast(s('eqtoolong'), true);
                return;
            }
            var m = { id: 'tmp' + (++seq), kind: cwid ? 'conv' : 'text', text: text, at: Math.floor(nowMs() / 1000), mine: true, pending: true,
                author: { userid: me.userid, agentid: me.agentid, name: me.name, firstname: me.firstname }, cwid: cwid, conv: cwid ? cardOf(cwid) : null };
            ed.innerHTML = '';
            delete st.teamDrafts[r.key];
            delete st.teamCards[r.key];
            var att = el.chat.querySelector('.eq-adjunta');
            if (att) {
                att.remove();
            }
            r.pending = (r.pending || []).concat([m]);
            renderRoomThread('end');
            ed.focus();
            teamDeliver(r, m);
        }
        function teamDeliver(r, m) {
            // The conversation it was written in, although another one may be open by the time it goes.
            var args = { action: 'send', id: r.id || 0, userid: r.id ? 0 : r.userid || 0, teamid: r.id ? 0 : r.teamid || 0, text: m.text, cwid: m.cwid || 0 };
            var wasPicking = st.teamPick;
            return loadTeam(args).then(function (res) {
                r.pending = (r.pending || []).filter(function (x) {
                    return x !== m;
                });
                if (st.room === r) {
                    renderRoomThread('end');
                }
                // The first message to someone new: back to the list, where the conversation now is.
                if (wasPicking && st.teamPick && st.inbox === 'team') {
                    st.teamPick = false;
                    st.teamQ = '';
                    renderListHead();
                    renderListBody();
                }
                pollSoon();
                return res;
            }).catch(function () {
                m.pending = false;
                m.failed = true;
                if (st.room === r) {
                    renderRoomThread();
                }
                return null;
            });
        }
        function teamRetry(id) {
            var r = st.room, m = r ? (r.pending || []).filter(function (x) {
                return String(x.id) === String(id);
            })[0] : null;
            if (!m) {
                return;
            }
            m.failed = false;
            m.pending = true;
            m.at = Math.floor(nowMs() / 1000);
            r.pending = r.pending.filter(function (x) {
                return x !== m;
            }).concat([m]);
            renderRoomThread('end');
            teamDeliver(r, m);
        }
        function teamOlder() {
            var r = st.room, first = r && (r.msgs || [])[0];
            if (!r || !r.id || r.older || !r.more || !first) {
                return;
            }
            r.older = true;
            var b = el.chat.querySelector('[data-a="eqolder"]');
            if (b) {
                b.textContent = s('loading');
            }
            var args = roomArgs('older');
            args.before = first.id;
            loadTeam(args).then(function (res) {
                r.older = false;
                return res;
            }).catch(function () {
                r.older = false;
                return null;
            });
        }
        // The family's conversation of a card, with the permissions of always: in its own tray, out of the team's.
        function openFamily(id) {
            var go = function () {
                if (conv(id)) {
                    openConv(id);
                } else {
                    toast(s('eqcardnoaccess'), true);
                }
            };
            if (conv(id)) {
                go();
                return;
            }
            tr.call(M.resolved, { id: id }).then(function (r) {
                (r.conversations || []).forEach(mergeConv);
                go();
                return r;
            }).catch(function () {
                toast(s('eqcardnoaccess'), true);
                return null;
            });
        }
        // The poll says how many wait unread and whether anything moved: then the list, and what is open, are asked again.
        function teamPolled(t) {
            var open = st.inbox === 'team' && st.room && st.room.id && !conv(st.open);
            var changed = t.rev !== st.teamRev;
            // What arrives in the conversation open is read in a moment: the count waits for that, without a jump.
            if (!(changed && open && !document.hidden)) {
                st.teamUnread = +t.unread || 0;
            }
            // While the list is on its way, the change waits for the next poll.
            if (!changed || READONLY || st.team === 'loading') {
                return;
            }
            st.teamRev = t.rev;
            if (open && !document.hidden) {
                loadTeam(roomArgs('read')).catch(function () {
                    return null;
                });
            } else if (st.team !== null) {
                loadTeam().catch(function () {
                    return null;
                });
            }
        }
        // Into the messages or the board: no conversation with a family stays open beside them.
        function enterZone(key) {
            if (!inZone()) {
                st.lastTray = st.inbox;
            }
            st.inbox = key;
            st.teamHalf = key;
            st.open = 0;
            st.msgs = [];
            st.more = false;
            st.nueva = null;
            st.search = '';
            st.showResolved = false;
            if (key !== 'board') {
                st.boardNew = null;
                st.boardEdit = null;
            }
            if (key !== 'team') {
                st.teamPick = false;
                st.teamQ = '';
            }
            renderFicha();
        }
        // «Equipo» on a phone opens the half with something new; with both or neither, the last one.
        function zoneHalf() {
            var u = teamUnread(), b = boardToSee().length;
            if (u && !b) {
                return 'team';
            }
            if (b && !u) {
                return 'board';
            }
            return st.teamHalf || 'team';
        }
        function loadHistory(before) {
            if (st.history === 'loading') {
                return;
            }
            st.history = 'loading';
            var range = historyRange();
            tr.call(M.resolved, { all: true, before: before || range.before, mine: st.hf.mine, team: st.hf.team, from: range.from }).then(function (r) {
                (r.conversations || []).forEach(mergeConv);
                st.history = 'done';
                st.historyMore = !!r.more;
                if (st.inbox === 'history') {
                    renderListBody();
                }
                return r;
            }).catch(function () {
                st.history = 'error';
                if (st.inbox === 'history') {
                    renderListBody();
                }
            });
        }
        function loadResolved(before) {
            if (st.resolved === 'loading') {
                return;
            }
            st.resolved = 'loading';
            renderListBody();
            tr.call(M.resolved, { before: before || 0 }).then(function (r) {
                (r.conversations || []).forEach(mergeConv);
                st.resolved = 'done';
                st.resolvedMore = !!r.more;
                st.resolvedDays = r.days || st.resolvedDays;
                renderListBody();
                return r;
            }).catch(function () {
                st.resolved = 'error';
                renderListBody();
            });
        }
        function oldestHistory() {
            var min = 0;
            allConvs().forEach(function (c) {
                if (c.section === 'resolved' && historyMatch(c) && c.lastactivity && (!min || c.lastactivity < min)) {
                    min = c.lastactivity;
                }
            });
            return min;
        }
        function oldestResolved() {
            var min = 0;
            allConvs().forEach(function (c) {
                if (c.section === 'resolved' && c.lastactivity && (!min || c.lastactivity < min)) {
                    min = c.lastactivity;
                }
            });
            return min;
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
            el.chat.classList.toggle('eq-sala', !st.nueva && !c && inZone());
            if (st.nueva) {
                renderNew();
                return;
            }
            if (!c && st.inbox === 'board') {
                renderBoardPane();
                return;
            }
            if (!c && st.inbox === 'team') {
                renderTeamPane();
                return;
            }
            if (!c) {
                el.chat.innerHTML = st.ready ? '<div class="mrd-hilo mrd-inicio-hilo">' + homeHtml() + '</div>'
                    : '<div class="mrd-hilo"><div class="mrd-hilo-nada">' + esc(s('loading')) + '</div></div>';
                st.composerSig = '';
                renderPie(inboxCounts());
                return;
            }
            el.chat.innerHTML = '<div class="mrd-chat-cab"></div><div class="mrd-bandas"></div>' +
                '<div class="mrd-hilo" tabindex="-1"></div>' +
                '<div class="mrd-programados"></div><div class="mrd-escribir"></div>';
            renderHead();
            renderBands();
            renderThread();
            st.schedSig = '';
            renderScheduled();
            st.composerSig = '';
            renderComposer();
        }
        /* ---------------------------------------------------------------------------------------------------------------
           What WhatsApp costs this month and the sends to many that wait for approval (its mock-up): only for the
           people the site chose, and for whoever administers, who chooses them. From chat_spend, every five minutes.
           --------------------------------------------------------------------------------------------------------------- */
        function loadSpend(args) {
            if (READONLY || !(me.spend || me.canchoose)) {
                return Promise.resolve(null);
            }
            return tr.call(M.spend, args || {}).then(function (r) {
                st.spend = r;
                renderHome();
                return r;
            }).catch(function (e) {
                toast((e && e.message) || s('gserror'), true);
            });
        }
        function gsData() {
            return st.spend && typeof st.spend === 'object' ? st.spend : null;
        }
        // Nothing to approve and nothing spent: the month goes up, short, under the alert.
        function gsQuiet() {
            var d = gsData();
            return !!d && !d.pending.length && !d.month.cost;
        }
        function gsMoney(x, cur) {
            try {
                return new Intl.NumberFormat(LANG, { style: 'currency', currency: cur || 'EUR', minimumFractionDigits: 2,
                    maximumFractionDigits: 2 }).format(x);
            } catch (e) {
                return Number(x).toFixed(2) + ' ' + (cur || '');
            }
        }
        function gsAgo(ms) {
            var min = Math.round((nowMs() - ms) / 60000);
            if (min < 1) {
                return s('gsnow');
            }
            if (min < 60) {
                return s(min === 1 ? 'gsago1' : 'gsago', min);
            }
            return whenPhrase(ms);
        }
        function gsNames(list, joiner) {
            var names = list.slice();
            if (names.length < 2) {
                return names.join('');
            }
            var last = names.pop();
            return s(joiner, { list: names.join(', '), last: last });
        }
        function gsPerson(userid) {
            return (st.extras.people || []).filter(function (p) {
                return userid && p.userid === userid;
            })[0] || null;
        }
        function gsFace(userid, name) {
            var p = gsPerson(userid);
            return avAgent(p ? { agentid: p.agentid, name: p.name } : { name: name || '' }, 'mrd-s');
        }
        var GS_CAT = { utility: 'util', marketing: 'mkt', authentication: 'aut' };
        // Sends approved after Meta was last read: already decided, not yet in Meta's figures.
        function gsCommitted() {
            var d = gsData(), m = d.month, sum = 0, today = true;
            d.decided.forEach(function (b) {
                if (b.status === 'queued' && b.only !== 'telegram' && b.cost > 0 && b.decided > (m.time || 0)) {
                    sum += b.cost;
                    today = today && dayKey(b.decided * 1000) === dayKey(nowMs());
                }
            });
            return { sum: sum, today: today };
        }
        function gsByMe(b) {
            return b.deciderid ? +b.deciderid === +me.userid : !!(b.decidedby && me.name && b.decidedby === me.name);
        }
        function gsAsker(b) {
            var p = gsPerson(b.askerid);
            return (p && p.firstname) || String(b.askedby || '').split(' ')[0];
        }
        function gsWhy(b) {
            var d = gsData(), m = d.month;
            if (b.why === 'budget') {
                // The same figure as the bar: spent already, or spent with this send.
                return m.cost >= m.budget ? s('gswhybudget', m.name) :
                    s('gswhyover', { month: m.name, total: gsMoney(m.cost + b.cost, m.currency), budget: gsMoney(m.budget, m.currency) });
            }
            // The whole sum: what has gone this month, and where this send leaves it.
            var spent = m.cost + gsCommitted().sum, a = { spent: gsMoney(spent, m.currency), total: gsMoney(spent + b.cost, m.currency),
                budget: gsMoney(m.budget, m.currency) };
            return s('gswhythreshold', gsMoney(d.threshold, b.currency)) + ' ' + s(m.budget > 0 ? 'gswhyspent' : 'gswhyspentnobudget', a);
        }
        function gsSend(b) {
            var d = gsData(), open = st.gsOpen === b.id, rejecting = st.gsReject && st.gsReject.id === b.id;
            var quote = b.preview || '';
            var short = quote.length > 120 && !open ? quote.slice(0, 117).replace(/\s+\S*$/, '') + '…' : quote;
            var cat = GS_CAT[b.category] || 'mkt', asker = gsAsker(b);
            // Who receives it and who does not, one channel per row: what it costs and what each one costs.
            var h = '<div class="gs-hoja gs-envio">' +
                '<p class="gs-quien">' + gsFace(b.askerid, b.askedby) + '<span>' + esc(s('gsasked')) + ' <b>' + esc(b.askedby) + '</b>' +
                (b.plugin ? ' · ' + esc(b.plugin) : '') + ' · ' + esc(gsAgo(b.asked * 1000)) + '</span></p>' +
                '<h4>' + esc(b.label) + '</h4>' +
                (quote ? '<p class="gs-cita">«' + esc(short) + '»' + (quote.length > 120 ? ' <button type="button" class="mrd-enlace" data-a="gsver" data-v="' +
                    b.id + '">' + esc(s(open ? 'gsless' : 'gsseemsg')) + '</button>' : '') + '</p>' : '') +
                '<div class="gs-canales">' +
                (b.whatsapp || !b.telegram ? '<div class="gs-canal"><b>' + ico('gs-wa') + esc(s('gsbywa', b.whatsapp)) + '</b><span class="gs-cat gs-cat-' + cat + '">' +
                    esc(gsMoney(b.cost, b.currency)) + '</span>' + (b.whatsapp ? '<small>' + esc(s('gseach', { price: priceWords(b.cost / b.whatsapp, b.currency),
                    kind: s('gseach' + cat) })) + '</small>' : '') + '</div>' : '') +
                (b.telegram ? '<div class="gs-canal"><b>' + ico('gs-tg') + esc(s('gsbytg', b.telegram)) + '</b><span class="gs-cat gs-cat-gratis">' +
                    esc(s('gsfree')) + '</span></div>' : '') +
                (b.skipped ? '<div class="gs-canal gs-fuera"><b>' + ico('gs-sin') + esc(s('gsskipped', b.skipped)) + '</b><small>' +
                    esc(s('gsskippedwho', b.skipped)) + '</small></div>' : '') +
                '</div>' +
                '<p class="gs-porque">' + ico('gs-euro') + '<span>' + esc(gsWhy(b)) + '</span></p>';
            if (!d.canapprove) {
                return h + '</div>';
            }
            if (rejecting) {
                // Why, in one touch or in words: whoever asked reads it and can change it and ask again.
                var r = st.gsReject;
                return h + '<div class="gs-rechazo"><b>' + esc(s('gsrejectwhy')) + '</b><small>' + esc(s('gsrejecttell', asker)) + '</small>' +
                    '<div class="gs-motivos">' + [1, 2, 3].map(function (i) {
                        var on = r.motive === s('gsmotive' + i);
                        return '<button type="button" class="mrd-chip' + (on ? ' mrd-on' : '') + '" data-a="gsmotivo" data-v="' + i + '" aria-pressed="' + on + '">' +
                            esc(s('gsmotive' + i)) + '</button>';
                    }).join('') + '</div>' +
                    '<textarea class="gs-motivo" rows="2" maxlength="200" aria-label="' + esc(s('gsrejectwhy')) + '" placeholder="' + esc(s('gsrejectph')) + '">' +
                    esc(r.reason || '') + '</textarea>' +
                    '<div class="gs-acciones"><button type="button" class="mrd-btn gs-rechazar-b" data-a="gsrejectok" data-v="' + b.id + '">' + ico('gs-x') +
                    esc(s('gsrejectandtell', asker)) + '</button><button type="button" class="mrd-enlace" data-a="gsrejectno">' + esc(s('gsrejectback')) +
                    '</button></div></div></div>';
            }
            return h + '<div class="gs-acciones"><button type="button" class="mrd-btn mrd-primario" data-a="gsapprove" data-v="' + b.id + '">' + ico('check') +
                esc(s('gsapproveok')) + '</button><button type="button" class="mrd-btn" data-a="gsreject" data-v="' + b.id + '">' + ico('gs-x') +
                esc(s('gsrejectdots')) + '</button>' + (b.telegram && b.whatsapp ? '<span class="gs-solo-tg"><button type="button" class="mrd-enlace" data-a="gstelegram" data-v="' +
                b.id + '">' + esc(s('gsonlytgfree', b.telegram)) + '</button><small>' + esc(s('gsonlytgmiss', b.whatsapp)) + '</small></span>' : '') + '</div></div>';
        }
        // A decision, with its money; the ones taken on this page go first, marked, with how the send goes.
        function gsDecided(b, recent) {
            var yes = b.status === 'queued', mine = gsByMe(b), first = b.decidedbyfirst;
            var who = yes ? s(b.only === 'telegram' ? (mine ? 'gsapprovedtgyou' : 'gsapprovedtg') : (mine ? 'gsapprovedyou' : 'gsapproved'), first) :
                (b.status === 'rejected' ? s(mine ? 'gsrejectedyou' : 'gsrejected', first) : s('gscancelled'));
            var parts = [esc(b.label)];
            if (yes) {
                parts.push(esc(b.only === 'telegram' ? s('gsbytg', b.telegram) : s('gsbywa', b.whatsapp)));
                if (b.only !== 'telegram') {
                    parts.push('<b>' + esc(gsMoney(b.cost, b.currency)) + '</b>');
                }
                var total = b.only === 'telegram' ? b.telegram : b.whatsapp + b.telegram;
                if (recent && total) {
                    parts.push('<span class="gs-saliendo">' + esc(b.sent < total ? s('gssending', { sent: b.sent, total: total }) : s('gssentall', total)) + '</span>');
                }
            } else if (b.reason) {
                parts.push('«' + esc(b.reason) + '»');
            }
            if (recent && b.status === 'rejected') {
                parts.push(esc(s('gstold', gsAsker(b))));
            }
            var at = b.decided * 1000;
            return '<div' + (recent ? ' class="gs-recien"' : '') + '><span class="' + (yes ? 'gs-si' : 'gs-no') + '">' + ico(yes ? 'check' : 'gs-x') +
                '</span><span><b>' + esc(who) + '</b> · ' + parts.join(' · ') + '</span><small>' +
                esc(dayKey(at) === dayKey(nowMs()) ? s('gstoday', fmtTime(at)) : listTime(at)) + '</small></div>';
        }
        /* The month, short, next to what is being approved: what has gone, what this send would add (striped, as it is not
           spent yet), the 80 % line, and the way to the whole month below. */
        function gsShortHtml(b) {
            var d = gsData(), m = d.month, cur = m.currency, com = gsCommitted(), cat = {};
            m.categories.forEach(function (c) {
                cat[c.key] = c;
            });
            var spent = m.cost + com.sum, adds = b && b.only !== 'telegram' ? b.cost : 0, budget = m.budget;
            var h = '<div class="gs-hoja gs-corto" aria-label="' + esc(s('gsmonth', m.name)) + '"><p class="gs-corto-t">' + ico('gs-euro') +
                esc(s('gsmonth', m.name)) + '</p><div class="gs-cifras"><span class="gs-total">' + esc(gsMoney(spent, cur)) + '</span>' +
                (budget ? '<span class="gs-de">' + esc(s('gsofshort', { budget: gsMoney(budget, cur), share: Math.floor(100 * spent / budget) })) + '</span>' : '') + '</div>';
            if (budget) {
                var scale = Math.max(budget, spent + adds), pc = function (x) {
                    return (100 * x / scale) + '%';
                };
                h += '<div class="gs-corto-barra"><div class="gs-barra" role="img" aria-label="' + esc(s('gsbarshare', Math.floor(100 * spent / budget))) + '">' +
                    ['utility', 'marketing', 'authentication'].map(function (k) {
                        return '<i class="gs-b-' + GS_CAT[k] + '" style="width:' + pc((cat[k] || {}).cost || 0) + '"></i>';
                    }).join('') + (com.sum ? '<i class="gs-b-mkt" style="width:' + pc(com.sum) + '"></i>' : '') +
                    (adds ? '<i class="gs-b-este" style="width:' + pc(adds) + '"></i>' : '') + '</div><span class="gs-corto-80" style="left:' +
                    pc(0.8 * budget) + '"></span></div>';
            }
            if (adds) {
                h += '<p class="gs-corto-l"><i class="gs-b-este"></i>' + esc(s('gswiththis')) + ' <b>' + esc(gsMoney(spent + adds, cur)) + '</b>' +
                    (budget ? esc(s('gsshareof', Math.floor(100 * (spent + adds) / budget))) : '') + '</p>';
            } else if (com.sum) {
                h += '<p class="gs-corto-l"><i class="gs-b-mkt"></i>' + esc(s(com.today ? 'gswithtoday' : 'gswithdecided')) + ' <b>' +
                    esc(gsMoney(com.sum, cur)) + '</b></p>';
            }
            if (budget) {
                h += '<p class="gs-corto-l gs-corto-l80"><i></i>' + esc(s('gsat80', gsMoney(0.8 * budget, cur))) + '</p>';
            }
            return h + '<button type="button" class="mrd-enlace" data-a="gsfull">' + esc(s('gsseemonth')) + '</button></div>';
        }
        function gsApprovals() {
            var d = gsData();
            if (!d) {
                return '';
            }
            // What was decided today stays, also once nothing is left: the section does not vanish under the person.
            var today = keyTime(dayKey(nowMs()));
            var recent = d.decided.filter(function (b) {
                return st.gsJust[b.id];
            }).sort(function (a, b) {
                return b.decided - a.decided;
            });
            var rest = d.decided.filter(function (b) {
                return !st.gsJust[b.id];
            });
            if (!d.pending.length && !recent.length && !d.decided.some(function (b) {
                return b.decided >= today;
            })) {
                return '';
            }
            var names = d.approvers.map(function (p) {
                return p.firstname;
            });
            var side = d.canapprove || d.canchoose ? gsShortHtml(d.pending[0] || null) : '';
            return '<section class="gs-bloque"><h3>' + ico('gs-firma') + esc(s(d.pending.length ? 'gsapprovetitle' : 'gsapprovalstitle')) +
                (names.length ? '<small>' + esc(s('gsseenby', gsNames(names, 'gsand'))) + '</small>' : '') + '</h3>' +
                '<div class="gs-fila' + (side ? '' : ' gs-sin-lado') + '"><div class="gs-pendientes">' + (d.pending.length ? d.pending.map(gsSend).join('')
                : '<div class="gs-hoja gs-nada">' + ico('check') + '<span>' + esc(s('gsnothingleft')) + '</span></div>') + '</div>' + side + '</div>' +
                (d.decided.length ? '<div class="gs-hoja gs-hecho">' + recent.map(function (b) {
                    return gsDecided(b, true);
                }).join('') + rest.map(function (b) {
                    return gsDecided(b, false);
                }).join('') + '</div>' : '') + '</section>';
        }
        function gsDays(m) {
            var max = 0, by = {}, cols = [], axis = [];
            m.bydays.forEach(function (x) {
                by[x.day] = x;
                max = Math.max(max, x.utility + x.marketing + x.authentication);
            });
            for (var d = 1; d <= m.days; d++) {
                if (d > m.today) {
                    cols.push('<span class="gs-dia gs-futuro"></span>');
                } else {
                    var x = by[d] || { utility: 0, marketing: 0, authentication: 0 }, t = x.utility + x.marketing + x.authentication;
                    var hgt = max ? Math.max(2, Math.round(56 * t / max)) : 2;
                    cols.push('<span class="gs-dia' + (d === m.today ? ' gs-hoy' : '') + '" style="height:' + hgt + 'px" title="' +
                        esc(s('gsday', { day: d, money: gsMoney(t, m.currency) })) + '">' + (t ? '<i style="flex:' + x.utility + ';background:var(--cat-util)"></i>' +
                        '<i style="flex:' + x.marketing + ';background:var(--cat-mkt)"></i><i style="flex:' + x.authentication +
                        ';background:var(--cat-aut)"></i>' : '') + '</span>');
                }
                axis.push('<span>' + ([1, 7, 14, 21, 28].indexOf(d) >= 0 ? d : '') + '</span>');
            }
            return '<div class="gs-dias" aria-label="' + esc(s('gsbyday')) + '">' + cols.join('') + '</div><div class="gs-ejes" aria-hidden="true">' +
                axis.join('') + '</div>';
        }
        var GS_STEPS = {
            threshold: [0, 1, 2, 3, 5, 10, 15, 20, 25, 30, 40, 50, 75, 100, 150, 200, 300, 500],
            budget: [0, 5, 10, 15, 20, 25, 30, 40, 50, 75, 100, 150, 200, 300, 500, 750, 1000, 1500, 2000]
        };
        function gsStep(kind, value, help) {
            var d = gsData(), cur = d.month.currency, shown = kind === 'budget' && !value ? s('gsnobudget') : gsMoney(value, cur);
            var dis = d.canapprove ? '' : ' disabled';
            return '<div class="gs-ajuste"' + (kind === 'budget' ? ' id="mrd-gs-presupuesto"' : '') + '><b>' + esc(s(kind === 'budget' ? 'gsbudget' : 'gsthreshold')) +
                '</b><div class="gs-paso"><button type="button" data-a="gspaso" data-v="' + kind + ':-1" aria-label="' + esc(s('gsless1')) + '"' + dis + '>' +
                ico('gs-menos') + '</button><output>' + esc(shown) + '</output><button type="button" data-a="gspaso" data-v="' + kind + ':1" aria-label="' +
                esc(s('gsmore1')) + '"' + dis + '>' + ico('gs-mas') + '</button></div><small>' + esc(help) + '</small></div>';
        }
        function gsPeople() {
            var d = gsData();
            if (st.gsPick) {
                var pick = st.gsPick;
                var people = (st.extras.people || []).filter(function (p) {
                    return p.userid;
                });
                // Each touch keeps itself; the last one marked stays, so that someone always sees it.
                return '<div class="gs-quienes gs-eligiendo"><div><b>' + esc(s('gswho')) + '</b><small>' + esc(s('gswhopick')) + '</small></div>' +
                    '<div class="gs-elegir">' + people.map(function (p) {
                        var on = pick.indexOf(p.userid) >= 0, last = on && pick.length === 1;
                        return '<button type="button" class="mrd-btn mrd-chico' + (on ? ' mrd-on' : '') + '" aria-pressed="' + on + '" data-a="gspick" data-v="' +
                            p.userid + '"' + (last ? ' aria-disabled="true" title="' + esc(s('gsneedone')) + '"' : '') + '>' +
                            avAgent({ agentid: p.agentid, name: p.name }, 'mrd-s') + esc(p.name) + '</button>';
                    }).join('') + '</div><div class="gs-acciones"><button type="button" class="mrd-btn mrd-chico" data-a="gspeopledone">' +
                    esc(s('gsdonebtn')) + '</button></div></div>';
            }
            return '<div class="gs-quienes"><div><b>' + esc(s('gswho')) + '</b><small>' + esc(s('gswhohelp')) + '</small></div>' +
                '<span class="gs-caras">' + d.approvers.map(function (p) {
                    return gsFace(p.userid, p.name) + '<span>' + esc(p.firstname) + '</span>';
                }).join('') + '</span>' + (d.canchoose ? '<button type="button" class="mrd-btn mrd-chico" data-a="gspeople">' + esc(s('gschange')) + '</button>' : '') +
                '</div>';
        }
        function gsMonth() {
            var d = gsData();
            if (!d || !d.canapprove && !d.canchoose) {
                return '';
            }
            var m = d.month, cur = m.currency, cat = {};
            m.categories.forEach(function (c) {
                cat[c.key] = c;
            });
            var spent = m.cost, scale = m.budget ? Math.max(m.budget, spent) : (spent || 1);
            var h = '<section class="gs-bloque" id="mrd-gs-mes" tabindex="-1"><h3>' + ico('gs-euro') + esc(s('gsmonth', m.name)) + '<small>' +
                esc(m.time ? s('gsfrommeta') : s('gsnotyet')) + '</small></h3><div class="gs-hoja">';
            h += '<div class="gs-cifras"><span class="gs-total">' + esc(gsMoney(spent, cur)) + '</span><span class="gs-de">' +
                esc(m.budget ? s('gsofbudget', { budget: gsMoney(m.budget, cur), share: m.share }) :
                (spent ? s('gsnobudgetline') : s('gsservicefree', (cat.service || {}).count || 0))) + '</span></div>';
            if (spent || m.budget) {
                h += '<div class="gs-barra" role="img" aria-label="' + esc(m.budget ? s('gsbarshare', m.share) : s('gsbarspent')) + '">' +
                    ['utility', 'marketing', 'authentication'].map(function (k) {
                        return '<i class="gs-b-' + GS_CAT[k] + '" style="width:' + (100 * ((cat[k] || {}).cost || 0) / scale) + '%"></i>';
                    }).join('') + '</div>';
                if (m.budget) {
                    h += '<div class="gs-marcas" aria-hidden="true"><span style="left:' + (80 * m.budget / scale) + '%">' + esc(s('gsmark80', gsMoney(0.8 * m.budget, cur))) + '</span>' +
                        (spent > m.budget ? '<span class="gs-cien" style="left:' + (100 * m.budget / scale) + '%">' + esc(gsMoney(m.budget, cur)) + '</span>' : '') +
                        '</div>';
                }
            }
            if (m.budget && m.share >= 100) {
                h += '<p class="gs-aviso">' + ico('candado') + '<span><b>' + esc(s('gsoverb', m.name)) + '</b> ' + esc(s('gsover', m.name)) + '</span>' +
                    (d.canapprove ? '<button type="button" class="mrd-btn mrd-chico" data-a="gsraise">' + esc(s('gsraise')) + '</button>' : '') + '</p>';
            } else if (m.budget && m.share >= 80) {
                h += '<p class="gs-aviso">' + ico('alerta') + '<span><b>' + esc(s('gsnearb', { share: m.share, month: m.name })) + '</b> ' + esc(s('gsnear')) +
                    '</span></p>';
            }
            if (spent) {
                h += gsDays(m);
            }
            h += '<div class="gs-cats">' + ['utility', 'marketing', 'service', 'authentication'].filter(function (k) {
                return k !== 'authentication' || (cat[k] && cat[k].count);
            }).map(function (k) {
                // Plain replies are free up to a thousand a month by number (Meta, since October 2026); past them, their cost.
                var c = cat[k] || { count: 0, cost: 0 }, free = k === 'service' && !(c.cost > 0);
                return '<div><i style="background:var(--cat-' + (free ? 'srv' : GS_CAT[k]) + ')"></i><span>' + esc(s('cat' + k)) +
                    (free ? ' <small>· ' + esc(s('gsfreelow')) + '</small>' : '') + '</span><span class="gs-n">' + esc(free ? String(c.count) : s('gsmessages', c.count)) + '</span><span class="gs-e">' +
                    esc(free ? s('gsfree') : gsMoney(c.cost, cur)) + '</span></div>';
            }).join('') + '</div>';
            h += '<p class="gs-nota">' + esc(s('gsnote')) + '</p>';
            h += '<div class="gs-ajustes">' + gsStep('threshold', d.threshold, s('gsthresholdhelp')) +
                gsStep('budget', m.budget, m.budget ? s('gsbudgethelp') : s('gsbudgetnone')) + '</div>';
            return h + gsPeople() + '</div></section>';
        }
        function gsStepValue(kind, dir) {
            var d = gsData(), now = kind === 'budget' ? d.month.budget : d.threshold, steps = GS_STEPS[kind], i;
            if (dir > 0) {
                for (i = 0; i < steps.length; i++) {
                    if (steps[i] > now + 0.001) {
                        return steps[i];
                    }
                }
                return now;
            }
            for (i = steps.length - 1; i >= 0; i--) {
                if (steps[i] < now - 0.001) {
                    return steps[i];
                }
            }
            return now;
        }

        /* With no conversation open, the room on the right says where things are, yours first: «Mías», «Sin asignar» and
           «Nueva»; then what asks something of the person (what to approve, the board); then, for whoever sees everything,
           the whole centre in a sentence and a table, without alarm; then the month of WhatsApp and how the trays work. */
        function homeHtml() {
            var open = allConvs().filter(function (c) {
                return c.section !== 'resolved';
            });
            var mine = open.filter(function (c) {
                return isMine(c) && c.section === 'reply';
            }).sort(byWait);
            var free = open.filter(function (c) {
                return inInbox(c, 'unassigned') && c.section === 'reply';
            }).sort(byWait);
            var h = '<div class="mrd-inicio"><h2>' + esc(s('homehello', firstName(me) || me.name || '')) + '</h2>';
            // On a phone the start is where the notices of the browser are asked for, once.
            if (narrow() && offerDesktop()) {
                h += offerHtml();
            }
            h += '<div class="mrd-inicio-tarjetas mrd-lo-tuyo">';
            var card = function (key, icon, title, list, button) {
                var first = list[0], long = first && waitMs(first) >= 4 * 3600000;
                return '<div class="mrd-inicio-t"><div class="mrd-inicio-t1">' + ico(icon) + '<b>' + esc(title) + '</b></div>' +
                    '<p class="mrd-inicio-n">' + (list.length ? '<span class="mrd-cifra' + (long ? ' mrd-larga' : '') + '">' + list.length + '</span> ' +
                        esc(s('toreplyword')) : esc(nothingText())) + '</p>' +
                    (first ? '<p class="mrd-inicio-espera ' + waitTier(first) + '">' + ico('reloj') + esc(s('homelongest', waitText(first))) + '</p>' : '') +
                    (first ? '<button type="button" class="mrd-btn mrd-chico' + (key === 'mine' ? ' mrd-primario' : '') + '" data-a="conv" data-v="' + first.id + '">' +
                        esc(button) + '</button>' : '<button type="button" class="mrd-btn mrd-chico" data-a="inbox" data-v="' + key + '">' + esc(s('homesee')) + '</button>') +
                    '</div>';
            };
            h += card('mine', 'yo', s('mine'), mine, s('homeopenfirst'));
            h += card('unassigned', 'nadie', s('unassigned'), free, s('homeopenfirst'));
            if (!READONLY) {
                h += '<div class="mrd-inicio-t mrd-inicio-t-nueva"><div class="mrd-inicio-t1">' + ico('lapiz') + '<b>' + esc(s('newconv')) + '</b></div>' +
                    '<p class="mrd-inicio-n">' + esc(s('newconvhelp')) + '</p>' +
                    '<button type="button" class="mrd-btn mrd-chico" data-a="new">' + esc(s('newconvtitle')) + '</button></div>';
            }
            h += '</div>' + homeHoursHtml();
            var spendUp = gsQuiet();
            h += gsApprovals() + (spendUp ? gsMonth() : '');
            var mineToSee = READONLY ? [] : boardToSee();
            if (mineToSee.length) {
                h += '<section class="eq-inicio"><h3>' + ico('eq-tablon') + esc(s('boardforyou')) + '</h3>' + mineToSee.map(function (b) {
                    return boardCard(b, { inicio: true });
                }).join('') + '</section>';
            }
            if (me.seesall) {
                h += homeCentreHtml(open);
            }
            if (!spendUp) {
                h += gsMonth();
            }
            h += '<details class="mrd-inicio-como"' + (howFolded() ? '' : ' open') + '><summary><h3>' + esc(s('homehow')) + '</h3>' + ico('abajo') +
                '</summary><ul>' +
                '<li><b>' + esc(s('mine')) + '</b>: ' + esc(s('homehowmine')) + '</li>' +
                '<li><b>' + esc(s('unassigned')) + '</b>: ' + esc(s('homehowfree', s('takeit'))) + '</li>' +
                '<li><b>' + esc(s('homehowteamsname')) + '</b>: ' + esc(s('homehowteams')) + '</li>' +
                '<li><b>' + esc(s('tabnote')) + '</b>: ' + esc(s('homehownote')) + '</li>' +
                '<li><b>' + esc(s('homehow24name')) + '</b>: ' + esc(s('homehow24')) + '</li></ul>' +
                (guideLinks() ? '<div class="mrd-inicio-ayuda">' + guideLinks() + '</div>' : '') + '</details></div>';
            return h;
        }
        // «How it works» folds for whoever knows it already, and stays folded on this computer.
        function howFolded() {
            try {
                return root.localStorage.getItem('mrd-chat-como') === 'plegado';
            } catch (e) {
                return false;
            }
        }
        function keepHow(open) {
            try {
                root.localStorage.setItem('mrd-chat-como', open ? 'abierto' : 'plegado');
            } catch (e) {
                // A browser without storage opens it each time.
            }
        }
        // The guides in PDF the person has: whoever attends, and whoever coordinates if they see everything.
        function guideLinks() {
            return (cfg.guides || []).map(function (g) {
                return '<a class="mrd-btn mrd-chico" href="' + esc(g.url) + '" target="_blank" rel="noopener">' + ico('libro') +
                    esc(s(g.kind === 'coordinate' ? 'guidecoordinate' : 'guideattend')) + '</a>';
            }).join('');
        }
        /* With departments of their own hours, whoever does not see the whole centre sees how theirs are now, each one a
           way into its tray: open until when, or closed and when it opens. Whoever sees everything has it in the table. */
        function homeHoursHtml() {
            var mine = teams().filter(function (t) {
                return t.mine;
            });
            if (!BYTEAM || me.seesall || !mine.length) {
                return '';
            }
            return '<section class="mrd-inicio-horas"><h3>' + ico('reloj') + esc(s('hometeamhours')) + '</h3><div class="mrd-inicio-horas-l">' +
                mine.map(function (t) {
                    var x = teamState(t.id);
                    return '<button type="button" class="mrd-inicio-hora' + (x.open ? '' : ' mrd-cerrado') + '" data-a="inbox" data-v="team:' + t.id +
                        '">' + ico(x.open ? teamIcon(t) : 'luna') + '<span><b>' + esc(t.name) + '</b><small>' + esc(x.text) + '</small></span></button>';
                }).join('') + '</div></section>';
        }
        /* The whole centre, for whoever sees everything: a sentence in the grey of the text with its parts (how many, how
           many in the person's departments and in the others, the one that waits longest) and the table below. No alarm:
           the long waits show on their figures and clocks. */
        function homeCentreHtml(open) {
            var reply = open.filter(function (c) {
                return c.section === 'reply';
            }).sort(byWait);
            var p;
            if (!reply.length) {
                p = esc(unknownSince() === null ? s('homecentrenone') : uncheckedText());
            } else {
                var mineIds = myTeamIds(), anyMine = Object.keys(mineIds).length > 0, inMine = reply.filter(function (c) {
                    return !!mineIds[c.teamid];
                }).length, other = reply.length - inMine;
                p = '<b>' + esc(s('toreply', reply.length)) + '</b>' + esc(anyMine && inMine && other
                    ? s('homecentresplit', { mine: inMine, other: other }) : anyMine && !other ? s('homecentreallmine') : '.');
                var top = reply[0], who = (top.contact && top.contact.name) || fmtPhone(top.contact && top.contact.phone);
                var where = top.teamid ? teamName(top.teamid) : s('noteam');
                p += ' ' + esc(waitMs(top) < 600 * 60000 ? s('homecentrelongest', { name: who, team: where, wait: waitText(top) })
                    : s('homecentrelongestsince', { name: who, team: where, wait: waitText(top) }));
            }
            return '<section class="mrd-inicio-centro"><h3>' + ico('edificio') + esc(s('homecentre')) + '</h3><p>' + p + '</p>' +
                homeTeamsHtml(open) + '</section>';
        }
        function homeTeamsHtml(open) {
            var by = {};
            // Every department, those at nought too, dimmed below: so that what waits stands out among them.
            teams().forEach(function (t) {
                by[t.id] = [];
            });
            open.forEach(function (c) {
                if (c.section !== 'reply') {
                    return;
                }
                var k = c.teamid || 0;
                (by[k] = by[k] || []).push(c);
            });
            var rows = Object.keys(by).map(function (k) {
                var list = by[k].sort(byWait), who = {}, nobody = 0;
                list.forEach(function (c) {
                    if (c.assignee) {
                        var n = firstName(c.assignee);
                        who[n] = (who[n] || 0) + 1;
                    } else {
                        nobody++;
                    }
                });
                var people = Object.keys(who).sort(function (a, b) {
                    return who[b] - who[a];
                }).map(function (n) {
                    return n + ' ' + who[n];
                });
                if (nobody) {
                    people.push(s('homenobody', nobody));
                }
                return { id: +k, list: list, people: people };
            }).sort(function (a, b) {
                if (!a.list.length || !b.list.length) {
                    return (b.list.length ? 1 : 0) - (a.list.length ? 1 : 0) || teamName(a.id).localeCompare(teamName(b.id));
                }
                return waitMs(b.list[0]) - waitMs(a.list[0]);
            });
            var h = '<div class="mrd-inicio-deps">';
            if (!rows.length) {
                return '';
            }
            h += '<div class="mrd-inicio-dep mrd-inicio-dep-cab" aria-hidden="true"><span>' + esc(s('hometeamcol')) + '</span><span>' +
                esc(s('hometoreplycol')) + '</span><span>' + esc(s('homelongestcol')) + '</span><span>' + esc(s('homewhocol')) + '</span><span></span></div>';
            h += rows.map(function (r) {
                var first = r.list[0], long = first && waitMs(first) >= 4 * 3600000;
                // With departments of their own hours, each one says how it is now, under its name.
                var now = BYTEAM && r.id ? teamState(r.id) : null;
                return '<button type="button" class="mrd-inicio-dep' + (first ? (long ? ' mrd-con-pend mrd-larga' : ' mrd-con-pend') : ' mrd-inicio-dep-cero') +
                    '" data-a="inbox" data-v="' + (r.id ? 'team:' + r.id : 'noteam') + '" aria-label="' +
                    esc(s('hometeamopen', { team: r.id ? teamName(r.id) : s('noteam'), n: r.list.length }) + (now ? ' · ' + now.text : '')) + '">' +
                    '<span class="mrd-inicio-dep-n">' + esc(r.id ? teamName(r.id) : s('noteam')) +
                    (now ? '<small class="mrd-inicio-dep-h' + (now.open ? '' : ' mrd-cerrado') + '">' + (now.open ? '' : ico('luna')) + esc(now.text) +
                        '</small>' : '') + '</span>' +
                    '<span class="mrd-inicio-dep-c">' + (first ? '<span class="mrd-cifra' + (long ? ' mrd-larga' : '') + '">' + r.list.length + '</span>' : unknownSince() === null ? '0' : '—') +
                    '</span>' + (first ? '<span class="mrd-inicio-espera ' + waitTier(first) + '">' + ico('reloj') + esc(waitText(first)) + '</span>' : '<span></span>') +
                    '<span class="mrd-inicio-dep-q">' + esc(r.people.join(' · ')) + '</span>' + ico('der', 'mrd-inicio-dep-ir') + '</button>';
            }).join('');
            return h + '</div>';
        }
        function goHome() {
            if (st.nueva) {
                closeNew();
            }
            // The start belongs to the families' trays: from what is between the team, back to the last of those.
            if (inZone()) {
                st.inbox = st.lastTray || 'mine';
                st.boardNew = null;
                st.boardEdit = null;
                st.teamPick = false;
                st.teamQ = '';
                renderRail();
                renderListHead();
            }
            st.open = 0;
            st.msgs = [];
            st.ficha = null;
            st.fichaFor = 0;
            renderChat();
            renderFicha();
            renderListBody();
            syncUrl();
            if (narrow()) {
                setLayer('chat', true);
            }
        }
        function renderHome() {
            if (!st.ready || st.nueva || conv(st.open) || inZone()) {
                return;
            }
            // Whoever is writing why they reject a send keeps the box as it is.
            var typing = document.activeElement;
            if (st.gsReject && typing && typing.classList && typing.classList.contains('gs-motivo')) {
                return;
            }
            var box = el.chat.querySelector('.mrd-inicio');
            if (box) {
                box.outerHTML = homeHtml();
            } else {
                renderChat();
            }
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
            // Whoever is writing (why to be reminded, a line for the department it goes to, or what was said on the phone)
            // keeps the box and the caret.
            var typed = head.querySelector('.mrd-rem-motivo, .mrd-pasar-nota, .mrd-llamada-linea');
            var typing = typed && document.activeElement === typed;
            var caret = typing ? typed.selectionStart : 0, typedCls = typing ? typed.className : '';
            // On a phone the bell and the tick say what they do, in a word under them.
            var remLabel = c.remind ? s('remindback', remShort(c.remind.at * 1000)) : s('remindbtn');
            var remBtn = READONLY ? '' : '<div class="mrd-rem-caja"><button type="button" class="mrd-btn mrd-recordar' + (c.remind ? ' mrd-on' : '') +
                '" data-a="pop" data-v="recordar" aria-expanded="' + (st.pop === 'recordar') + '" aria-label="' + esc(remLabel) + '" title="' +
                esc(c.remind ? remLabel : s('remtitle')) + '">' + ico('campana') + '<span class="mrd-t">' + esc(remLabel) + '</span><span class="mrd-rotulo-movil">' +
                esc(s('remindshort')) + '</span></button>' + (st.pop === 'recordar' ? popRemind(c) : '') + '</div>';
            // The department reads as a fact; passing it on is its own button, next to who has it.
            head.innerHTML = '<button type="button" class="mrd-volver" data-a="back" data-v="lista" aria-label="' + esc(s('back')) + '">' + ico('izq') + '</button>' +
                '<h2>' + esc(ct.name || fmtPhone(ct.phone)) + '<span class="mrd-rel">' + relHtml(ct) + '</span></h2>' +
                '<div class="mrd-mandos"><span class="mrd-mando mrd-dato" title="' + esc(s('depnow', t ? t.name : s('noteam'))) + '">' +
                ico(teamIcon(t)) + '<span>' + esc(t ? t.name : s('noteam')) + '</span></span>' +
                '<button type="button" class="mrd-mando mrd-quien" data-a="pop" data-v="dueno"' + dis + ' aria-expanded="' + (st.pop === 'dueno') + '">' +
                owner + (READONLY ? '' : ico('abajo', 'mrd-flecha')) + '</button>' +
                (READONLY ? '' : '<button type="button" class="mrd-mando mrd-pasar-b" data-a="pop" data-v="dep" aria-expanded="' + (st.pop === 'dep') + '">' +
                    ico('pasar') + '<span>' + esc(s('passbtn')) + '</span></button>') +
                // A call to this number, written down in two touches.
                (READONLY || !digits(ct.phone) ? '' : '<button type="button" class="mrd-mando mrd-llamar-b" data-a="pop" ' +
                    'data-v="llamada" aria-expanded="' + (st.pop === 'llamada') + '" aria-label="' + esc(s('callchip')) +
                    '" title="' + esc(s('callbtntitle')) + '">' + ico('tel') + '<span>' + esc(s('callchip')) + '</span></button>') +
                (st.pop === 'dep' ? popTeams(c) : st.pop === 'dueno' ? popPeople(c) : st.pop === 'llamada' ? popCall(c) : '') +
                '</div>' +
                '<div class="mrd-cab-der"><button type="button" class="mrd-btn mrd-ver-ficha" data-a="ficha">' + ico('ficha') + esc(s('file')) + '</button>' +
                remBtn + (READONLY ? '' : resolved
                    ? '<button type="button" class="mrd-btn mrd-hecho mrd-resolver" data-a="reopen" title="' + esc(s('reopen')) + '">' + ico('check') +
                    '<span class="mrd-t">' + esc(s('resolved')) + '</span><span class="mrd-rotulo-movil">' + esc(s('resolved')) + '</span></button>'
                    : '<button type="button" class="mrd-btn mrd-resolver" data-a="resolve">' + ico('check') + '<span class="mrd-t">' + esc(s('resolve')) +
                    '</span><span class="mrd-rotulo-movil">' + esc(s('resolveshort')) + '</span></button>') + '</div>';
            var again = typing ? head.querySelector('.' + typedCls.split(' ')[0]) : null;
            if (again) {
                again.focus();
                again.setSelectionRange(caret, caret);
            }
            // A menu opens under its button, inside the screen.
            var pm = head.querySelector('.mrd-pop-menu');
            if (pm) {
                var b = head.querySelector(st.pop === 'dueno' ? '.mrd-mando.mrd-quien' : st.pop === 'llamada' ? '.mrd-llamar-b'
                    : '.mrd-pasar-b');
                var mandos = head.querySelector('.mrd-mandos');
                var w = pm.offsetWidth || 320;
                pm.style.setProperty('--x', Math.max(0, Math.min(b ? b.offsetLeft : 0, mandos.clientWidth - w)) + 'px');
            }
        }

        /* -------- Above the conversation: what did not arrive, who of the team already attends the family, and its
           department out of hours -------- */
        function renderBands() {
            var c = conv(st.open), zone = el.chat.querySelector('.mrd-bandas');
            if (!zone) {
                return;
            }
            var h = c ? failBand(c) + heldBand(c) + hoursBand(c) : '';
            if (zone.getAttribute('data-sig') === h) {
                return;
            }
            zone.setAttribute('data-sig', h);
            zone.innerHTML = h;
        }
        /* The last message did not reach them: why, in plain words, and the number to call, with the call written down
           after. Until a later message goes out, or they write. */
        function failBand(c) {
            if (!c.failedat) {
                return '';
            }
            var ct = c.contact || {}, d = digits(ct.phone), number = fmtPhone(d);
            var dial = d ? '<a class="mrd-btn mrd-chico mrd-banda-tel" href="tel:+' + d + '" data-a="tel" data-v="' + esc(number) +
                '" aria-label="' + esc(s('calldial', number)) + '">' + ico('tel') + '<span>' + esc(number) + '</span></a>' : '';
            var write = READONLY || !d ? '' : '<button type="button" class="mrd-btn mrd-chico" data-a="callopen">' + ico('nota') +
                esc(s('callbtn')) + '</button>';
            return '<div class="mrd-banda mrd-banda-fallo" role="status"><span class="mrd-banda-i">' + ico('nollego') + '</span>' +
                '<div class="mrd-banda-t"><b>' + esc(s('failtitle')) + '</b>' +
                '<span class="mrd-banda-cuando">' + esc(listTime(c.failedat * 1000)) + '</span>' +
                '<p>' + esc(c.failreason || s('failedplain')) + '</p></div>' +
                '<div class="mrd-banda-b">' + dial + write + '</div></div>';
        }
        /* Someone else of the team already has another conversation of the same family (by the common record): who, in
           which department, with whom, and the last thing said. Whoever opens this one sees it, and decides: pass it to
           that person, or keep it. Kept, it stays as one quiet line. */
        function heldBand(c) {
            var held = st.fichaFor === c.id && st.ficha && st.ficha.held ? st.ficha.held : [];
            var x = held[0];
            if (!x) {
                return '';
            }
            var who = { agentid: x.agentid, name: x.name, firstname: x.firstname }, first = x.firstname || x.name;
            var where = x.teamname ? s('heldwhere', { team: x.teamname, adult: x.adult }) : s('heldwith', x.adult);
            var see = x.sees ? '<button type="button" class="mrd-btn mrd-chico" data-a="heldsee" data-v="' + x.conversation + '">' +
                esc(s('beforesee')) + '</button>' : '';
            if (heldLater(c.id)) {
                return '';
            }
            if (READONLY) {
                return '<div class="mrd-banda mrd-banda-lleva mrd-banda-quieta">' + avAgent(who, 'mrd-s') +
                    '<span class="mrd-banda-t">' + esc(s('heldquiet', first)) + ' · ' + esc(where) + '</span>' +
                    (see ? '<div class="mrd-banda-b">' + see + '</div>' : '') + '</div>';
            }
            var when = x.at ? '<small>' + esc(listTime(x.at * 1000)) + '</small>' : '';
            var said = x.preview ? '<p class="mrd-banda-cita"><span>«' + esc(x.preview) + '»</span>' + when + '</p>' : '';
            // Keeping it is the «I'll handle it» of below; here, giving it to whoever already attends the family.
            return '<div class="mrd-banda mrd-banda-lleva">' + avAgent(who, 'mrd-m') +
                '<div class="mrd-banda-t"><b>' + esc(s('heldby', first)) + '</b><p>' + esc(where) + '</p>' + said + '</div>' +
                '<div class="mrd-banda-b"><button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="heldpass" data-v="' +
                x.agentid + '">' + ico('pasar') + esc(s('heldpass', first)) + '</button>' + see +
                '<button type="button" class="mrd-btn mrd-chico mrd-banda-no" data-a="heldlater">' + esc(s('heldlater')) +
                '</button>' +
                '</div></div>';
        }
        /* Its department keeps hours of its own and is out of them while the person's are open (when theirs are closed
           too, the rail says so): the wait of this conversation does not count, and when it opens. One quiet line. */
        function hoursBand(c) {
            if (!BYTEAM || !c.teamid || c.section === 'resolved' || firstOpening(myHours())) {
                return '';
            }
            var x = teamState(c.teamid);
            if (x.open) {
                return '';
            }
            return '<div class="mrd-banda mrd-banda-horario" role="status"><span class="mrd-banda-i">' + ico('luna') + '</span>' +
                '<div class="mrd-banda-t"><b>' + esc(s('teamclosedtitle', teamName(c.teamid))) + '</b><p>' +
                esc(x.next ? s('teamclosedwait', whenPhrase(x.next)) : s('teamclosedwaitnever')) + '</p></div></div>';
        }
        /* «Ahora no» to the band of who already attends the family: it does not come back in that conversation for that
           person while the tab is open (the browser's storage of the session, or the page's own if there is none). */
        function heldKey() {
            return 'mrd-chat-lleva-' + (me.userid || 0);
        }
        function heldLater(id, set) {
            if (set) {
                st.heldOk[id] = true;
                try {
                    root.sessionStorage.setItem(heldKey(), JSON.stringify(Object.keys(st.heldOk)));
                } catch (e) {
                    // Without storage it is remembered while the page is open.
                }
            }
            return !!st.heldOk[id];
        }
        /* «Llamada»: the number to call, a line of what was said if one wants, and how it went, which keeps it at once.
           Nobody answered and the 24 hours are over: the approved message for that, ready in one touch. */
        function popCall(c) {
            var ct = c.contact || {}, d = digits(ct.phone), name = contactFirst(c);
            // The veil only shows on a phone, where the sheet comes from below: touching it closes the sheet.
            var head = '<div class="mrd-pop-velo" data-a="callnotnow" aria-hidden="true"></div>' +
                '<div class="mrd-pop mrd-pop-menu mrd-pop-llamada" role="dialog" aria-label="' + esc(s('calltitle', name)) + '">';
            var tpl = (st.extras.templates || [])[callTpl()];
            if (st.call.offer === c.id && tpl) {
                return head + '<div class="mrd-llamada-hecha">' + ico('telno') + '<span>' + esc(s('calldonemissed')) +
                    '</span></div>' +
                    '<p class="mrd-llamada-q">' + esc(s('callofferq')) + '</p>' +
                    '<button type="button" class="mrd-btn mrd-primario mrd-llamada-tpl" data-a="calltpl">' + ico('mensaje') +
                    '<span>' + esc(s('calloffer', tplTitle(tpl))) + '</span></button>' +
                    '<button type="button" class="mrd-btn mrd-chico mrd-llamada-ahora-no" data-a="callnotnow">' +
                    esc(s('callnotnow')) + '</button></div>';
            }
            var dial = d ? '<a class="mrd-llamada-num" href="tel:+' + d + '" data-a="tel" data-v="' + esc(fmtPhone(d)) + '">' +
                ico('tel') + '<span>' + esc(s('calldial', fmtPhone(d))) + '</span></a>' : '';
            return head + '<h5>' + esc(s('calltitle', name)) + '</h5>' + dial +
                '<label class="mrd-rem-l"><span>' + esc(s('callline')) + '</span>' +
                '<input type="text" class="mrd-llamada-linea" maxlength="300" autocomplete="off" placeholder="' + esc(s('callph')) +
                '" value="' + esc(st.call.line) + '"></label>' +
                '<p class="mrd-llamada-q">' + esc(s('callask')) + '</p><div class="mrd-llamada-botones">' +
                '<button type="button" class="mrd-btn mrd-llamada-si" data-a="callsave" data-v="1">' + ico('telsi') +
                esc(s('callanswered')) + '</button>' +
                '<button type="button" class="mrd-btn mrd-llamada-no" data-a="callsave" data-v="0">' + ico('telno') +
                esc(s('callmissed')) + '</button></div>' +
                '<p class="mrd-rem-ayuda">' + esc(s('callhelp')) + '</p></div>';
        }
        // The approved message for a call nobody answered, among those that may be sent: in the language of the page if
        // it is there in several.
        function callTpl() {
            var name = cfg.calltemplate || '', list = st.extras.templates || [], found = -1;
            if (!name) {
                return -1;
            }
            list.forEach(function (t, i) {
                if (t.name === name && (found < 0 || String(t.language).indexOf(LANG) === 0)) {
                    found = i;
                }
            });
            return found;
        }
        // The last call written down nobody answered, if nothing went to them after it and they can no longer be written
        // to freely: the approved message for it is offered on its line.
        function callOfferId(c) {
            if (READONLY || !c || canReply(c) || callTpl() < 0) {
                return 0;
            }
            for (var i = st.msgs.length - 1; i >= 0; i--) {
                var m = st.msgs[i];
                if (m.kind === 'incoming' || (m.kind === 'outgoing' && !m.failed && m.status !== 'failed')) {
                    return 0;
                }
                if (m.kind === 'note' && m.call) {
                    return m.call === 'missed' ? m.id : 0;
                }
            }
            return 0;
        }
        function writeCall(btn, answered) {
            var c = conv(st.open), line = st.call.line.trim();
            if (!c) {
                return;
            }
            busy(btn, true);
            tr.call(M.call, { conversation: c.id, answered: answered, text: line }).then(function (r) {
                st.call.line = '';
                if (r && r.conversation) {
                    mergeConv(r.conversation);
                }
                if (st.open === c.id && r && r.message && indexOfMsg(r.message.id) < 0) {
                    st.msgs.push(r.message);
                    sortMsgs();
                }
                var now = conv(c.id) || c;
                // Nobody answered and the 24 hours are over: the approved message for it, in one touch.
                if (!answered && !canReply(now) && callTpl() >= 0 && !READONLY) {
                    st.call.offer = c.id;
                    st.pop = 'llamada';
                } else {
                    st.pop = null;
                    toast(s(answered ? 'calldoneanswered' : 'calldonemissed'));
                }
                refreshAfterData();
                renderThread();
                focusPop(st.pop ? '.mrd-llamada-tpl' : '');
                pollSoon();
                return r;
            }).catch(function (e) {
                busy(btn, false);
                failToast(e);
            });
        }
        // The approved message for a call nobody answered, in the box, with the gaps it can fill already filled.
        function prepareCallTpl() {
            var i = callTpl();
            st.pop = null;
            st.call.offer = 0;
            if (i < 0 || !conv(st.open)) {
                renderHead();
                return;
            }
            st.mode = 'reply';
            st.tpl = i;
            renderHead();
            renderComposer(true);
            // The gap still empty takes the keys, or the button if the file filled them all; on a phone, no keyboard yet.
            var gaps = el.chat.querySelectorAll('.mrd-escribir .mrd-hueco'), next = null;
            Array.prototype.forEach.call(gaps, function (g) {
                if (!next && !g.textContent.trim()) {
                    next = g;
                }
            });
            var go = next || el.chat.querySelector('.mrd-escribir [data-a="tplsend"]');
            if (go && !touchy()) {
                go.focus();
            }
        }
        /* «Remind me»: a day (the next ones the team works, or any in the sheet of five weeks) and a line of what for;
           that day, when the team opens, the conversation is back in «Mine» with the line as a note, and a notice. */
        function popRemind(c) {
            // The days and the hour its department opens.
            var hh = hoursConv(c), openDay = hh.openDay, remAt = hh.remAt;
            var today = dayKey(nowMs()), chosen = isoKey(st.rem.day), quick = [], k = shiftKey(today, 1);
            for (var i = 0; i < 14 && quick.length < 3; i++, k = shiftKey(k, 1)) {
                if (openDay(k)) {
                    quick.push([k, k === shiftKey(today, 1) ? s('remtomorrow') : s('remweekday', fWeekday.format(new Date(remAt(k))))]);
                }
            }
            var week = shiftKey(today, 7);
            for (var j = 0; j < 7 && !openDay(week); j++) {
                week = shiftKey(week, 1);
            }
            if (quick.every(function (q) {
                return q[0] !== week;
            })) {
                quick.push([week, s('remweek')]);
            }
            var dayChip = function (q) {
                return '<button type="button" class="mrd-chip' + (q[0] === chosen ? ' mrd-on' : '') + '" data-a="remday" data-v="' + keyIso(q[0]) +
                    '" aria-pressed="' + (q[0] === chosen) + '">' + esc(q[1]) + '</button>';
            };
            var start = weekStart(nowMs()), cells = '', heads = '';
            for (var w = 0; w < 7; w++) {
                heads += '<span>' + esc(new Intl.DateTimeFormat(LANG, { weekday: 'narrow', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, 10, 16 + w)))) + '</span>';
            }
            for (var n = 0; n < 35; n++) {
                var key = shiftKey(start, n), d = key % 100, ok = key > today && openDay(key);
                var label = d === 1 || n === 0 ? fShort.format(new Date(remAt(key))) : String(d);
                cells += '<button type="button" class="mrd-rem-dia' + (key === chosen ? ' mrd-on' : '') + (key === today ? ' mrd-hoy' : '') + '" data-a="remday" data-v="' +
                    keyIso(key) + '"' + (ok ? '' : ' disabled') + ' aria-pressed="' + (key === chosen) + '" aria-label="' + esc(dayText(remAt(key))) + '">' +
                    esc(label) + '</button>';
            }
            var at = chosen ? remAt(chosen) : 0;
            var same = c.remind && chosen === dayKey(c.remind.at * 1000) && st.rem.reason.trim() === c.remind.reason;
            var okText = !at ? s('remsavenoday') : c.remind ? s('remchange', whenPhrase(at)) : s('remsave', whenPhrase(at));
            return '<div class="mrd-pop mrd-pop-recordar" role="dialog" aria-label="' + esc(s('remtitle')) + '"><h5>' + esc(s('remtitle')) + '</h5>' +
                (c.remind ? '<div class="mrd-rem-ahora">' + ico('campana') + '<span>' + esc(s('remnowline', whenPhrase(c.remind.at * 1000))) + '</span>' +
                    '<button type="button" class="mrd-btn mrd-chico" data-a="remclear">' + esc(s('remclear')) + '</button></div>' : '') +
                '<div class="mrd-rem-rapidos">' + quick.map(dayChip).join('') + '</div>' +
                '<div class="mrd-rem-cal"><div class="mrd-rem-sem">' + heads + '</div><div class="mrd-rem-dias">' + cells + '</div></div>' +
                '<label class="mrd-rem-l"><span>' + esc(s('remreason')) + '</span><input type="text" class="mrd-rem-motivo" maxlength="200" autocomplete="off" ' +
                'placeholder="' + esc(s('remreasonph')) + '" value="' + esc(st.rem.reason) + '"></label>' +
                '<p class="mrd-rem-ayuda">' + esc(s('remhelp')) + '</p>' +
                // With a day already set and nothing changed, the box above says it all: no button until something changes.
                (same ? '' : '<button type="button" class="mrd-btn mrd-primario mrd-rem-ok" data-a="remsave"' + (at ? '' : ' disabled') + '>' +
                esc(okText) + '</button>') + '</div>';
        }
        /* -------- A message for later: the day and the hour, with the opening of the department as the first one -------- */
        function schedStart(c) {
            var k = dayKey(nowMs()), hh = hoursConv(c), at = hh.on ? hh.nextOpening(nowMs()) : 0;
            if (st.sched && st.sched.conv === c.id) {
                return;
            }
            if (!at) {
                at = hh.remAt(shiftKey(k, 1));
            }
            st.sched = { conv: c.id, key: dayKey(at), min: minutesOf(at) };
        }
        // Minutes since midnight of a moment, in the time zone of the site.
        function minutesOf(ms) {
            var k = dayKey(ms);
            return Math.round((ms - zonedMs(Math.floor(k / 10000), Math.floor(k / 100) % 100, k % 100, 0, 0)) / 60000);
        }
        function schedAtOf(k, min) {
            return zonedMs(Math.floor(k / 10000), Math.floor(k / 100) % 100, k % 100, Math.floor(min / 60), min % 60);
        }
        function schedAt(c) {
            return st.sched && st.sched.conv === c.id ? schedAtOf(st.sched.key, st.sched.min) : 0;
        }
        function schedButton(c, note) {
            if (note || READONLY || !c || !c.id) {
                return '';
            }
            return '<button type="button" class="mrd-btn mrd-prog-abrir' + (st.pop === 'programar' ? ' mrd-on' : '') +
                '" data-a="pop" data-v="programar" aria-haspopup="dialog" aria-expanded="' + (st.pop === 'programar') + '" aria-label="' +
                esc(s('schedopen')) + '" title="' + esc(s('schedopen')) + '">' + ico('prog') + '<span class="mrd-t">' + esc(s('schedshort')) + '</span></button>';
        }
        // Whether what is being scheduled is an approved message, and the moment chosen with what it stops.
        function schedCheck(c) {
            var approved = composerState(c) === 'preview', at = schedAt(c);
            return { at: at, approved: approved, past: at <= nowMs() + 90000,
                far: at > nowMs() + 60 * 86400000, closed: !approved && !!c.replyuntil && at >= c.replyuntil * 1000,
                file: !approved && !!st.files[c.id] };
        }
        function popSchedule(c) {
            var hh = hoursConv(c), today = dayKey(nowMs()), chosen = st.sched && st.sched.conv === c.id ? st.sched : null;
            if (!chosen) {
                schedStart(c);
                chosen = st.sched;
            }
            var quick = [], k = today;
            for (var i = 0; i < 14 && quick.length < 3; i++, k = shiftKey(k, 1)) {
                var o = hh.remAt(k);
                if (hh.openDay(k) && o > nowMs() + 90000) {
                    quick.push(o);
                }
            }
            var chips = quick.map(function (ms) {
                var on = dayKey(ms) === chosen.key && minutesOf(ms) === chosen.min;
                return '<button type="button" class="mrd-chip' + (on ? ' mrd-on' : '') + '" data-a="schedquick" data-v="' + ms + '" aria-pressed="' + on + '">' +
                    esc(cap(whenPhrase(ms))) + '</button>';
            }).join('');
            var start = weekStart(nowMs()), cells = '', heads = '';
            for (var w = 0; w < 7; w++) {
                heads += '<span>' + esc(new Intl.DateTimeFormat(LANG, { weekday: 'narrow', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, 10, 16 + w)))) + '</span>';
            }
            for (var n = 0; n < 35; n++) {
                var key = shiftKey(start, n), d = key % 100, ok = key >= today && hh.openDay(key) && schedAtOf(key, 1439) > nowMs() + 90000;
                cells += '<button type="button" class="mrd-rem-dia' + (key === chosen.key ? ' mrd-on' : '') + (key === today ? ' mrd-hoy' : '') + '" data-a="schedday" data-v="' +
                    key + '"' + (ok ? '' : ' disabled') + ' aria-pressed="' + (key === chosen.key) + '" aria-label="' + esc(dayText(hh.remAt(key))) + '">' +
                    esc(d === 1 || n === 0 ? fShort.format(new Date(hh.remAt(key))) : String(d)) + '</button>';
            }
            var mins = hh.on ? hh.windows(chosen.key).map(function (x) {
                return [minutesOf(x[0]), minutesOf(x[1])];
            }) : [[480, 1080]], hours = [];
            mins.forEach(function (x) {
                [x[0], 720, 960, x[1] - 60].forEach(function (m) {
                    if (m >= x[0] && m < x[1] && hours.indexOf(m) < 0) {
                        hours.push(m);
                    }
                });
            });
            hours.sort(function (a, b) {
                return a - b;
            });
            var check = schedCheck(c), note = check.past ? s('schedpast') : check.far ? s('schedfar') : check.closed ? s('schedclosed', whenPhrase(c.replyuntil * 1000))
                : check.file ? s('schedfile') : '';
            return '<div class="mrd-pop mrd-pop-programar" role="dialog" aria-label="' + esc(s('schedtitle')) + '"><h5>' + esc(s('schedtitle')) + '</h5>' +
                '<div class="mrd-rem-rapidos">' + chips + '</div>' +
                '<div class="mrd-rem-cal"><div class="mrd-rem-sem">' + heads + '</div><div class="mrd-rem-dias">' + cells + '</div></div>' +
                '<div class="mrd-prog-hora" role="group" aria-label="' + esc(s('schedhour')) + '"><button type="button" class="mrd-prog-paso" data-a="schedtime" data-v="-15" aria-label="' +
                esc(s('schedearlier')) + '">−</button><b>' + esc(fmtTime(check.at)) + '</b><button type="button" class="mrd-prog-paso" data-a="schedtime" data-v="15" aria-label="' +
                esc(s('schedlater')) + '">+</button></div>' +
                '<div class="mrd-rem-rapidos mrd-prog-horas">' + hours.slice(0, 5).map(function (m) {
                    var on = m === chosen.min;
                    return '<button type="button" class="mrd-chip' + (on ? ' mrd-on' : '') + '" data-a="schedtime" data-v="=' + m + '" aria-pressed="' + on + '">' +
                        esc(fmtTime(schedAtOf(chosen.key, m))) + '</button>';
                }).join('') + '</div>' +
                (note ? '<p class="mrd-prog-nota' + (check.past || check.far || check.closed || check.file ? ' mrd-parado' : '') + '" role="status">' + ico('alerta') +
                    '<span>' + esc(note) + (check.closed && (st.extras.templates || []).length ? ' <button type="button" class="mrd-enlace" data-a="schedpick">' +
                    esc(s('schedchoose')) + '</button>' : '') + '</span></p>' : '<p class="mrd-rem-ayuda">' + esc(s('schedhelp')) + '</p>') +
                '<button type="button" class="mrd-btn mrd-primario mrd-rem-ok" data-a="schedgo"' + (check.past || check.far || check.closed || check.file ? ' disabled' : '') + '>' +
                esc(s('schedgo', whenPhrase(check.at))) + '</button></div>';
        }
        function schedChoose(c, what, v) {
            var now = st.sched && st.sched.conv === c.id ? st.sched : null;
            if (!now) {
                return;
            }
            if (what === 'schedquick') {
                now.key = dayKey(+v);
                now.min = minutesOf(+v);
            } else if (what === 'schedday') {
                var old = now.key;
                now.key = +v;
                // Another day keeps its hour, or takes the opening of that day if it was one of them.
                if (now.min === minutesOf(hoursConv(c).remAt(old))) {
                    now.min = minutesOf(hoursConv(c).remAt(now.key));
                }
            } else {
                now.min = String(v).charAt(0) === '=' ? +String(v).slice(1) : Math.min(1425, Math.max(0, Math.round((now.min + (+v)) / 15) * 15));
            }
            refreshWritePop();
            var again = el.chat.querySelector('.mrd-pop-programar [data-a="' + what + '"][data-v="' + v + '"]');
            if (again) {
                again.focus({ preventScroll: true });
            }
        }
        function scheduleIt(btn, c) {
            var check = schedCheck(c), approved = check.approved, t = (st.extras.templates || [])[st.tpl];
            var ed = el.chat.querySelector('.mrd-editor'), wire = '', params = [], name = '', language = '';
            if (approved) {
                params = gapValues();
                if (!params || !t) {
                    st.pop = null;
                    refreshWritePop();
                    return;
                }
                name = t.name;
                language = t.language;
            } else {
                wire = editorText(ed, true);
                if (!wire.trim()) {
                    st.pop = null;
                    refreshWritePop();
                    if (ed) {
                        ed.focus();
                    }
                    toast(s('schedempty'), true);
                    return;
                }
            }
            busy(btn, true);
            tr.call(M.schedule, { conversation: c.id, action: 'add', id: 0, at: Math.round(check.at / 1000), text: wire, name: name, language: language, params: params })
                .then(function (r) {
                    if (r && r.conversation) {
                        mergeConv(r.conversation);
                    }
                    st.pop = null;
                    st.schedPick = 0;
                    st.tpl = -1;
                    st.sched = null;
                    if (approved) {
                        delete (st.kept || {})[keptKey(c)];
                    } else {
                        if (st.drafts[c.id]) {
                            st.drafts[c.id].reply = '';
                        }
                        if (ed) {
                            ed.innerHTML = '';
                        }
                    }
                    toast(s('schedtoast', whenPhrase(check.at)));
                    refreshAfterData();
                    renderComposer(true);
                    pollSoon();
                }).catch(function (e) {
                    busy(btn, false);
                    failToast(e);
                });
        }
        // What waits to go out, above the box: when, whose, what it says, and what can be done with it.
        function schedItem(c, x) {
            var held = x.status === 'held', head, name = x.mine ? s('You') : x.by;
            if (x.status === 'sending') {
                head = s('schedsending');
            } else if (held) {
                head = s({ wrote: 'schedwrote', window: 'schedwindowed', late: 'schedlate', access: 'schedaccess', refused: 'schedrefused', unsure: 'schedunsure' }[x.reason] ||
                    'schedrefused');
            } else {
                head = s('schedgoes', whenPhrase(x.at * 1000));
            }
            var free = !x.approved && !canReply(c);
            return '<li class="mrd-prog' + (held ? ' mrd-parado' : '') + (x.status === 'sending' ? ' mrd-saliendo' : '') + '" data-id="' + x.id + '">' +
                '<span class="mrd-prog-i">' + ico(held ? 'alerta' : 'prog') + '</span><div class="mrd-prog-c"><b>' + esc(head) + '</b><small>' +
                esc(name) + (x.approved ? ' · ' + esc(x.title || s('schedapproved')) : '') + '</small><p>' + esc(x.text) + '</p></div>' +
                (READONLY || x.status === 'sending' ? '' : '<div class="mrd-prog-b">' +
                (free ? '' : '<button type="button" class="mrd-btn mrd-chico" data-a="schednow" data-v="' + x.id + '">' + esc(s('schednow')) + '</button>') +
                '<button type="button" class="mrd-btn mrd-chico" data-a="schedchange" data-v="' + x.id + '">' + esc(s('schedchange')) + '</button>' +
                '<button type="button" class="mrd-btn mrd-chico mrd-quitar" data-a="schedremove" data-v="' + x.id + '">' + esc(s('schedremove')) + '</button></div>') + '</li>';
        }
        function renderScheduled() {
            var where = el.chat.querySelector('.mrd-programados'), c = conv(st.open);
            if (!where) {
                return;
            }
            var list = (c && c.scheduled) || [], h = list.map(function (x) {
                return schedItem(c, x);
            }).join('');
            if (h === st.schedSig) {
                return;
            }
            st.schedSig = h;
            where.innerHTML = h ? '<ul>' + h + '</ul>' : '';
            where.classList.toggle('mrd-con', !!h);
        }
        // Taken back, or sent now; «change» takes it back and leaves it in the box as it was written.
        function schedDo(btn, c, what, id) {
            var one = (c.scheduled || []).filter(function (x) {
                return x.id === id;
            })[0];
            if (!one) {
                return;
            }
            busy(btn, true);
            tr.call(M.schedule, { conversation: c.id, action: what === 'schednow' ? 'now' : 'remove', id: id, at: 0, text: '', name: '', language: '', params: [] })
                .then(function (r) {
                    if (r && r.conversation) {
                        mergeConv(r.conversation);
                    }
                    if (what === 'schedchange') {
                        schedToBox(c, one);
                    } else {
                        toast(s(what === 'schednow' ? 'schedsent' : 'schedremoved'));
                    }
                    refreshAfterData();
                    pollSoon();
                }).catch(function (e) {
                    busy(btn, false);
                    failToast(e);
                });
        }
        function schedToBox(c, one) {
            var tpls = st.extras.templates || [], at = -1, values = {};
            if (one.approved) {
                tpls.forEach(function (t, i) {
                    if (t.name === one.template && t.language === one.language) {
                        at = i;
                    }
                });
                try {
                    values = JSON.parse(one.body) || {};
                } catch (e) {
                    values = {};
                }
                if (at >= 0) {
                    var gaps = {}, key = one.template + '|' + one.language, mine = tplWrite(tpls[at]);
                    gaps[key] = {};
                    Object.keys(values).forEach(function (k) {
                        gaps[key][k] = values[k];
                    });
                    st.kept = st.kept || {};
                    st.kept[keptKey(c)] = { own: mine ? values[mine] || '' : '', gaps: gaps };
                    st.schedPick = canReply(c) ? c.id : 0;
                    st.tpl = at;
                    st.mode = 'reply';
                    renderComposer(true);
                    return;
                }
                return;
            }
            st.drafts[c.id] = st.drafts[c.id] || {};
            if (canReply(c)) {
                // Whatever was half written stays, and what comes back goes first.
                var left = st.drafts[c.id].reply && st.mode === 'reply' ? st.drafts[c.id].reply : '';
                var ed = el.chat.querySelector('.mrd-editor');
                st.mode = 'reply';
                renderComposer(true);
                ed = el.chat.querySelector('.mrd-editor');
                if (ed) {
                    var rest = editorText(ed, false).trim();
                    ed.textContent = one.body + (rest ? '\n' + rest : '');
                    caretToEnd(ed);
                }
                st.drafts[c.id].reply = left;
            } else {
                // The 24 hours are over: the words go to the box of an approved message.
                st.kept = st.kept || {};
                st.kept[keptKey(c)] = { own: one.body, gaps: {} };
                st.unfold[c.id] = true;
                renderComposer(true);
            }
        }
        /* Passing on, in two steps in the same menu: the department, then a line for it if one wants (it goes as an
           internal note) and the button that says what it does. Without a line it is passed on all the same. */
        function popTeams(c) {
            var all = (st.extras.teams && st.extras.teams.length ? st.extras.teams : teams());
            var to = st.pass && st.pass.conv === c.id ? team(st.pass.team) : null;
            if (to) {
                return '<div class="mrd-pop mrd-pop-menu mrd-pop-pasar" role="dialog" aria-label="' + esc(s('passto', to.name)) + '">' +
                    '<div class="mrd-pasar-paso"><button type="button" class="mrd-pasar-volver" data-a="passback" aria-label="' + esc(s('passbackto')) + '">' +
                    ico('izq') + '</button><h5>' + esc(s('passto', to.name)) + '</h5></div>' +
                    '<label class="mrd-pasar-l" for="mrd-pasar-nota">' + esc(s('passsay', to.name)) + ' <small>' + esc(s('passoptional')) + '</small></label>' +
                    '<textarea id="mrd-pasar-nota" class="mrd-pasar-nota" rows="3" maxlength="1000" placeholder="' + esc(s('passph')) + '">' +
                    esc(st.pass.note || '') + '</textarea>' +
                    '<p class="mrd-pasar-ayuda">' + ico('nota') + '<span>' + esc(s('passhelp')) + '</span></p>' +
                    '<div class="mrd-pasar-pie"><button type="button" class="mrd-btn mrd-primario" data-a="passgo" data-v="' + to.id + '">' + ico('pasar') +
                    esc(s('passto', to.name)) + '</button></div></div>';
            }
            var row = function (t) {
                var here = t.id === c.teamid, ppl = peopleIn(t.id).map(firstName);
                return '<button type="button" class="mrd-pop-fila' + (here ? ' mrd-on mrd-aqui' : '') + '" data-a="team" data-v="' + t.id + '"' +
                    (here ? ' aria-current="true"' : '') + '>' + ico(teamIcon(t)) + '<span><b>' + esc(t.name) + '</b><small>' +
                    esc(ppl.length ? ppl.join(', ') : s('teamnobody')) + '</small></span>' + (here ? '<em class="mrd-aqui-t">' + esc(s('passhere')) + '</em>' : '') +
                    '</button>';
            };
            var menu = all.filter(function (t) {
                return !t.internal;
            }), inside = all.filter(function (t) {
                return t.internal;
            });
            return '<div class="mrd-pop mrd-pop-menu mrd-pop-pasar" role="dialog" aria-label="' + esc(s('passtitle')) + '"><h5>' + esc(s('passtitle')) + '</h5>' +
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
            if (m.kind === 'note' && m.call) {
                return callLineHtml(m, time);
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
                // Out of the hours of the department of the conversation, when it came.
                var hh = hoursConv(conv(st.open)), night = hh.on && at && !hh.openAt(at);
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
        // What did not go or did not arrive, in the charcoal of «No le llegó», with its sign: red is for long waits.
        function failHtml(m) {
            if (m.failed) {
                return '<div class="mrd-fallo-l">' + ico('nollego') + '<span>' + esc(s('notsent')) + '</span>' +
                    '<button type="button" data-a="retry" data-v="' + esc(m.id) + '">' + esc(s('retry')) + '</button></div>';
            }
            if (m.status === 'failed') {
                // Why, in words the team can act on; what WhatsApp said, for whoever needs it, on the pointer.
                if (m.reason) {
                    return '<div class="mrd-fallo-l" title="' + esc(m.error) + '">' + ico('nollego') + '<span><b>' +
                        esc(s('failedplain')) + '.</b> ' + esc(m.reason) + '</span></div>';
                }
                return '<div class="mrd-fallo-l">' + ico('nollego') + '<span>' +
                    esc(m.error ? s('failed', m.error) : s('failedplain')) + '</span></div>';
            }
            return '';
        }
        /* A call written down: who called and when, how it went, and what was said. */
        function callLineHtml(m, time) {
            var who = m.author && m.author.me ? me.firstname || firstName(m.author) : firstName(m.author);
            var ok = m.call === 'answered';
            return '<div class="mrd-llamada mrd-llamada-' + (ok ? 'si' : 'no') + (m.pending ? ' mrd-pendiente' : '') +
                '" data-id="' + esc(m.id) + '"><span class="mrd-llamada-i">' + ico(ok ? 'telsi' : 'telno') + '</span>' +
                '<div class="mrd-llamada-t"><div class="mrd-llamada-cab"><b>' + esc(s('callby', who || '')) + '</b>' +
                '<span class="mrd-llamada-res">' + esc(ok ? s('callwasanswered') : s('callmissed')) + '</span>' +
                (time ? '<span class="mrd-llamada-h">' + esc(time) + '</span>' : '') + '</div>' +
                (m.text ? '<p>' + textHtml(m.text, false) + '</p>' : '') + '</div></div>';
        }
        // The approved message for a call nobody answered, on the line of the last one, while it is still due.
        function placeCallOffer() {
            var hilo = hiloEl();
            if (!hilo) {
                return;
            }
            Array.prototype.forEach.call(hilo.querySelectorAll('.mrd-llamada-ofrece'), function (n) {
                n.remove();
            });
            var id = callOfferId(conv(st.open)), tpl = (st.extras.templates || [])[callTpl()];
            var node = id && tpl ? hilo.querySelector('.mrd-llamada[data-id="' + cssEsc(id) + '"] .mrd-llamada-t') : null;
            if (node) {
                node.insertAdjacentHTML('beforeend', '<button type="button" class="mrd-btn mrd-chico mrd-llamada-ofrece" ' +
                    'data-a="calltpl">' + ico('mensaje') + esc(s('calloffer', tplTitle(tpl))) + '</button>');
            }
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
        /* On top of a conversation, what came before with the same person: the conversations resolved before (also those
           brought from the app of the phone), the last one a tap away. */
        // The conversation of now with the same person, if the one open is an old one, already resolved.
        function newerOf(c) {
            if (!c || c.section !== 'resolved' || !st.ficha || st.fichaFor !== st.open) {
                return null;
            }
            return (st.ficha.conversations || []).filter(function (o) {
                return o.id !== c.id && o.status !== 'resolved' && (o.at || 0) > (c.lastactivity || 0);
            })[0] || null;
        }
        function renderBefore() {
            var hilo = hiloEl(), c = conv(st.open);
            if (!hilo) {
                return;
            }
            var was = hilo.querySelector('.mrd-antes');
            var newer = newerOf(c);
            if (newer) {
                // An old one, open to read: it says so, and takes back to the one of now.
                var old = '<div class="mrd-antes mrd-vieja">' + ico('reloj') + '<span>' + esc(s('oldconv', dayText((c.lastactivity || 0) * 1000))) +
                    '</span><button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="otherconv" data-v="' + newer.id + '">' +
                    esc(s('backtonow')) + '</button></div>';
                if (was) {
                    was.outerHTML = old;
                } else {
                    hilo.insertAdjacentHTML('afterbegin', old);
                }
                return;
            }
            var before = c && st.ficha && st.fichaFor === st.open ? (st.ficha.conversations || []).filter(function (o) {
                return o.id !== st.open && o.status === 'resolved';
            }) : [];
            if (!before.length) {
                if (was) {
                    was.remove();
                }
                return;
            }
            var last = before[0];
            var html = '<div class="mrd-antes">' + ico('reloj') + '<span>' + esc(s(before.length === 1 ? 'before1' : 'before', {
                name: contactFirst(c), n: before.length, when: dayText((last.at || 0) * 1000) })) + '</span>' +
                '<button type="button" class="mrd-btn mrd-chico" data-a="otherconv" data-v="' + last.id + '">' + esc(s('beforesee')) + '</button></div>';
            if (was) {
                was.outerHTML = html;
            } else {
                hilo.insertAdjacentHTML('afterbegin', html);
            }
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
            renderBefore();
            placeReadBy();
            placeUndo();
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
                placeUndo();
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
            placeUndo();
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
                // One that WhatsApp did not deliver is not waited for: another way is needed.
                if (m.kind === 'outgoing' && m.template && !m.failed && m.status !== 'failed') {
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
            if (canReply(c) && st.schedPick !== c.id) {
                return 'reply';
            }
            if (canReply(c)) {
                // For an hour past the 24: an approved message, still with the box of writing to go back to.
                return st.tpl >= 0 ? 'preview' : 'pick';
            }
            if (st.tpl >= 0) {
                return 'preview';
            }
            return lastTemplateWaiting(c) || (st.justStarted === c.id && !st.pickAgain) ? 'waiting' : 'pick';
        }
        function composerSig(c) {
            var newer = newerOf(c), state = composerState(c);
            return [st.open, state, c.assignee ? 1 : 0, c.section, st.tpl, st.unfold[c.id] ? 1 : 0, onlyReading(c) ? 1 : 0,
                optoutBlocked(c.contact && c.contact.phone, st.fichaFor === c.id && st.ficha ? st.ficha.optout : null) ? 1 : 0, state === 'pick' ? tplSigs() : '', st.pop,
                newer ? newer.id : 0, draftSig(c)].join('|');
        }
        /* -------- «Propose an answer»: a draft the person reads, changes and sends; it never goes by itself -------- */
        function draftUi(c) {
            return st.draftUi && c && st.draftUi.conv === c.id ? st.draftUi : null;
        }
        function draftSig(c) {
            var ui = draftUi(c);
            return ui ? (ui.busy ? 'b' : '') + (ui.slow ? 's' : '') + (ui.shown ? 'v' : '') : '';
        }
        function draftButton(c) {
            if (!st.extras.draft || READONLY) {
                return '';
            }
            var ui = draftUi(c), busy = !!(ui && ui.busy);
            return '<button type="button" class="mrd-herr-b mrd-proponer' + (busy ? ' mrd-ocupado' : '') + '" data-a="draft" title="' +
                esc(s('drafthint')) + '"' + (busy ? ' disabled aria-busy="true"' : '') + '>' + ico('proponer') + '<span class="mrd-t">' +
                esc(busy ? (ui.slow ? s('draftslow') : s('drafting')) : s('draft')) + '</span></button>';
        }
        function draftBand(c) {
            var ui = draftUi(c);
            if (!ui || !ui.shown || ui.busy) {
                return '';
            }
            return '<div class="mrd-propuesta" role="status">' + ico('proponer') + '<span>' + esc(s('draftband')) + '</span>' +
                '<span class="mrd-propuesta-b"><button type="button" class="mrd-btn mrd-chico" data-a="draftagain">' + esc(s('draftagain')) +
                '</button><button type="button" class="mrd-btn mrd-chico" data-a="draftundo">' + esc(s('draftundo')) + '</button></span></div>';
        }
        function popPolicy() {
            return '<div class="mrd-pop mrd-pop-ia" role="dialog" aria-label="' + esc(s('draftpolicytitle')) + '"><h5>' + esc(s('draftpolicytitle')) +
                '</h5><div class="mrd-pop-ia-texto">' + (st.draftPolicy || '') + '</div><div class="mrd-pop-ia-b">' +
                '<button type="button" class="mrd-btn mrd-chico" data-a="draftdecline">' + esc(s('draftdecline')) + '</button>' +
                '<button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="draftaccept">' + esc(s('draftaccept')) + '</button></div></div>';
        }
        function requestDraft(accept) {
            var c = conv(st.open), ed = el.chat.querySelector('.mrd-editor');
            if (!c || (st.draftUi && st.draftUi.busy)) {
                return;
            }
            var before = draftUi(c), hint = ed ? editorText(ed, false) : '', prev = ed ? ed.innerHTML : '';
            if (before && before.shown) {
                // «Another one»: what the person had before the first proposal, and the same hint.
                hint = before.hint;
                prev = before.prev;
            }
            var id = c.id;
            st.draftUi = { conv: id, busy: true, slow: false, shown: false, hint: hint, prev: prev };
            st.pop = null;
            renderComposer(true);
            var slowT = setTimeout(function () {
                if (st.draftUi && st.draftUi.conv === id && st.draftUi.busy) {
                    st.draftUi.slow = true;
                    renderComposer(true);
                }
            }, 12000);
            var done = function (text) {
                var ui = st.draftUi;
                if (text === null) {
                    st.draftUi = null;
                } else {
                    ui.busy = false;
                    ui.slow = false;
                    ui.shown = true;
                }
                if (st.open !== id) {
                    if (text !== null) {
                        (st.drafts[id] || (st.drafts[id] = {})).reply = esc(text);
                    }
                    return;
                }
                renderComposer(true);
                var box = el.chat.querySelector('.mrd-editor');
                if (box && text !== null) {
                    box.textContent = text;
                    saveDraft();
                    box.focus();
                    caretToEnd(box);
                }
            };
            tr.call(M.draft, { conversation: id, hint: hint, accept: !!accept }).then(function (r) {
                clearTimeout(slowT);
                if (!st.draftUi || st.draftUi.conv !== id) {
                    return r;
                }
                if (r.status === 'ok') {
                    st.extras.draftpolicy = true;
                    done(r.text);
                } else if (r.status === 'policy') {
                    st.draftPolicy = r.policy;
                    done(null);
                    if (st.open === id) {
                        st.pop = 'ia';
                        renderComposer(true);
                    }
                } else {
                    done(null);
                    toast(r.status === 'unavailable' ? s('draftunavailable') : s('draftfailed'), true);
                }
                return r;
            }).catch(function () {
                clearTimeout(slowT);
                if (st.draftUi && st.draftUi.conv === id) {
                    done(null);
                }
                toast(s('draftfailed'), true);
            });
        }
        function undoDraft() {
            var c = conv(st.open), ui = draftUi(c), ed = el.chat.querySelector('.mrd-editor');
            if (!ui) {
                return;
            }
            if (ed) {
                ed.innerHTML = ui.prev;
            }
            st.draftUi = null;
            renderComposer(true);
            var box = el.chat.querySelector('.mrd-editor');
            if (box) {
                box.focus();
                caretToEnd(box);
            }
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
            var hilo0 = hiloEl(), wasEnd = hilo0 ? atBottom(hilo0) : false, held = heldComposer();
            renderComposerNow(force);
            giveComposerBack(held);
            if (wasEnd && hilo0 && hilo0.isConnected) {
                stickToBottom(hilo0);
            }
        }
        /* The composer is drawn again when something about it changes (the approved messages arriving a moment after the
           conversation opens, the conversation being taken): whoever is typing keeps the box, the caret and the gaps of the
           message they are filling in. */
        function heldComposer() {
            var box2 = el.chat.querySelector('.mrd-escribir'), a = document.activeElement, tpls = st.extras.templates || [];
            if (!box2) {
                return null;
            }
            var held = { conv: st.open, mode: st.mode, gaps: null, focus: null };
            var gaps = box2.querySelectorAll('.mrd-hueco');
            if (gaps.length && tpls[st.tpl] && tpls[st.tpl].name === st.gapsTpl) {
                held.gaps = { tpl: st.gapsTpl, values: {} };
                Array.prototype.forEach.call(gaps, function (g) {
                    var k = g.getAttribute('data-k');
                    if (!(k in held.gaps.values)) {
                        held.gaps.values[k] = g.textContent;
                    }
                });
            }
            if (a && a !== box2 && box2.contains(a) && (a.classList.contains('mrd-editor') || a.classList.contains('mrd-hueco'))) {
                held.focus = { el: a, gap: a.classList.contains('mrd-hueco') ? a.getAttribute('data-k') : null, caret: caretOffset(a) };
            }
            return held;
        }
        function giveComposerBack(held) {
            var box2 = el.chat.querySelector('.mrd-escribir'), tpls = st.extras.templates || [];
            if (!held || !box2 || held.conv !== st.open) {
                return;
            }
            if (held.gaps && tpls[st.tpl] && tpls[st.tpl].name === held.gaps.tpl) {
                var changed = false;
                Array.prototype.forEach.call(box2.querySelectorAll('.mrd-hueco'), function (g) {
                    var k = g.getAttribute('data-k');
                    if (k in held.gaps.values && g.textContent !== held.gaps.values[k]) {
                        g.textContent = held.gaps.values[k];
                        changed = true;
                    }
                });
                if (changed) {
                    updateGaps();
                }
            }
            var f = held.focus;
            if (!f || (f.el.isConnected && document.activeElement === f.el)) {
                return;
            }
            var target = null;
            if (f.gap === null && st.mode === held.mode) {
                target = box2.querySelector('.mrd-editor');
            } else if (f.gap !== null) {
                target = Array.prototype.filter.call(box2.querySelectorAll('.mrd-hueco'), function (g) {
                    return g.getAttribute('data-k') === f.gap;
                })[0] || null;
            }
            if (target) {
                placeCaret(target, f.caret);
            }
        }
        function caretOffset(node) {
            var sel = window.getSelection();
            if (!sel || !sel.rangeCount || !node.contains(sel.focusNode)) {
                return -1;
            }
            var r = document.createRange();
            r.selectNodeContents(node);
            r.setEnd(sel.focusNode, sel.focusOffset);
            return r.toString().length;
        }
        function placeCaret(node, offset) {
            node.focus({ preventScroll: true });
            var sel = window.getSelection();
            if (!sel) {
                return;
            }
            var r = document.createRange(), left = offset, walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT), t, done = false;
            while (offset >= 0 && (t = walker.nextNode())) {
                if (t.length >= left) {
                    r.setStart(t, left);
                    r.collapse(true);
                    done = true;
                    break;
                }
                left -= t.length;
            }
            if (!done) {
                r.selectNodeContents(node);
                r.collapse(false);
            }
            sel.removeAllRanges();
            sel.addRange(r);
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
            var newer = newerOf(c);
            var areas = [box2, hiloEl()].filter(Boolean), areaState = function (cls) {
                areas.forEach(function (area) {
                    area.classList.remove('mrd-eligiendo', 'mrd-con-previa');
                    if (cls) {
                        area.classList.add(cls);
                    }
                });
            };
            areaState('');
            if (newer && !READONLY) {
                // Whoever came to an old one answers in the one of now, never here.
                box2.innerHTML = '<div class="mrd-solo-leer mrd-vieja-pie">' + ico('reloj') + '<span>' + esc(s('oldconvfoot')) + '</span>' +
                    '<button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="otherconv" data-v="' + newer.id + '">' +
                    esc(s('backtonow')) + '</button></div>';
                return;
            }
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
                    (note ? '' : draftBand(c)) + '<div class="mrd-caja' + (note ? ' mrd-nota-c' : '') + (!note && draftUi(c) && draftUi(c).busy ? ' mrd-redactando' : '') +
                    '">' + (note ? '<div class="mrd-aviso-nota">' + ico('nota') + esc(s('notehiddenbox')) +
                    '</div>' : '') + '<div class="mrd-editor" contenteditable="' + (!note && draftUi(c) && draftUi(c).busy ? 'false" aria-busy="true' : 'true') +
                    '" role="textbox" aria-multiline="true" aria-label="' + esc(ph) +
                    '" data-vacio="' + esc(ph) + '">' + draftOf(state) + '</div>' + chosenFileHtml() + '<div class="mrd-herr">' +
                    '<button type="button" class="mrd-herr-b" data-a="attach">' + ico('clip') + '<span class="mrd-t">' + esc(s('attach')) +
                    '</span></button><input type="file" class="mrd-archivo" hidden>' +
                    (note ? '<button type="button" class="mrd-herr-b' + (st.pop === 'mencion' ? ' mrd-on' : '') + '" data-a="pop" data-v="mencion">' +
                    ico('arroba') + '<span class="mrd-t">' + esc(s('mention')) + '</span></button>'
                        : '<button type="button" class="mrd-herr-b' + (st.pop === 'guardadas' ? ' mrd-on' : '') + '" data-a="pop" data-v="guardadas">' +
                    ico('guardadas') + '<span class="mrd-t">' + esc(s('canned')) + '</span></button>' + draftButton(c)) +
                    schedButton(c, note) + '<button type="button" class="mrd-btn mrd-primario" data-a="send">' + (note ? ico('nota') + esc(s('savenote')) : ico('enviar') + esc(s('send'))) +
                    '</button></div></div>';
                h += st.pop === 'guardadas' ? popCanned(c) : st.pop === 'mencion' ? popMention() : st.pop === 'ia' && !note ? popPolicy()
                    : st.pop === 'programar' && !note ? popSchedule(c) : '';
                box2.innerHTML = h;
                var ed = box2.querySelector('.mrd-editor');
                if (ed.innerHTML === '<br>') {
                    ed.innerHTML = '';
                }
                return;
            }
            if (state === 'waiting') {
                box2.innerHTML = '<div class="mrd-esperando"><span class="mrd-reloj">' + ico('mensaje') + '</span><div><b>' +
                    esc(st.justStarted === c.id ? s('newsentwait', name) : s('sentwait', name)) +
                    '</b><p>' + esc(s('sentwaithelp')) + '</p></div><div class="mrd-esperando-b"><button type="button" class="mrd-btn mrd-chico" data-a="mode" data-v="note">' +
                    ico('nota') + esc(s('tabnote')) + '</button><button type="button" class="mrd-btn mrd-chico" data-a="pickagain">' + ico('mensaje') +
                    esc(s('sendanother')) + '</button></div></div>';
                return;
            }
            var tpls = st.extras.templates || [];
            if (state === 'preview' && tpls[st.tpl]) {
                st.gapsTpl = tpls[st.tpl].name;
                areaState('mrd-con-previa');
                box2.innerHTML = previewHtml(c, tpls[st.tpl]) + (st.pop === 'programar' ? popSchedule(c) : '');
                focusGap(box2);
                return;
            }
            var lastIn = c.lastincoming ? c.lastincoming * 1000 : 0;
            // Only to read (resolved, or our message the last one): the 24 hours in one line, unfolded only to write.
            if (lastIn && !st.unfold[c.id] && st.schedPick !== c.id && onlyReading(c)) {
                box2.innerHTML = '<div class="mrd-24-plegada">' + ico('reloj') + '<span><b>' + esc(s('foldedover')) + '</b> ' +
                    esc(s('foldedtail')) + '</span><button type="button" class="mrd-btn mrd-chico" data-a="unfold" aria-expanded="false">' + esc(s('foldedwrite')) +
                    ico('abajo') + '</button><button type="button" class="mrd-24-nota" data-a="mode" data-v="note">' + ico('nota') + esc(s('tabnote')) +
                    '</button></div>';
                return;
            }
            areaState(tpls.length ? 'mrd-eligiendo' : '');
            if (st.schedPick === c.id) {
                h = '<div class="mrd-retomar-pie mrd-prog-volver"><button type="button" data-a="schedback">' + ico('izq') + esc(s('schedback')) + '</button></div>';
            } else {
                h = '';
            }
            h += '<div class="mrd-retomar-cab"><span class="mrd-reloj">' + ico('reloj') + '</span><div><b>' + esc(st.schedPick === c.id ? s('schedpicktitle')
                : lastIn ? s('closedtitle') : s('starttitle')) + '</b>' +
                '<p>' + esc(st.schedPick === c.id ? s('schedpickhelp', whenPhrase(schedAt(c)))
                : lastIn ? s('closedlast', { name: name, when: whenPhrase(lastIn) }) : s('closednever', name)) + '</p>' +
                (tpls.length ? '<p class="mrd-paga">' + ico('gs-euro') + '<span>' + esc(s('payline')) + ' <strong>' + esc(s('paylinewho')) + '</strong>' +
                    esc(s('paylinetail')) + '</span></p>' : '') + '</div></div>';
            var said = st.fichaFor === c.id && st.ficha ? st.ficha.optout : null, held = optoutBlocked(c.contact && c.contact.phone, said);
            h += optoutBand(c.contact && c.contact.phone, said, false);
            if (tpls.length) {
                var langs = {};
                tpls.forEach(function (t) {
                    langs[t.language] = true;
                });
                var showLang = Object.keys(langs).length > 1;
                // The free message is another kind of thing, and the dearest: a line under the cards, not one more card.
                var free = tpls.map(function (t, i) {
                    return i;
                }).filter(function (i) {
                    return isFreeTpl(tpls[i]);
                });
                var order = tpls.map(function (t, i) {
                    return i;
                }).filter(function (i) {
                    return free.indexOf(i) < 0;
                }).sort(function (a, b) {
                    var ka = !!tpls[a].start === !lastIn ? 0 : 1, kb = !!tpls[b].start === !lastIn ? 0 : 1;
                    return ka - kb || byPrice(tpls[a], tpls[b]) || a - b;
                });
                h += (order.length ? tplGroups(order.map(function (i) {
                    return tpls[i];
                }), 'tpl', c, held) : '') + free.map(function (i) {
                    var t = tpls[i];
                    return '<button type="button" class="mrd-libre" data-a="tpl" data-v="' + i + '"' + (held ? ' disabled' : '') + '>' + ico('lapiz') + '<span>' +
                        esc(order.length ? s('freenone') : '') + ' <b>' + esc(s('freewrite')) + (showLang ? ' · ' + esc(t.language) : '') + '</b></span>' +
                        catPill(t) + '</button>';
                }).join('');
            } else {
                h += '<p class="mrd-ayuda">' + esc(s('notemplates')) + '</p>';
            }
            h += '<div class="mrd-retomar-pie">' + esc(s('teamnolimit')) + ' <button type="button" data-a="mode" data-v="note">' + ico('nota') +
                esc(s('writenotebtn')) + '</button></div>';
            box2.innerHTML = h;
        }
        /* Whether the conversation is open only to be read: resolved, or with our message as the last one. When the family
           wrote last and it is not resolved, it waits for an answer and the approved messages show unfolded. */
        function onlyReading(c) {
            if (c.section === 'resolved') {
                return true;
            }
            for (var i = st.msgs.length - 1; i >= 0; i--) {
                var m = st.msgs[i];
                if (m.kind === 'incoming') {
                    return false;
                }
                if (m.kind === 'outgoing' || m.kind === 'bot') {
                    return true;
                }
            }
            return false;
        }
        /* The free message: the whole text written by whoever sends it, around one gap. The server may say so; otherwise,
           a message with one gap, no buttons and little text of its own. */
        function isFreeTpl(t) {
            if (t.free !== undefined) {
                return !!t.free;
            }
            var keys = {};
            (t.params || []).forEach(function (p) {
                keys[p.key] = true;
            });
            var rest = String(t.body || '').replace(/\{\{\s*[\w.]+\s*\}\}/g, '').replace(/\s+/g, ' ').trim();
            var only = Object.keys(keys)[0];
            return Object.keys(keys).length === 1 && !/name|nombre|nom\b/i.test(only) && !greets(t, only) && !(t.buttons || []).length && rest.length < 90;
        }
        // The gap of an approved message where whoever sends it writes the message itself; empty if it comes written.
        function tplWrite(t) {
            return t.write || (isFreeTpl(t) ? (t.params[0] || {}).key || '' : '');
        }
        function sharedPrefix() {
            var names = (st.extras.templates || []).map(function (t) {
                return String(t.name || '');
            });
            if (names.length < 2) {
                return '';
            }
            var first = names[0], cut = first.indexOf('_') + 1;
            if (!cut) {
                return '';
            }
            var prefix = first.slice(0, cut);
            return names.every(function (n) {
                return n.indexOf(prefix) === 0 && n.length > prefix.length;
            }) ? prefix : '';
        }
        function tplTitle(t) {
            if (t.title) {
                return t.title;
            }
            var name = String(t.name || ''), prefix = sharedPrefix();
            return cap(name.slice(prefix.length).replace(/_+/g, ' ').trim());
        }
        function greets(t, k) {
            var key = String(k).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var re = new RegExp('\\b(?:hola|buenas|buenos d[ií]as|hello|hi|hey|ol[aá]|bonjour|salut|ciao|bon dia|kaixo)[\\s,!¡]+\\{\\{\\s*' + key + '\\s*\\}\\}', 'i');
            return re.test(String((t && t.body) || ''));
        }
        /* What goes in a gap, never «write here»: as the organisation says it; the name after a greeting; its key in words
           («dia» is «el día»); «el hueco 2» for a number; the key itself, as written. */
        var GAP_WORDS = {
            dia: 'day', day: 'day', fecha: 'date', date: 'date', hora: 'time', time: 'time', hour: 'time', documento: 'document',
            document: 'document', asunto: 'subject', subject: 'subject', tema: 'subject', topic: 'subject', lugar: 'place', place: 'place',
            curso: 'course', course: 'course', clase: 'course', importe: 'amount', amount: 'amount', motivo: 'reason', reason: 'reason',
            alumno: 'student', student: 'student', mensaje: 'message', message: 'message', texto: 'message', text: 'message'
        };
        function gapLabel(p, t) {
            if (p.label) {
                return p.label;
            }
            var key = String(p.key || '');
            if (/name|nombre|nom\b/i.test(key) || greets(t, key)) {
                return s('gapnamename');
            }
            var word = GAP_WORDS[folded(key).replace(/[^a-z]/g, '')];
            if (word) {
                return s('gapw' + word);
            }
            return /^\d+$/.test(key) ? s('gapnumber', key) : key.replace(/[_.]+/g, ' ');
        }
        // The name of a gap inside its sentence: after «el», «del» or «a las» its own article would read twice («el el día»).
        function gapHere(label, before) {
            return /(?:^|[\s(«])(?:el|la|los|las|del|al|the)\s*$/i.test(before) ? String(label).replace(/^(?:el|la|los|las|the)\s+/i, '') : label;
        }
        /* What a gap brings already filled, and can be changed: the name after a greeting, and the day of the family's
           last message where the gap is that day (as the server marks it, or as its name or the words before it say). */
        function tplValue(p, c, t) {
            if (/name|nombre|nom\b/i.test(p.key || '') || greets(t, p.key)) {
                return firstName(c && c.contact) || '';
            }
            if (c && c.lastincoming && lastDayGap(p, t)) {
                return lastDayText(c.lastincoming * 1000, p.example);
            }
            if (isCallTpl(t) && p.key === callReasonKey(t)) {
                return callReason(c);
            }
            return '';
        }
        /* The approved message after a call nobody answered says what it was about. Its gap gets what the file says when
           it is sure (the one place the family asks for); otherwise it stays empty with its name, never an example that
           reads as a datum of this family. */
        function isCallTpl(t) {
            return !!(t && cfg.calltemplate && t.name === cfg.calltemplate);
        }
        function callReasonKey(t) {
            var gap = (t.params || []).filter(function (p) {
                return !/name|nombre|nom\b/i.test(p.key || '') && !greets(t, p.key);
            })[0];
            return gap ? gap.key : '';
        }
        function callReason(c) {
            var f = c && st.fichaFor === c.id && st.ficha ? st.ficha : null;
            var asking = f ? (f.children || []).filter(function (k) {
                return k.applying && String(k.group || '').trim();
            }) : [];
            // One place asked for, and only one: «1.º de ESO, curso 2027-2028» is «a place in 1.º de ESO».
            return asking.length === 1 ? s('callgapplace', String(asking[0].group).split(',')[0].trim()) : '';
        }
        function lastDayGap(p, t) {
            if (p.fill) {
                return p.fill === 'lastincoming';
            }
            var said = folded([p.label, p.key].join(' '));
            if (/(su|ultimo|last|their) (mensaje|message|consulta)|mensaje del|message of|written on|escrib/.test(said)) {
                return true;
            }
            var key = String(p.key).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var before = new RegExp('(mensaje|consulta|escrito|message|enquiry|query)\\s+(del?|de el|of|on|from)\\s*\\{\\{\\s*' + key + '\\s*\\}\\}', 'i');
            return before.test(folded(t.body || ''));
        }
        // The day as the example writes it: with the weekday if it has one («lunes 16 de noviembre»), or without.
        function lastDayText(ms, example) {
            var d = new Date(ms), ex = folded(example || '');
            var weekday = /lunes|martes|miercoles|jueves|viernes|sabado|domingo|monday|tuesday|wednesday|thursday|friday|saturday|sunday/.test(ex);
            return weekday || !ex ? fWeekday.format(d) + ' ' + fDayMonth.format(d) : fDayMonth.format(d);
        }
        function tplPlain(t, c) {
            var params = {};
            (t.params || []).forEach(function (p) {
                params[p.key] = tplValue(p, c, t) || p.example || '…';
            });
            return String(t.body || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, k) {
                return params[k] !== undefined ? params[k] : m;
            });
        }
        /* What is half written in an approved message stays, as the draft of any answer: by the person it is for, whatever
           the way out of it was (choosing another message, another conversation, Escape). The message itself goes along to
           any other card where it is written; what went in the other gaps, only to its own. It goes away when it is sent. */
        function keptKey(c) {
            return c && c.id ? 'c' + c.id : 'p' + ((c && c.contact && c.contact.phone) || '');
        }
        function keptOf(c) {
            st.kept = st.kept || {};
            return st.kept[keptKey(c)] || null;
        }
        function keepGaps() {
            var tpls = st.extras.templates || [], c = st.nueva ? newConvLike() : conv(st.open), t = tpls[st.nueva ? st.nueva.tpl : st.tpl];
            if (!c || !t || (st.nueva && !st.nueva.pick)) {
                return;
            }
            var mine = tplWrite(t), name = t.name + '|' + t.language, kept = keptOf(c) || { own: '', gaps: {} };
            kept.gaps[name] = {};
            Array.prototype.forEach.call(el.chat.querySelectorAll('.mrd-hueco'), function (g) {
                var k = g.getAttribute('data-k');
                if (k === mine) {
                    kept.own = gapText(g);
                } else {
                    kept.gaps[name][k] = gapText(g);
                }
            });
            st.kept[keptKey(c)] = kept;
        }
        function gapStart(p, c, t) {
            var kept = keptOf(c), said = kept && kept.gaps[t.name + '|' + t.language];
            if (kept && p.key === tplWrite(t)) {
                return kept.own;
            }
            return said && p.key in said ? said[p.key] : tplValue(p, c, t);
        }
        function previewHtml(c, t) {
            var params = {}, mine = tplWrite(t);
            (t.params || []).forEach(function (p) {
                params[p.key] = p;
            });
            var body = esc(t.body || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, k, at, whole) {
                var p = params[k] || { key: k, example: '' };
                var label = gapLabel(p, t), own = k === mine, here = gapHere(label, whole.slice(0, at));
                return '<span class="mrd-hueco' + (own ? ' mrd-hueco-mensaje' : '') + '" contenteditable="true" role="textbox" data-k="' + esc(k) +
                    '" data-nombre="' + esc(own ? s('tplwritehere') : here) +
                    '" data-falta="' + esc(label) + '" data-ejemplo="' + esc(own ? s('tplwritehere') : isCallTpl(t) ? here : p.example || here) +
                    '" aria-label="' + esc(s('gap', label)) + '"' + (own ? ' aria-multiline="true"' : '') + '>' + esc(gapStart(p, c, t)) + '</span>';
            }).replace(/(<span class="mrd-hueco mrd-hueco-mensaje"[^>]*>[^<]*<\/span>)([.,;:!?…)»]+)/g, function (m, gap, mark) {
                // The stop after the box of the message would open the next line on its own: it goes out, not in sight.
                return gap + '<span class="mrd-tras">' + mark + '</span>';
            }).replace(/(<span class="mrd-hueco"[^>]*>[^<]*<\/span>)([.,;:!?…)»]+)/g, function (m, gap, mark) {
                // A gap and the stop after it go together: the line never starts with the stop.
                return '<span class="mrd-hueco-y">' + gap + mark + '</span>';
            });
            var btns = (t.buttons || []).map(function (b) {
                return '<span>' + esc(b && b.text !== undefined ? b.text : b) + '</span>';
            }).join('');
            var empty = (t.params || []).filter(function (p, i, all) {
                return !gapStart(p, c, t) && all.map(function (x) {
                    return x.key;
                }).indexOf(p.key) === i;
            }).map(function (p) {
                return gapLabel(p, t);
            });
            return '<div class="mrd-previa"><div class="mrd-previa-t">' + ico('mensaje') + esc(s('preview', contactFirst(c))) +
                '<button type="button" data-a="tplback">' + esc(s('pickother')) + '</button></div>' +
                '<div class="mrd-msg mrd-nos mrd-plantilla">' + (t.header ? '<span class="mrd-cabecera-wa">' + esc(t.header) + '</span>' : '') +
                '<p>' + body + '</p>' + (t.footer ? '<span class="mrd-pie-wa">' + esc(t.footer) + '</span>' : '') +
                (btns ? '<div class="mrd-botones-wa">' + btns + '</div>' : '') + '</div>' +
                (mine ? '<p class="mrd-una-linea" role="status"></p>' : '') +
                '<div class="mrd-previa-pie"><button type="button" class="mrd-btn mrd-primario" data-a="tplsend" aria-disabled="' + !!empty.length + '">' +
                ico('enviar') + esc(s('sendtemplate')) + '</button>' + (c.id && !st.nueva ? schedButton(c, false) : '') + '<small class="mrd-huecos-estado' + (empty.length ? ' mrd-faltan' : '') + '" data-a="gapnext">' +
                esc(gapsText(empty)) + '</small>' + (catPill(t) ? '<small class="mrd-cuesta">' + catPill(t) + '<span>' + esc(s('paidby')) + '</span></small>' : '') +
                '</div></div>';
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
            } else if (st.pop === 'eqpasar' && st.room && !conv(st.open)) {
                box2.insertAdjacentHTML('beforeend', popPassConv());
            } else if (st.pop === 'programar' && conv(st.open)) {
                box2.insertAdjacentHTML('beforeend', popSchedule(conv(st.open)));
            }
            var later = box2.querySelector('[data-v="programar"]');
            if (later) {
                later.classList.toggle('mrd-on', st.pop === 'programar');
                later.setAttribute('aria-expanded', String(st.pop === 'programar'));
            }
            var pass = box2.querySelector('[data-v="eqpasar"]');
            if (pass) {
                pass.setAttribute('aria-expanded', String(st.pop === 'eqpasar'));
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
            var kind = f.kind || 'unknown', o, marks = f.marks || [];
            if (kind === 'both') {
                o = origin('casa', s('kindfamily')) + origin('puerta', s('kindboth'));
            } else if (marks.length && !(marks.length === 1 && marks[0] === 'family' && f.familyfile)) {
                // Every mark, with its icon: «Personal · Antiguo alumno · Familia».
                o = marks.map(function (m) {
                    var op = QF.filter(function (x) {
                        return x[0] === m;
                    })[0];
                    return origin(op ? op[1] : 'yo', s('mark' + m));
                }).join('');
            } else {
                var k = KIND_FILE[kind] || KIND_FILE.unknown;
                o = origin(k[0], s(k[1]));
            }
            var phoneRow = (!f.familyfile && kind !== 'both' && f.phone) ? '<div class="mrd-tel-fila"><span>' + esc(fmtPhone(f.phone)) + '</span>' +
                callButton(f.phone) + '</div>' : '';
            var changed = f.changed && f.changed.text && (!f.changed.until || f.changed.until * 1000 > nowMs())
                ? '<p class="mrd-recien">' + ico('check') + esc(f.changed.text) + '</p>' : '';
            var said = f.optout, now = optoutNow(said), trail = optoutTrailHtml(said);
            var ask = st.optoutAsk === (c && c.id);
            changed += now ? '<div class="mrd-baja">' + ico('mudo') + '<div><b>' + esc(now.text) + '</b>' + trail + '</div></div>'
                : (trail ? '<details class="mrd-baja-antes"><summary>' + esc(s('trailtitle')) + '</summary>' + trail + '</details>' : '') +
                (READONLY || !c || !c.id ? '' : ask ? '<div class="mrd-baja mrd-baja-pregunta">' + ico('mudo') + '<div><b>' +
                    esc(s('optoutask', dayText(nowMs()))) + '</b><p>' + esc(s('optoutaskhelp')) + '</p><div class="mrd-botones">' +
                    '<button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="optoutmark">' + esc(s('optoutdo')) + '</button>' +
                    '<button type="button" class="mrd-btn mrd-chico" data-a="optoutnoask">' + esc(s('optoutcancel')) + '</button></div></div></div>'
                    : '<button type="button" class="mrd-enlace mrd-baja-marcar" data-a="optoutask">' + esc(s('optoutmark')) + '</button>');
            return '<div class="mrd-ficha-cab"><button type="button" class="mrd-volver" data-a="back" data-v="chat">' + ico('izq') + esc(s('backtochat')) +
                '</button><span class="mrd-origenes">' + o + '</span><div class="mrd-ficha-nombre"><h3>' + esc(f.title || (c.contact && c.contact.name) || '') +
                '</h3>' + (READONLY ? '' : starButton(c.contact && c.contact.id)) + '</div>' +
                (qfNoteLine(f) ? '<p>' + esc(qfNoteLine(f)) + '</p>' : '') +
                (f.line && !f.familyfile && kind !== 'both' ? '<p>' + esc(f.line) + '</p>' : '') + phoneRow + changed + '</div>';
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
        function fold(x) {
            var t = String(x || '').toLowerCase();
            return t.normalize ? t.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : t;
        }
        function courseText(k) {
            var label = k.stage && k.stage.label, group = k.group || '';
            if (label && group && fold(group).indexOf(fold(label)) >= 0) {
                return group;
            }
            return [label, group].filter(Boolean).join(' · ');
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
                        esc(k.name) + '</b>' + (k.group || (k.stage && k.stage.label) ? '<span class="mrd-curso">' + esc(courseText(k)) + '</span>' : '') +
                        (k.tutor ? '<small>' + esc(s('tutor', k.tutor)) + '</small>' : '') + '</span></div>';
                }).join('') + '</div>';
            }
            if (applying.length && !f.panel) {
                h += '<div class="mrd-plaza-en-familia">' + applying.map(function (k) {
                    return '<div class="mrd-seccion"><h4>' + esc(f.kind === 'applicant' ? s('applying') : s('applyingfor')) + '</h4><div class="mrd-pide">' +
                        '<span class="mrd-curso-pide">' + esc(k.name) + '</span><small>' + esc(courseText(k)) +
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
        function contactsHtml(f, quiet) {
            if (!f.contacts || !f.contacts.length) {
                return '';
            }
            return '<div class="mrd-seccion"><h4>' + esc(quiet ? s('familyadults') : s('whowrites')) + '</h4>' + f.contacts.map(function (k) {
                // A student of age says so beside their name, in one line («En el centro» says they are a student); the
                // rest, who they are and their number.
                var line = [k.student ? '' : k.role, fmtPhone(k.phone)].filter(Boolean).join(' · ');
                return '<div class="mrd-contacto' + (k.student ? ' mrd-contacto-alumno' : '') + '">' +
                    '<span class="mrd-av mrd-m mrd-con-bicho" aria-hidden="true">' + bicho(digits(k.phone) || k.name) +
                    '</span><span><b>' +
                    esc(k.name) + (k.student ? ' ' + studentTag(true) : '') +
                    (f.contacts.length > 1 && k.conversation && k.conversation === st.open
                        ? ' <span class="mrd-marca-este">' + esc(s('thischat')) + '</span>' : '') +
                    '</b><small>' + esc(line) + '</small></span>' + callButton(k.phone) + '</div>';
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
        /* «Who is it?»: several marks at once, as labels (a teacher can also be the mother of a pupil), each with what it
           needs written under it, and the face it has in the lists among the marked ones (its mock-up). The family
           of the records comes by itself and is not unmarked here. */
        var QF = [['family', 'casa', true], ['staff', 'gafete', false], ['supplier', 'caja', false], ['alumni', 'birrete', true],
            ['other', 'lapiz', false]];
        var QF_NOTES = { staff: ['staff'], supplier: ['offer', 'company'], alumni: ['year'], other: ['other'] };
        function qfMine(f) {
            return (f.marks || []).filter(function (m) {
                return !(m === 'family' && f.familyauto);
            });
        }
        function qfRow(op, on, compact, hint, locked) {
            return '<button type="button" class="qf-op' + (compact ? ' qf-compacto' : '') + '" role="checkbox" aria-checked="' + (on ? 'true' : 'false') +
                '" data-a="qfmark" data-v="' + op[0] + '"' + (locked ? ' aria-disabled="true" title="' + esc(s('qffromfile')) + '"' : '') + '>' +
                '<span class="qf-forma' + (op[2] ? ' qf-circulo' : '') + '">' + ico(op[1]) + '</span>' +
                '<span><b>' + esc(s('mark' + op[0])) + '</b><small>' + esc(s('mark' + op[0] + 'help')) + '</small>' +
                (hint ? '<small class="qf-pista">' + esc(hint) + '</small>' : '') + '</span>' +
                '<span class="qf-check" aria-hidden="true">' + ico('check') + '</span></button>';
        }
        function qfLine(f, key) {
            var text = (f.notes || {})[key] || '', saved = st.qfSaved === key;
            return '<div class="qf-linea"><label>' + esc(s('qfnote' + key)) + '</label><div class="qf-escribe" contenteditable="true" role="textbox" ' +
                'aria-label="' + esc(s('qfnote' + key)) + '" data-note="' + key + '" data-vacio="' + esc(s('qfnote' + key + 'ph')) + '">' + esc(text) + '</div>' +
                (saved ? '<span class="qf-guardado">' + ico('check') + esc(s('qfsaved')) + '</span>' : '') + '</div>';
        }
        function qfDetail(f, mark) {
            if (mark === 'family') {
                if (f.familyfile) {
                    return '<p class="qf-de-donde">' + ico('ficha') + '<span>' + esc(s('qffamilyfile')) + '</span></p>';
                }
                return maybeHtml(f, conv(st.open)) + (f.panel && f.panel.url ? '<div class="mrd-panel"><iframe src="' + esc(f.panel.url) + '" title="' +
                    esc(s('panel')) + '"></iframe></div>' : '');
            }
            return (QF_NOTES[mark] || []).map(function (key) {
                return qfLine(f, key);
            }).join('');
        }
        // The lines of the marks, as the lists show them: «profesora de Inglés · promoción de 2004».
        function qfNoteLine(f) {
            var n = f.notes || {};
            return (f.marks || []).map(function (m) {
                if (m === 'staff') {
                    return n.staff || '';
                }
                if (m === 'supplier') {
                    return n.company || n.offer || '';
                }
                if (m === 'alumni') {
                    return n.year ? s('qfyear', n.year) : '';
                }
                return m === 'other' ? n.other || '' : '';
            }).filter(Boolean).join(' · ');
        }
        function qfRings(f) {
            return (f.children || []).filter(function (ch) {
                return ch.stage && ch.stage.color;
            }).map(function (ch) {
                return { color: ch.stage.color, dark: ch.stage.dark, dashed: false };
            });
        }
        function qfFace(f, mark) {
            return avContact({ kind: mark, phone: f.phone, name: f.title, rings: mark === 'family' ? qfRings(f) : [] }, 'mrd-s');
        }
        function whoisHtml(f, c) {
            if (READONLY || !c || !c.id || !(c.contact && c.contact.id)) {
                return '';
            }
            var marks = f.marks || [], none = !marks.length, face = f.face && marks.indexOf(f.face) >= 0 ? f.face : marks[0];
            var order = marks.concat(QF.map(function (op) {
                return op[0];
            }).filter(function (k) {
                return marks.indexOf(k) < 0;
            }));
            var rows = order.map(function (k) {
                var op = QF.filter(function (x) {
                    return x[0] === k;
                })[0];
                if (marks.indexOf(k) >= 0) {
                    var detail = qfDetail(f, k);
                    return '<div class="qf-marcada">' + qfRow(op, true, false, '', k === 'family' && f.familyauto) +
                        (detail ? '<div class="qf-detalle">' + detail + '</div>' : '') + '</div>';
                }
                // What may be the same family is already in its own block, above: no hint here.
                return qfRow(op, false, !none, '', false);
            }).join('');
            var faces = marks.length > 1 ? '<div class="qf-caras"><span>' + esc(s('qffaces')) + '</span><div class="qf-caras-fila">' + marks.map(function (k) {
                return '<button type="button" class="qf-cara" aria-pressed="' + (k === face) + '" data-a="qfface" data-v="' + k + '">' + qfFace(f, k) +
                    esc(s('mark' + k)) + '</button>';
            }).join('') + '</div><p class="mrd-ayuda">' + esc(s('qffaceshelp')) + '</p></div>' : '';
            return '<div class="mrd-seccion"><h4>' + esc(s('whoisq')) + (none ? '' : ' <small>' + esc(s('qfseveral')) + '</small>') + '</h4>' +
                '<div class="qf-opciones" role="group" aria-label="' + esc(s('whoisq')) + '">' + rows + '</div>' + faces +
                (none ? '<p class="mrd-ayuda">' + esc(s('qfnonehelp')) + '</p>' : '') + '</div>';
        }
        function restoreFicha() {
            box.classList.toggle('mrd-sin-ficha', !conv(st.open) || st.fichaState === 'error');
            renderFicha();
        }
        // While a new conversation is being chosen: the file of whom was chosen, as the server knows them.
        function renderNewFicha() {
            var n = st.nueva;
            panelFrame = null;
            panelData = null;
            if (!n.pick) {
                el.ficha.innerHTML = '<div class="mrd-ficha-vacia">' + esc(s('newpicknone')) + '</div>';
                return;
            }
            if (!n.ficha) {
                el.ficha.innerHTML = '<div class="mrd-ficha-vacia">' + esc(n.fichaState === 'error' ? s('fileerror') : s('fileloading')) + '</div>';
                return;
            }
            var f = n.ficha, k = f.kind || 'unknown', c = newConvLike();
            var h = fichaHead(f, c);
            if (k === 'family' || k === 'both') {
                h += contactsHtml(f, true) + childrenHtml(f) + rowsHtml(f.services, s('services')) + rowsHtml(f.rows, '');
            } else {
                h += rowsHtml(f.rows, '');
            }
            if (f.panel && f.panel.url) {
                h += '<div class="mrd-seccion mrd-panel"><iframe src="' + esc(f.panel.url) + '" title="' + esc(s('panel')) + '"></iframe></div>';
            }
            h += otherConvsHtml(f);
            if (f.source) {
                h += '<div class="mrd-fuente">' + esc(f.source) + '</div>';
            }
            el.ficha.innerHTML = h;
            if (f.panel && f.panel.url) {
                panelFrame = el.ficha.querySelector('.mrd-panel iframe');
                panelData = f.panel;
                panelFrame.addEventListener('load', function () {
                    postPanelContext();
                    fitPanel(panelFrame);
                });
            }
        }
        function loadNewFicha() {
            var n = st.nueva, x = n && n.pick;
            if (!x) {
                renderFicha();
                return;
            }
            n.ficha = null;
            n.fichaState = 'loading';
            renderFicha();
            tr.call(M.contact, { conversation: 0, phone: '+' + x.phone, name: x.name || '' }).then(function (f) {
                if (st.nueva !== n || n.pick !== x) {
                    return;
                }
                n.ficha = f || null;
                n.fichaState = f ? 'ok' : 'error';
                renderFicha();
                // What the number said no to warns before its messages: the sheet is drawn again with it.
                if (n.tpl < 0 && optoutNow(f && f.optout)) {
                    renderNew();
                }
            }).catch(function () {
                if (st.nueva !== n || n.pick !== x) {
                    return;
                }
                n.fichaState = 'error';
                renderFicha();
            });
        }
        function renderFicha() {
            if (st.nueva) {
                renderNewFicha();
                return;
            }
            // Whoever is writing a line of «Who is it?» keeps it as it is until they leave it.
            var writing = document.activeElement;
            if (writing && writing.classList && writing.classList.contains('qf-escribe') && el.ficha.contains(writing)) {
                return;
            }
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
            // Marked as family without a file: what may be the same family and admissions go inside its row.
            var inRow = (f.marks || []).indexOf('family') >= 0 && !f.familyfile;
            h += inRow ? '' : maybeHtml(f, c);
            if (f.familyfile || k === 'both') {
                h += contactsHtml(f, !c.lastincoming) + childrenHtml(f) + rowsHtml(f.services, s('services')) + rowsHtml(f.rows, '');
            } else if (k === 'applicant') {
                h += childrenHtml(f) + rowsHtml(f.rows, '');
            } else {
                h += rowsHtml(f.rows, '');
            }
            // «Who is it?» first: the card of another plugin below it, as one more card.
            h += whoisHtml(f, c);
            if (f.panel && f.panel.url && !inRow) {
                h += '<div class="mrd-seccion mrd-panel"><iframe src="' + esc(f.panel.url) + '" title="' + esc(s('panel')) + '"></iframe></div>';
            }
            h += otherConvsHtml(f);
            if (f.source) {
                h += '<div class="mrd-fuente">' + esc(f.source) + '</div>';
            }
            var keep = el.ficha.scrollTop;
            el.ficha.innerHTML = h;
            el.ficha.scrollTop = keep;
            if (f.panel && f.panel.url && el.ficha.querySelector('.mrd-panel iframe')) {
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
                renderBefore();
                renderBands();
                renderComposer();
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
            // From what is between the team (a card, a notice), the conversation opens in the tray where it is.
            if (inZone()) {
                st.inbox = c.section === 'resolved' ? 'history' : isMine(c) ? 'mine' : c.teamid ? 'team:' + c.teamid : !c.assignee ? 'unassigned' : 'mine';
                st.boardNew = null;
                st.boardEdit = null;
                st.teamPick = false;
                st.teamQ = '';
                st.pop = null;
                renderRail();
                renderListHead();
            }
            if (st.nueva) {
                st.nueva = null;
                clearTimeout(findT);
                markNewButton();
                if (st.open === id) {
                    renderChat();
                    restoreFicha();
                }
            }
            if (st.open !== id) {
                saveDraft();
                if (st.justStarted !== id) {
                    st.justStarted = 0;
                }
                st.open = id;
                st.msgs = [];
                st.loaded = false;
                st.more = false;
                st.readby = [];
                st.mode = canReply(c) || READONLY ? 'reply' : (st.drafts[id] && st.drafts[id].note ? 'note' : 'reply');
                st.pop = null;
                st.call = { line: '', offer: 0 };
                st.tpl = -1;
                st.schedPick = 0;
                st.sched = null;
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
            // The first conversation this person opens, after the first tour: what is done inside one.
            if (!tour && tourSeen('inicio') && !tourSeen('conv') && canTour()) {
                setTimeout(function () {
                    if (!tour && conv(st.open)) {
                        startTour('conv');
                    }
                }, 900);
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
            // The server calls the wait «waitsince»; the screen reads «waitingsince», as the contract first wrote it.
            if (c.waitingsince === undefined && c.waitsince !== undefined) {
                c.waitingsince = c.waitsince;
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
            updateTitle();
            if (tour) {
                drawTour();
            }
            var c = conv(st.open);
            if (!c) {
                renderHome();
            }
            if (c) {
                renderHead();
                renderBands();
                placeCallOffer();
                renderScheduled();
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
                asked(r);
                applyPoll(r || {}, id);
                showRed(st.unchecked === null ? null : 'unchecked');
            }).catch(function (e) {
                st.failed++;
                var code = e && e.errorcode;
                if (code === 'invalidsesskey' || code === 'servicerequireslogin' || code === 'requireloginerror' || code === 'sessionexpired') {
                    st.expired = true;
                    showRed('expired');
                } else if (st.failed >= 2) {
                    showRed('offline');
                    if (st.failed === 2 && st.ready) {
                        // What said «nothing» says from now on that it is not known.
                        refreshAfterData();
                    }
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
            if (r.board && r.board.rev !== st.boardRev && st.board !== 'loading') {
                st.boardRev = r.board.rev;
                loadBoard();
            }
            if (r.team) {
                teamPolled(r.team);
            }
            (r.conversations || []).forEach(mergeConv);
            onNotices(r.notices);
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
            // Back in sight with a conversation of the team open that got messages meanwhile: they are read now.
            var it = !document.hidden && st.inbox === 'team' && !conv(st.open) && st.room && st.room.id ? roomItem() : null;
            if (it && it.unread) {
                loadTeam(roomArgs('read')).catch(function () {
                    return null;
                });
            }
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
                    if (e && typeof e === 'object') {
                        e.shown = true;
                    }
                }
                throw e;
            });
        }
        function remind(btn, id, day, reason) {
            busy(btn, true);
            return tr.call(M.remind, { conversation: id, day: day, reason: day ? reason : '' }).then(function (r) {
                var c = r && r.conversation;
                if (c && c.id) {
                    mergeConv(c);
                }
                st.pop = null;
                toast(c && c.remind ? s('remtoast', whenPhrase(c.remind.at * 1000)) : s('remcleared'));
                refreshAfterData();
                pollSoon();
                return r;
            }).catch(function (e) {
                busy(btn, false);
                failToast(e);
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

        /* -------- Passing on and resolving, with a way back --------
           What was just done can be undone while nobody else has touched it: from the line in the thread (which stays)
           or the toast (which goes). Passing on is undone by passing it back, to whoever had it; resolving, by reopening. */
        function sendNote(c, text) {
            var m = { id: 'tmp' + (++seq), kind: 'note', text: text, wire: text, at: Math.floor(nowMs() / 1000),
                author: { name: me.name, firstname: me.firstname, me: true }, signature: '', status: 'pending', pending: true, attachments: [],
                template: '', conv: c.id };
            st.msgs.push(m);
            addMessagesLocal(m);
            return tr.call(M.send, { conversation: c.id, text: text, private: true }).then(function (r) {
                var msg = r && (r.message || (r.id ? r : null));
                if (st.open === c.id && msg) {
                    replaceMsg(m.id, msg);
                }
                return r;
            }).catch(function (e) {
                var i = indexOfMsg(m.id), node = hiloEl() && hiloEl().querySelector('[data-id="' + cssEsc(m.id) + '"]');
                if (i >= 0) {
                    st.msgs.splice(i, 1);
                }
                if (node) {
                    node.remove();
                }
                throw e;
            });
        }
        // The line first, while the conversation is still in sight (passed on, it may leave it), then the department.
        function passOn(btn, c, to, note) {
            var id = c.id, from = c.teamid, who = c.assignee ? c.assignee.agentid : 0, text = String(note || '').trim();
            busy(btn, true);
            var first = text ? sendNote(c, s('passnote', { team: teamName(to), text: text })) : Promise.resolve(null);
            return first.then(function () {
                st.pop = null;
                st.pass = null;
                return act(id, 'team', to).then(function (res) {
                    // Passed to a department this person does not see, it is no longer theirs to pass back.
                    var gone = !!(res && res.gone);
                    st.undo = gone ? null : { conv: id, kind: 'team', from: from, to: to, who: who, at: nowMs() };
                    toast(gone ? s('toastgone', teamName(to)) : s('toastpassed', teamName(to)), false, !gone);
                    if (conv(id)) {
                        renderHead();
                        renderComposer(true);
                        placeUndo();
                    }
                    return res;
                });
            }).catch(function (e) {
                busy(btn, false);
                if (e && !e.shown) {
                    failToast(e);
                }
                return null;
            });
        }
        // Valid for five minutes, while the conversation is as it was left: nobody of the department has kept it or
        // written in it, and it was not opened again.
        function undoValid() {
            var u = st.undo;
            if (!u || nowMs() - u.at > 5 * 60000) {
                return false;
            }
            var c = conv(u.conv);
            if (!c) {
                return false;
            }
            var touched = u.conv === st.open && st.msgs.some(function (m) {
                return (m.at || 0) * 1000 > u.at && (m.kind === 'outgoing' || m.kind === 'note') && !(m.author && m.author.me);
            });
            if (touched) {
                return false;
            }
            return u.kind === 'team' ? c.teamid === u.to && !c.assignee : c.section === 'resolved';
        }
        function undo() {
            var u = st.undo;
            hideToast();
            if (!undoValid()) {
                st.undo = null;
                placeUndo();
                toast(s('undolate'), true);
                return;
            }
            st.undo = null;
            placeUndo();
            var steps = u.kind === 'team' ? act(u.conv, 'team', u.from).then(function () {
                return u.who ? act(u.conv, 'person', u.who) : null;
            }) : act(u.conv, 'reopen', 0);
            steps.then(function () {
                toast(u.kind === 'team' ? s('undonepass', teamName(u.from)) : s('toastreopened'));
                if (conv(u.conv) && st.open !== u.conv) {
                    openConv(u.conv);
                } else if (conv(st.open)) {
                    renderHead();
                    renderComposer(true);
                }
                return null;
            }).catch(function () {
                // act() said what went wrong.
                return null;
            });
        }
        // «Deshacer» on the line of what was done: the server's own line once it has come, ours until then. The thread is
        // drawn or grows wherever this is called: the approved message after a call nobody answered goes in place too.
        function placeUndo() {
            placeCallOffer();
            var hilo = hiloEl();
            if (!hilo) {
                return;
            }
            Array.prototype.forEach.call(hilo.querySelectorAll('.mrd-deshacer, .mrd-sistema-local'), function (n) {
                n.remove();
            });
            Array.prototype.forEach.call(hilo.querySelectorAll('.mrd-con-deshacer'), function (n) {
                n.classList.remove('mrd-con-deshacer');
            });
            var u = st.undo;
            if (!u || u.conv !== st.open || !undoValid()) {
                return;
            }
            var btn = '<button type="button" class="mrd-deshacer" data-a="undo">' + ico('deshacer') + esc(s('undo')) + '</button>';
            var line = null;
            st.msgs.forEach(function (m) {
                if (m.kind === 'activity' && (m.at || 0) * 1000 >= u.at - 5000) {
                    line = m;
                }
            });
            var node = line && hilo.querySelector('.mrd-sistema[data-id="' + cssEsc(line.id) + '"]');
            if (node) {
                node.classList.add('mrd-con-deshacer');
                node.insertAdjacentHTML('beforeend', btn);
                // What was resolved says so with its tick, as the line drawn here before the server's came.
                var use = u.kind === 'team' ? null : node.querySelector('svg use');
                if (use) {
                    use.setAttribute('href', '#mrd-i-check');
                }
                return;
            }
            hilo.insertAdjacentHTML('beforeend', '<div class="mrd-sistema mrd-sistema-local mrd-con-deshacer">' + ico(u.kind === 'team' ? 'flecha' : 'check') +
                '<span>' + esc(u.kind === 'team' ? s('passedline', { who: me.firstname || firstName(me), from: teamName(u.from), to: teamName(u.to) })
                : s('resolvedline', me.firstname || firstName(me))) + ' · ' + esc(fmtTime(u.at)) + '</span>' + btn + '</div>');
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
            if (!note && draftUi(c)) {
                st.draftUi = null;
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
            placeUndo();
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
        /* A text with several lines, in one, as it would be written in one: the items of a list go with a semicolon and the
           paragraphs with a stop, where they bring none; a line cut by its width, with a space. */
        function oneLine(text) {
            var out = '', blank = false, was = false;
            String(text).split(/\r\n|[\r\n\u2028\u2029]/).forEach(function (line) {
                var item = /^\s*(?:[-–—•*·]|\d{1,2}[.)])\s+/.test(line);
                var said = line.replace(/^\s*[-–—•*·]\s+/, '').replace(/[\s\u00a0]+/g, ' ').trim();
                if (!said) {
                    blank = !!out;
                    return;
                }
                if (out) {
                    out += (/[.,:;!?…]$/.test(out) ? '' : item ? ';' : blank || was ? '.' : '') + ' ';
                }
                out += said;
                blank = false;
                was = item;
            });
            return out;
        }
        // Whoever leaves a line or pastes several hears that it goes out as one paragraph.
        function sayOneLine() {
            var note = el.chat.querySelector('.mrd-una-linea');
            if (note) {
                note.textContent = s('tploneline');
            }
        }
        // What a gap has, in one line: WhatsApp takes no line breaks inside a gap.
        function gapText(g) {
            return String(g.innerText !== undefined ? g.innerText : g.textContent).replace(/[\s\u00a0]+/g, ' ').trim();
        }
        // The next gap with nothing in it, after another one if it is given and then from the first; null with none.
        function emptyGap(from) {
            var gaps = Array.prototype.slice.call(el.chat.querySelectorAll('.mrd-hueco')), at = from ? gaps.indexOf(from) + 1 : 0;
            return gaps.slice(at).concat(gaps.slice(0, at)).filter(function (g) {
                return g !== from && !gapText(g);
            })[0] || null;
        }
        // Where the keys go with an approved message just chosen: the message, when it is for whoever sends it to write (with
        // the keyboard of the phone too, as in any chat); if not, the first gap with nothing, or the first one.
        function focusGap(area) {
            var own = area && area.querySelector('.mrd-hueco-mensaje');
            if (own) {
                placeCaret(own, -1);
                own.scrollIntoView({ block: 'nearest' });
                return;
            }
            var gap = area && (area.querySelector('.mrd-hueco:empty') || area.querySelector('.mrd-hueco'));
            if (gap && !touchy()) {
                gap.focus();
            }
        }
        // What is still missing, by its name: «Missing: what for».
        function gapsText(missing) {
            return missing.length ? s('gapsmissing', joinList(missing)) : s('yellowhelp');
        }
        // After each key in a gap: what is left, next to the button that sends.
        function updateGaps() {
            var seen = {}, missing = [];
            keepGaps();
            Array.prototype.forEach.call(el.chat.querySelectorAll('.mrd-hueco'), function (g) {
                var k = g.getAttribute('data-k');
                if (!seen[k]) {
                    seen[k] = true;
                    if (!gapText(g)) {
                        missing.push(g.getAttribute('data-falta') || k);
                    }
                }
            });
            var note = el.chat.querySelector('.mrd-huecos-estado'), btn = el.chat.querySelector('.mrd-previa-pie .mrd-btn');
            if (note) {
                note.textContent = gapsText(missing);
                note.classList.toggle('mrd-faltan', !!missing.length);
            }
            if (btn) {
                btn.setAttribute('aria-disabled', String(!!missing.length));
            }
        }
        function gapValues() {
            var gaps = el.chat.querySelectorAll('.mrd-hueco'), params = [], missing = false, seen = {};
            Array.prototype.forEach.call(gaps, function (g) {
                var v = gapText(g), k = g.getAttribute('data-k'), mark = g.nextElementSibling;
                // The message already brings its stop after the gap: whoever writes theirs does not get two.
                if (mark && mark.classList.contains('mrd-tras') && mark.textContent.charAt(0) === '.') {
                    v = v.replace(/([^.,:;])[.,:;]$/, '$1');
                }
                g.classList.toggle('mrd-falta', !v);
                if (!v) {
                    missing = true;
                }
                if (!seen[k]) {
                    seen[k] = true;
                    params.push({ key: k, value: v });
                }
            });
            if (missing) {
                var first = el.chat.querySelector('.mrd-hueco.mrd-falta');
                if (first) {
                    first.focus();
                } else {
                    toast(s('fillgaps'), true);
                }
                updateGaps();
                return null;
            }
            return params;
        }
        function sendTemplate(btn) {
            var c = conv(st.open), t = (st.extras.templates || [])[st.tpl];
            if (!c || !t) {
                return;
            }
            var params = gapValues();
            if (!params) {
                return;
            }
            var bubble = el.chat.querySelector('.mrd-previa .mrd-msg p');
            var shown = bubble ? bubble.textContent.trim() : '';
            var id = c.id;
            busy(btn, true);
            tr.call(M.template, { conversation: id, name: t.name, language: t.language, params: params }).then(function (r) {
                var msg = r && (r.message || (r.id ? r : null));
                if (r && r.conversation) {
                    mergeConv(r.conversation);
                }
                delete (st.kept || {})[keptKey(c)];
                if (st.open === id) {
                    st.tpl = -1;
                    st.pickAgain = false;
                    st.schedPick = 0;
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
           A new conversation: whom (a number, a family, someone who wrote before), from which department and with which
           approved message. If they have one open that the person sees, that one opens instead.
           --------------------------------------------------------------------------------------------------------------- */
        var findT = null, findSeq = 0, searchT = null;
        function defaultTeam() {
            var mine = (st.extras.teams || []).filter(function (t) {
                return t.mine;
            });
            return mine.length ? mine[0].id : 0;
        }
        function markNewButton() {
            var b = el.lista.querySelector('.mrd-nueva-b');
            if (b) {
                b.setAttribute('aria-pressed', String(!!st.nueva));
            }
        }
        /* The approved messages, the saved answers and the people are asked for again now and then and on opening «New»: a
           page left open all day shows what changed since. The answer takes the place of what there was, and what is chosen
           goes by its name, since its place in the list may be another. */
        var extrasAt = 0, extrasBusy = false;
        function tplSigs() {
            return JSON.stringify(st.extras.templates || []);
        }
        function refreshExtras(age) {
            if (extrasBusy || Date.now() - extrasAt < age) {
                return;
            }
            extrasBusy = true;
            tr.call(M.extras, {}).then(function (x) {
                extrasBusy = false;
                if (x) {
                    extrasAt = Date.now();
                    takeExtras(x);
                }
            }).catch(function () {
                extrasBusy = false;
            });
        }
        function takeExtras(x) {
            var before = tplSigs(), had = st.extras.templates || [];
            var moved = function (i) {
                var t = had[i];
                return !t ? -1 : (st.extras.templates || []).findIndex(function (o) {
                    return o.name === t.name && o.language === t.language;
                });
            };
            st.extras = Object.assign(st.extras, x);
            if (tplSigs() === before) {
                return;
            }
            st.tpl = moved(st.tpl);
            if (st.nueva) {
                var kept = st.nueva.tpl < 0;
                st.nueva.tpl = moved(st.nueva.tpl);
                // Whoever is filling one in keeps it as it is, unless it is gone.
                if (st.nueva.pick && (kept || st.nueva.tpl < 0)) {
                    renderNew();
                }
            } else if (conv(st.open)) {
                renderComposer();
            }
        }
        function openNew() {
            if (READONLY) {
                return;
            }
            refreshExtras(60000);
            closePops();
            saveDraft();
            st.nueva = { q: '', found: null, needprefix: false, searching: false, pick: null, team: defaultTeam(), tpl: -1, allteams: false,
                ficha: null, fichaState: '' };
            box.classList.remove('mrd-sin-ficha');
            renderChat();
            renderFicha();
            markNewButton();
            setLayer('chat', true);
            focusNew();
        }
        function closeNew() {
            if (!st.nueva) {
                return;
            }
            st.nueva = null;
            clearTimeout(findT);
            renderChat();
            restoreFicha();
            markNewButton();
            if (narrow()) {
                goBack('lista');
            }
        }
        function focusNew() {
            var inp = el.chat.querySelector('[data-role="nuevabuscar"]');
            if (inp && !touchy()) {
                inp.focus();
            }
        }
        function candContact(x) {
            return { name: x.name, phone: x.phone, rings: x.rings || [], line: x.line || '', kind: 'unknown' };
        }
        function newConvLike() {
            return { id: 0, contact: candContact(st.nueva.pick || {}) };
        }
        function candHtml(x, i) {
            var title = x.name || (x.source === 'number' ? s('newnumber', fmtPhone(x.phone)) : fmtPhone(x.phone));
            var state = x.open && x.conversation ? '<span class="mrd-nueva-estado">' + ico('mensaje') + esc(s('newhasopen')) + '</span>'
                : x.elsewhere ? '<span class="mrd-nueva-estado mrd-ocupada">' + esc(s('newelsewhere', x.elsewhere)) + '</span>' : '';
            return '<button type="button" class="mrd-nueva-cand" data-a="newpick" data-v="' + i + '">' + avContact(candContact(x), 'mrd-m') +
                '<span class="mrd-nueva-cand-t"><b>' + esc(title) + '</b>' + (x.line ? '<small class="mrd-rel">' + relHtml(candContact(x)) + '</small>' : '') +
                '<span class="mrd-nueva-meta">' + (x.student ? studentTag() : '') +
                '<span class="mrd-nueva-fuente">' + esc(s('source' + x.source)) + '</span>' +
                (x.name ? '<span>' + esc(fmtPhone(x.phone)) + '</span>' : '') + state + '</span></span>' + ico('der', 'mrd-flecha') + '</button>';
        }
        // A student of age, written to beside the adults of their family (never instead of them): «Of age», without
        // saying whether they are a boy or a girl, which the site does not know.
        function studentTag(bare) {
            return '<span class="mrd-etq-alumno">' + (bare ? '' : ico('birrete')) + esc(s('adultstudent')) + '</span>';
        }
        function newListHtml() {
            var n = st.nueva;
            if (n.needprefix) {
                return '<div class="mrd-nueva-aviso mrd-espera mrd-prefijo">' + ico('alerta') + '<div><b>' + esc(s('newneedprefix')) + '</b></div></div>';
            }
            if (!n.found || (!n.found.length && n.searching)) {
                return '<p class="mrd-ayuda">' + esc(n.searching ? s('newsearching') : s('newsearchhint')) + '</p>';
            }
            if (!n.found.length) {
                return '<p class="mrd-ayuda">' + esc(s('newnone')) + '</p>';
            }
            return n.found.map(candHtml).join('');
        }
        function refreshNewList() {
            var list = el.chat.querySelector('.mrd-nueva-lista');
            if (list) {
                list.innerHTML = newListHtml();
            }
        }
        function findSoon(q) {
            var n = st.nueva;
            n.q = q;
            clearTimeout(findT);
            var clean = q.trim();
            if (clean.length < 2) {
                n.found = null;
                n.needprefix = false;
                n.searching = false;
                refreshNewList();
                return;
            }
            n.searching = true;
            refreshNewList();
            findT = setTimeout(function () {
                var mine = ++findSeq;
                tr.call(M.find, { query: clean }).then(function (r) {
                    if (mine !== findSeq || st.nueva !== n) {
                        return;
                    }
                    n.searching = false;
                    n.found = (r && r.candidates) || [];
                    n.needprefix = !!(r && r.needprefix);
                    refreshNewList();
                }).catch(function (e) {
                    if (mine !== findSeq || st.nueva !== n) {
                        return;
                    }
                    n.searching = false;
                    n.found = [];
                    refreshNewList();
                    failToast(e);
                });
            }, 300);
        }
        function newNoTemplatesHtml() {
            var n = +st.extras.pending || 0;
            return '<div class="mrd-nueva-aviso mrd-espera">' + '<span class="mrd-reloj">' + ico('reloj') + '</span><div><b>' + esc(s('newnotemplates')) +
                '</b><p>' + esc(s('newnotemplateshelp')) + ' ' + esc(n === 1 ? s('newpending1') : n ? s('newpending', n) : s('newpendingnone')) +
                '</p></div></div>';
        }
        function pickedHtml(x) {
            return '<div class="mrd-nueva-para"><span class="mrd-nueva-rotulo">' + esc(s('newto')) + '</span>' + avContact(candContact(x), 'mrd-m') +
                '<div class="mrd-nueva-cand-t"><b>' + esc(x.name || fmtPhone(x.phone)) + '</b>' +
                (x.line ? '<small class="mrd-rel">' + relHtml(candContact(x)) + '</small>' : '') +
                (x.name || x.student ? '<span class="mrd-nueva-meta">' + (x.student ? studentTag() : '') +
                    (x.name ? '<span>' + esc(fmtPhone(x.phone)) + '</span>' : '') + '</span>' : '') + '</div>' +
                '<span class="mrd-nueva-para-b">' + callButton(x.phone) + '<button type="button" class="mrd-btn mrd-chico" data-a="newchange">' +
                esc(s('newchange')) + '</button></span></div>';
        }
        function teamChips() {
            var all = st.extras.teams || [], n = st.nueva;
            var mine = all.filter(function (t) {
                return t.mine;
            }), rest = all.filter(function (t) {
                return !t.mine;
            });
            var chip = function (id, icon, name) {
                var on = n.team === id;
                return '<button type="button" class="mrd-chip' + (on ? ' mrd-on' : '') + '" data-a="newteam" data-v="' + id + '" aria-pressed="' + on + '">' +
                    ico(icon) + esc(name) + '</button>';
            };
            var shown = !mine.length || n.allteams ? mine.concat(rest) : mine.concat(rest.filter(function (t) {
                return t.id === n.team;
            }));
            var folded = all.length - shown.length;
            return shown.map(function (t) {
                return chip(t.id, teamIcon(t), t.name);
            }).join('') + (folded ? '<button type="button" class="mrd-chip mrd-chip-mas" data-a="newallteams">' + ico('der') + esc(s('newmoreteams')) +
                '</button>' : '') + chip(0, 'nadie', s('noteam'));
        }
        /* What a number said no to: in its file, and before writing to it, as a warning that never locks (what goes out
           on its own does not go to them). Writing again of their own takes it back on that channel. */
        function optoutNow(o) {
            if (!o) {
                return null;
            }
            if (o.all) {
                return { text: s('optoutall', dayText(o.all * 1000)), warn: s('optoutwarn', dayText(o.all * 1000)) };
            }
            if (o.scopes && o.scopes.length) {
                var x = o.scopes[0], a = { name: x.name, when: dayText(x.since * 1000) };
                return { text: s('optoutscope', a), warn: s('optoutwarnscope', a) };
            }
            return null;
        }
        function optoutTrailHtml(o) {
            var steps = (o && o.trail) || [];
            if (!steps.length) {
                return '';
            }
            return '<ul class="mrd-baja-rastro">' + steps.map(function (t) {
                var what = t.action === 'optout' ? (t.scope === 'all' ? s('trailoutall') : s('trailoutscope', t.name))
                    : t.source === 'wrote' ? s('trailwrote') : s('trailin');
                var by = /^chat:/.test(t.source) ? ' ' + s('trailbychat', t.source.slice(5)) : '';
                return '<li><span>' + esc(dayText(t.time * 1000)) + '</span> · ' + esc(what + by) + '</li>';
            }).join('') + '</ul>';
        }
        function optoutBlocked(phone, o) {
            return !!optoutNow(o) && !st.optoutOk[digits(phone)];
        }
        function optoutBand(phone, o, cancel) {
            var w = optoutNow(o);
            if (!w || st.optoutOk[digits(phone)]) {
                return '';
            }
            return '<div class="mrd-baja-aviso" role="status">' + ico('mudo') + '<div><b>' + esc(w.warn) + '</b><div class="mrd-botones">' +
                '<button type="button" class="mrd-btn mrd-chico" data-a="optoutok" data-v="' + esc(digits(phone)) + '">' + esc(s('optoutanyway')) + '</button>' +
                (cancel ? '<button type="button" class="mrd-btn mrd-chico" data-a="newclose">' + esc(s('optoutcancel')) + '</button>' : '') +
                '</div><small>' + esc(s('optoutwhen')) + '</small></div></div>';
        }
        // The category of an approved message, with what it costs, as a pill at the foot of its card.
        var PRICES = cfg.prices || { currency: 'EUR', utility: 0.02, marketing: 0.06, authentication: 0.03 };
        var CAT = { UTILITY: ['utility', 'util'], MARKETING: ['marketing', 'mkt'], AUTHENTICATION: ['authentication', 'aut'] };
        function tplPrice(t) {
            var c = CAT[String(t.category || '').toUpperCase()];
            return c && PRICES[c[0]] !== undefined ? PRICES[c[0]] : 99;
        }
        /* What one message costs, said as money and not as Meta's category: «Unos 2 céntimos». In another currency, the
           figure with its symbol. The colour of the category stays, the same as in the month's spend. */
        function priceWords(x, cur) {
            cur = cur || PRICES.currency || 'EUR';
            if (cur === 'EUR') {
                var n = Math.round(x * 100);
                return n < 1 ? s('priceunder1') : n === 1 ? s('pricecent1') : s('pricecents', n);
            }
            var money;
            try {
                money = new Intl.NumberFormat(LANG, { style: 'currency', currency: cur, minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(x);
            } catch (e) {
                money = String(x) + ' ' + cur;
            }
            return s('priceabout', money);
        }
        function catPill(t) {
            var c = CAT[String(t.category || '').toUpperCase()];
            if (!c) {
                return '';
            }
            return '<small class="mrd-cat mrd-cat-' + c[1] + '" title="' + esc(s('pricewhy' + c[1])) + '">' + esc(priceWords(PRICES[c[0]])) + '</small>';
        }
        // The cheapest first; at the same price, utility before the rest.
        function byPrice(a, b) {
            return tplPrice(a) - tplPrice(b) || (String(a.category).toUpperCase() === 'UTILITY' ? -1 : 0) -
                (String(b.category).toUpperCase() === 'UTILITY' ? -1 : 0);
        }
        /* The cards of the approved messages, in two groups told apart at a glance: the ones where whoever sends them writes
           the message (their card has a box to write in) and the ones that come written (their card has the text, whole). */
        function tplGroups(list, action, c, held) {
            var all = st.extras.templates || [], langs = {};
            all.forEach(function (t) {
                langs[t.language] = true;
            });
            var showLang = Object.keys(langs).length > 1, kept = keptOf(c);
            var card = function (t, pill) {
                var own = !!tplWrite(t), said = own && kept ? kept.own : '';
                return '<button type="button" class="mrd-plantilla' + (own ? ' mrd-escribes' : '') + '" data-a="' + action + '" data-v="' + all.indexOf(t) + '"' +
                    (held ? ' disabled' : '') + '><span class="mrd-tit' + (pill ? ' mrd-con-precio' : '') + '"><b>' + ico(own ? 'lapiz' : 'mensaje') + esc(tplTitle(t)) +
                    (showLang ? ' · ' + esc(t.language) : '') + '</b>' + pill +
                    (t.description || own ? '<span class="mrd-para">' + (t.description ? esc(t.description) : tplLead(t, c)) + '</span>' : '') + '</span>' +
                    (own ? '<span class="mrd-finge' + (said ? ' mrd-a-medias' : '') + '">' + esc(said || s('tplwritehere')) + '</span>'
                        : '<span class="mrd-dice">' + tplSaid(t, c) + '</span>') + '</button>';
            };
            // The price, once for the group: the one of most of its cards. Whichever costs something else says its own.
            var group = function (cls, icon, title, help, some) {
                var pills = some.map(catPill), times = function (x) {
                    return pills.filter(function (y) {
                        return y === x;
                    }).length;
                };
                var most = pills.slice().sort(function (a, b) {
                    return times(b) - times(a);
                })[0] || '', one = times(most) > 1 && pills.indexOf('') < 0, all1 = one && times(most) === pills.length;
                return some.length ? '<div class="mrd-tpl-grupo ' + cls + '"><h4>' + ico(icon) + '<b>' + esc(title) + '</b>' +
                    (one ? '<span class="mrd-cada">' + most + (all1 ? ' <span class="mrd-cada-t">' + esc(s('tpleach')) + '</span>' : '') + '</span>' : '') + '<small>' + esc(help) + '</small></h4>' +
                    '<div class="mrd-tpl-cartas">' + some.map(function (t, i) {
                        return card(t, one && pills[i] === most ? '' : pills[i]);
                    }).join('') + '</div></div>' : '';
            };
            return '<div class="mrd-plantillas">' + group('mrd-tpl-tuyas', 'lapiz', s('tplgroupwrite'), s('tplgroupwritehelp'), list.filter(tplWrite)) +
                group('mrd-tpl-hechas', 'mensaje', s('tplgroupready'), s('tplgroupreadyhelp'), list.filter(function (t) {
                    return !tplWrite(t);
                })) + '</div>';
        }
        // What an approved message says before what one writes: what tells it apart when the organisation says nothing of it.
        function tplLead(t, c) {
            var gap = tplWrite(t), at = String(t.body || '').search(new RegExp('\\{\\{\\s*' + gap.replace(/[.]/g, '\\.') + '\\s*\\}\\}'));
            return tplSaid({ body: String(t.body || '').slice(0, Math.max(at, 0)).trim(), params: t.params, name: t.name, language: t.language }, c);
        }
        // The text of an approved message as it goes out, with the name of each gap nobody has filled yet.
        function tplSaid(t, c) {
            var params = {};
            (t.params || []).forEach(function (p) {
                params[p.key] = p;
            });
            return esc(t.body || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, k, at, whole) {
                var p = params[k] || { key: k }, v = tplValue(p, c, t);
                return v ? esc(v) : '<i>' + esc(gapHere(gapLabel(p, t), whole.slice(0, at))) + '</i>';
            });
        }
        function newTplCards() {
            var nv = st.nueva, held = !!(nv && nv.pick && optoutBlocked(nv.pick.phone, nv.ficha && nv.ficha.optout));
            var list = (st.extras.templates || []).slice().sort(function (a, b) {
                return (a.start ? 0 : 1) - (b.start ? 0 : 1) || byPrice(a, b);
            });
            return tplGroups(list, 'newtpl', newConvLike(), held);
        }
        function renderNew() {
            var n = st.nueva, tpls = st.extras.templates || [];
            var h = '<div class="mrd-nueva"><div class="mrd-nueva-cab"><button type="button" class="mrd-volver" data-a="newclose" aria-label="' +
                esc(s('newcancel')) + '">' + ico('izq') + '</button><div class="mrd-nueva-tit"><h2>' + esc(s('newconvtitle')) + '</h2><p>' +
                esc(s('newconvhelp')) + '</p></div><button type="button" class="mrd-btn mrd-chico mrd-nueva-cerrar" data-a="newclose">' + ico('cerrar') +
                esc(s('newcancel')) + '</button></div><div class="mrd-nueva-cuerpo">';
            if (!tpls.length) {
                h += newNoTemplatesHtml();
            }
            if (!n.pick) {
                h += '<h3>' + esc(s('newwho')) + '</h3><label class="mrd-buscar mrd-nueva-buscar">' + ico('buscar') +
                    '<input type="search" data-role="nuevabuscar" autocomplete="off" enterkeyhint="search" placeholder="' + esc(s('newsearch')) +
                    '" aria-label="' + esc(s('newsearch')) + '" value="' + esc(n.q) + '"></label><div class="mrd-nueva-lista" aria-live="polite">' +
                    newListHtml() + '</div>';
            } else {
                h += pickedHtml(n.pick);
                if (n.pick.open && n.pick.conversation) {
                    h += '<div class="mrd-nueva-aviso">' + ico('mensaje') + '<div><b>' + esc(s('newhasopen')) + '</b></div>' +
                        '<button type="button" class="mrd-btn mrd-chico mrd-primario" data-a="newopen" data-v="' + n.pick.conversation + '">' +
                        esc(s('newopenit')) + '</button></div>';
                } else if (n.pick.elsewhere) {
                    h += '<div class="mrd-nueva-aviso mrd-ocupada">' + ico('alerta') + '<div><b>' + esc(s('newbusy', n.pick.elsewhere)) + '</b></div></div>';
                } else if (tpls.length) {
                    h += '<h3>' + esc(s('newfrom')) + '</h3><div class="mrd-chips mrd-nueva-deps">' + teamChips() + '</div>';
                    if (n.tpl >= 0 && tpls[n.tpl]) {
                        h += previewHtml(newConvLike(), tpls[n.tpl]).replace('data-a="tplback"', 'data-a="newtplback"')
                            .replace('data-a="tplsend"', 'data-a="newsend"').replace(esc(s('sendtemplate')), esc(s('newsend')));
                    } else {
                        h += optoutBand(n.pick.phone, n.ficha && n.ficha.optout, true) + '<h3>' + esc(s('newpick')) + '</h3>' + newTplCards();
                    }
                }
            }
            el.chat.innerHTML = h + '</div></div>';
            st.composerSig = '';
        }
        function newSend(btn) {
            var n = st.nueva, t = n && (st.extras.templates || [])[n.tpl];
            if (!n || !n.pick || !t) {
                return;
            }
            var params = gapValues();
            if (!params) {
                return;
            }
            busy(btn, true);
            tr.call(M.start, { phone: '+' + n.pick.phone, contactname: n.pick.name || '', team: n.team || 0, name: t.name, language: t.language,
                params: params }).then(function (r) {
                if (st.nueva !== n) {
                    return;
                }
                if (!r || r.status === 'busy' || !r.conversation) {
                    busy(btn, false);
                    toast(r && r.where ? s('newbusy', r.where) : s('newbusyany'), true);
                    return;
                }
                mergeConv(r.conversation);
                if (r.status === 'started') {
                    delete (st.kept || {})[keptKey(newConvLike())];
                }
                st.nueva = null;
                restoreFicha();
                markNewButton();
                var id = r.conversation.id;
                if (st.open === id) {
                    renderChat();
                    loadMessages(id);
                }
                st.justStarted = r.status === 'started' ? id : 0;
                openConv(id);
                if (r.status === 'exists') {
                    toast(s('newexists'));
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
            st.pass = null;
            st.call.offer = 0;
            if (was === 'perfil' || was === 'avisos' || was === 'ayuda') {
                renderRail();
            } else if (was === 'dep' || was === 'dueno' || was === 'recordar' || was === 'llamada') {
                renderHead();
            } else {
                refreshWritePop();
            }
            // Drawn again, the menu's button is a new one: the focus goes back to it, not to the page.
            var a = document.activeElement;
            if (!a || a === document.body || !box.contains(a)) {
                var opener = box.querySelector(was === 'perfil' ? '.mrd-yo-mas' : '[data-a="pop"][data-v="' + was + '"]');
                if (opener) {
                    opener.focus({ preventScroll: true });
                }
            }
            return true;
        }
        // Into an open menu, to its first choice (or a box to write in).
        function focusPop(sel) {
            var x = sel ? box.querySelector(sel) : null;
            if (x && !touchy()) {
                x.focus({ preventScroll: true });
            }
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
                // A conversation of the team, as the notices link it (chat.php?eq=…).
                if (st.inbox === 'team' && st.room && st.room.id) {
                    u.searchParams.set('eq', String(st.room.id));
                } else {
                    u.searchParams.delete('eq');
                }
                history.replaceState(history.state, '', u.toString());
            } catch (e) {
                // An app window without an address to change.
            }
        }
        function chooseInbox(key) {
            if (key === 'equipo') {
                key = zoneHalf();
            }
            if (key === 'board' || key === 'team') {
                enterZone(key);
                if (key === 'board' && !st.boardNew && !boardItem(st.boardOpen)) {
                    var first = boardToSee()[0] || boardItems()[0];
                    st.boardOpen = first ? first.id : 0;
                }
            } else {
                st.boardNew = null;
                st.boardEdit = null;
                st.teamPick = false;
                st.teamQ = '';
            }
            st.inbox = key;
            st.search = '';
            st.showResolved = false;
            renderRail();
            renderListHead();
            renderListBody();
            setLayer('lista', narrow() && st.capa === 'bandejas');
            syncUrl();
            if (key === 'board') {
                renderChat();
                if (st.board === null) {
                    loadBoard();
                }
                return;
            }
            if (key === 'team') {
                renderChat();
                // On a computer, what waits unread (or the newest) opens on the right once the list is there.
                if (teamData() && !st.room && !narrow()) {
                    var top = teamList().filter(function (x) {
                        return x.unread;
                    })[0] || teamList()[0];
                    if (top) {
                        openRoom('c' + top.id, { keepLayer: true });
                    }
                } else if (teamData()) {
                    // The one open is read again only where it is in sight, beside the list.
                    loadTeam(st.room && st.room.id && !narrow() && !document.hidden ? roomArgs('read') : null).catch(function () {
                        return null;
                    });
                }
                return;
            }
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

        // The sound of the notices is made ready with the first tap or key on the page.
        box.addEventListener('pointerdown', wakeAudio);
        box.addEventListener('keydown', wakeAudio);
        box.addEventListener('toggle', function (e) {
            if (e.target.classList && e.target.classList.contains('mrd-inicio-como')) {
                keepHow(e.target.open);
            }
            if (e.target.classList && e.target.classList.contains('mrd-leyenda-caja')) {
                try {
                    root.localStorage.setItem('mrd-chat-leyenda', e.target.open ? 'abierta' : 'plegada');
                } catch (e3) {
                    // Without storage it folds again with each visit.
                }
            }
        }, true);
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
            var nv = st.nueva;
            switch (a) {
                case 'new':
                    if (nv) {
                        closeNew();
                    } else {
                        openNew();
                    }
                    break;
                case 'newclose':
                    closeNew();
                    break;
                case 'newpick':
                    if (nv && nv.found && nv.found[+v]) {
                        nv.pick = nv.found[+v];
                        nv.tpl = -1;
                        renderNew();
                        loadNewFicha();
                    }
                    break;
                case 'newchange':
                    if (nv) {
                        nv.pick = null;
                        nv.tpl = -1;
                        renderNew();
                        loadNewFicha();
                        focusNew();
                    }
                    break;
                case 'newteam':
                    if (nv) {
                        nv.team = +v;
                        Array.prototype.forEach.call(el.chat.querySelectorAll('.mrd-nueva-deps .mrd-chip'), function (b) {
                            var on = +b.getAttribute('data-v') === nv.team;
                            b.classList.toggle('mrd-on', on);
                            b.setAttribute('aria-pressed', on);
                        });
                    }
                    break;
                case 'newallteams':
                    if (nv) {
                        nv.allteams = true;
                        var deps = el.chat.querySelector('.mrd-nueva-deps');
                        if (deps) {
                            deps.innerHTML = teamChips();
                        }
                    }
                    break;
                case 'newtpl':
                    if (nv) {
                        nv.tpl = +v;
                        renderNew();
                        focusGap(el.chat);
                    }
                    break;
                case 'newtplback':
                    if (nv) {
                        nv.tpl = -1;
                        renderNew();
                    }
                    break;
                case 'newsend':
                    newSend(t);
                    break;
                case 'newopen':
                    if (conv(+v)) {
                        openConv(+v);
                    } else {
                        toast(s('toastnotfound'), true);
                    }
                    break;
                case 'inbox':
                    chooseInbox(v);
                    break;
                case 'home':
                    goHome();
                    break;
                case 'tour':
                    startTour(v === 'conv' ? 'conv' : 'inicio');
                    break;
                case 'tournext':
                    if (tour) {
                        tour.i = Math.min(tour.steps.length - 1, tour.i + 1);
                        drawTour();
                    }
                    break;
                case 'tourprev':
                    if (tour) {
                        tour.i = Math.max(0, tour.i - 1);
                        drawTour();
                    }
                    break;
                case 'tourend':
                    endTour();
                    break;
                case 'gapnext':
                    var gap = emptyGap(null);
                    if (gap) {
                        gap.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        placeCaret(gap, -1);
                    }
                    break;
                case 'desktopon':
                    askDesktop();
                    break;
                case 'desktopno':
                    setFlag('desktop', 'no');
                    renderListBody();
                    renderHome();
                    renderRail();
                    break;
                case 'sound':
                    setFlag('sound', FLAGS.sound === 'off' ? 'on' : 'off');
                    renderRail();
                    if (FLAGS.sound !== 'off') {
                        wakeAudio();
                        chime();
                    }
                    break;
                case 'otros':
                    st.otros = !st.otrosNow;
                    try {
                        root.localStorage.setItem('mrd-chat-otros', st.otros ? 'abierto' : 'plegado');
                    } catch (e2) {
                        // Without storage it goes back to opening on its own.
                    }
                    renderRail();
                    break;
                case 'conv':
                    openConv(+v);
                    break;
                case 'otherconv':
                    openAnyConv(+v);
                    break;
                case 'agendaopen':
                    var who = agendaContact(+v);
                    if (who && who.conversation) {
                        openAnyConv(who.conversation);
                    } else if (who && !READONLY) {
                        // Nothing to open: «New», with its number already looked up.
                        openNew();
                        findSoon('+' + who.phone);
                        renderNew();
                    }
                    break;
                case 'boardopen':
                    st.boardOpen = +v;
                    st.boardNew = null;
                    st.boardEdit = null;
                    if (st.inbox !== 'board') {
                        chooseInbox('board');
                    }
                    renderListBody();
                    renderBoardPane();
                    setLayer('chat', true);
                    break;
                case 'gsver':
                    st.gsOpen = st.gsOpen === +v ? 0 : +v;
                    renderHome();
                    break;
                case 'gsapprove':
                case 'gstelegram':
                    t.disabled = true;
                    // What is decided here stays on top of what was decided, marked, with how the send goes.
                    st.gsJust[+v] = true;
                    loadSpend({ action: a === 'gstelegram' ? 'telegram' : 'approve', id: +v }).then(function (r) {
                        if (r) {
                            toast(s(a === 'gstelegram' ? 'gsdonetg' : 'gsdone'));
                        }
                    });
                    break;
                case 'gsreject':
                    st.gsReject = { id: +v, reason: '', motive: '' };
                    renderHome();
                    var firstMotive = el.chat.querySelector('.gs-motivos .mrd-chip');
                    if (firstMotive) {
                        firstMotive.focus();
                    }
                    break;
                case 'gsmotivo':
                    if (st.gsReject) {
                        var motive = s('gsmotive' + v);
                        st.gsReject.motive = st.gsReject.motive === motive ? '' : motive;
                        renderHome();
                        var pressed = el.chat.querySelector('.gs-motivos [data-v="' + v + '"]');
                        if (pressed) {
                            pressed.focus();
                        }
                    }
                    break;
                case 'gsrejectno':
                    st.gsReject = null;
                    renderHome();
                    break;
                case 'gsrejectok':
                    t.disabled = true;
                    // What was written says more than the touch; one of the two, as whoever asked will read it.
                    var reason = st.gsReject ? (st.gsReject.reason || '').trim() || st.gsReject.motive || '' : '';
                    st.gsReject = null;
                    st.gsJust[+v] = true;
                    loadSpend({ action: 'reject', id: +v, reason: reason }).then(function (r) {
                        if (r) {
                            toast(s('gsrejecteddone'));
                        }
                    });
                    break;
                case 'gsfull':
                    var whole = el.chat.querySelector('#mrd-gs-mes');
                    if (whole) {
                        whole.scrollIntoView({ block: 'start', behavior: 'smooth' });
                        whole.focus({ preventScroll: true });
                    }
                    break;
                case 'gspaso':
                    var parts = String(v).split(':'), next = gsStepValue(parts[0], +parts[1]);
                    loadSpend({ action: parts[0], value: next });
                    break;
                case 'gsraise':
                    var box2 = el.chat.querySelector('#mrd-gs-presupuesto');
                    if (box2) {
                        box2.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        var plus = box2.querySelector('[data-v="budget:1"]');
                        if (plus) {
                            plus.focus();
                        }
                    }
                    break;
                case 'gspeople':
                    st.gsPick = gsData().approvers.map(function (p) {
                        return p.userid;
                    });
                    renderHome();
                    break;
                case 'gspick':
                    var uid = +v, at = st.gsPick.indexOf(uid);
                    if (at >= 0 && st.gsPick.length === 1) {
                        toast(s('gsneedone'));
                        break;
                    }
                    if (at >= 0) {
                        st.gsPick.splice(at, 1);
                    } else {
                        st.gsPick.push(uid);
                    }
                    renderHome();
                    loadSpend({ action: 'people', people: st.gsPick.slice() }).then(function (r) {
                        if (r) {
                            toast(s('gspeoplenow', gsNames(r.approvers.map(function (p) {
                                return p.firstname;
                            }), 'gsand')));
                        }
                    });
                    break;
                case 'gspeopledone':
                    st.gsPick = null;
                    renderHome();
                    break;
                case 'boardseen':
                    e.stopPropagation();
                    loadBoard({ action: 'seen', id: +v }, function () {
                        toast(s('boardmarked'));
                    });
                    break;
                case 'boardnew':
                    if (st.inbox !== 'board') {
                        chooseInbox('board');
                    }
                    st.boardNew = { teams: {}, all: false, text: '', shown: false };
                    st.boardEdit = null;
                    Object.keys(myTeamIds()).forEach(function (id) {
                        st.boardNew.teams[id] = true;
                    });
                    renderBoardPane();
                    setLayer('chat', true);
                    break;
                case 'boardnewcancel':
                    st.boardNew = null;
                    renderBoardPane();
                    break;
                case 'boardteam':
                    if (st.boardNew) {
                        st.boardNew.all = false;
                        st.boardNew.teams[v] = !st.boardNew.teams[v];
                        renderBoardPane();
                    }
                    break;
                case 'boardall':
                    if (st.boardNew) {
                        st.boardNew.all = !st.boardNew.all;
                        renderBoardPane();
                    }
                    break;
                case 'boardpost':
                    if (st.boardNew) {
                        var bn = st.boardNew, teamIds = Object.keys(bn.teams).filter(function (id) {
                            return bn.teams[id];
                        }).map(Number);
                        busy(t, true);
                        loadBoard({ action: 'post', text: bn.text.trim(), teams: bn.all ? [] : teamIds, all: bn.all }, function (r) {
                            st.boardNew = null;
                            st.boardOpen = r.posted;
                            toast(s('boardposted'));
                        });
                    }
                    break;
                case 'boardremind':
                    loadBoard({ action: 'remind', id: +v }, function () {
                        toast(s('boardreminded'));
                    });
                    break;
                case 'boardedit':
                    var be = boardItem(+v);
                    if (be) {
                        st.boardEdit = { id: be.id, text: be.text, shown: false };
                        renderBoardPane();
                    }
                    break;
                case 'boardeditcancel':
                    st.boardEdit = null;
                    renderBoardPane();
                    break;
                case 'boardeditsave':
                    if (st.boardEdit && st.boardEdit.text.trim()) {
                        loadBoard({ action: 'edit', id: +v, text: st.boardEdit.text.trim() }, function () {
                            st.boardEdit = null;
                            toast(s('boardsaved'));
                        });
                    }
                    break;
                case 'boardremove':
                    loadBoard({ action: 'remove', id: +v }, function () {
                        st.boardOpen = 0;
                        toast(s('boardremoved'));
                    });
                    break;
                case 'boarddone':
                    st.boardDone = true;
                    renderListBody();
                    break;
                case 'boardagain':
                    st.board = null;
                    renderListBody();
                    break;
                case 'eqopen':
                    // Someone who already has a conversation opens it and the list comes back; someone new keeps «Write to…»
                    // until the first message goes.
                    if (/^c/.test(v) && (st.teamPick || st.teamQ)) {
                        st.teamPick = false;
                        st.teamQ = '';
                        renderListHead();
                    }
                    openRoom(v);
                    break;
                case 'eqpick':
                    if (st.inbox !== 'team') {
                        chooseInbox('team');
                    }
                    st.teamPick = !st.teamPick;
                    st.teamQ = '';
                    renderListHead();
                    renderListBody();
                    if (narrow()) {
                        setLayer('lista', true);
                    }
                    if (st.teamPick) {
                        focusPop('[data-role="eqbuscar"]');
                    }
                    break;
                case 'eqclear':
                    st.teamQ = '';
                    renderListHead();
                    renderListBody();
                    break;
                case 'eqagain':
                    st.team = null;
                    renderListBody();
                    break;
                case 'eqsend':
                    teamSend();
                    break;
                case 'eqretry':
                    teamRetry(v);
                    break;
                case 'eqolder':
                    teamOlder();
                    break;
                case 'eqconv':
                    openFamily(+v);
                    break;
                case 'eqcard':
                    chooseCard(+v);
                    break;
                case 'eqcardoff':
                    if (st.room) {
                        delete st.teamCards[st.room.key];
                        renderRoomComposer();
                        var edq = el.chat.querySelector('.eq-editor');
                        if (edq && !touchy()) {
                            caretToEnd(edq);
                        }
                    }
                    break;
                case 'optoutok':
                    st.optoutOk[v] = true;
                    if (st.nueva) {
                        renderNew();
                    } else {
                        renderComposer(true);
                    }
                    break;
                case 'optoutask':
                case 'optoutnoask':
                    st.optoutAsk = a === 'optoutask' && c ? c.id : 0;
                    renderFicha();
                    break;
                case 'optoutmark':
                    st.optoutAsk = 0;
                    if (c) {
                        act(c.id, 'optout', 0, 'out', true).then(function () {
                            toast(s('optoutmarked'));
                            loadFicha(c.id);
                            return null;
                        }).catch(failToast);
                    }
                    break;
                case 'agendaagain':
                    st.agenda = null;
                    renderListBody();
                    break;
                case 'fav':
                    var was = !!FAVS[+v];
                    if (was) {
                        delete FAVS[+v];
                    } else {
                        FAVS[+v] = true;
                    }
                    t.classList.toggle('mrd-on', !was);
                    t.setAttribute('aria-pressed', String(!was));
                    toast(s(was ? 'favremoved' : 'favadded'));
                    loadAgenda(+v, !was);
                    break;
                case 'capa':
                    setLayer(v, true);
                    break;
                case 'back':
                    goBack(v);
                    break;
                case 'resolvedlist':
                    st.showResolved = true;
                    if (st.resolved === '' || st.resolved === 'error') {
                        loadResolved(0);
                    } else {
                        renderListBody();
                    }
                    break;
                case 'resolvedmore':
                    loadResolved(oldestResolved());
                    break;
                case 'historymore':
                    loadHistory(oldestHistory());
                    renderListBody();
                    break;
                case 'histmine':
                case 'histperiod':
                case 'histteam':
                    if (a === 'histmine') {
                        st.hf.mine = v === '1';
                    } else if (a === 'histperiod') {
                        st.hf.period = v;
                        st.hfMonths = false;
                    } else {
                        st.hf.team = +v;
                        st.hfDep = false;
                    }
                    // Another filter, another history: the server is asked again.
                    st.history = '';
                    st.historyMore = false;
                    renderListBody();
                    break;
                case 'schedday':
                case 'schedtime':
                case 'schedquick':
                    if (c) {
                        schedChoose(c, a, v);
                    }
                    break;
                case 'schedgo':
                    if (c) {
                        scheduleIt(t, c);
                    }
                    break;
                case 'schedpick':
                    if (c) {
                        st.pop = null;
                        st.schedPick = c.id;
                        st.tpl = -1;
                        renderComposer(true);
                        focusPop('.mrd-escribir .mrd-plantilla, .mrd-escribir .mrd-libre');
                    }
                    break;
                case 'schedback':
                    st.schedPick = 0;
                    st.tpl = -1;
                    renderComposer(true);
                    break;
                case 'schednow':
                case 'schedremove':
                case 'schedchange':
                    if (c && !READONLY) {
                        schedDo(t, c, a, +v);
                    }
                    break;
                case 'remday':
                    st.rem.day = v;
                    renderHead();
                    break;
                case 'remsave':
                case 'remclear':
                    if (c) {
                        remind(t, c.id, a === 'remsave' ? st.rem.day : '', st.rem.reason);
                    }
                    break;
                case 'callopen':
                    // From the band of what did not arrive: the sheet of the call, under its button in the head.
                    if (c && !READONLY) {
                        st.pop = 'llamada';
                        st.call.offer = 0;
                        renderHead();
                        focusPop('.mrd-llamada-linea');
                    }
                    break;
                case 'callsave':
                    writeCall(t, v === '1');
                    break;
                case 'calltpl':
                    prepareCallTpl();
                    break;
                case 'callnotnow':
                    closePops();
                    break;
                case 'heldsee':
                    openAnyConv(+v);
                    break;
                case 'heldpass':
                    if (c) {
                        var heldTo = +v, heldList = (st.ficha && st.ficha.held) || [];
                        var heldName = firstName(personName(heldTo)) || (heldList.filter(function (x) {
                            return x.agentid === heldTo;
                        })[0] || {}).firstname || '';
                        runAction(t, 'person', heldTo, '', s('toastgiven', heldName)).then(function (res) {
                            if (res && !res.gone && conv(st.open)) {
                                loadFicha(st.open);
                            }
                            return res;
                        });
                    }
                    break;
                case 'heldlater':
                    if (c) {
                        heldLater(c.id, true);
                        renderBands();
                    }
                    break;
                case 'histclear':
                    st.hf = { mine: false, team: 0, period: '' };
                    st.hfDep = false;
                    st.history = '';
                    st.historyMore = false;
                    renderListBody();
                    break;
                case 'histdep':
                case 'histmonths':
                    // Its own state: the list is drawn again with each poll and what is unfolded stays so.
                    e.preventDefault();
                    if (a === 'histdep') {
                        st.hfDep = !st.hfDep;
                    } else {
                        st.hfMonths = !st.hfMonths;
                    }
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
                    st.pass = null;
                    if (v === 'perfil' || v === 'avisos' || v === 'ayuda') {
                        renderRail();
                        if (st.pop) {
                            focusPop('.mrd-rail .mrd-pop button, .mrd-rail .mrd-pop a');
                        } else {
                            var railOpener = el.rail.querySelector(v === 'perfil' ? '.mrd-yo-mas' : '[data-a="pop"][data-v="' + v + '"]');
                            if (railOpener) {
                                railOpener.focus();
                            }
                        }
                    } else if (v === 'dep' || v === 'dueno' || v === 'recordar' || v === 'llamada') {
                        if (v === 'recordar' && st.pop && c) {
                            st.rem = { day: c.remind ? keyIso(dayKey(c.remind.at * 1000)) : '', reason: c.remind ? c.remind.reason : '' };
                        }
                        st.call.offer = 0;
                        renderHead();
                        // The focus goes into the menu, to its first choice; closed, back to its button.
                        var into = v === 'recordar' ? '.mrd-rem-rapidos .mrd-chip, .mrd-pop button'
                            : v === 'llamada' ? '.mrd-llamada-linea' : '.mrd-pop-fila';
                        focusPop(st.pop ? into : '');
                        if (!st.pop) {
                            var opener = el.chat.querySelector('[data-a="pop"][data-v="' + v + '"]');
                            if (opener) {
                                opener.focus();
                            }
                        }
                    } else if (v === 'eqpasar') {
                        // Passing a conversation: the search of the conversations takes the keys.
                        st.passQ = '';
                        refreshWritePop();
                        focusPop(st.pop ? '.mrd-pop-eqpasar input' : '');
                    } else {
                        if (v === 'programar' && st.pop && c) {
                            schedStart(c);
                        }
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
                        // Second step: a line for the department, if one wants, and the button that says what it does.
                        st.pass = { conv: c.id, team: +v, note: '' };
                        renderHead();
                        focusPop('.mrd-pasar-nota');
                    } else {
                        closePops();
                    }
                    break;
                case 'passback':
                    st.pass = null;
                    renderHead();
                    focusPop('.mrd-pop-fila');
                    break;
                case 'passgo':
                    if (c) {
                        passOn(t, c, +v, st.pass ? st.pass.note : '');
                    }
                    break;
                case 'undo':
                    undo();
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
                    var resolving = c.id;
                    runAction(t, 'resolve', 0, '', null).then(function (res) {
                        if (res && !res.gone && conv(resolving)) {
                            // Resolved at the first touch, and a way back while nobody has written again.
                            st.undo = { conv: resolving, kind: 'resolve', at: nowMs() };
                            toast(s('toastresolved'), false, true);
                            placeUndo();
                        }
                        return res;
                    });
                    break;
                case 'reopen':
                    if (st.undo && st.undo.conv === c.id) {
                        st.undo = null;
                        hideToast();
                    }
                    runAction(t, 'reopen', 0, '', s('toastreopened')).then(function (res) {
                        placeUndo();
                        return res;
                    });
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
                case 'draft':
                    if (st.extras.draftpolicy || st.draftPolicy === undefined) {
                        requestDraft(false);
                    } else {
                        st.pop = st.pop === 'ia' ? null : 'ia';
                        renderComposer(true);
                    }
                    break;
                case 'draftagain':
                    requestDraft(false);
                    break;
                case 'draftundo':
                    undoDraft();
                    break;
                case 'draftaccept':
                    requestDraft(true);
                    break;
                case 'draftdecline':
                    st.pop = null;
                    renderComposer(true);
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
                case 'unfold':
                    if (c) {
                        st.unfold[c.id] = true;
                        renderComposer(true);
                        focusPop('.mrd-escribir .mrd-plantilla, .mrd-escribir .mrd-libre');
                    }
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
                case 'qfmark':
                    if (!c || !st.ficha || t.getAttribute('aria-disabled') === 'true') {
                        if (t.getAttribute('aria-disabled') === 'true') {
                            toast(s('qffromfile'));
                        }
                        break;
                    }
                    var mine = qfMine(st.ficha), was = mine.indexOf(v) >= 0;
                    var next = was ? mine.filter(function (m) {
                        return m !== v;
                    }) : mine.concat([v]);
                    // At once in the card too: a second mark ticked before the first one is saved counts on top of it.
                    var auto = st.ficha.familyauto && (st.ficha.marks || []).indexOf('family') >= 0;
                    st.ficha.marks = (auto ? ['family'] : []).concat(next);
                    busy(t, true);
                    act(c.id, 'whois', 0, JSON.stringify({ marks: next })).then(function () {
                        toast(s(was ? 'qfunmarked' : 'qfmarked', s('mark' + v)));
                        loadFicha(c.id);
                    }).catch(function () {
                        busy(t, false);
                    });
                    break;
                case 'qfface':
                    if (!c) {
                        break;
                    }
                    act(c.id, 'whois', 0, JSON.stringify({ face: v })).then(function () {
                        loadFicha(c.id);
                    });
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
        // A line of «Who is it?» keeps itself when its writer leaves it, if it changed.
        box.addEventListener('focusout', function (e) {
            var t = e.target, c = conv(st.open);
            if (!t.classList || !t.classList.contains('qf-escribe') || !c || !st.ficha) {
                return;
            }
            var key = t.getAttribute('data-note'), text = t.innerText.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
            if (text === ((st.ficha.notes || {})[key] || '')) {
                return;
            }
            act(c.id, 'whois', 0, JSON.stringify({ note: key, text: text }), true).then(function () {
                st.qfSaved = key;
                loadFicha(c.id);
                setTimeout(function () {
                    if (st.qfSaved === key) {
                        st.qfSaved = '';
                        renderFicha();
                    }
                }, 2500);
            });
        });
        box.addEventListener('keydown', function (e) {
            // In a line, Enter ends it, as the line is one.
            if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('qf-escribe')) {
                e.preventDefault();
                e.target.blur();
            }
        });
        box.addEventListener('input', function (e) {
            var t = e.target;
            if (t.classList && t.classList.contains('eq-escribe')) {
                var typed = t.innerText.replace(/\u00a0/g, ' ');
                if (st.boardNew) {
                    st.boardNew.text = typed;
                    var okp = el.chat.querySelector('[data-a="boardpost"]');
                    var anyTeam = st.boardNew.all || Object.keys(st.boardNew.teams).some(function (id) {
                        return st.boardNew.teams[id];
                    });
                    if (okp) {
                        okp.disabled = !(anyTeam && typed.trim());
                    }
                } else if (st.boardEdit) {
                    st.boardEdit.text = typed;
                }
                return;
            }
            if (t.classList && t.classList.contains('gs-motivo') && st.gsReject) {
                st.gsReject.reason = t.value;
                return;
            }
            if (t.classList && t.classList.contains('mrd-pasar-nota') && st.pass) {
                st.pass.note = t.value;
                return;
            }
            if (t.classList && t.classList.contains('mrd-llamada-linea')) {
                st.call.line = t.value;
                return;
            }
            if (t.classList && t.classList.contains('mrd-rem-motivo')) {
                st.rem.reason = t.value;
                // With a reminder already there, a new line is a change to save.
                var rc = conv(st.open), okb = el.chat.querySelector('.mrd-rem-ok');
                if (rc && rc.remind && st.rem.day) {
                    var unchanged = isoKey(st.rem.day) === dayKey(rc.remind.at * 1000) && t.value.trim() === rc.remind.reason;
                    if (unchanged === !!okb) {
                        // The button comes or goes; what is typed stays (renderHead gives the focus back).
                        renderHead();
                    }
                }
                return;
            }
            if (t.getAttribute && t.getAttribute('data-role') === 'nuevabuscar' && st.nueva) {
                findSoon(t.value);
                return;
            }
            if (t.classList && t.classList.contains('eq-editor')) {
                saveTeamDraft();
                return;
            }
            if (t.getAttribute && t.getAttribute('data-role') === 'eqbuscar') {
                st.teamQ = t.value;
                var clearq = el.lista.querySelector('.mrd-limpiar');
                if (clearq) {
                    clearq.hidden = !st.teamQ;
                }
                renderListBody();
                return;
            }
            if (t.getAttribute && t.getAttribute('data-role') === 'eqpasarbuscar') {
                passSearch(t.value);
                return;
            }
            if (t.getAttribute && t.getAttribute('data-role') === 'buscar') {
                st.search = t.value;
                // A search looks in every conversation of whom it finds, whenever it was, not only in what is open.
                clearTimeout(searchT);
                var asked = st.search.trim();
                if (asked.length >= 2) {
                    st.searchFor = asked;
                    st.searchScope = 'loading';
                    searchT = setTimeout(function () {
                        tr.call(M.resolved, { query: asked }).then(function (r) {
                            (r.conversations || []).forEach(mergeConv);
                            st.hits = {};
                            (r.hits || []).forEach(function (hit) {
                                st.hits[hit.conversation] = hit;
                            });
                            if (st.search.trim() === asked) {
                                st.searchScope = 'server';
                                renderListBody();
                            }
                            return r;
                        }).catch(function () {
                            // What is on the page is still searched, and the head says so.
                            if (st.search.trim() === asked) {
                                st.searchScope = 'page';
                                renderListBody();
                            }
                        });
                    }, 350);
                }
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
                if (t.innerHTML === '<br>') {
                    t.innerHTML = '';
                }
                updateGaps();
            }
        });
        // The keyboards of the phones that send no «Enter» key.
        box.addEventListener('beforeinput', function (e) {
            if ((e.inputType === 'insertParagraph' || e.inputType === 'insertLineBreak') && e.target.classList &&
                e.target.classList.contains('mrd-hueco')) {
                e.preventDefault();
                sayOneLine();
            }
        });
        box.addEventListener('paste', function (e) {
            var t = e.target.closest && e.target.closest('.mrd-editor, .mrd-hueco');
            if (!t) {
                return;
            }
            e.preventDefault();
            var text = (e.clipboardData || root.clipboardData).getData('text/plain') || '';
            if (t.classList.contains('mrd-hueco')) {
                var lines = /\S\s*[\r\n\u2028\u2029]\s*\S/.test(text);
                insertText(lines ? oneLine(text) : text.replace(/\s+/g, ' '));
                if (lines) {
                    sayOneLine();
                }
                return;
            }
            insertText(text);
        });
        box.addEventListener('keydown', function (e) {
            var t = e.target;
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && t.classList && t.classList.contains('mrd-pasar-nota')) {
                e.preventDefault();
                var go = el.chat.querySelector('[data-a="passgo"]');
                if (go && !go.disabled) {
                    go.click();
                }
                return;
            }
            if (e.key === 'Enter' && t.classList && t.classList.contains('mrd-rem-motivo')) {
                e.preventDefault();
                var ok = el.chat.querySelector('.mrd-rem-ok');
                if (ok && !ok.disabled) {
                    ok.click();
                }
                return;
            }
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
                    return;
                }
                if (t.getAttribute && t.getAttribute('data-role') === 'nuevabuscar' && t.value) {
                    t.value = '';
                    findSoon('');
                    return;
                }
                if (t.getAttribute && t.getAttribute('data-role') === 'eqbuscar' && st.teamQ) {
                    st.teamQ = '';
                    t.value = '';
                    renderListBody();
                    return;
                }
                if (st.nueva) {
                    closeNew();
                }
                return;
            }
            if (t.getAttribute && t.getAttribute('data-role') === 'nuevabuscar' && e.key === 'Enter') {
                e.preventDefault();
                var cands = st.nueva && st.nueva.found;
                if (cands && cands.length && !st.nueva.searching) {
                    st.nueva.pick = cands[0];
                    st.nueva.tpl = -1;
                    renderNew();
                    loadNewFicha();
                }
                return;
            }
            if (t.classList && t.classList.contains('mrd-hueco') && e.key === 'Enter') {
                // No line breaks in a gap. From a short one, Enter goes to what is still missing. In the message it stays where
                // it is and says why: whoever leaves a blank line presses it twice, and that cannot send it half written. So
                // it never goes to the button either.
                e.preventDefault();
                if (!e.isComposing) {
                    var own = t.classList.contains('mrd-hueco-mensaje'), nextGap = own ? null : emptyGap(t);
                    if (own) {
                        sayOneLine();
                    } else if (nextGap) {
                        placeCaret(nextGap, -1);
                    }
                }
                return;
            }
            // Between the team: Enter sends, as with the families; Shift+Enter, a new line.
            if (t.classList && t.classList.contains('eq-editor')) {
                if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !touchy()) {
                    e.preventDefault();
                    teamSend();
                } else if (e.key === 'Enter' && e.shiftKey) {
                    e.preventDefault();
                    insertText('\n');
                }
                return;
            }
            // In the search of a conversation to pass, Enter takes the first one.
            if (t.getAttribute && t.getAttribute('data-role') === 'eqpasarbuscar' && e.key === 'Enter') {
                e.preventDefault();
                var firstCard = el.chat.querySelector('.mrd-pop-eqpasar [data-a="eqcard"]');
                if (firstCard) {
                    chooseCard(+firstCard.getAttribute('data-v'));
                }
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
        // Escape closes a menu also when it was opened with the pointer and the focus stayed on the page.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || box.contains(e.target)) {
                return;
            }
            if (!el.visor.hidden) {
                closeViewer();
            } else if (closePops()) {
                e.preventDefault();
            }
        });
        box.addEventListener('scroll', function (e) {
            var t = e.target;
            if (t.classList && t.classList.contains('mrd-hilo')) {
                var room = st.inbox === 'team' && !conv(st.open) ? st.room : null;
                if (room) {
                    if (t.scrollTop < 60 && room.more && !room.older) {
                        teamOlder();
                    }
                } else if (t.scrollTop < 60 && st.more && !st.older) {
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
                placeUndo();
            } else {
                renderHome();
            }
        }, 30000);
        setInterval(function () {
            if (st.ready && !document.hidden) {
                refreshExtras(0);
            }
        }, 300000);

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
                    extrasAt = Date.now();
                }
                var r = res[1] || {};
                if (r.now) {
                    skew = r.now * 1000 - Date.now();
                }
                st.inboxes = r.inboxes || null;
                asked(r);
                (r.conversations || []).forEach(mergeConv);
                st.cursor = r.cursor !== undefined ? r.cursor : 0;
                if (r.board) {
                    st.boardRev = r.board.rev;
                }
                if (r.team) {
                    st.teamUnread = +r.team.unread || 0;
                    st.teamRev = r.team.rev;
                }
                if (startCfg.aviso) {
                    st.boardOpen = +startCfg.aviso;
                }
                // A notice of a message of the team (chat.php?eq=…): «Mensajes», with that conversation open.
                var wantTeam = READONLY ? 0 : +startCfg.team || 0;
                if (READONLY && inZone()) {
                    // Whoever only reads has no messages nor board between the team.
                    st.inbox = 'mine';
                } else if (wantTeam || st.inbox === 'team') {
                    st.lastTray = !inZone() ? st.inbox : 'mine';
                    st.inbox = 'team';
                    st.teamHalf = 'team';
                }
                loadBoard();
                loadSpend();
                setInterval(function () {
                    if (!document.hidden) {
                        loadSpend();
                    }
                }, 300000);
                st.ready = true;
                renderAll();
                updateTitle();
                if (!tourSeen('inicio') && canTour()) {
                    setTimeout(function () {
                        startTour('inicio');
                    }, 700);
                }
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
                } else if (wantTeam) {
                    openRoom('c' + wantTeam);
                }
                // Without a conversation in the address, the start: what is yours and what waits, before any conversation.
                showRed(st.unchecked === null ? null : 'unchecked');
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
