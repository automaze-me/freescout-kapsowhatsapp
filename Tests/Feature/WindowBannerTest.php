<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use Carbon\Carbon;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Pins the Stage 3b UI: the `conversation.after_subject_block` banner (open
 * line vs. blocking red notice) and the module JS asset that disables the
 * reply triggers once the banner says closed. Fixture helpers
 * (makeAccount()/makeConversation()/seedMessage()) are copied from
 * WindowStateTest.php per this module's convention of each test file owning
 * its own fixtures rather than sharing a trait.
 */
class WindowBannerTest extends TestCase
{
    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function makeConversation(KapsoAccount $account, int $channel = KapsoAccount::CHANNEL): Conversation
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Wanda', 'last_name' => 'WhatsApp']);

        $folder = Folder::where('mailbox_id', $account->mailbox_id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = $channel;
        $conversation->mailbox_id  = $account->mailbox_id;
        $conversation->folder_id   = $folder->id;
        $conversation->customer_id = $customer->id;
        $conversation->status      = Conversation::STATUS_ACTIVE;
        $conversation->state       = Conversation::STATE_PUBLISHED;
        $conversation->source_via  = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->subject     = 'WhatsApp chat';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    protected function seedMessage(KapsoAccount $account, Conversation $conversation, string $contactPhone, string $direction, Carbon $createdAt): KapsoMessage
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->direction       = $direction;
        $row->contact_phone   = $contactPhone;
        $row->status          = $direction === KapsoMessage::DIRECTION_INBOUND ? 'received' : 'sent';
        $row->save();

        $row->created_at = $createdAt;
        $row->save();

        return $row;
    }

    /**
     * Fires the `conversation.after_subject_block` action exactly the way
     * resources/views/conversations/view.blade.php:231 does
     * (@action('conversation.after_subject_block', $conversation, $mailbox))
     * and captures the echoed output -- hook-level capture only, no
     * page-GET rendering (see this module's other tests, e.g.
     * SentMarkerTest::renderThreadMeta(), for why).
     */
    protected function renderBanner($conversation, $mailbox): string
    {
        ob_start();
        \Eventy::action('conversation.after_subject_block', $conversation, $mailbox);

        return (string) ob_get_clean();
    }

    /**
     * Fires the `conversation.after_subject` action exactly the way
     * resources/views/conversations/view.blade.php:207 does
     * (@action('conversation.after_subject', $conversation, $mailbox)) --
     * the chat-mode placement of the Stage 3b banner. Same hook-level
     * capture convention as renderBanner() above.
     */
    protected function renderAfterSubject($conversation, $mailbox): string
    {
        ob_start();
        \Eventy::action('conversation.after_subject', $conversation, $mailbox);

        return (string) ob_get_clean();
    }

    /**
     * Conversation::isInChatMode() (app/Conversation.php:2521) reads three
     * things: $this->isChat() (already true -- makeConversation() above
     * always creates TYPE_CHAT conversations), \Helper::isChatMode() (a
     * session flag, \Session::get('chat_mode', 0), toggled here via
     * \Helper::setChatMode()), and \Route::is('conversations.view') -- the
     * *router's* currently-matched route (Router::$current), not the
     * request URL or request()->route(). This suite never makes a real HTTP
     * request against conversations.view (this module's convention -- see
     * renderBanner()'s docblock -- is hook-level \Eventy::action() calls, not
     * page GETs), so there is no request for the router to have matched and
     * Route::is() would otherwise always read false here.
     *
     * Router::$current is protected and only ever written by the router's
     * own (also protected) findRoute(), normally invoked mid-dispatch. There
     * is no public setter. A bound closure, scoped to the Router instance,
     * is the minimal way to set that one property directly -- using the
     * framework's real object, not a Mockery facade mock (this module's
     * tests avoid those) -- without executing routing/middleware/controller
     * machinery (auth, view rendering, summernote assets, ...) at all. The
     * route itself comes from RouteCollection::getByName(), the same public
     * lookup `route('conversations.view', ...)` performs.
     */
    protected function setMatchedRouteName(?string $name): void
    {
        $router = app('router');
        $route  = $name ? $router->getRoutes()->getByName($name) : null;

        \Closure::bind(function () use ($route) {
            $this->current = $route;
        }, $router, $router)();
    }

