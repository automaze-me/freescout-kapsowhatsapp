{{--
    Stage 4: the per-reply channel picker -- see "Stage 4: per-reply channel
    selection" -> "UI" in dev-notes/specs/2026-07-28-kapso-whatsapp-design.md
    and Services/ChannelChoice.php's class docblock. Injected at core's
    `reply_form.after` hook (resources/views/conversations/view.blade.php:342)
    by KapsoWhatsAppServiceProvider::renderChannelPicker(), which already
    gated on ChannelChoice::pickerAvailable($conversation) -- both channels
    must be genuinely reachable, or there is nothing to pick between -- so
    this partial never re-checks that itself, same convention
    window_banner.blade.php follows for its own provider-computed $state.

    Placement finding (verified against core's markup, empirically, per the
    Task 3 plan's instruction -- no browser is available here): the hook
    fires OUTSIDE the reply <form> (form: view.blade.php:238-338; hook:
    view.blade.php:342, after the form's closing tag AND after
    @include('conversations/editor_bottom_toolbar')). Core's own send flow
    never does a native form submit -- the .btn-reply-submit click handler
    (public/js/main.js:2262) posts `$(".form-reply:first").serialize()`
    (main.js:2314), which walks only DESCENDANTS of the <form> element. A
    radio rendered here therefore cannot reach that payload on its own.
    Public/js/kapsowhatsapp.js's submit-time hidden-input copy is what
    actually gets the choice into the request -- see that file's own
    docblock for the mechanism and why a capture-phase document listener is
    what makes it reliable regardless of script/handler registration order.

    Variables, all provided by
    KapsoWhatsAppServiceProvider::renderChannelPicker() (or its F5 sibling
    renderTemplateOnlyPicker() for $templateOnly -- see below):
      $conversation      the conversation being replied to.
      $default            'whatsapp'|'email'|null (ChannelChoice::defaultChannel()'s
                           exact return values -- literal strings are used
                           below rather than importing the class, the same
                           choice window_banner.blade.php makes for its own
                           constants; null when $templateOnly, since there
                           is no radio to default).
      $windowOpen         bool -- whether WhatsApp free-form sending is
                           currently legal on this conversation.
      $windowHint         string, already __()'d ('' only in the
                           unreachable no-window-state case -- see the
                           provider).
      $templateTransport  bool -- true for a CLOSED window on a NON-102
                           conversation, whether or not both channels are
                           pickable. A channel-102 conversation already
                           carries the Stage 3c template control on its
                           closed banner (window_banner.blade.php) -- never
                           both, per spec.
      $templateOnly       bool (whole-stage review, F5) -- true when
                           ChannelChoice::pickerAvailable() said no (the
                           conversation has no email leg) but there is
                           still WhatsApp history, a closed window, and a
                           non-102 channel, so the template send is the
                           ONLY thing left an agent can do here. Suppresses
                           the "Send via" label and both radios; nothing
                           to pick between when only one of the two
                           channels is even theoretically reachable.
--}}
@php
    // The $kwaPickerAttrs idiom, reused verbatim from window_banner.blade.php
    // (see that file's own comment on why it is built once and echoed via a
    // foreach rather than six separate attribute lines): the four transport
    // attributes plus the pre-translated label set
    // Public/js/kapsowhatsapp.js's existing (unchanged) template-picker
    // binding reads off ANY element carrying data-kwa-templates-url --
    // this picker is simply one more such element.
    $kwaPickerAttrs = $templateTransport ? [
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
    ] : [];
@endphp
{{-- data-kwa-picker="1" is what js/kapsowhatsapp.js's submit-time copy
     looks the picker up by -- deliberately not a class-based lookup, same
     data-attribute-as-transport convention the template picker already
     uses. Omitted when $templateOnly: there is no radio for that JS to
     ever find checked here, so marking this element as "the picker" would
     be misleading -- a harmless no-op either way (kwaCopyChannelChoiceIntoForm()
     bails on a missing checked radio regardless), but the marker is meant
     to say "a channel choice lives here", which is exactly what is not
     true in this mode. --}}
<div class="kwa-channel-picker form-inline"@if (empty($templateOnly)) data-kwa-picker="1"@endif
     @foreach ($kwaPickerAttrs as $kwaAttr => $kwaValue) {{ $kwaAttr }}="{{ $kwaValue }}" @endforeach>
    @if (empty($templateOnly))
        <span class="text-help">{{ __('Send via') }}:</span>
        <label class="radio-inline">
            <input type="radio" name="kwa_channel" value="email"{{ $default === 'email' ? ' checked' : '' }}>
            {{ __('Email') }}
        </label>
        {{-- F8 (whole-stage review): the WhatsApp label's own muted state is
             now server-rendered (text-muted, echoed only when the window is
             closed) rather than leaned on a CSS `input:disabled + *`
             sibling-selector -- see Public/css/kapsowhatsapp.css's comment
             on the rule this replaced. --}}
        <label class="radio-inline{{ $windowOpen ? '' : ' text-muted' }}">
            {{-- Conditional attributes via echoes, never word-adjacent inline
                 @if -- Blade's directive pattern (\B@) never matches an @ that
                 directly follows a word character, so e.g. `checked@if(...)`
                 would ship literally while its @endif still compiled
                 (unbalancing the outer conditional; see
                 window_banner.blade.php's own comment for the real parse error
                 this once caused). --}}
            <input type="radio" name="kwa_channel" value="whatsapp"{{ $default === 'whatsapp' ? ' checked' : '' }}{{ $windowOpen ? '' : ' disabled' }}>
            {{ __('WhatsApp') }}
        </label>
        {{-- F7 (whole-stage review): moved OUTSIDE the WhatsApp <label> --
             it used to sit inside it, which meant clicking the hint text
             selected the WhatsApp radio underneath it (a <label> forwards
             any click on its content to the control it wraps). Placed
             immediately after the label instead, so it reads in the same
             spot visually with no such side effect. --}}
        @if ($windowHint)
            <span class="text-help kwa-channel-window-hint">{{ $windowHint }}</span>
        @endif
    @elseif ($windowHint)
        <span class="text-help kwa-channel-window-hint">{{ $windowHint }}</span>
    @endif
    @if ($templateTransport)
        <button type="button" class="btn btn-default btn-xs kwa-send-template-btn">{{ __('Send a template…') }}</button>
    @endif
</div>
