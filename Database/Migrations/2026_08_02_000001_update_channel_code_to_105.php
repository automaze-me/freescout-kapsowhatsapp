<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The FreeScout team assigns community channel codes centrally; 103 and 104
 * went to the MetaWhatsApp community module and this module moved from its
 * self-picked 102 to the next free code, 105. Both numbers are deliberately
 * hardcoded here rather than read from KapsoAccount::CHANNEL -- this
 * migration must keep mapping 102 to 105 even if the constant ever moves
 * again (a later move gets its own migration).
 *
 * Every UPDATE is scoped to rows this module itself created -- via the
 * module's own message/account tables -- so a hypothetical third-party
 * module that also picked 102 never gets its data remapped.
 */
class UpdateChannelCodeTo105 extends Migration
{
    const OLD_CHANNEL = 102;
    const NEW_CHANNEL = 105;

    public function up()
    {
        $this->remap(self::OLD_CHANNEL, self::NEW_CHANNEL);
    }

    public function down()
    {
        $this->remap(self::NEW_CHANNEL, self::OLD_CHANNEL);
    }

    protected function remap($from, $to)
    {
        // Conversations are ours when a kapso message row points at them, or
        // -- belt and braces for a row whose backfill never ran -- when they
        // live in a mailbox that has a Kapso account.
        DB::table('conversations')
            ->where('channel', $from)
            ->where(function ($query) {
                $query->whereIn('id', function ($q) {
                    $q->select('conversation_id')
                        ->from('kapso_whatsapp_messages')
                        ->whereNotNull('conversation_id');
                })->orWhereIn('mailbox_id', function ($q) {
                    $q->select('mailbox_id')->from('kapso_whatsapp_accounts');
                });
            })
            ->update(['channel' => $to]);

        // A row may already exist at the target code for the same phone:
        // module code writes $to-rows the moment the new version goes live,
        // and a webhook can land before this migration runs (some installs
        // only migrate at the next container boot). The bulk UPDATE below
        // would then trip the unique (channel, channel_id) index and abort
        // the whole migrate run, so the superseded source rows are dropped
        // first — the target row is the one the running code already
        // resolves customers through. If the interim message created a
        // duplicate customer, the log line below carries both customer ids
        // so an agent can merge them later (same trade-off CustomerResolver
        // makes for ambiguous matches). Collisions are collected in PHP
        // because MySQL cannot subquery the table being deleted from.
        $collisions = DB::table('customer_channel')
            ->where('channel', $to)
            ->whereIn('channel_id', function ($q) {
                $q->select('contact_phone')
                    ->from('kapso_whatsapp_messages')
                    ->whereNotNull('contact_phone');
            })
            ->get(['channel_id', 'customer_id']);

        foreach ($collisions->chunk(500) as $chunk) {
            $stale = DB::table('customer_channel')
                ->where('channel', $from)
                ->whereIn('channel_id', $chunk->pluck('channel_id')->all())
                ->get(['id', 'channel_id', 'customer_id']);

            if ($stale->isEmpty()) {
                continue;
            }

            $stalePhones = $stale->pluck('channel_id')->all();

            \Log::info('[KapsoWhatsApp] Channel remap: dropping stale rows superseded by rows live code already created', [
                'from'     => $from,
                'to'       => $to,
                'dropped'  => $stale->map(function ($row) {
                    return ['channel_id' => $row->channel_id, 'customer_id' => $row->customer_id];
                })->all(),
                'kept'     => $chunk->whereIn('channel_id', $stalePhones)->map(function ($row) {
                    return ['channel_id' => $row->channel_id, 'customer_id' => $row->customer_id];
                })->values()->all(),
            ]);

            DB::table('customer_channel')
                ->whereIn('id', $stale->pluck('id')->all())
                ->delete();
        }

        // customer_channel rows are only ever written by CustomerResolver
        // with the same E164 string ProcessInboundMessage stores as
        // contact_phone, so the phone match identifies exactly our rows.
        DB::table('customer_channel')
            ->where('channel', $from)
            ->whereIn('channel_id', function ($q) {
                $q->select('contact_phone')
                    ->from('kapso_whatsapp_messages')
                    ->whereNotNull('contact_phone');
            })
            ->update(['channel' => $to]);

        // The legacy customers.channel/channel_id flag columns (set once by
        // Customer::addChannel() when empty) follow the customer_channel rows
        // just updated above.
        DB::table('customers')
            ->where('channel', $from)
            ->whereIn('id', function ($q) use ($to) {
                $q->select('customer_id')
                    ->from('customer_channel')
                    ->where('channel', $to);
            })
            ->update(['channel' => $to]);
    }
}
