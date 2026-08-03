<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use Carbon\Carbon;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Task 3 of Stage 4: the per-reply channel picker rendered at core's
 * `reply_form.after` hook (resources/views/conversations/view.blade.php:342)
 * by KapsoWhatsAppServiceProvider::renderChannelPicker(). Hook-level
 * ob_start capture only, no page-GET rendering -- this module's convention,
 * see WindowBannerTest's own docblock for why. Fixture idiom
 * (account/conversation/seeded inbound row/customer thread) copied from
 * ChannelChoiceTest.php per this module's convention of each test file
 * owning its own fixtures rather than sharing a trait.
 */
class ChannelPickerViewTest extends TestCase
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

    protected function makeConversation(KapsoAccount $account, int $channel, Customer $customer): Conversation
    {
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
        $conversation->subject     = 'Mixed conversation';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    /**
     * $contactPhone is a required param here, unlike ChannelChoiceTest's own
     * seedInbound() (which hardcodes one phone) -- WindowState is keyed per
     * (account, contact), not per conversation
     * (WindowBannerTest::test_the_reply_button_matrix's own comment pins
     * this same trap), and this file's default-vs-window tests deliberately
     * put an open and a closed conversation side by side, so sharing a
     * phone would let the open one's recent row reopen the closed one's
     * window too.
     */
    protected function seedInbound(KapsoAccount $account, Conversation $conversation, string $contactPhone, ?int $threadId, Carbon $createdAt, string $wamid): KapsoMessage
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->thread_id       = $threadId;
        $row->direction       = KapsoMessage::DIRECTION_INBOUND;
        $row->wamid           = $wamid;
        $row->contact_phone   = $contactPhone;
        $row->status          = 'received';
        $row->save();

        // Two saves, same idiom as WindowStateTest::seedMessage() /
        // ChannelChoiceTest::seedInbound(): the first insert lets Eloquent's
        // own timestamp behaviour populate created_at, the second overwrites
        // it with the caller's value so it survives.
        $row->created_at = $createdAt;
        $row->save();

        return $row;
    }

    protected function makeCustomerThread(Conversation $conversation): Thread
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = Thread::TYPE_CUSTOMER;
        $thread->status          = Thread::STATUS_ACTIVE;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = '<p>Hi</p>';
        $thread->source_via      = Thread::PERSON_CUSTOMER;
        $thread->source_type     = Thread::SOURCE_TYPE_API;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    /**
     * Fires the `reply_form.after` action exactly the way
     * resources/views/conversations/view.blade.php:342 does
     * (@action('reply_form.after', $conversation)) and captures the echoed
     * output -- same hook-level capture convention as
     * WindowBannerTest::renderBanner().
     */
    protected function renderPicker(Conversation $conversation): string
    {
        ob_start();
        \Eventy::action('reply_form.after', $conversation);

        return (string) ob_get_clean();
    }

    public function test_the_picker_renders_only_when_both_channels_are_possible()
    {
        $account = $this->makeAccount();

        // channel-1 + rows + customer email -> both channels are genuinely
        // reachable, so the picker renders.
        $emailCustomer = Customer::create('picker-both@example.com', ['first_name' => 'Mo']);
        $mixed         = $this->makeConversation($account, 1, $emailCustomer);
        $this->seedInbound($account, $mixed, '+491771111111', null, now()->subHour(), 'wamid.PICK1');

        $html = $this->renderPicker($mixed);
        $this->assertStringContainsString('kwa-channel-picker', $html);
        $this->assertStringContainsString('name="kwa_channel"', $html);

        // Rowless channel-1 conversation: the customer has an email, but
        // there is no WhatsApp history on THIS conversation at all -- never
        // customer-scoped cross-conversation lookup (Stage 4 decision) --
        // so there is nothing to pick between.
        $rowless = $this->makeConversation($account, 1, $emailCustomer);
        $rowlessHtml = $this->renderPicker($rowless);
        $this->assertStringNotContainsString('kwa-channel-picker', $rowlessHtml);

        // Channel-105 conversation (has rows by construction) but the
        // customer has no email on file: no email leg to choose either.
        $noEmailCustomer = Customer::createWithoutEmail(['first_name' => 'No', 'last_name' => 'Email']);
        $waOnly          = $this->makeConversation($account, KapsoAccount::CHANNEL, $noEmailCustomer);
        $this->seedInbound($account, $waOnly, '+491772222222', null, now()->subHour(), 'wamid.PICK2');

        $waOnlyHtml = $this->renderPicker($waOnly);
        $this->assertStringNotContainsString('kwa-channel-picker', $waOnlyHtml);
    }

    public function test_the_default_option_follows_channel_choice()
    {
        $account  = $this->makeAccount();
        $customer = Customer::create('picker-default@example.com', ['first_name' => 'Mo']);

        // Open-window mixed conversation: the newest customer thread is the
        // WhatsApp one, one hour old -> window open -> default WhatsApp.
        $open        = $this->makeConversation($account, 1, $customer);
        $openThread  = $this->makeCustomerThread($open);
        $this->seedInbound($account, $open, '+491773333333', $openThread->id, now()->subHour(), 'wamid.PICK3');

        $openHtml = $this->renderPicker($open);
        $this->assertStringContainsString('value="whatsapp" checked', $openHtml);
        $this->assertStringNotContainsString('value="email" checked', $openHtml);
        $this->assertStringNotContainsString('disabled', $openHtml, 'an open window must not disable the WhatsApp option');

        // Closed-window mixed conversation: same shape, but the inbound row
        // is 30h old -> window closed -> default falls back to email, and
        // the WhatsApp option is disabled with the window hint attached.
        $closed       = $this->makeConversation($account, 1, $customer);
        $closedThread = $this->makeCustomerThread($closed);
        $this->seedInbound($account, $closed, '+491774444444', $closedThread->id, now()->subHours(30), 'wamid.PICK4');

        $closedHtml = $this->renderPicker($closed);
        $this->assertStringContainsString('value="email" checked', $closedHtml);
        $this->assertStringNotContainsString('value="whatsapp" checked', $closedHtml);
        $this->assertStringContainsString('disabled', $closedHtml);
        $this->assertStringContainsString('kwa-channel-window-hint', $closedHtml);
    }

    public function test_a_closed_mixed_conversation_carries_the_template_transport()
    {
        $account  = $this->makeAccount();
        $customer = Customer::create('picker-templates@example.com', ['first_name' => 'Mo']);

        // Channel-1 + rows + email, window closed -> the picker itself is
        // the only closed-window surface on a mixed conversation (there is
        // no banner -- that stays channel-105-only per spec), so it must
        // carry the 3c template transport.
        $mixed = $this->makeConversation($account, 1, $customer);
        $this->seedInbound($account, $mixed, '+491775555555', null, now()->subHours(30), 'wamid.PICK5');

        $mixedHtml = $this->renderPicker($mixed);
        $this->assertStringContainsString('data-kwa-templates-url', $mixedHtml);
        $this->assertStringContainsString('data-kwa-send-url', $mixedHtml);
        $this->assertStringContainsString('kwa-send-template-btn', $mixedHtml);

        // Channel-105, window closed, customer WITH an email so the picker
        // still renders (pickerAvailable() needs both legs) -- the closed
        // banner already carries the template transport for a native
        // WhatsApp conversation, so the picker must NOT carry it too: never
        // both.
        $waConversation = $this->makeConversation($account, KapsoAccount::CHANNEL, $customer);
        $this->seedInbound($account, $waConversation, '+491776666666', null, now()->subHours(30), 'wamid.PICK6');

        $waHtml = $this->renderPicker($waConversation);
        $this->assertStringContainsString('kwa-channel-picker', $waHtml, 'sanity: the picker itself must still render here');
        $this->assertStringNotContainsString('data-kwa-templates-url', $waHtml);
        $this->assertStringNotContainsString('data-kwa-send-url', $waHtml);
        $this->assertStringNotContainsString('kwa-send-template-btn', $waHtml);
    }

    /**
     * F5 (whole-stage review, MINOR): a closed, non-105 conversation with
     * WhatsApp history but no customer email is otherwise a dead end --
     * pickerAvailable() needs both legs so the normal picker never renders,
     * the window banner stays channel-105-only per spec, and the revised C1
     * filter correctly removes the Reply button because nothing free-form
     * can send -- yet the template endpoints would still happily serve this
     * conversation. renderChannelPicker() must fall back to a
     * template-only render here: the 3c transport attributes and the
     * "Send a template…" button, but no radios (there is nothing left to
     * pick between). An open window on the same shape has no template leg
     * either, so it renders nothing at all.
     */
    public function test_a_closed_non105_conversation_with_no_email_gets_a_template_only_picker()
    {
        $account         = $this->makeAccount();
        $noEmailCustomer = Customer::createWithoutEmail(['first_name' => 'No', 'last_name' => 'Email']);

        $closed = $this->makeConversation($account, 1, $noEmailCustomer);
        $this->seedInbound($account, $closed, '+491777777777', null, now()->subHours(30), 'wamid.PICK7');

        $closedHtml = $this->renderPicker($closed);
        $this->assertStringContainsString('data-kwa-templates-url', $closedHtml);
        $this->assertStringContainsString('data-kwa-send-url', $closedHtml);
        $this->assertStringContainsString('kwa-send-template-btn', $closedHtml);
        $this->assertStringNotContainsString('name="kwa_channel"', $closedHtml);

        $open = $this->makeConversation($account, 1, $noEmailCustomer);
        $this->seedInbound($account, $open, '+491778888888', null, now()->subHour(), 'wamid.PICK8');

        $openHtml = $this->renderPicker($open);
        $this->assertStringNotContainsString('kwa-channel-picker', $openHtml);
        $this->assertStringNotContainsString('data-kwa-templates-url', $openHtml);
    }
}
