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

    public function test_a_closed_window_renders_the_blocking_banner()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));

        $html = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringContainsString('data-kwa-window-closed', $html);
        $this->assertStringContainsString('this window closed', $html);
        $this->assertStringContainsString('alert-danger', $html);
    }

    public function test_an_open_window_renders_the_status_line_without_the_block_marker()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(1));

        $html = $this->renderBanner($conversation, $account->mailbox);

        $this->assertStringContainsString('closes', $html);
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

        $matches = array_filter($javascripts, function ($path) {
            return substr($path, -strlen('kapsowhatsapp.js')) === 'kapsowhatsapp.js';
        });

        $this->assertNotEmpty($matches, 'the javascripts filter must ship kapsowhatsapp.js');
        $this->assertFileExists(__DIR__.'/../../Public/js/kapsowhatsapp.js');
    }
}
