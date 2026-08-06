<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoContact;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ReconcileOutboundMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class ReconcileBsuidTest extends TestCase
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

    protected function makeConversation(KapsoAccount $account, Customer $customer): Conversation
    {
        $folder = Folder::where('mailbox_id', $account->mailbox_id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = KapsoAccount::CHANNEL;
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

    protected function seedBsuidHistory(KapsoAccount $account, Conversation $conversation, string $bsuid): void
    {
        $inbound = new KapsoMessage();
        $inbound->account_id      = $account->id;
        $inbound->conversation_id = $conversation->id;
        $inbound->direction       = KapsoMessage::DIRECTION_INBOUND;
        $inbound->wamid           = 'wamid.hist.'.$bsuid;
        $inbound->contact_bsuid   = $bsuid;
        $inbound->save();
    }

    public function test_foreign_send_resolves_the_conversation_via_bsuid()
    {
        $account  = $this->makeAccount();
        $customer = Customer::createWithoutEmail(['first_name' => 'Rita', 'last_name' => 'Reconcile']);
        $conversation = $this->makeConversation($account, $customer);
        $this->seedBsuidHistory($account, $conversation, 'US.Reconcile1');

        $job = new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', [
            'message' => [
                'id'         => 'wamid.foreign.bsuid1',
                'type'       => 'text',
                'to_user_id' => 'US.Reconcile1',
                'text'       => ['body' => 'Sent from Kapso inbox'],
                'kapso'      => ['direction' => 'outbound', 'status' => 'sent', 'content' => 'Sent from Kapso inbox'],
            ],
            'conversation' => ['id' => 'conv_reconcile_1', 'phone_number' => null],
        ]);
        $job->handle();

        $row = KapsoMessage::where('wamid', 'wamid.foreign.bsuid1')->first();
        $this->assertNotNull($row, 'the foreign send must be recorded');
        $this->assertSame($conversation->id, (int) $row->conversation_id);
        $this->assertSame('US.Reconcile1', $row->contact_bsuid);
        $this->assertNotNull(Thread::find($row->thread_id));
    }

    public function test_unknown_failure_resolves_via_bsuid_and_posts_a_line_item()
    {
        $account  = $this->makeAccount();
        $customer = Customer::createWithoutEmail(['first_name' => 'Sven', 'last_name' => 'Fehler']);
        $conversation = $this->makeConversation($account, $customer);
        $this->seedBsuidHistory($account, $conversation, 'US.Reconcile2');

        $job = new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', [
            'message' => [
                'id'         => 'wamid.failed.bsuid1',
                'type'       => 'text',
                'to_user_id' => 'US.Reconcile2',
                'text'       => ['body' => 'Never arrived'],
                'kapso'      => [
                    'direction' => 'outbound',
                    'status'    => 'failed',
                    'content'   => 'Never arrived',
                    'statuses'  => [['errors' => [['code' => 131062, 'title' => 'Unsupported recipient', 'message' => 'BSUID recipients are not supported for this message.']]]],
                ],
            ],
            'conversation' => ['id' => 'conv_reconcile_2', 'phone_number' => null],
        ]);
        $job->handle();

        $row = KapsoMessage::where('wamid', 'wamid.failed.bsuid1')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame($conversation->id, (int) $row->conversation_id);
        $this->assertNotNull(
            Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->first()
        );
    }

    public function test_status_webhook_capture_backfills_the_mapping()
    {
        $account  = $this->makeAccount();
        $customer = Customer::createWithoutEmail(['first_name' => 'Tara', 'last_name' => 'Mapped']);
        $customer->addChannel(KapsoAccount::CHANNEL, '+4915177777720');
        $conversation = $this->makeConversation($account, $customer);

        // Our own accepted send, so the `sent` branch takes markOwnSendSent():
        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $this->adminUser()->id;
        $thread->created_by_user_id = $thread->user_id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->body               = '<p>Hi</p>';
        $thread->customer_id        = $customer->id;
        $thread->save();

        $own = new KapsoMessage();
        $own->account_id      = $account->id;
        $own->conversation_id = $conversation->id;
        $own->thread_id       = $thread->id;
        $own->part_key        = KapsoMessage::PART_BODY;
        $own->direction       = KapsoMessage::DIRECTION_OUTBOUND;
        $own->send_state      = KapsoMessage::SEND_STATE_ACCEPTED;
        $own->wamid           = 'wamid.own.capture1';
        $own->contact_phone   = '+4915177777720';
        $own->save();

        $job = new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', [
            'message' => [
                'id'         => 'wamid.own.capture1',
                'to'         => '4915177777720',
                'to_user_id' => 'US.Capture1',
                'kapso'      => ['direction' => 'outbound', 'status' => 'sent'],
            ],
            'conversation' => ['id' => 'conv_capture_1', 'phone_number' => '4915177777720'],
        ]);
        $job->handle();

        $contact = KapsoContact::where('bsuid', 'US.Capture1')->first();
        $this->assertNotNull($contact, 'the status webhook must backfill the mapping');
        $this->assertSame($customer->id, (int) $contact->customer_id);
        $this->assertSame('+4915177777720', $contact->phone);
    }
}
