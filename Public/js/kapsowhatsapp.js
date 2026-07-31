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
    if (!document.querySelector('[data-kwa-window-closed]')) {
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
