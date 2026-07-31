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

        // A captioned image's body must be the caption itself, never
        // Kapso's `kapso.content` fallback -- even though this fixture's
        // `content` happens to mirror the caption verbatim (the real
        // payload shape, per the module's own docs), the body must still
        // read as the caption and carry no stray URL.
        $this->assertSame(nl2br(e('Broken part', true)), $thread->body);
        $this->assertStringNotContainsString('http', $thread->body);
    }

    protected function captionlessMediaPayload(): array
    {
        return [
            'message' => [
                'id'    => 'wamid.media.2',
                'type'  => 'image',
                'from'  => '4915188888888',
                'image' => ['id' => 'media_2'],
                'kapso' => [
                    'direction'  => 'inbound',
                    'has_media'  => true,
                    'content'    => 'Image attached (photo.jpg) [Size: 117.4 KB | Type: image/jpeg] URL: https://app.kapso.ai/rails/active_storage/blobs/redirect/abc',
                    'media_url'  => 'https://api.kapso.ai/media/def',
                    'media_data' => [
                        'url'          => 'https://api.kapso.ai/media/def',
                        'filename'     => 'photo.jpg',
                        'content_type' => 'image/jpeg',
                        'byte_size'    => 11,
                    ],
                ],
            ],
            'conversation' => [
                'id' => 'conv_media_2', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'Caption-less Sender'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => '123456789012345',
        ];
    }

    /**
     * The bug this pins: a caption-less inbound image previously fell
     * through to `kapso.content`, which Kapso renders as a human-readable
     * description ending in a *temporary, signed* URL -- dead later, useless
     * to an agent, and redundant with the attachment that is already on the
     * thread. The body must instead be the translatable placeholder, with
     * no trace of Kapso's URL or domain.
     */
    public function test_a_captionless_image_body_is_the_placeholder_not_the_kapso_url()
    {
        $account = $this->makeAccount();

        KapsoClient::fake(function ($url) {
            $this->assertSame('https://api.kapso.ai/media/def', $url);

            return 'fake-bytes';
        });

        (new ProcessInboundMessage($account->id, $this->captionlessMediaPayload()))->handle();

        $threadId = KapsoMessage::where('wamid', 'wamid.media.2')->value('thread_id');
        $thread   = Thread::findOrFail($threadId);

        $this->assertStringNotContainsString('http', $thread->body);
        $this->assertStringNotContainsString('kapso', $thread->body);
        $this->assertSame(
            nl2br(e(__('WhatsApp message: :type', ['type' => 'image']), true)),
            $thread->body
        );

        // The attachment is unaffected by the body fix -- still downloaded
        // and attached exactly as before.
        $this->assertSame(1, $thread->attachments()->count());
        $this->assertSame('photo.jpg', $thread->attachments()->first()->file_name);
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
