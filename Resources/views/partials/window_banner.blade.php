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
--}}
@if ($state['open'])
    <div class="text-help kwa-window-status">
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
