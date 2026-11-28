/* «Sugerencias», the page of whoever receives them (proposals.php): each gesture of a card is saved as it is made, and
   the card comes back as it is now (its seal, with «Deshacer»); «Esto no, por esto…» opens its line, saved with Enter;
   a picture is seen big with a touch. Without this script the same forms post and the page comes back. Plain ES5. */
(function () {
    'use strict';

    var page = document.querySelector('.sg-recibe');
    if (!page) {
        return;
    }

    function card(id) {
        return page.querySelector('[data-sg-card="' + id + '"]');
    }

    page.addEventListener('click', function (e) {
        var why = e.target.closest ? e.target.closest('[data-sg-why]') : null;
        if (why) {
            var form = document.getElementById('sg-porque-' + why.getAttribute('data-sg-why'));
            var open = form.hidden;
            form.hidden = !open;
            why.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                form.querySelector('[name="reason"]').focus();
            }
            return;
        }
        var zoom = e.target.closest ? e.target.closest('[data-sg-zoom]') : null;
        if (zoom) {
            var big = document.createElement('button');
            big.type = 'button';
            big.className = 'sg-grande';
            big.setAttribute('aria-label', zoom.getAttribute('aria-label') || '');
            var img = document.createElement('img');
            img.src = zoom.getAttribute('data-sg-zoom');
            img.alt = '';
            big.appendChild(img);
            var done = function () {
                big.remove();
                document.removeEventListener('keydown', esc, true);
                zoom.focus();
            };
            var esc = function (k) {
                if (k.key === 'Escape') {
                    done();
                }
            };
            big.addEventListener('click', done);
            document.addEventListener('keydown', esc, true);
            document.body.appendChild(big);
            big.focus();
        }
    });

    // The line of «why not»: it grows with the text; Enter saves it, Shift+Enter breaks the line.
    page.addEventListener('keydown', function (e) {
        var area = e.target;
        if (area && area.hasAttribute && area.hasAttribute('data-sg-enter') && e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (area.value.trim() !== '') {
                if (area.form.requestSubmit) {
                    area.form.requestSubmit();
                } else {
                    area.form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            }
        }
    });
    page.addEventListener('input', function (e) {
        var area = e.target;
        if (area && area.hasAttribute && area.hasAttribute('data-sg-enter')) {
            area.style.height = 'auto';
            area.style.height = area.scrollHeight + 'px';
        }
    });

    page.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute('data-sg-form') || !window.fetch || !window.FormData) {
            return;
        }
        e.preventDefault();
        var data = new FormData(form);
        data.append('ajax', '1');
        var id = data.get('id');
        var buttons = form.querySelectorAll('button');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = true;
        }
        fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
            return r.json();
        }).then(function (answer) {
            var old = card(id);
            if (!old || !answer.html) {
                return null;
            }
            var t = document.createElement('template');
            t.innerHTML = answer.html.trim();
            var fresh = t.content.firstChild;
            old.replaceWith(fresh);
            if (answer.late) {
                var note = fresh.querySelector('.sg-sello-fin span');
                if (note) {
                    note.textContent = answer.late;
                }
            }
            var focus = fresh.querySelector('.sg-sello-fin button, .sg-salidas button');
            if (focus) {
                focus.focus();
            }
            return null;
        }).catch(function () {
            form.submit();
        });
    });
})();
