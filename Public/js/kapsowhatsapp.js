/**
 * Stage 3b: when the 24h customer-service window is closed, disable the
 * reply triggers client-side. Advisory only -- see the "Honesty boundary"
 * section of dev-notes/specs/2026-07-28-kapso-whatsapp-design.md; the real
 * enforcement stays with Meta, and a stale page's send still fails loudly
 * into the existing red line item.
 *
 * Belt-and-braces: core's own conversation.reply_button.enabled filter
 * (Providers/KapsoWhatsAppServiceProvider.php) already removes `.conv-reply`
 * from the DOM entirely on a closed window, so most of what follows exists
 * only for whatever that server-side filter cannot reach -- e.g. this
 * script running against a page that was already rendered before the
 * window closed.
 *
 * Vanilla JS, no jQuery dependency for the guard itself, even though this
 * loads alongside a jQuery-heavy page -- registered via the `javascripts`
 * Eventy filter, appended after core's own bundle
 * (resources/views/layouts/app.blade.php). jQuery 3.6's `$(document).ready`
 * resolves asynchronously (its Deferred settles via a microtask/setTimeout,
 * never synchronously on DOMContentLoaded), so this file's own
 * `DOMContentLoaded` listener actually runs BEFORE core's ready-time work in
 * public/js/main.js, not after, despite main.js loading first on the page.
 * That is exactly why a one-shot hide is not enough: draft/note restore
 * (main.js's maybeShowStoredNote()/maybeShowDraft(), ~main.js:1347-1348) and
 * other async openers (editDraft() at main.js:~4494-4506, the
 * `?show_draft` flow at main.js:~5123-5148) all run later -- via ajax
 * callbacks, well after this script has finished -- and un-hide
 * `.conv-reply-block` regardless of what happened at DOMContentLoaded. A
 * MutationObserver on the block's `class` attribute re-asserts `hidden`
 * every time something removes it, which is what actually survives those
 * paths.
 */
