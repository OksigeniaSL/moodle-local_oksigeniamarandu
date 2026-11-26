/* «Propose an improvement»: the common piece of the tools of the site. A page loads it (api::proposal_js() in PHP) and a
   button with data-marandu-proposal="<component>" (and data-screen="<screen>") opens it; or
   window.MaranduProposal.open({component: '…', screen: '…'}). It takes a picture of what the person sees of the page,
   drawn in the browser (nothing outside the page), lets them mark on it with a finger or the mouse and say what they
   would change, and sends it to the site. Plain ES5, no build: it works on any page of the site.
   The drawing of the page is html2canvas, loaded only when someone asks for it. */
(function (root) {
    'use strict';

    var SKIP = 'data-marandu-proposal-skip';
    var layer = null;
    var loading = null;

    // A page that prints itself without the header of Moodle gives the texts and the site in
    // <script type="application/json" id="marandu-proposal"> (api::proposal_assets()).
    var printed = null;
    function data() {
        if (printed === null) {
            var node = document.getElementById('marandu-proposal');
            try {
                printed = node ? JSON.parse(node.textContent) : {};
            } catch (e) {
                printed = {};
            }
        }
        return printed;
    }
    function str(key) {
        var s = root.MaranduProposalStrings || data().strings || {};
        return s[key] || key;
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
        var url = cfg().wwwroot + '/local/oksigeniamarandu/proposal/html2canvas.min.js';
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
        var c = cfg();
        return fetch(c.wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(c.sesskey) +
            '&info=local_oksigeniamarandu_proposal_send', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify([{ index: 0, methodname: 'local_oksigeniamarandu_proposal_send', args: args }])
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

    function close() {
        if (layer) {
            layer.remove();
            layer = null;
            document.removeEventListener('keydown', onKey, true);
        }
    }
    function onKey(e) {
        if (e.key === 'Escape' && layer && !layer.classList.contains('mrdp-enviando')) {
            e.preventDefault();
            close();
        }
    }

    // The marks: free strokes over the picture, in the pixels of the picture.
    function marker(shot, marks) {
        var ctx = marks.getContext('2d');
        var drawing = false;
        var last = null;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = 'rgba(240, 101, 20, 0.55)';
        ctx.lineWidth = Math.max(8, Math.round(marks.width / 90));
        function at(e) {
            var r = marks.getBoundingClientRect();
            return { x: (e.clientX - r.left) * marks.width / r.width, y: (e.clientY - r.top) * marks.height / r.height };
        }
        marks.addEventListener('pointerdown', function (e) {
            drawing = true;
            last = at(e);
            marks.setPointerCapture(e.pointerId);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(last.x + 0.1, last.y + 0.1);
            ctx.stroke();
            e.preventDefault();
        });
        marks.addEventListener('pointermove', function (e) {
            if (!drawing) {
                return;
            }
            var p = at(e);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
            e.preventDefault();
        });
        var stop = function () {
            drawing = false;
        };
        marks.addEventListener('pointerup', stop);
        marks.addEventListener('pointercancel', stop);
        return function () {
            ctx.clearRect(0, 0, marks.width, marks.height);
        };
    }

    // The picture with the marks, as a JPEG small enough to send.
    function compose(shot, marks) {
        var out = document.createElement('canvas');
        var scale = Math.min(1, 2200 / Math.max(shot.width, 1));
        out.width = Math.round(shot.width * scale);
        out.height = Math.round(shot.height * scale);
        var ctx = out.getContext('2d');
        ctx.drawImage(shot, 0, 0, out.width, out.height);
        ctx.drawImage(marks, 0, 0, out.width, out.height);
        return out.toDataURL('image/jpeg', 0.82);
    }

    function open(opts) {
        opts = opts || {};
        if (layer) {
            return;
        }
        layer = el('div', 'mrdp-capa');
        layer.setAttribute(SKIP, '');
        layer.setAttribute('role', 'dialog');
        layer.setAttribute('aria-modal', 'true');
        layer.setAttribute('aria-label', str('title'));
        var box = el('div', 'mrdp-hoja');
        box.appendChild(el('p', 'mrdp-espera', str('taking')));
        layer.appendChild(box);
        // The layer is not in the picture, but it must not cover the page while the picture is taken.
        layer.style.visibility = 'hidden';
        document.body.appendChild(layer);
        document.addEventListener('keydown', onKey, true);
        picture().then(function (shot) {
            if (!layer) {
                return;
            }
            layer.style.visibility = '';
            build(box, shot, opts);
        });
    }

    function build(box, shot, opts) {
        box.innerHTML = '';
        var head = el('div', 'mrdp-cab');
        head.appendChild(el('h2', '', str('title')));
        var x = el('button', 'mrdp-cerrar');
        x.type = 'button';
        x.setAttribute('aria-label', str('cancel'));
        x.textContent = '×';
        x.addEventListener('click', close);
        head.appendChild(x);
        box.appendChild(head);
        var marks = null;
        var clear = null;
        if (shot) {
            box.appendChild(el('p', 'mrdp-pista', str('mark')));
            var stage = el('div', 'mrdp-escena');
            shot.className = 'mrdp-foto';
            // As wide as fits, and no taller than most of the window, keeping its shape.
            shot.style.width = '';
            shot.style.height = '';
            stage.style.maxWidth = Math.round(root.innerHeight * (root.innerWidth < 600 ? 0.45 : 0.58) * shot.width /
                Math.max(shot.height, 1)) + 'px';
            stage.appendChild(shot);
            marks = document.createElement('canvas');
            marks.className = 'mrdp-marcas';
            marks.width = shot.width;
            marks.height = shot.height;
            marks.setAttribute('aria-label', str('mark'));
            stage.appendChild(marks);
            box.appendChild(stage);
            clear = marker(shot, marks);
        } else {
            box.appendChild(el('p', 'mrdp-pista', str('nopicture')));
        }
        var label = el('label', 'mrdp-etiqueta', str('what'));
        var area = el('textarea', 'mrdp-texto');
        area.id = 'mrdp-texto';
        label.htmlFor = area.id;
        area.rows = 3;
        area.maxLength = 4000;
        area.placeholder = str('whathint');
        box.appendChild(label);
        box.appendChild(area);
        var foot = el('div', 'mrdp-pie');
        if (clear) {
            var wipe = el('button', 'btn btn-outline-secondary mrdp-borrar', str('clear'));
            wipe.type = 'button';
            wipe.addEventListener('click', clear);
            foot.appendChild(wipe);
        }
        var status = el('p', 'mrdp-estado');
        status.setAttribute('role', 'status');
        foot.appendChild(status);
        var cancel = el('button', 'btn btn-secondary', str('cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', close);
        var send = el('button', 'btn btn-primary', str('send'));
        send.type = 'button';
        foot.appendChild(cancel);
        foot.appendChild(send);
        box.appendChild(foot);
        area.focus();
        send.addEventListener('click', function () {
            var text = area.value.trim();
            if (!text) {
                status.textContent = str('needtext');
                area.focus();
                return;
            }
            layer.classList.add('mrdp-enviando');
            send.disabled = true;
            cancel.disabled = true;
            status.textContent = str('sending');
            call({
                component: opts.component || 'core',
                screen: opts.screen || '',
                url: root.location.href,
                text: text,
                image: shot ? compose(shot, marks).replace(/^data:image\/jpeg;base64,/, '') : ''
            }).then(function () {
                box.innerHTML = '';
                box.appendChild(el('h2', 'mrdp-gracias', str('thanks')));
                box.appendChild(el('p', '', str('thankshelp')));
                var ok = el('button', 'btn btn-primary', str('close'));
                ok.type = 'button';
                ok.addEventListener('click', close);
                var end = el('div', 'mrdp-pie');
                end.appendChild(ok);
                box.appendChild(end);
                layer.classList.remove('mrdp-enviando');
                ok.focus();
                return true;
            }).catch(function () {
                layer.classList.remove('mrdp-enviando');
                send.disabled = false;
                cancel.disabled = false;
                status.textContent = str('failed');
            });
        });
    }

    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-marandu-proposal]') : null;
        if (t) {
            e.preventDefault();
            open({ component: t.getAttribute('data-marandu-proposal'), screen: t.getAttribute('data-screen') || '' });
        }
    });

    root.MaranduProposal = { open: open, close: close };
})(window);
