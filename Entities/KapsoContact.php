<?php

namespace Modules\KapsoWhatsApp\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per business-scoped user ID (BSUID) this install has seen: the
 * module-owned BSUID -> FreeScout customer directory (Stage 5).
 *
 * Lives here and NOT in customer_channel: core's Customer::addChannel()
 * (app/Customer.php) keeps exactly one row per (customer, channel) and
 * overwrites its channel_id on change, so a second identity written there
 * would destroy the phone identity (spec D10). `customer_id` is never
 * repointed by ContactDirectory::record(); the only way a bsuid changes
 * customers is CustomerResolver's stale-row delete-and-recreate.
 */
class KapsoContact extends Model
{
    protected $table = 'kapso_whatsapp_contacts';

    protected $fillable = [
        'bsuid', 'parent_bsuid', 'customer_id', 'phone', 'username',
    ];
}
