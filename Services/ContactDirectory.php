<?php

namespace Modules\KapsoWhatsApp\Services;

use Modules\KapsoWhatsApp\Entities\KapsoContact;

/**
 * The single home for business-scoped user ID (BSUID) concerns: payload
 * extraction, format validation, and the module-owned bsuid -> customer
 * directory (kapso_whatsapp_contacts). See the Stage 5 spec
 * (dev-notes/specs/2026-08-05-kapso-whatsapp-bsuid-design.md) for why the
 * mapping cannot live in customer_channel (core keeps one row per
 * customer+channel and overwrites it) and for the field paths extracted
 * here (verified against Kapso's BSUID doc, 2026-08-05).
 */
class ContactDirectory
{
    /**
     * Meta's BSUID shape: two-letter country code, period, optional "ENT."
     * segment (parent BSUIDs), then up to 128 alphanumerics. Max total
     * length 135. Case-insensitive to validate, but values are stored and
     * compared verbatim -- never normalised.
     */
    const BSUID_PATTERN = '/\A[A-Z]{2}\.(?:ENT\.)?[A-Za-z0-9]{1,128}\z/i';

    public static function isValidBsuid($value): bool
    {
        return is_string($value) && preg_match(self::BSUID_PATTERN, $value) === 1;
    }

    /**
     * Identity of the customer who SENT this webhook's message. Field
     * paths are defensive, first non-empty wins: Kapso's own BSUID doc
     * lists `business_scoped_user_id` for Kapso-formatted message
     * payloads while both of its webhook examples show the Meta-style
     * `from_user_id`, and the conversation object carries the normalised
     * names -- so all three are tried, message before conversation.
     */
    public function extractInbound(array $payload): array
    {
        $message      = $payload['message'] ?? [];
        $conversation = $payload['conversation'] ?? [];

        return $this->identity(
            $message['business_scoped_user_id']
                ?? $message['from_user_id']
                ?? $conversation['business_scoped_user_id']
                ?? null,
            $message['parent_business_scoped_user_id']
                ?? $message['from_parent_user_id']
                ?? $conversation['parent_business_scoped_user_id']
                ?? null,
            $message['username'] ?? $conversation['username'] ?? null
        );
    }

    /**
     * Identity of the customer an OUTBOUND message (a `sent`/`failed`
     * event, a delivery receipt) was addressed to -- `to_user_id` instead
     * of `from_user_id`, otherwise the same defensive layering.
     */
    public function extractOutbound(array $payload): array
    {
        $message      = $payload['message'] ?? [];
        $conversation = $payload['conversation'] ?? [];

        return $this->identity(
            $message['to_user_id']
                ?? $message['business_scoped_user_id']
                ?? $conversation['business_scoped_user_id']
                ?? null,
            $message['to_parent_user_id']
                ?? $message['parent_business_scoped_user_id']
                ?? $conversation['parent_business_scoped_user_id']
                ?? null,
            $message['username'] ?? $conversation['username'] ?? null
        );
    }

    public function customerIdFor(?string $bsuid): ?int
    {
        if (!$bsuid) {
            return null;
        }

        $id = KapsoContact::where('bsuid', $bsuid)->value('customer_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Upsert by bsuid. Creates the mapping when missing; when the row
     * already exists, refreshes phone/username/parent_bsuid if newly
     * learned or changed -- but NEVER repoints customer_id: the bsuid
     * row's customer always won resolution first (CustomerResolver checks
     * the directory before anything else), so a different customer id
     * arriving here could only be a race artifact, and silently moving a
     * bsuid between customers would re-route their conversation history.
     */
    public function record(string $bsuid, int $customerId, array $attrs = []): void
    {
        $contact = KapsoContact::where('bsuid', $bsuid)->first();

        if (!$contact) {
            try {
                KapsoContact::create([
                    'bsuid'        => $bsuid,
                    'customer_id'  => $customerId,
                    'phone'        => $attrs['phone'] ?? null,
                    'username'     => $attrs['username'] ?? null,
                    'parent_bsuid' => $attrs['parent_bsuid'] ?? null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Module convention (ProcessInboundMessage, SendReplyMessage):
                // catch broadly, re-check, and only swallow when the re-check
                // proves a concurrent writer won the unique-index race.
                if (!KapsoContact::where('bsuid', $bsuid)->exists()) {
                    throw $e;
                }
            }

            return;
        }

        $dirty = false;

        foreach (['phone', 'username', 'parent_bsuid'] as $field) {
            if (!empty($attrs[$field]) && $contact->{$field} !== $attrs[$field]) {
                $contact->{$field} = $attrs[$field];
                $dirty = true;
            }
        }

        if ($dirty) {
            $contact->save();
        }
    }

    /**
     * Shared shaping/validation for both extract paths: malformed values
     * are treated as absent (warned once) so a garbage field can neither
     * crash inbound processing nor address a send.
     */
    protected function identity($bsuid, $parent, $username): array
    {
        if ($bsuid !== null && !self::isValidBsuid($bsuid)) {
            \Log::warning('[KapsoWhatsApp] Ignoring a malformed business-scoped user id', [
                'value' => is_scalar($bsuid) ? (string) $bsuid : gettype($bsuid),
            ]);
            $bsuid = null;
        }

        if ($parent !== null && !self::isValidBsuid($parent)) {
            $parent = null;
        }

        $username = is_string($username) ? trim($username) : '';

        return [
            'bsuid'        => $bsuid,
            'parent_bsuid' => $parent,
            'username'     => $username !== '' ? $username : null,
        ];
    }
}
