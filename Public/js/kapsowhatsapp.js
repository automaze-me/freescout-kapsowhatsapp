/**
 * Stage 3b: when nothing can be sent to the customer any more, disable the
 * reply triggers client-side. Advisory only -- see the "Honesty boundary"
 * section of dev-notes/specs/2026-07-28-kapso-whatsapp-design.md; the real
 * enforcement stays with Meta, and a stale page's send still fails loudly
 * into the existing red line item.
 *
 * F1 revision (whole-stage review, CRITICAL): blocking is keyed on
 * `[data-kwa-block-reply]`, NOT `[data-kwa-window-closed]`. The two used to
 * be the same marker, which meant every closed WhatsApp window blocked
 * Reply outright -- including a closed 102 conversation whose customer has
 * an email on file, where the agent can and should still be able to click
 * Reply and send email through the Stage 4 picker (which renders INSIDE
 * `.conv-reply-block` -- see below -- so a stale blocker there made the
 * picker itself unreachable, defeating the email escape hatch entirely).
 * `data-kwa-block-reply` is emitted by window_banner.blade.php only when
 * the provider's `$blockReply` is true (window closed AND no customer
 * email -- the exact same predicate as the server-side C1
 * conversation.reply_button.enabled filter, so the two can never
 * disagree). `data-kwa-window-closed` still exists and is still read below
 * -- but only for pill-colouring and as the co-attribute the template
 * picker's own marker (`data-kwa-templates-url`) happens to sit next to; it
 * no longer implies blocking by itself.
 *
 * Belt-and-braces: core's own conversation.reply_button.enabled filter
 * (Providers/KapsoWhatsAppServiceProvider.php) already removes `.conv-reply`
 * from the DOM entirely once nothing can be sent, so most of what follows
 * exists only for whatever that server-side filter cannot reach -- e.g.
 * this script running against a page that was already rendered before the
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
    var closed      = !!document.querySelector('[data-kwa-window-closed]');
    var open        = !!document.querySelector('[data-kwa-window-open]');
    // F1: the reply-blocking authority -- see the file docblock above for
    // why this is a separate marker from `closed`.
    var blockReply  = !!document.querySelector('[data-kwa-block-reply]');

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

    // Stage 4: get the per-reply channel picker's choice into the reply
    // request. The picker (Resources/views/partials/channel_picker.blade.php)
    // renders at core's reply_form.after hook, which -- verified against
    // core's markup, see the partial's own docblock for the exact line
    // numbers -- sits OUTSIDE the reply <form>. Core's send flow never does
    // a native form submit; the .btn-reply-submit click handler
    // (public/js/main.js:2262) posts $(".form-reply:first").serialize()
    // (main.js:2314), which walks only DESCENDANTS of the <form> element,
    // so a radio rendered outside it is invisible to that call no matter
    // what. The fix: on every click that could be a send, copy whichever
    // radio is currently checked into a hidden <input> APPENDED TO THE FORM
    // itself, before serialize() runs.
    //
    // A capture-phase listener on document, not a listener bound to the
    // button node(s) directly, for two reasons at once: (1) .btn-reply-submit
    // is shared by four different buttons (Send Reply/Forward/Add Note/
    // Create) inside Public/../editor_bottom_toolbar.blade.php, whose HTML
    // core's own main.js re-creates via $('.note-statusbar').html(...)
    // (main.js:1800) during jQuery's ready-time editor init -- AFTER this
    // file's own DOMContentLoaded already ran (see the file's opening
    // docblock for why that ordering holds), so the visible buttons a user
    // actually clicks do not exist yet when this listener would otherwise
    // be attached directly to them, and a plain node listener could not
    // survive that re-creation regardless. Event delegation on document
    // sidesteps both: it fires for a target added to the DOM at any later
    // time. (2) Capture always runs before bubble regardless of binding
    // order, so this listener is guaranteed to run before main.js's own
    // bubble-phase .click() handler on the same button -- the same
    // capture-phase idiom this file already uses below to preempt
    // .conv-reply's click on a closed window.
    document.addEventListener('click', function (e) {
        var target = e.target;
        while (target && target.nodeType === 1) {
            if (target.classList && target.classList.contains('btn-reply-submit')) {
                kwaCopyChannelChoiceIntoForm();
                return;
            }
            target = target.parentNode;
        }
    }, true);

    // Copies the picker's checked radio into a hidden field inside the
    // actual <form> element (document.createElement -- IE11-safe, no
    // template literals). A no-op, by design, whenever there is no picker
    // on the page (a conversation where only one channel is possible) or no
    // reply form to write into -- the reply then posts exactly as it always
    // has, with no kwa_channel field at all, which
    // Listeners/RouteReplyChannel.php::capture() already treats as "native".
    function kwaCopyChannelChoiceIntoForm() {
        var picker = document.querySelector('.kwa-channel-picker[data-kwa-picker="1"]');
        if (!picker) {
            return;
        }

        var checked = picker.querySelector('input[name="kwa_channel"]:checked');
        var form = document.querySelector('.form-reply');
        if (!checked || !form) {
            return;
        }

        var hidden = form.querySelector('input[name="kwa_channel"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'kwa_channel';
            form.appendChild(hidden);
        }
        hidden.value = checked.value;
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
            // Always the bordered standalone panel: since the normal-mode
            // alert box is gone too (user feedback 2026-08-02 -- the red
            // pill is the closed-state visual in BOTH modes), every notice
            // the panel can attach to now sits on the page's white
            // background, where an unframed form floats ambiguously.
            thisPicker.className = 'kwa-template-picker kwa-template-picker-standalone';
            thisPicker.textContent = labels.loading;
            if ((' ' + notice.className + ' ').indexOf(' kwa-window-closed-inline ') !== -1) {
                // Chat mode: the notice is a float sitting to the RIGHT of
                // the channel pills, so a picker appended inside it would
                // hang indented mid-row under the hint text. As a sibling
                // with clear:both it drops below the whole pill row,
                // aligned with the content's left edge.
                notice.parentNode.insertBefore(thisPicker, notice.nextSibling);
            } else {
                // Normal mode (and the Stage 4 channel picker's template
                // control): inside the notice, which carries the side
                // margins that keep the panel aligned with the subject
                // column.
                notice.appendChild(thisPicker);
            }
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

    // Renders WhatsApp-formatted text into a node: newlines survive via the
    // preview's own white-space:pre-wrap, and WhatsApp's inline marks --
    // *bold*, _italic_, ~strikethrough~ -- become real <strong>/<em>/<s>
    // elements so the agent reads the template the way the customer will
    // (user feedback 2026-08-02: the raw body read as one run-together
    // blob). Built exclusively from createTextNode/createElement +
    // textContent -- the body is API data and must never travel through
    // innerHTML. A mark only counts when it wraps non-empty text on ONE
    // line (no \n inside), matching WhatsApp's own conservative parsing;
    // anything unmatched stays literal. String.split with a capturing
    // group keeps the delimiters in the result (fine on IE11 -- the
    // capture-dropping bug died with IE8's JScript).
    function kwaRenderWhatsAppText(node, text) {
        node.innerHTML = '';

        var parts = String(text).split(/(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g);

        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (!part) {
                continue;
            }

            var tag = null;
            if (part.length > 2) {
                var first = part.charAt(0);
                if (first === part.charAt(part.length - 1)) {
                    tag = first === '*' ? 'strong' : (first === '_' ? 'em' : (first === '~' ? 's' : null));
                }
            }

            if (tag) {
                var el = document.createElement(tag);
                el.textContent = part.slice(1, -1);
                node.appendChild(el);
            } else {
                node.appendChild(document.createTextNode(part));
            }
        }
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
            kwaRenderWhatsAppText(preview, template.body);

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

    if (!blockReply) {
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
