{{--
    Stage 3b: the 24h customer-service window banner, rendered above the
    reply/note editor on every WhatsApp conversation (see
    Providers/KapsoWhatsAppServiceProvider.php and Services/WindowState.php).
    `$state` is WindowState::forConversation()'s exact return shape:
    ['open' => bool, 'last_inbound_at' => Carbon, 'closes_at' => Carbon].

    Times use core's own user-timezone-aware date helpers (the same ones
    resources/views/conversations/partials/thread.blade.php uses for thread
    timestamps: dateDiffForHumans() for the relative phrasing,
    dateFormat() for the absolute one) -- ->copy() because both helpers
    mutate the Carbon instance they're given (they call ->setTimezone() on
    it in place), and $state['closes_at'] must not be left mutated for
    whichever branch's second call reads it next.

    The block is advisory UI only -- see the "Honesty boundary" section of
    the Stage 3b spec. `data-kwa-window-closed` is what
    Public/js/kapsowhatsapp.js looks for to disable the reply triggers.

    `$inline` is true when this renders inside the subject row (chat mode,
    conversation.after_subject): there it sits directly after the floated
    .conv-tags pills (Chat Mode button + channel pill), whose children carry
    margin-top: 8px and stand ~28px tall (public/css/style.css:4405-4455).
    A plain block's first text line would hug the top of that row, so the
    open-state hint floats in with the same 8px offset and a 28px
    line-height to sit vertically centered against the pills. In the
    normal-mode placement (after_subject_block) it renders as its own row
    and needs none of that.
--}}
@if ($state['open'])
    {{-- data-kwa-window-open lets js/kapsowhatsapp.js colour the channel
         pill green without re-deriving the state client-side; line-height
         24px matches the measured border-box height of the pills
         (.fs-tag-md / .fs-tag-btn) so the hint text sits vertically centred
         beside them when it shares their line (wide screens; verified with
         getBoundingClientRect on the live page); on narrow screens the
         float wraps below them, which is the intended responsive
         behaviour. --}}
    <div class="text-help kwa-window-status" data-kwa-window-open="1"@if (!empty($inline)) style="float:left;margin-top:8px;line-height:24px;"@endif>
        {{ __('WhatsApp window open — closes :relative (:time)', [
            'relative' => \App\User::dateDiffForHumans($state['closes_at']->copy()),
            'time'     => \App\User::dateFormat($state['closes_at']->copy()),
        ]) }}
    </div>
@else
    <div class="alert alert-danger kwa-window-status" data-kwa-window-closed="1">
        {{ __('WhatsApp only allows replies within 24 hours of the customer\'s last message — this window closed :when.', [
            'when' => \App\User::dateDiffForHumans($state['closes_at']->copy()),
        ]) }}
    </div>
@endif
