<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use Carbon\Carbon;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Services\WindowState;
use Modules\KapsoWhatsApp\Tests\TestCase;

class WindowStateTest extends TestCase
{
    /**
     * Copied from SendReplyTest::makeAccount() per module convention (each
     * test file duplicates its own fixture helpers rather than sharing a
     * trait). One call per test -- phone_number_id is unique-constrained and
     * hardcoded here, but DatabaseTransactions rolls every test back.
     */
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

    /**
     * Copied from SendReplyTest::makeConversation(), with the channel made
     * overridable -- needed here for the "non-WhatsApp conversation" case,
     * which SendReplyTest never needs.
     */
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

    /**
     * Writes a KapsoMessage row directly (no clock mocking -- see the plan)
     * with an explicit `created_at`. `save()` twice: the first insert lets
     * Eloquent's own timestamp behaviour populate `created_at`/`updated_at`
     * as usual, the second overwrites `created_at` with the caller's value
     * so it survives (Eloquent would otherwise stamp "now" on insert
     * regardless of what was set before the first save()).
     */
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

    public function test_a_non_whatsapp_conversation_has_no_window()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account, 1);

        $this->assertNull(WindowState::forConversation($conversation));
    }

    public function test_a_whatsapp_conversation_with_no_inbound_has_no_window()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->assertNull(WindowState::forConversation($conversation));
    }

    public function test_a_recent_inbound_means_open()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        // Truncated to whole seconds: the `created_at` column has
        // second-level precision, so a value carrying microseconds (as
        // now()->subHours() does) would never round-trip back out equal to
        // what was written, making the exact-value assertions below flaky
        // rather than a real bug in WindowState.
        $createdAt    = now()->subHours(23)->startOfSecond();

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, $createdAt);

        $state = WindowState::forConversation($conversation);

        $this->assertNotNull($state);
        $this->assertTrue($state['open']);
        $this->assertTrue($createdAt->eq($state['last_inbound_at']));
        $this->assertTrue($createdAt->copy()->addHours(WindowState::WINDOW_HOURS)->eq($state['closes_at']));
    }

    public function test_an_old_inbound_means_closed()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(25));

        $state = WindowState::forConversation($conversation);

        $this->assertNotNull($state);
        $this->assertFalse($state['open']);
    }

    public function test_the_exact_24h_boundary_is_closed()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        // isFuture() on an equal-or-past instant is false -- exactly-24h-old
        // must therefore read as closed, not open.
        $this->seedMessage($account, $conversation, '+491771234567', KapsoMessage::DIRECTION_INBOUND, now()->subHours(24));

        $state = WindowState::forConversation($conversation);

        $this->assertNotNull($state);
        $this->assertFalse($state['open']);
    }

    /**
     * THE test this stage exists for: two FreeScout conversations, same
     * account, same contact phone. Conversation A's own inbound history is
     * 30h stale, but the customer also messaged on conversation B one hour
     * ago -- the same WhatsApp contact, from Meta's point of view, so the
     * window must read OPEN from either conversation.
     */
    public function test_the_window_is_keyed_per_contact_not_per_conversation()
    {
        $account = $this->makeAccount();
        $convA   = $this->makeConversation($account);
        $convB   = $this->makeConversation($account);
        $phone   = '+491771234567';

        $this->seedMessage($account, $convA, $phone, KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));
        $this->seedMessage($account, $convB, $phone, KapsoMessage::DIRECTION_INBOUND, now()->subHours(1));

        $state = WindowState::forConversation($convA);

        $this->assertNotNull($state);
        $this->assertTrue($state['open']);
    }

    public function test_another_contacts_messages_do_not_open_the_window()
    {
        $account = $this->makeAccount();
        $convA   = $this->makeConversation($account);
        $convB   = $this->makeConversation($account);

        $this->seedMessage($account, $convA, '+491771111111', KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));
        $this->seedMessage($account, $convB, '+491772222222', KapsoMessage::DIRECTION_INBOUND, now()->subHours(1));

        $state = WindowState::forConversation($convA);

        $this->assertNotNull($state);
        $this->assertFalse($state['open']);
    }

    public function test_outbound_rows_never_extend_the_window()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $phone        = '+491771234567';

        $this->seedMessage($account, $conversation, $phone, KapsoMessage::DIRECTION_INBOUND, now()->subHours(30));
        $this->seedMessage($account, $conversation, $phone, KapsoMessage::DIRECTION_OUTBOUND, now()->subHours(1));

        $state = WindowState::forConversation($conversation);

        $this->assertNotNull($state);
        $this->assertFalse($state['open']);
    }
}