    public function test_a_closed_window_renders_the_blocking_banner()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));

        $html = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringContainsString('data-kwa-window-closed', $html);
        $this->assertStringContainsString('this window closed', $html);
        $this->assertStringContainsString('alert-danger', $html);
        // The two data markers are mutually exclusive -- the JS colours the
        // channel pill red-or-green off exactly one of them.
        $this->assertStringNotContainsString('data-kwa-window-open', $html);
    }

    public function test_an_open_window_renders_the_status_line_without_the_block_marker()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(1));

        $html = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringContainsString('closes', $html);
        $this->assertStringContainsString('data-kwa-window-open', $html);
        $this->assertStringNotContainsString('data-kwa-window-closed', $html);
    }

    public function test_non_whatsapp_conversations_get_no_banner()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account, 1);

        $html = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringNotContainsString('data-kwa-window-closed', $html);
        $this->assertStringNotContainsString('kwa-window-status', $html);
    }

    public function test_the_module_js_is_registered_and_shipped()
    {
        $javascripts = \Eventy::filter('javascripts', []);

        // No query string allowed on these paths -- Minify stats them on
        // disk and a ?v= buster takes the whole app bundle down (see the
        // provider's comment). Assert the exact-suffix form.
        $matches = array_filter($javascripts, function ($path) {
            return substr($path, -strlen('/js/kapsowhatsapp.js')) === '/js/kapsowhatsapp.js';
        });

        $this->assertNotEmpty($matches, 'the javascripts filter must ship kapsowhatsapp.js as a bare path');
        $this->assertFileExists(__DIR__.'/../../Public/js/kapsowhatsapp.js');
    }

    public function test_the_module_css_is_registered_and_shipped()
    {
        $stylesheets = \Eventy::filter('stylesheets', []);

        $matches = array_filter($stylesheets, function ($path) {
            return substr($path, -strlen('/css/kapsowhatsapp.css')) === '/css/kapsowhatsapp.css';
        });

        $this->assertNotEmpty($matches, 'the stylesheets filter must ship kapsowhatsapp.css as a bare path');
        $this->assertFileExists(__DIR__.'/../../Public/css/kapsowhatsapp.css');
    }

    /**
     * C1 (Critical): core's own conversation.reply_button.enabled filter
     * (app/Misc/ConversationActionButtons.php:25,
     * \Eventy::filter('conversation.reply_button.enabled', true,
     * $conversation)) is the real fix -- with no `.conv-reply` in the DOM,
     * the toolbar shows no dead button and chat mode's auto-open
     * (public/js/main.js:1354 `$(".conv-reply").click()`) simply no-ops.
     */
    public function test_the_reply_button_is_removed_when_the_window_is_closed()
    {
        $account = $this->makeAccount();

        // Deliberately different contact phones for $closed and $open:
        // WindowState is keyed per (account, contact), not per conversation
        // (WindowStateTest::test_the_window_is_keyed_per_contact_not_per_conversation
        // pins this) -- sharing one phone across both would let $open's
        // recent inbound message reopen $closed's window too.
        $closed = $this->makeConversation($account);
        $this->seedMessage($account, $closed, '+491771111111', KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));

        $open = $this->makeConversation($account);
        $this->seedMessage($account, $open, '+491772222222', KapsoMessage::DIRECTION_INBOUND, now()->subHours(1));

        $other = $this->makeConversation($account, 1);

        $this->assertFalse(\Eventy::filter('conversation.reply_button.enabled', true, $closed), 'a closed window must remove the reply button');
        $this->assertTrue(\Eventy::filter('conversation.reply_button.enabled', true, $open), 'an open window must leave the reply button alone');
        $this->assertTrue(\Eventy::filter('conversation.reply_button.enabled', true, $other), 'a non-WhatsApp conversation must pass the filter through untouched');
    }

    /**
     * Task 3 of Stage 3c: the closed notice gains the "Send a template…"
     * control plus the three data attributes the picker JS reads
     * (Public/js/kapsowhatsapp.js) -- the endpoint URLs and the CSRF token,
     * all server-rendered so the JS needs no route-building or translation
     * of its own.
     */
    public function test_the_closed_banner_carries_the_template_picker_control()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));

        $html = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringContainsString('data-kwa-templates-url', $html);
        $this->assertStringContainsString('data-kwa-send-url', $html);
        $this->assertStringContainsString('data-kwa-csrf', $html);
        $this->assertStringContainsString('kwa-send-template-btn', $html);
        $this->assertStringContainsString('Send a template', $html);
    }

    /**
     * Mutually exclusive with the closed banner's control -- an open window
     * has nothing to send a template past, and the picker markup must not
     * be shipped where the button that opens it does not exist either.
     */
    public function test_the_open_banner_carries_no_template_picker_control()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(1));

        $html = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringNotContainsString('data-kwa-templates-url', $html);
        $this->assertStringNotContainsString('data-kwa-send-url', $html);
        $this->assertStringNotContainsString('data-kwa-csrf', $html);
        $this->assertStringNotContainsString('kwa-send-template-btn', $html);
    }

    /**
     * C1: in chat mode, core collapses conversation.after_subject_block's
     * output inside the #conv-top-blocks accordion
     * (view.blade.php:229-234), which is not shown by default -- a banner
     * rendered there would be effectively invisible. conversation.after_subject
     * (view.blade.php:207), by contrast, renders above that collapse in
     * every mode. So chat mode must render the banner on after_subject and
     * stay silent on after_subject_block; normal mode is the exact inverse.
     * Marker-based negatives ('kwa-window-status') rather than "output is
     * empty": Tags (after_subject) and Checklists/CustomFields
     * (after_subject_block) share both hooks and may render their own
     * content.
     */
    public function test_the_banner_moves_above_the_collapse_in_chat_mode_and_stays_below_it_in_normal_mode()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));

        \Helper::setChatMode(true);
        $this->setMatchedRouteName('conversations.view');
        $this->assertTrue($conversation->isInChatMode(), 'test setup must actually satisfy isInChatMode()');

        $afterSubject      = $this->renderAfterSubject($conversation, $account->mailbox);
        $afterSubjectBlock = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringContainsString('kwa-window-status', $afterSubject, 'chat mode must render the banner on after_subject');
        $this->assertStringNotContainsString('kwa-window-status', $afterSubjectBlock, 'chat mode must not also render the banner on after_subject_block');

        \Helper::setChatMode(false);
        $this->setMatchedRouteName(null);
        $this->assertFalse($conversation->isInChatMode(), 'test setup must actually leave chat mode');

        $afterSubjectNormal      = $this->renderAfterSubject($conversation, $account->mailbox);
        $afterSubjectBlockNormal = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringNotContainsString('kwa-window-status', $afterSubjectNormal, 'normal mode must not render the banner on after_subject');
        $this->assertStringContainsString('kwa-window-status', $afterSubjectBlockNormal, 'normal mode must keep rendering the banner on after_subject_block');
    }
}