document.addEventListener('DOMContentLoaded', function () {
    var closed = !!document.querySelector('[data-kwa-window-closed]');
    var open   = !!document.querySelector('[data-kwa-window-open]');

    // Colour the channel pill by window state. The pill is CORE markup
    // (view.blade.php's .fs-tag.fs-tag-md next to the Chat Mode button) with
    // no server-side styling hook, so it is classed here instead, keyed off
    // the banner's own data markers rather than re-deriving the state. The
    // channel pill is identified by its glyphicon-phone icon -- the Tags
    // module renders its own .fs-tag elements into this row and those must
    // keep their colours. (css/kapsowhatsapp.css defines the two classes;
    // no Element.closest() -- IE11.)
    if (closed || open) {
        Array.prototype.slice.call(document.querySelectorAll('.conv-tags .fs-tag')).forEach(function (tag) {
            if (tag.querySelector('.glyphicon-phone')) {
                tag.classList.add(closed ? 'kwa-window-pill-closed' : 'kwa-window-pill-open');
            }
        });
    }

    // Stage 3c: the "Send a template…" picker on the closed-window notice.
    // Guarded on the presence of data-kwa-templates-url itself, not the
    // `closed` flag above -- the same marker-driven style as the
    // pill-colouring block: only the closed banner
    // (Resources/views/partials/window_banner.blade.php) ever carries this
    // attribute, so this is simply a no-op on an open-window page.
    Array.prototype.slice.call(document.querySelectorAll('[data-kwa-templates-url]')).forEach(function (notice) {
        var toggle = notice.querySelector('.kwa-send-template-btn');
        if (!toggle) {
            return;
        }

        // All picker chrome text is pre-translated server-side into these
        // attributes (see the partial's docblock) -- this file has no
        // client-side translation lookup of its own.
        var labels = {
            send:      notice.getAttribute('data-kwa-label-send') || 'Send',
            cancel:    notice.getAttribute('data-kwa-label-cancel') || 'Cancel',
            loading:   notice.getAttribute('data-kwa-label-loading') || '',
            none:      notice.getAttribute('data-kwa-label-none') || '',
            error:     notice.getAttribute('data-kwa-label-error') || '',
            sendError: notice.getAttribute('data-kwa-label-send-error') || '',
            value:     notice.getAttribute('data-kwa-label-value') || 'Value'
        };

        var picker = null;

        toggle.addEventListener('click', function () {
            // A second click while the picker is open collapses it again,
            // whatever state it is in (still loading, showing the form, or
            // showing an error) -- same toggle idiom as the reply/note
            // buttons elsewhere in core's own main.js.
            if (picker) {
                if (picker.parentNode) {
                    picker.parentNode.removeChild(picker);
                }
                picker = null;
                return;
            }

            var thisPicker = document.createElement('div');
            thisPicker.className = 'kwa-template-picker';
            thisPicker.textContent = labels.loading;
            notice.appendChild(thisPicker);
            picker = thisPicker;

            kwaFetchJson('GET', notice.getAttribute('data-kwa-templates-url'), null, null, function (failed, data) {
                // The picker may have been collapsed (or re-opened, creating
                // a new one) while this request was in flight.
                if (picker !== thisPicker) {
                    return;
                }

                // The onClose callback exists because the render function
                // cannot reset this closure's `picker` variable itself (its
                // own first parameter shadows it) -- without the reset, a
                // Cancel would leave `picker` pointing at the detached div
                // and the NEXT toggle click would take the collapse branch
                // above and do nothing.
                kwaRenderTemplatePicker(thisPicker, notice, labels, data, failed, function () {
                    if (thisPicker.parentNode) {
                        thisPicker.parentNode.removeChild(thisPicker);
                    }
                    if (picker === thisPicker) {
                        picker = null;
                    }
                });
            });
        });
    });

    // XMLHttpRequest, not fetch(): this file's own convention (see the file
    // docblock) is vanilla JS with no IE11-breaking syntax or APIs, and
    // fetch()/Promise have no IE11 fallback here to lean on. $body is JSON
    // string|null; a non-null body sends the CSRF header Laravel's
    // VerifyCsrfToken middleware requires for a same-origin POST
    // (core's own main.js does the equivalent via a jQuery ajax header).
    function kwaFetchJson(method, url, body, csrfToken, callback) {
        var xhr = new XMLHttpRequest();
        // On a transport failure XHR fires BOTH readystatechange (readyState
        // 4, status 0) and onerror -- without this guard the callback would
        // run twice.
        var done = false;
        var finish = function (failed, data) {
            if (done) {
                return;
            }
            done = true;
            callback(failed, data);
        };
        xhr.open(method, url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        if (body !== null) {
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken || '');
        }
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                data = null;
            }
            finish(xhr.status < 200 || xhr.status >= 300 || !data, data);
        };
        xhr.onerror = function () {
            finish(true, null);
        };
        xhr.send(body !== null ? body : undefined);
    }

    function kwaStatusNode(text, isError) {
        var node = document.createElement('div');
        node.className = isError ? 'kwa-template-status text-danger' : 'kwa-template-status text-muted';
        node.textContent = text;
        return node;
    }

    // Renders the picker's contents once the template list has loaded (or
    // failed to). $data is the endpoint's decoded JSON body (or null on a
    // transport-level failure); {error: ...} is Kapso's own honest failure
    // (see TemplatesController::list()), always shown verbatim -- it is
    // already a translated, server-composed string, never a stack trace.
    // $onClose collapses the picker AND resets the toggle's own tracking
    // variable (which this function cannot reach -- its `picker` parameter
    // shadows it); the Cancel button must go through it, never remove the
    // node directly.
    function kwaRenderTemplatePicker(picker, notice, labels, data, loadFailed, onClose) {
        picker.innerHTML = '';

        if (loadFailed || !data) {
            picker.appendChild(kwaStatusNode(labels.error, true));
            return;
        }

        if (data.error) {
            picker.appendChild(kwaStatusNode(data.error, true));
            return;
        }

        var templates = data.templates || [];

        if (!templates.length) {
            picker.appendChild(kwaStatusNode(labels.none, false));
            return;
        }

        var select = document.createElement('select');
        select.className = 'form-control input-sm kwa-template-select';
        for (var i = 0; i < templates.length; i++) {
            var option = document.createElement('option');
            option.value = String(i);
            option.textContent = templates[i].name + ' — ' + templates[i].language;
            select.appendChild(option);
        }
        picker.appendChild(select);

        var preview = document.createElement('div');
        preview.className = 'kwa-template-preview';
        picker.appendChild(preview);

        var varsContainer = document.createElement('div');
        varsContainer.className = 'kwa-template-vars';
        picker.appendChild(varsContainer);

        var status = document.createElement('div');
        status.className = 'kwa-template-status text-danger';
        picker.appendChild(status);

        var actions = document.createElement('div');
        actions.className = 'kwa-template-actions';

        var sendBtn = document.createElement('button');
        sendBtn.type = 'button';
        sendBtn.className = 'btn btn-primary btn-xs';
        sendBtn.textContent = labels.send;
        actions.appendChild(sendBtn);

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-link btn-xs';
        cancelBtn.textContent = labels.cancel;
        actions.appendChild(cancelBtn);

        picker.appendChild(actions);

        var renderSelected = function () {
            var template = templates[Number(select.value)];
            preview.textContent = template.body;

            varsContainer.innerHTML = '';
            for (var v = 1; v <= template.variables; v++) {
                var wrapper = document.createElement('div');
                wrapper.className = 'form-group kwa-template-var';

                var label = document.createElement('label');
                label.textContent = labels.value + ' ' + v;

                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control input-sm kwa-template-var-input';
                input.maxLength = 1024;

                wrapper.appendChild(label);
                wrapper.appendChild(input);
                varsContainer.appendChild(wrapper);
            }
        };

        select.addEventListener('change', renderSelected);
        renderSelected();

        cancelBtn.addEventListener('click', function () {
            onClose();
        });

        sendBtn.addEventListener('click', function () {
            var template  = templates[Number(select.value)];
            var inputs    = Array.prototype.slice.call(varsContainer.querySelectorAll('.kwa-template-var-input'));
            var variables = inputs.map(function (input) {
                return input.value;
            });

            status.textContent = '';
            sendBtn.disabled = true;

            var payload = JSON.stringify({
                name:      template.name,
                language:  template.language,
                variables: variables
            });

            kwaFetchJson('POST', notice.getAttribute('data-kwa-send-url'), payload, notice.getAttribute('data-kwa-csrf'), function (failed, response) {
                if (!failed && response && response.thread_id) {
                    // The new thread and every state change (preview,
                    // last-reply fields) render server-side -- a reload is
                    // the cheap, honest way to show them, the same choice
                    // this module makes rather than duplicating server
                    // rendering logic in JS.
                    window.location.reload();
                    return;
                }

                sendBtn.disabled = false;
                // labels.sendError, not labels.error -- a send that dies
                // without a JSON {error} body (419/500, network drop) must
                // not claim templates "could not be loaded".
                status.textContent = (response && response.error) ? response.error : labels.sendError;
            });
        });
    }

    if (!closed) {
        return;
    }

    // Block the reply trigger. Core's main.js binds .conv-reply via jQuery
    // (public/js/main.js:1137) and has no disable mechanism of its own. For
    // a genuine user click, this capture-phase listener runs ahead of
    // jQuery's own bubble-phase handler, so preventDefault() +
    // stopImmediatePropagation() preempt it. That guarantee does not cover
    // chat mode's own auto-open (main.js:1354 `$(".conv-reply").click()`):
    // jQuery's synthetic .click() never dispatches a real DOM click event,
    // so no listener -- capture or bubble -- ever sees it. It is the
    // server-side removal of `.conv-reply` (see the file docblock above)
    // that actually stops that path: with no `.conv-reply` in the DOM,
    // chat mode's auto-click simply no-ops.
    Array.prototype.slice.call(document.querySelectorAll('.conv-reply')).forEach(function (el) {
        el.classList.add('inactive');
        el.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }, true);
    });

    // Hide the reply block, and keep it hidden across whatever re-opens it
    // later. Never touch a note block -- notes must stay available on a
    // closed window. view.blade.php has a single .conv-reply-block element
    // that core's own JS toggles a .conv-note-block class onto for note
    // mode; showNoteForm() adds conv-note-block BEFORE removing hidden (see
    // main.js:1563-1564), so by the time the observer callback below runs,
    // the class list already reflects note mode and the guard correctly
    // leaves it alone.
    var replyBlock = document.querySelector('.conv-reply-block');
    if (!replyBlock) {
        return;
    }

    var enforceHidden = function () {
        if (replyBlock.classList.contains('conv-note-block')) {
            return;
        }
        if (!replyBlock.classList.contains('hidden')) {
            replyBlock.classList.add('hidden');
        }
    };

    enforceHidden();

    new MutationObserver(enforceHidden).observe(replyBlock, {
        attributes: true,
        attributeFilter: ['class'],
    });
});
