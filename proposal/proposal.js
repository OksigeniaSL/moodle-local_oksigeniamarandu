/* «Sugerencias»: the common piece of the tools for ideas and for what does not work. A page loads it (api::proposal_js()
   in PHP) and a button with data-marandu-proposal="<component>" (and data-screen, data-tool, data-release) opens it; or
   window.MaranduProposal.open({component, screen, tool, release, kind}). It takes a picture of what the person sees of
   the page, drawn in the browser (nothing outside the page); the person says whether it is an idea or something that does
   not work, marks on the picture with a finger or the mouse and writes it; something that does not work goes with the
   version, the browser and the screen. Plain ES5, no build: it works on any page of the site, and loads its own sheet.
   The drawing of the page is html2canvas, loaded only when someone asks for it. */
(function (root) {
    'use strict';

    var SKIP = 'data-marandu-proposal-skip';
    var layer = null;
    var loading = null;

    // What the page says: window.ProponerConfig set before this script, or the JSON a page prints in
    // <script type="application/json" id="marandu-proposal"> (or id="proponer"; api::proposal_assets() in Marandu).
    // strings: the texts. send: function (fields) returning a promise; or endpoint: the address of a receiver such as
    // Oksigenia's (fields as it takes them, plus producto); or service: the external function of Moodle that keeps it
    // (local_oksigeniamarandu_proposal_send by default). css and drawer: the addresses of the sheet and of html2canvas
    // (by default, next to Marandu's in the site). producto, sitio: for a receiver outside Moodle.
    var printed = null;
    function data() {
        if (printed === null) {
            var node = document.getElementById('marandu-proposal') || document.getElementById('proponer');
            try {
                printed = node ? JSON.parse(node.textContent) : {};
            } catch (e) {
                printed = {};
            }
            var own = root.ProponerConfig || {};
            for (var key in own) {
                if (Object.prototype.hasOwnProperty.call(own, key)) {
                    printed[key] = own[key];
                }
            }
        }
        return printed;
    }
    function str(key) {
        var s = root.MaranduProposalStrings || data().strings || {};
        return s[key] || key;
    }
    function asset(name) {
        var d = data();
        if (name === 'css' && d.css) {
            return d.css;
        }
        if (name === 'drawer' && d.drawer) {
            return d.drawer;
        }
        return cfg().wwwroot + '/local/oksigeniamarandu/proposal/' + (name === 'css' ? 'proposal.css' : 'html2canvas.min.js');
    }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) {
            e.className = cls;
        }
        if (text) {
            e.textContent = text;
        }
        return e;
    }
    function cfg() {
        return root.M && root.M.cfg && root.M.cfg.sesskey ? root.M.cfg : data().config || { wwwroot: '', sesskey: '' };
    }

    // html2canvas, once. On a page of Moodle it goes through RequireJS, as the anonymous module its wrapper declares
    // there (putting «define» aside instead breaks the modules Moodle is loading at the same time); elsewhere, as a
    // plain script.
    function loadDrawer() {
        if (root.html2canvas) {
            return Promise.resolve(root.html2canvas);
        }
        if (loading) {
            return loading;
        }
        var url = asset('drawer');
        loading = new Promise(function (resolve, reject) {
            if (typeof root.require === 'function' && root.define && root.define.amd) {
                root.require([url], function (draw) {
                    if (draw) {
                        resolve(draw);
                    } else {
                        reject(new Error('html2canvas'));
                    }
                }, function (e) {
                    loading = null;
                    reject(e);
                });
                return;
            }
            var s = document.createElement('script');
            s.src = url;
            s.onload = function () {
                if (root.html2canvas) {
                    resolve(root.html2canvas);
                } else {
                    reject(new Error('html2canvas'));
                }
            };
            s.onerror = function () {
                loading = null;
                reject(new Error('html2canvas'));
            };
            document.head.appendChild(s);
        });
        return loading;
    }

    // The colours html2canvas does not know (it stops at the first one): the browser gives color-mix() already worked out
    // as color(srgb …), and oklch(), oklab(), lab() and lch() as they are.
    var MODERN = /\b(?:color|color-mix|oklch|oklab|lab|lch|hwb|light-dark)\((?:[^()]|\([^()]*\))*\)/g;
    var COLOURED = ['color', 'background-color', 'background-image', 'border-top-color', 'border-right-color',
        'border-bottom-color', 'border-left-color', 'box-shadow', 'text-shadow', 'text-decoration-color',
        '-webkit-text-stroke-color'];
    var plains = {};
    var brush = null;

    // One of those colours as rgba(), worked out by the browser itself. The opacity goes apart: a pixel that is almost
    // transparent keeps little of its colour.
    function plainColour(value) {
        if (plains[value]) {
            return plains[value];
        }
        var alpha = 1;
        var solid = value.replace(/\s*\/\s*([\d.]+)(%?)\s*\)$/, function (all, number, percent) {
            alpha = parseFloat(number) / (percent ? 100 : 1);
            return ')';
        });
        if (!brush) {
            var canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            brush = canvas.getContext('2d', { willReadFrequently: true });
        }
        brush.fillStyle = '#000000';
        brush.fillStyle = solid;
        var first = brush.fillStyle;
        brush.fillStyle = '#ffffff';
        brush.fillStyle = solid;
        if (first !== brush.fillStyle) {
            // The browser does not know it either: better no colour than no picture.
            plains[value] = 'rgba(0, 0, 0, 0)';
            return plains[value];
        }
        brush.clearRect(0, 0, 1, 1);
        brush.fillRect(0, 0, 1, 1);
        var dot = brush.getImageData(0, 0, 1, 1).data;
        plains[value] = 'rgba(' + dot[0] + ', ' + dot[1] + ', ' + dot[2] + ', ' + alpha * dot[3] / 255 + ')';
        return plains[value];
    }

    // A picture as another element would show it, as a data: address of the size of the box; null if it cannot be drawn.
    function painted(width, height, paint) {
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * 2));
        canvas.height = Math.max(1, Math.round(height * 2));
        try {
            var ctx = canvas.getContext('2d');
            paint(ctx, canvas.width, canvas.height);
            return canvas.toDataURL('image/png');
        } catch (e) {
            return null;
        }
    }
    // An image, loaded; null if it does not load (it never keeps the person waiting for more than a moment).
    function loadedImage(src) {
        return new Promise(function (resolve) {
            var image = new Image();
            image.onload = function () {
                resolve(image);
            };
            image.onerror = function () {
                resolve(null);
            };
            setTimeout(function () {
                resolve(null);
            }, 3000);
            image.src = src;
        });
    }
    // The address of the first image of a property such as mask-image; empty if it is none or a gradient.
    function imageUrl(value) {
        var found = /^url\((["']?)(.*?)\1\)/.exec(value || '');
        return found ? found[2] : '';
    }
    // An icon made with a mask (its colour is the background, its shape the mask): html2canvas paints the whole square.
    function plainMask(node, style, url) {
        var box = node.getBoundingClientRect();
        var size = style.getPropertyValue('mask-size') || style.getPropertyValue('-webkit-mask-size');
        var colour = style.backgroundColor;
        return loadedImage(url).then(function (image) {
            var picture = image && box.width && box.height ? painted(box.width, box.height, function (ctx, w, h) {
                var iw = image.naturalWidth || w;
                var ih = image.naturalHeight || h;
                var px = /^([\d.]+)px(?: ([\d.]+)px)?/.exec(size || '');
                var fit = size === 'cover' ? Math.max(w / iw, h / ih) : Math.min(w / iw, h / ih);
                var dw = px ? px[1] * 2 : iw * fit;
                var dh = px ? (px[2] || px[1] * ih / iw) * 2 : ih * fit;
                ctx.drawImage(image, (w - dw) / 2, (h - dh) / 2, dw, dh);
                ctx.globalCompositeOperation = 'source-in';
                ctx.fillStyle = colour;
                ctx.fillRect(0, 0, w, h);
            }) : null;
            put(node, 'mask-image', 'none');
            put(node, '-webkit-mask-image', 'none');
            put(node, 'background-color', 'transparent');
            put(node, 'background-image', picture ? 'url("' + picture + '")' : 'none');
            put(node, 'background-size', '100% 100%');
            put(node, 'background-position', '0 0');
        });
    }
    // An image that is an SVG with no size of its own: html2canvas finds it empty and leaves it out.
    function plainImage(node) {
        var box = node.getBoundingClientRect();
        if (!box.width || !box.height) {
            return null;
        }
        var src = node.currentSrc || node.src;
        return loadedImage(src).then(function (image) {
            var picture = image ? painted(box.width, box.height, function (ctx, w, h) {
                // With no size of its own, the browser gives it one of its shape: whole and centred, as «contain».
                var fit = image.naturalWidth && image.naturalHeight ? Math.min(w / image.naturalWidth, h / image.naturalHeight) : 0;
                var dw = fit ? image.naturalWidth * fit : w;
                var dh = fit ? image.naturalHeight * fit : h;
                ctx.drawImage(image, (w - dw) / 2, (h - dh) / 2, dw, dh);
            }) : null;
            if (!picture) {
                return null;
            }
            node.removeAttribute('srcset');
            put(node, 'width', box.width + 'px');
            put(node, 'height', box.height + 'px');
            node.src = picture;
            return node.decode ? node.decode().catch(function () {
                return null;
            }) : null;
        });
    }
    function put(node, property, value) {
        node.style.setProperty('transition', 'none', 'important');
        node.style.setProperty(property, value, 'important');
    }

    // The copy of the page html2canvas is about to draw, with what it cannot draw changed: those colours, as rgba(); the
    // shadows, which it paints inside the boxes too and go away; what a closed <details> hides, which it draws; the icons
    // made with a mask and the SVG images with no size. Only in the copy, and through the styles of each element (no style
    // sheet, for the pages with a policy that allows none).
    function drawable(copy) {
        // html2canvas puts the ::before and ::after of a field (a switch drawn with appearance: none) inside it as a node
        // of its own, and fails reading it. A field draws no children: nothing is lost.
        var inside = copy.querySelectorAll('input > *, textarea > *');
        for (var k = 0; k < inside.length; k++) {
            inside[k].parentNode.removeChild(inside[k]);
        }
        // The texts only a screen reader reads («Ir al contenido principal»): html2canvas does not honour the clip that
        // hides them and would paint them over the logo.
        var hidden = copy.querySelectorAll('.sr-only, .visually-hidden, .visually-hidden-focusable, .accesshide');
        for (k = 0; k < hidden.length; k++) {
            hidden[k].style.setProperty('display', 'none', 'important');
        }
        // What a page says for a browser without JavaScript: in the copy it comes back as text, and html2canvas painted it
        // in a heap over the logo (the header of Moodle has one).
        var quiet = copy.querySelectorAll('noscript');
        for (k = 0; k < quiet.length; k++) {
            quiet[k].parentNode.removeChild(quiet[k]);
        }
        // What enters with an animation starts it again in the copy and would be drawn in its first frame, faded: every
        // animation jumps to its end, keeping what it leaves there (fill-mode), and nothing changes by transition. Before
        // anything is read. A sheet reaches the ::before and ::after too, where the page allows one; the style of each
        // element, where it does not.
        var still = copy.createElement('style');
        still.textContent = '*, *::before, *::after { animation-duration: 0s !important; animation-delay: 0s !important; ' +
            'transition: none !important; }';
        (copy.head || copy.documentElement).appendChild(still);
        var all = copy.querySelectorAll('*');
        var moving = [];
        for (k = 0; k < all.length; k++) {
            var name = all[k].style ? copy.defaultView.getComputedStyle(all[k]).getPropertyValue('animation-name') : '';
            if (name && name !== 'none') {
                moving.push(all[k]);
            }
        }
        for (k = 0; k < moving.length; k++) {
            moving[k].style.setProperty('animation-duration', '0s', 'important');
            moving[k].style.setProperty('animation-delay', '0s', 'important');
            moving[k].style.setProperty('transition', 'none', 'important');
        }
        var nodes = copy.querySelectorAll('*');
        var found = [];
        var later = [];
        var i;
        var j;
        for (i = 0; i < nodes.length; i++) {
            if (nodes[i].ownerSVGElement || !nodes[i].style) {
                continue;
            }
            var parent = nodes[i].parentNode;
            if (parent && parent.nodeName === 'DETAILS' && !parent.open && nodes[i].nodeName !== 'SUMMARY') {
                // Its colours too, below: html2canvas reads them before it sees that it does not show.
                found.push([nodes[i], 'display', 'none']);
            }
            if (nodes[i].nodeName === 'SUMMARY') {
                // Its little triangle would come out as the number of a list.
                found.push([nodes[i], 'list-style', 'none']);
            }
            var style = copy.defaultView.getComputedStyle(nodes[i]);
            // The same, outside Moodle: whatever is clipped away to nothing.
            if (style.getPropertyValue('clip') === 'rect(0px, 0px, 0px, 0px)' || style.getPropertyValue('clip-path') === 'inset(50%)') {
                found.push([nodes[i], 'display', 'none']);
                continue;
            }
            for (j = 0; j < COLOURED.length; j++) {
                var value = style.getPropertyValue(COLOURED[j]);
                if (COLOURED[j] === 'box-shadow') {
                    if (value && value !== 'none') {
                        found.push([nodes[i], 'box-shadow', 'none']);
                    }
                } else if (value && value.search(MODERN) !== -1) {
                    found.push([nodes[i], COLOURED[j], value.replace(MODERN, plainColour)]);
                }
            }
            var mask = imageUrl(style.getPropertyValue('mask-image') || style.getPropertyValue('-webkit-mask-image'));
            if (mask) {
                later.push([plainMask, nodes[i], style, mask]);
            } else if (nodes[i].nodeName === 'IMG' && /\.svg(?:[?#]|$)|^data:image\/svg/i.test(nodes[i].currentSrc || nodes[i].src)) {
                later.push([plainImage, nodes[i]]);
            }
        }
        // All read before any is written, or a colour that passes on to the children would be read already changed.
        for (i = 0; i < found.length; i++) {
            put(found[i][0], found[i][1], found[i][2]);
        }
        return Promise.all(later.map(function (one) {
            return one[0](one[1], one[2], one[3]);
        }));
    }

    // What the person sees, as a canvas; null if the browser cannot draw it.
    function picture() {
        var doc = document.documentElement;
        return loadDrawer().then(function (draw) {
            return draw(document.body, {
                x: root.scrollX || root.pageXOffset || 0,
                y: root.scrollY || root.pageYOffset || 0,
                width: doc.clientWidth,
                height: root.innerHeight,
                windowWidth: doc.clientWidth,
                windowHeight: root.innerHeight,
                scale: Math.min(root.devicePixelRatio || 1, 1.5),
                useCORS: true,
                logging: false,
                backgroundColor: (getComputedStyle(document.body).backgroundColor || '#ffffff').replace(MODERN, plainColour),
                onclone: drawable,
                ignoreElements: function (node) {
                    return !!(node.hasAttribute && node.hasAttribute(SKIP));
                }
            });
        }).catch(function () {
            return null;
        });
    }

    function call(args) {
        var d = data();
        if (typeof d.send === 'function') {
            return Promise.resolve(d.send(args));
        }
        if (d.endpoint) {
            // Straight to a receiver such as Oksigenia's, from outside Moodle: without the name of the person.
            return fetch(d.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    producto: d.producto || args.component, version: d.version || '', tipo: args.kind === 'issue' ? 'incidencia' : 'idea',
                    texto: args.text, pantalla: args.screen, pagina: args.url, navegador: args.browser,
                    sitio: d.sitio || root.location.hostname, idioma: document.documentElement.lang || '',
                    imagen: args.image || undefined
                })
            }).then(function (r) {
                if (!r.ok) {
                    throw new Error('receiver ' + r.status);
                }
                return r.json();
            });
        }
        var c = cfg();
        var service = d.service || 'local_oksigeniamarandu_proposal_send';
        return fetch(c.wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(c.sesskey) +
            '&info=' + encodeURIComponent(service), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify([{ index: 0, methodname: service, args: args }])
        }).then(function (r) {
            return r.json();
        }).then(function (d) {
            var one = Array.isArray(d) ? d[0] : d;
            if (!one || one.error || (d && d.error)) {
                throw new Error((one && one.exception && one.exception.message) || (d && d.error) || 'error');
            }
            return one.data;
        });
    }

    // The sheet: «Sugerencias» (its design, 28-11-2026). The page, taken as it was, goes onto a desk,
    // and is marked there at the size it fits; beside it (below, on a phone), the sheet with the two ways in.
    var ICONS = {
        sugerencias: '<path d="M6.5 3.5h7.5l4.5 4.5v12.5h-12z"/><path d="M14 3.5v4.5h4.5"/>' +
            '<path d="M9 14.2c1.1-1.2 2.1-1.2 3 0s1.9 1.2 3 0"/>',
        idea: '<path d="M4.5 4.5h15v9.5l-5.5 5.5h-9.5z"/><path d="M19.5 14h-5.5v5.5"/><path d="M8 9h7.5M8 12.5h4"/>',
        issue: '<path d="M12.5 3.5h8v8l-9 9-8-8z"/><circle cx="16.8" cy="7.2" r="1.3"/><path d="M8 11.5l2 2M10.5 9l2 2"/>',
        x: '<path d="M6 6l12 12M18 6l-12 12"/>',
        check: '<path d="M5 12.5l4.5 4.5 9.5-10"/>',
        goma: '<path d="M9.5 19.5h10"/><path d="M4.6 14.6l8.8-8.8a2 2 0 0 1 2.8 0l2.5 2.5a2 2 0 0 1 0 2.8l-8.7 8.7h-3.4l-2-2a2.2 2.2 0 0 1 0-3.2z"/>' +
            '<path d="M8.5 10.7l5.3 5.3"/>',
        flecha: '<path d="M9 5.5l6.5 6.5-6.5 6.5"/>',
        atras: '<path d="M15 5.5l-6.5 6.5 6.5 6.5"/>'
    };
    function icon(name) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" ' +
            'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' + ICONS[name] + '</svg>';
    }
    function esc(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fill(text, values) {
        return String(text).replace(/\{(\w+)\}/g, function (all, name) {
            return values[name] !== undefined ? values[name] : all;
        });
    }
    function html(markup) {
        var t = document.createElement('template');
        t.innerHTML = markup.trim();
        return t.content.firstChild;
    }

    // The colours of the page: a highlighter multiplies on paper, and on a dark page it is laid on lighter.
    function darkPage() {
        var theme = document.documentElement.getAttribute('data-bs-theme');
        if (theme) {
            return theme === 'dark';
        }
        return !!(root.matchMedia && root.matchMedia('(prefers-color-scheme: dark)').matches);
    }
    function narrow() {
        return root.innerWidth < 700;
    }
    function touch() {
        return !!(root.matchMedia && root.matchMedia('(pointer: coarse)').matches);
    }

    // The browser in words («Chrome 131», «Windows»), not the whole string of the agent.
    function browserParts() {
        var ua = navigator.userAgent || '';
        var name = '';
        var m;
        if ((m = /Edg(?:e|A|iOS)?\/(\d+)/.exec(ua))) {
            name = 'Edge ' + m[1];
        } else if ((m = /OPR\/(\d+)/.exec(ua))) {
            name = 'Opera ' + m[1];
        } else if ((m = /(?:Chrome|CriOS)\/(\d+)/.exec(ua))) {
            name = 'Chrome ' + m[1];
        } else if ((m = /(?:Firefox|FxiOS)\/(\d+)/.exec(ua))) {
            name = 'Firefox ' + m[1];
        } else if ((m = /Version\/(\d+)[^ ]* .*Safari/.exec(ua))) {
            name = 'Safari ' + m[1];
        }
        var system = /Android/.test(ua) ? 'Android' : /iPhone|iPad|iPod/.test(ua) ? 'iOS' : /CrOS/.test(ua) ? 'ChromeOS' :
            /Windows/.test(ua) ? 'Windows' : /Mac OS X|Macintosh/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
        return { name: name, system: system };
    }
    function browserLine() {
        var b = browserParts();
        return b.name && b.system ? fill(str('browserin'), { browser: b.name, system: b.system }) : (b.name || b.system);
    }
    function browserFull() {
        var b = browserParts();
        var doc = document.documentElement;
        return [b.name, b.system, doc.clientWidth + '×' + root.innerHeight, darkPage() ? str('dark') : str('light')]
            .filter(function (part) {
                return !!part;
            }).join(' · ');
    }

    var state = null;

    function close() {
        if (layer) {
            layer.remove();
            layer = null;
            state = null;
            document.removeEventListener('keydown', onKey, true);
            if (root.__sgReturn && root.__sgReturn.focus) {
                root.__sgReturn.focus();
            }
        }
    }
    function onKey(e) {
        if (e.key === 'Escape' && layer && !layer.classList.contains('sg-enviando')) {
            e.preventDefault();
            close();
        }
    }

    // The marks: free strokes kept as points in the pixels of the picture, drawn again whole each time, so that a
    // highlighter does not darken where it crosses itself. An idea, a highlighter; what does not work, a red pen.
    function strokeStyle(ctx, kind, scale, forOutput) {
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        if (kind === 'idea') {
            ctx.lineWidth = 22 * scale;
            if (darkPage()) {
                ctx.globalCompositeOperation = 'source-over';
                ctx.strokeStyle = 'rgba(255, 212, 0, 0.36)';
            } else {
                ctx.globalCompositeOperation = forOutput ? 'multiply' : 'source-over';
                ctx.strokeStyle = 'rgba(255, 208, 0, 0.5)';
            }
        } else {
            ctx.globalCompositeOperation = 'source-over';
            ctx.lineWidth = 4.5 * scale;
            ctx.strokeStyle = '#d23f31';
        }
    }
    function drawStrokes(ctx, strokes, kind, scale, forOutput) {
        strokeStyle(ctx, kind, scale, forOutput);
        strokes.forEach(function (points) {
            if (!points.length) {
                return;
            }
            ctx.beginPath();
            ctx.moveTo(points[0].x * (forOutput || 1), points[0].y * (forOutput || 1));
            if (points.length === 1) {
                ctx.lineTo(points[0].x * (forOutput || 1) + 0.1, points[0].y * (forOutput || 1) + 0.1);
            }
            for (var i = 1; i < points.length; i++) {
                ctx.lineTo(points[i].x * (forOutput || 1), points[i].y * (forOutput || 1));
            }
            ctx.stroke();
        });
        ctx.globalCompositeOperation = 'source-over';
    }
    // The picture of the page in a box of the size that fits, with the marks over it; drawing on it adds strokes.
    function captureBox(maxw, maxh, drawable) {
        var shot = state.shot;
        var page = state.page;
        var s = Math.min(maxw / page.w, maxh / page.h);
        var box = html('<div class="sg-captura"></div>');
        box.style.width = Math.round(page.w * s) + 'px';
        box.style.height = Math.round(page.h * s) + 'px';
        var copy = document.createElement('canvas');
        copy.width = shot.width;
        copy.height = shot.height;
        copy.getContext('2d').drawImage(shot, 0, 0);
        box.appendChild(copy);
        var marks = document.createElement('canvas');
        marks.className = 'sg-marcas';
        marks.width = shot.width;
        marks.height = shot.height;
        if (!darkPage()) {
            marks.style.mixBlendMode = 'multiply';
        }
        box.appendChild(marks);
        var ctx = marks.getContext('2d');
        var ratio = shot.width / page.w;
        var redraw = function () {
            ctx.clearRect(0, 0, marks.width, marks.height);
            drawStrokes(ctx, state.strokes, state.kind, ratio, 0);
        };
        redraw();
        box.redraw = redraw;
        if (!drawable) {
            marks.style.pointerEvents = 'none';
            return box;
        }
        marks.setAttribute('aria-label', str(state.kind === 'idea' ? 'markidea' : 'markissue'));
        var current = null;
        var at = function (e) {
            var r = marks.getBoundingClientRect();
            return { x: (e.clientX - r.left) * marks.width / r.width, y: (e.clientY - r.top) * marks.height / r.height };
        };
        var queued = false;
        var later = function () {
            if (!queued) {
                queued = true;
                (root.requestAnimationFrame || setTimeout)(function () {
                    queued = false;
                    redraw();
                });
            }
        };
        marks.addEventListener('pointerdown', function (e) {
            current = [at(e)];
            state.strokes.push(current);
            marks.setPointerCapture(e.pointerId);
            later();
            e.preventDefault();
        });
        marks.addEventListener('pointermove', function (e) {
            if (current) {
                current.push(at(e));
                later();
                e.preventDefault();
            }
        });
        var stop = function () {
            if (current) {
                current = null;
                counted();
            }
        };
        marks.addEventListener('pointerup', stop);
        marks.addEventListener('pointercancel', stop);
        return box;
    }

    // The picture with its marks, as a JPEG small enough to send.
    function compose() {
        var shot = state.shot;
        var out = document.createElement('canvas');
        var scale = Math.min(1, 2200 / Math.max(shot.width, 1));
        out.width = Math.round(shot.width * scale);
        out.height = Math.round(shot.height * scale);
        var ctx = out.getContext('2d');
        ctx.drawImage(shot, 0, 0, out.width, out.height);
        drawStrokes(ctx, state.strokes, state.kind, (shot.width / state.page.w) * scale, scale);
        return out.toDataURL('image/jpeg', 0.82);
    }

    function marksLine() {
        var n = state.strokes.length;
        return n === 0 ? str('marks0') : n === 1 ? str('marks1') : fill(str('marksn'), { n: n });
    }
    // The line of the marks says how many there are, as they are drawn.
    function counted() {
        if (!layer) {
            return;
        }
        var line = layer.querySelector('[data-sg-marcas]');
        if (line) {
            line.textContent = marksLine();
        }
    }

    function header(title, back) {
        var h = '<header class="sg-cab">';
        if (back) {
            h += '<button type="button" class="sg-redondo" data-sg="back" aria-label="' + esc(str('back')) + '">' + icon('atras') +
                '</button>';
        } else {
            h += '<span class="sg-marca">' + icon('sugerencias') + '</span>';
        }
        return h + '<h2>' + esc(title) + '</h2><button type="button" class="sg-redondo" data-sg="close" aria-label="' +
            esc(str('close')) + '">' + icon('x') + '</button></header>';
    }
    function chooseHtml() {
        var h = (narrow() ? '<div class="sg-asa"></div>' : '') + header(str('title'), false);
        h += '<p class="sg-intro">' + esc(str('intro')) + '</p><div class="sg-caminos">';
        ['idea', 'issue'].forEach(function (k) {
            h += '<button type="button" class="sg-camino sg-t-' + (k === 'idea' ? 'idea' : 'fallo') + '" data-sg="kind" ' +
                'data-kind="' + k + '"><span class="sg-sello">' + icon(k) + '</span><span><b>' + esc(str(k)) + '</b><small>' +
                esc(str(k + 'desc')) + '</small></span>' + icon('flecha') + '</button>';
        });
        return h + '</div><p class="sg-nota">' + esc(str('goes')) + '</p>';
    }
    function kindsHtml() {
        return '<div class="sg-tipos" role="radiogroup" aria-label="' + esc(str('title')) + '">' + ['idea', 'issue'].map(function (k) {
            return '<button type="button" role="radio" class="sg-t-' + (k === 'idea' ? 'idea' : 'fallo') + '" data-sg="switch" ' +
                'data-kind="' + k + '" aria-checked="' + (k === state.kind) + '">' + icon(k) + esc(str(k + 'short')) + '</button>';
        }).join('') + '</div>';
    }
    function goesHtml() {
        var parts = [];
        var tool = (state.opts.tool || '') + (state.opts.release ? ' ' + state.opts.release : '');
        if (tool.trim()) {
            parts.push(tool.trim());
        }
        if (browserLine()) {
            parts.push(browserLine());
        }
        if (state.opts.screen) {
            parts.push(state.opts.screen);
        }
        return '<p class="sg-va"><b>' + esc(str('alsogoes')) + '</b>' + parts.map(function (p) {
            return '<span>' + esc(p) + '</span>';
        }).join('') + '</p>';
    }
    function writeHtml() {
        var k = state.kind;
        var t = k === 'idea' ? 'idea' : 'fallo';
        var h = (narrow() ? '<div class="sg-asa"></div>' : '') + header(str(k), true);
        if (!state.shot) {
            h += '<p class="sg-sin-captura">' + esc(str('nopicture')) + '</p>';
        } else if (!narrow()) {
            h += kindsHtml();
            h += '<div class="sg-captura-linea sg-t-' + t + '">' + icon('check') + '<span data-sg-marcas>' + esc(marksLine()) +
                '</span><button type="button" class="sg-enlace" data-sg="clear">' + icon('goma') + esc(str('clear')) + '</button></div>';
        } else {
            h += '<div class="sg-mini-fila"><span data-sg-mini></span><div><b data-sg-marcas>' + esc(marksLine()) + '</b>' +
                '<button type="button" class="sg-enlace" data-sg="again">' + esc(str('again')) + '</button></div></div>';
        }
        h += '<div><label class="sg-etiqueta" for="sg-texto">' + esc(str(k === 'idea' ? 'whatidea' : 'whatissue')) + '</label>' +
            '<textarea class="sg-texto" id="sg-texto" rows="4" maxlength="4000"></textarea></div>';
        if (k === 'issue') {
            h += goesHtml();
        }
        return h + '<div class="sg-pie"><p class="sg-estado" role="status"></p><button type="button" class="sg-enviar" ' +
            'data-sg="send">' + esc(str(k === 'idea' ? 'sendidea' : 'sendissue')) + '</button><p class="sg-nota">' +
            esc(str(k === 'idea' ? 'whoidea' : 'whoissue')) + '</p></div>';
    }
    function sentHtml() {
        var k = state.kind;
        var t = k === 'idea' ? 'idea' : 'fallo';
        var now = new Date();
        var time = fill(str('today'), { time: now.getHours() + ':' + ('0' + now.getMinutes()).slice(-2) });
        var h = (narrow() ? '<div class="sg-asa"></div>' : '');
        h += '<header class="sg-cab sg-gracias sg-t-' + t + '"><span class="sg-sello">' + icon(k) + '</span><span class="sg-h"></span>' +
            '<button type="button" class="sg-redondo" data-sg="close" aria-label="' + esc(str('close')) + '">' + icon('x') +
            '</button></header>';
        h += '<div class="sg-gracias sg-t-' + t + '"><h2>' + esc(str('thanks' + k)) + '</h2><p>' + esc(str('thanks' + k + 'text')) +
            '</p><ol class="sg-luego"><li class="sg-ya"><span>' + esc(str('done' + k)) + '<small>' + esc(time) + '</small></span></li>' +
            '<li><span>' + esc(str('next' + k)) + '<small>' + esc(str('next' + k + 'hint')) + '</small></span></li></ol></div>';
        return h + '<div class="sg-pie"><button type="button" class="sg-enviar sg-suave" data-sg="close">' +
            esc(str('backtopage')) + '</button></div>';
    }
    function hintHtml(sent) {
        if (!state.kind) {
            return '<span>' + esc(str('captured')) + '</span>';
        }
        var k = state.kind;
        var words = sent ? str(k === 'idea' ? 'sentwithidea' : 'sentwithissue') :
            str((k === 'idea' ? 'markidea' : 'markissue') + (touch() ? 'touch' : ''));
        return '<span class="sg-rotu">' + icon(k) + '</span>' + esc(words) +
            (sent ? '' : '<small>' + esc(str(k === 'idea' ? 'sideidea' : 'sideissue')) + '</small>');
    }

    // Draws the layer for the step: choose, mark (on a phone), write or sent.
    function show(step) {
        state.step = step;
        var text = layer.querySelector('#sg-texto');
        if (text) {
            state.text = text.value;
        }
        layer.innerHTML = '';
        layer.className = 'sg-capa' + (state.kind ? ' sg-t-' + (state.kind === 'idea' ? 'idea' : 'fallo') : '');
        var panel = html('<section class="sg-panel"></section>');
        var W = root.innerWidth;
        var H = root.innerHeight;
        if (!narrow()) {
            var mesa = html('<div class="sg-mesa"></div>');
            if (state.shot) {
                mesa.appendChild(html('<div class="sg-pista">' + hintHtml(step === 'sent') + '</div>'));
                mesa.appendChild(captureBox(W - 408 - 96, H - 64 - 48, step === 'write'));
            }
            layer.appendChild(mesa);
            panel.innerHTML = step === 'choose' ? chooseHtml() : step === 'sent' ? sentHtml() : writeHtml();
            layer.appendChild(panel);
        } else {
            layer.classList.add('sg-movil');
            if (step === 'mark') {
                var t = state.kind === 'idea' ? 'idea' : 'fallo';
                layer.appendChild(html('<div class="sg-arriba sg-t-' + t + '"><button type="button" class="sg-chip" data-sg="toggle">' +
                    icon(state.kind) + esc(str(state.kind + 'short')) + '</button><span class="sg-h"></span>' +
                    '<button type="button" class="sg-redondo" data-sg="close" aria-label="' + esc(str('close')) + '">' + icon('x') +
                    '</button></div>'));
                var area = html('<div class="sg-mesa"></div>');
                area.appendChild(captureBox(W - 24, H - 56 - 124 - 24, true));
                layer.appendChild(area);
                layer.appendChild(html('<div class="sg-abajo sg-t-' + t + '"><p>' + icon(state.kind) +
                    esc(str((state.kind === 'idea' ? 'markidea' : 'markissue') + 'touch')) + '</p><button type="button" ' +
                    'class="sg-borrar" data-sg="clear">' + icon('goma') + esc(str('clear')) + '</button><button type="button" ' +
                    'class="sg-enviar" data-sg="next">' + esc(str('next')) + '</button></div>'));
            } else {
                layer.classList.add('sg-velo');
                panel.innerHTML = step === 'choose' ? chooseHtml() : step === 'sent' ? sentHtml() : writeHtml();
                layer.appendChild(panel);
                var slot = panel.querySelector('[data-sg-mini]');
                if (slot && state.shot) {
                    var mini = captureBox(84, 84 * state.page.h / state.page.w, false);
                    mini.setAttribute('data-sg', 'again');
                    slot.replaceWith(mini);
                }
            }
        }
        var area2 = layer.querySelector('#sg-texto');
        if (area2) {
            area2.value = state.text || '';
            area2.focus();
        } else {
            var first = layer.querySelector('.sg-camino, [data-sg="next"], .sg-suave, [data-sg="close"]');
            if (first) {
                first.focus();
            }
        }
    }

    function act(e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-sg]') : null;
        if (!t || !layer || layer.classList.contains('sg-enviando')) {
            return;
        }
        var what = t.getAttribute('data-sg');
        if (what === 'close') {
            close();
        } else if (what === 'kind') {
            state.kind = t.getAttribute('data-kind');
            show(narrow() && state.shot ? 'mark' : 'write');
        } else if (what === 'switch' || what === 'toggle') {
            state.kind = what === 'toggle' ? (state.kind === 'idea' ? 'issue' : 'idea') : t.getAttribute('data-kind');
            show(state.step);
        } else if (what === 'back') {
            show('choose');
        } else if (what === 'clear') {
            state.strokes = [];
            var box = layer.querySelector('.sg-captura');
            if (box && box.redraw) {
                box.redraw();
            }
            counted();
        } else if (what === 'again') {
            show('mark');
        } else if (what === 'next') {
            show('write');
        } else if (what === 'send') {
            send(t);
        }
    }

    function send(button) {
        var area = layer.querySelector('#sg-texto');
        var status = layer.querySelector('.sg-estado');
        var text = area ? area.value.trim() : '';
        if (!text) {
            status.textContent = str('needtext');
            area.focus();
            return;
        }
        state.text = text;
        layer.classList.add('sg-enviando');
        button.disabled = true;
        status.textContent = str('sending');
        call({
            component: state.opts.component || 'core',
            screen: state.opts.screen || '',
            url: root.location.href,
            text: text,
            image: state.shot ? compose().replace(/^data:image\/jpeg;base64,/, '') : '',
            kind: state.kind,
            browser: browserFull(),
            marks: state.shot ? state.strokes.length : 0
        }).then(function () {
            if (layer) {
                layer.classList.remove('sg-enviando');
                show('sent');
            }
            return true;
        }).catch(function () {
            if (layer) {
                layer.classList.remove('sg-enviando');
                button.disabled = false;
                status.textContent = str('failed');
            }
        });
    }

    function open(opts) {
        opts = opts || {};
        if (layer) {
            return;
        }
        root.__sgReturn = document.activeElement;
        layer = html('<div class="sg-capa" role="dialog" aria-modal="true"></div>');
        layer.setAttribute(SKIP, '');
        layer.setAttribute('aria-label', str('title'));
        layer.appendChild(html('<p class="sg-espera"></p>'));
        layer.firstChild.textContent = str('taking');
        // The layer is not in the picture, but it must not cover the page while the picture is taken.
        layer.style.visibility = 'hidden';
        document.body.appendChild(layer);
        document.addEventListener('keydown', onKey, true);
        layer.addEventListener('click', act);
        var page = { w: document.documentElement.clientWidth, h: root.innerHeight };
        picture().then(function (shot) {
            if (!layer) {
                return;
            }
            layer.style.visibility = '';
            state = { opts: opts, shot: shot, page: page, strokes: [], kind: null, text: '', step: '' };
            if (opts.kind === 'idea' || opts.kind === 'issue') {
                state.kind = opts.kind;
                show(narrow() && shot ? 'mark' : 'write');
            } else {
                show('choose');
            }
        });
    }

    // The sheet of the piece, loaded once with the script: the button of the header is drawn with it too.
    (function styles() {
        var url = asset('css');
        var links = document.querySelectorAll('link[rel="stylesheet"]');
        for (var i = 0; i < links.length; i++) {
            if ((links[i].getAttribute('href') || '').split('?')[0] === url.split('?')[0]) {
                return;
            }
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url + (data().version && url.indexOf('?') === -1 ? '?v=' + encodeURIComponent(data().version) : '');
        document.head.appendChild(link);
    })();

    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-marandu-proposal], [data-proponer]') : null;
        if (t) {
            e.preventDefault();
            open({
                component: t.getAttribute('data-marandu-proposal') || t.getAttribute('data-proponer'),
                screen: t.getAttribute('data-screen') || '',
                tool: t.getAttribute('data-tool') || '',
                release: t.getAttribute('data-release') || ''
            });
        }
    });

    root.MaranduProposal = { open: open, close: close };
    root.Proponer = root.MaranduProposal;
})(window);
