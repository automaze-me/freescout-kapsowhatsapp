<?php

namespace Modules\KapsoWhatsApp\Services;

class MessageBody
{
    /**
     * Extracts the human-readable text of a Kapso message payload, inbound
     * or outbound alike. Prefer the typed text; fall back to Kapso's
     * rendered representation, and finally to a translatable placeholder
     * naming the message type, so unsupported types (location, contacts,
     * interactive, template) never leave an essentially blank thread.
     *
     * `kapso.content` is trusted ONLY for non-media messages. For a media
     * message (`kapso.has_media` true), Kapso's own rendering of `content`
     * is "<description> ... URL: <temporary signed link>" -- a link that
     * expires and is useless to an agent besides, and redundant with the
     * attachment already on the thread. Media messages therefore skip
     * `content` entirely and use the caption instead (from the type-keyed
     * field, e.g. `message.image.caption`, falling back to
     * `kapso.message_type_data.caption`), or the same translatable
     * placeholder when there is no caption.
     *
     * Shared by ProcessInboundMessage (inbound customer messages) and
     * ReconcileOutboundMessage (foreign sends and delivery failures): the
     * shape of `message.text.body` / `message.kapso.content` / `message.type`
     * is identical for both directions, so the same URL-avoidance rule
     * applies to a foreign media send too.
     */
    public static function extract(array $message): string
    {
        $text = $message['text']['body'] ?? null;

        if (is_scalar($text) && trim((string) $text) !== '') {
            return (string) $text;
        }

        $type = $message['type'] ?? null;
        $type = is_scalar($type) ? (string) $type : 'unknown';

        if (!empty($message['kapso']['has_media'])) {
            $typed   = $message[$type] ?? null;
            $caption = (is_array($typed) ? ($typed['caption'] ?? null) : null)
                ?? ($message['kapso']['message_type_data']['caption'] ?? null);

            if (is_scalar($caption) && trim((string) $caption) !== '') {
                return (string) $caption;
            }

            return __('WhatsApp message: :type', ['type' => $type]);
        }

        $content = $message['kapso']['content'] ?? null;

        if (is_scalar($content) && trim((string) $content) !== '') {
            return (string) $content;
        }

        return __('WhatsApp message: :type', ['type' => $type]);
    }
}
