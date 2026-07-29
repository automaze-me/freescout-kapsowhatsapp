<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Attachment;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class InboundMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute.
        Settings::setApiKey('key');
    }

    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name' => 'Support', 'phone_number_id' => '123456789012345',
            'mailbox_id' => $this->testMailbox()->id, 'is_active' => true,
        ]);
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function mediaPayload(): array
    {
        return [
            'message' => [
                'id'    => 'wamid.media.1',
                'type'  => 'image',
                'from'  => '4915188888888',
                'image' => ['caption' => 'Broken part', 'id' => 'media_1'],
                'kapso' => [
                    'direction'  => 'inbound',
                    'has_media'  => true,
                    'content'    => 'Broken part',
                    'media_url'  => 'https://api.kapso.ai/media/abc',
                    'media_data' => [
                        'url'          => 'https://api.kapso.ai/media/abc',
                        'filename'     => 'photo.jpg',
                        'content_type' => 'image/jpeg',
                        'byte_size'    => 11,
                    ],
                    'message_type_data' => ['caption' => 'Broken part'],
                ],
            ],
            'conversation' => [
                'id' => 'conv_media', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'Media Sender'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => '123456789012345',
        ];
    }

    public function test_media_is_downloaded_and_attached_to_the_thread()
    {
        $account = $this->makeAccount();

        KapsoClient::fake(function ($url) {
            $this->assertSame('https://api.kapso.ai/media/abc', $url);

            return 'fake-bytes';
        });

        (new ProcessInboundMessage($account->id, $this->mediaPayload()))->handle();

        $threadId = KapsoMessage::where('wamid', 'wamid.media.1')->value('thread_id');
        $thread   = Thread::findOrFail($threadId);

        $this->assertSame(1, $thread->attachments()->count());

        $attachment = $thread->attachments()->first();
        $this->assertSame('photo.jpg', $attachment->file_name);
        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertSame(Attachment::TYPE_IMAGE, (int) $attachment->type);
        // Thread has no boolean cast for has_attachments, so a value
        // re-fetched from the DB comes back as an int/string, not strict
        // PHP true; assertTrue() requires strict true, so cast explicitly.
        $this->assertTrue((bool) $thread->has_attachments);
    }

    public function test_a_failed_download_still_creates_the_thread_and_notes_the_failure()
    {
        $account = $this->makeAccount();

        KapsoClient::fake(function () {
            return null; // download failed
        });

        (new ProcessInboundMessage($account->id, $this->mediaPayload()))->handle();

        $threadId = KapsoMessage::where('wamid', 'wamid.media.1')->value('thread_id');
        $thread   = Thread::findOrFail($threadId);

        $this->assertSame(0, $thread->attachments()->count());
        $this->assertStringContainsString('photo.jpg', $thread->body,
            'the thread should say which attachment could not be retrieved');
    }
}
