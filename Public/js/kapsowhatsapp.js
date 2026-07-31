/**
 * Stage 3b: when the 24h customer-service window is closed, disable the
 * reply triggers client-side. Advisory only -- see the "Honesty boundary"
 * section of dev-notes/specs/2026-07-28-kapso-whatsapp-design.md; the real
 * enforcement stays with Meta, and a stale page's send still fails loudly
 * into the existing red line item.
 *
 * Vanilla JS, no jQuery dependency for the guard itself, even though this
 * loads alongside a jQuery-heavy page -- registered via the `javascripts`
 * Eventy filter, appended after core's own bundle
 * (resources/views/layouts/app.blade.php), so this file's DOMContentLoaded
 * listener fires after main.js's own ready handler has already run
 * (including its draft/note restore, which is exactly why the
 * :not(.conv-note-block) check below matters and is not just defensive
 * theatre -- verified against public/js/main.js:1347-1348).
 */
document.addEventListener('DOMContentLoaded', function () {
    if (!document.querySelector('[data-kwa-window-closed]')) {
        return;
    }

    // Block the reply trigger. Core's main.js binds .conv-reply via jQuery
    // (public/js/main.js:1137) and has no disable mechanism of its own;
    // the capture-phase listener here runs before jQuery's bubble-phase
    // handler, so preventDefault() + stopImmediatePropagation() preempt it.
    document.querySelectorAll('.conv-reply').forEach(function (el) {
        el.classList.add('inactive');
        el.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }, true);
    });

    // If the reply block is already open (e.g. a restored draft), collapse
    // it. Never touch a note block -- notes must stay available on a closed
    // window (view.blade.php has a single .conv-reply-block element that
    // core's own JS toggles a .conv-note-block class onto for note mode;
    // see edit_thread.blade.php and main.js:1557-1627).
    var replyBlock = document.querySelector('.conv-reply-block:not(.conv-note-block)');
    if (replyBlock) {
        replyBlock.classList.add('hidden');
    }
});
