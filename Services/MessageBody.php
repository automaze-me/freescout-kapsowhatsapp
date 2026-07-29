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
     * Shared by ProcessInboundMessage (inbound customer messages) and
     * ReconcileOutboundMessage (foreign sends and delivery failures): the
     * shape of `message.text.body` / `message.kapso.content` / `message.type`
     * is identical for both directions.
     */
    public static function extract(array $message): string
    {
        $text = $message['text']['body'] ?? null;

        if (is_scalar($text) && trim((string) $text) !== '') {
            return (string) $text;
        }

        $content = $message['kapso']['content'] ?? null;

        if (is_scalar($content) && trim((string) $content) !== '') {
            return (string) $content;
        }

        $type = $message['type'] ?? null;
        $type = is_scalar($type) ? (string) $type : 'unknown';

        return __('WhatsApp message: :type', ['type' => $type]);
    }
}
