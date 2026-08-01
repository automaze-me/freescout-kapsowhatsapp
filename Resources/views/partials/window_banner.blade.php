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
    {{-- Stage 3c: the "Send a template…" picker. The four data-kwa-* URL/
        token attributes are what Public/js/kapsowhatsapp.js reads to talk to
        TemplatesController -- rendered server-side (route(...)/csrf_token())
        so the JS needs no laroute build step and no route knowledge of its
        own. The data-kwa-label-* attributes are this feature's answer to "no
        client-side translation infra" (see the Stage 3c spec's "Endpoints &
        UI transport"): the picker's dynamic chrome (built by JS, since the
        template list itself is only known after the fetch) still needs
        translated strings, so those come from here too, __()'d like every
        other user-facing string in this module, rather than being
        hardcoded English in the JS.

        Built once and echoed into BOTH closed variants below: the JS reads
        the set off whichever variant rendered, so they must never drift
        apart. --}}
    @php
        $kwaPickerAttrs = [
            'data-kwa-window-closed'    => '1',
            'data-kwa-templates-url'    => route('kapsowhatsapp.templates.list', $conversation->id),
            'data-kwa-send-url'         => route('kapsowhatsapp.templates.send', $conversation->id),
            'data-kwa-csrf'             => csrf_token() ?: '',
            'data-kwa-label-send'       => __('Send'),
            'data-kwa-label-cancel'     => __('Cancel'),
            'data-kwa-label-loading'    => __('Loading templates…'),
            'data-kwa-label-none'       => __('No approved WhatsApp templates are available.'),
            'data-kwa-label-error'      => __('Could not load templates. Please try again.'),
            'data-kwa-label-send-error' => __('Could not send the template. Please try again.'),
            'data-kwa-label-value'      => __('Value'),
        ];
    @endphp
    @if (!empty($inline))
        {{-- Chat mode (user feedback, seen live): no alert box in the
            subject row -- the JS already colours the channel pill red, and
            the full-width pink block wrapped around the pills looked
            broken. Instead: the same quiet floated hint as the open state
            (same 8px/24px numbers, via .kwa-window-closed-inline in
            Public/css/kapsowhatsapp.css), compact wording, the template
            button riding on the same line, and the JS-appended picker
            clearing below it inside the float. --}}
        <div class="kwa-window-status kwa-window-closed-inline"
             @foreach ($kwaPickerAttrs as $kwaAttr => $kwaValue) {{ $kwaAttr }}="{{ $kwaValue }}" @endforeach>
            <span class="text-danger">{{ __('The 24-hour WhatsApp reply window closed :when.', [
                'when' => \App\User::dateDiffForHumans($state['closes_at']->copy()),
            ]) }}</span>
            <button type="button" class="btn btn-default btn-xs kwa-send-template-btn">{{ __('Send a template…') }}</button>
        </div>
    @else
        <div class="alert alert-danger kwa-window-status"
             @foreach ($kwaPickerAttrs as $kwaAttr => $kwaValue) {{ $kwaAttr }}="{{ $kwaValue }}" @endforeach>
            {{ __('WhatsApp only allows replies within 24 hours of the customer\'s last message — this window closed :when.', [
                'when' => \App\User::dateDiffForHumans($state['closes_at']->copy()),
            ]) }}
            <div class="kwa-template-picker-toggle">
                <button type="button" class="btn btn-default btn-xs kwa-send-template-btn">{{ __('Send a template…') }}</button>
            </div>
        </div>
    @endif
@endif
