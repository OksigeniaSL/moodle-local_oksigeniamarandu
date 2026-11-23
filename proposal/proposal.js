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

    function str(key) {
        var s = root.MaranduProposalStrings || {};
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
        return root.M && root.M.cfg ? root.M.cfg : { wwwroot: '', sesskey: '' };
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
                backgroundColor: getComputedStyle(document.body).backgroundColor || '#ffffff',
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
