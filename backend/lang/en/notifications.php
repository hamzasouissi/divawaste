<?php

return [
    'stock' => [
        'threshold_warning' => [
            'title' => 'Stock limit almost reached — :site',
            'body' => 'Waste stock is at :quantity_kg kg for a limit of :max_quantity_kg kg (:site).',
        ],
        'threshold_exceeded' => [
            'title' => 'Stock limit exceeded — :site',
            'body' => 'Waste stock is at :quantity_kg kg, above the :max_quantity_kg kg limit (:site). Plan a pickup.',
        ],
        'threshold_unverifiable' => [
            'title' => 'Stock limit cannot be verified — :site',
            'body' => 'Limit of :max_quantity_kg kg (:scope): the known weight is :quantity_kg kg, but :unweighed_lots lot(s) are not weighed (:lots). The limit is not exceeded on what is known, and it cannot be called respected: weigh these lots.',
        ],
    ],
    'capacity' => [
        'space_warning' => ['title' => 'Zone almost full — :scope', 'body' => 'The room of the zone is :pct % used (:detail). No lot is refused; think about making room.'],
        'space_exceeded' => ['title' => 'Zone room exceeded — :scope', 'body' => 'The room of the zone is :pct % used (:detail): its capacity is exceeded. Lots are still accepted; make room or review the capacity of the zone.'],
        'space_unverifiable' => ['title' => 'Zone room cannot be verified — :scope', 'body' => 'The known room is at least :pct % used (:detail), but :missing lot(s) cannot be counted (:lots): volume or packaging not on record. This is not a confirmed exceedance; the occupancy cannot be called within capacity.'],
        'load_warning' => ['title' => 'Zone load almost reached — :scope', 'body' => 'The zone bears :detail (:pct % of its maximum load).'],
        'load_exceeded' => ['title' => 'Zone load exceeded — :scope', 'body' => 'The zone bears :detail: its maximum load is exceeded by :excess. Lots are still accepted; move or evacuate lots.'],
        'load_unverifiable' => ['title' => 'Zone load cannot be verified — :scope', 'body' => 'Known weight: :detail, but :missing lot(s) are not weighed (:lots). This is not a confirmed exceedance; the load cannot be called respected: weigh these lots.'],
        'site_warning' => ['title' => 'Site capacity almost reached — :site', 'body' => 'The site holds :detail (:pct % of its maximum weight), all zones together.'],
        'site_exceeded' => ['title' => 'Site capacity exceeded — :site', 'body' => 'The site holds :detail, all zones together: its maximum weight is exceeded by :excess. Lots are still accepted; plan a pickup.'],
        'site_unverifiable' => ['title' => 'Site capacity cannot be verified — :site', 'body' => 'Known weight on the site: :detail, but :missing lot(s) are not weighed (:lots). This is not a confirmed exceedance; the capacity cannot be called respected: weigh these lots.'],
    ],
    'pickup' => [
        'requested' => ['title' => 'New pickup request :number', 'body' => ':company requests a pickup on :date at site :site (:lots lots, :weight_kg kg).'],
        'confirmed' => ['title' => 'Pickup :number confirmed', 'body' => ':provider confirmed pickup :number for :date.'],
        'refused' => ['title' => 'Pickup :number refused', 'body' => ':provider refused pickup :number. Reason: :reason'],
        'cancelled' => ['title' => 'Pickup :number cancelled', 'body' => ':company cancelled pickup :number. Reason: :reason'],
        'collected' => ['title' => 'Pickup :number loaded', 'body' => 'The lots of pickup :number left the site (:weight_kg kg at departure).'],
        'received' => ['title' => 'Pickup :number received', 'body' => ':provider received :weight_kg kg for pickup :number.'],
        'variance_flagged' => ['title' => 'Weight discrepancy on pickup :number', 'body' => 'Received :received_kg kg for :departure_kg kg at departure, a :variance_pct % discrepancy.'],
        'completed' => ['title' => 'Pickup :number closed', 'body' => 'Pickup :number is closed.'],
    ],
    'accreditation' => [
        'expiring_soon' => ['title' => 'Accreditation expiring soon — :provider', 'body' => 'Accreditation :reference of :provider expires on :date (in :days days).'],
        'expired' => ['title' => 'Accreditation expired — :provider', 'body' => 'Accreditation :reference of :provider expired on :date. This provider can no longer be selected until it is renewed.'],
        'reviewed' => ['title' => 'Accreditation :reference: :status', 'body' => 'Your accreditation :reference was reviewed by the platform. Decision: :status.'],
    ],
    'authorization' => [
        'usage_high' => ['title' => 'Authorisation :reference almost used up', 'body' => 'A limit of authorisation :reference (:waste_type) is :pct % used. Plan a new one: without it, the next pickups will be blocked.'],
        'expiring_soon' => ['title' => 'Authorisation :reference ends on :date', 'body' => 'Authorisation :reference (:waste_type) ends on :date, in :days day(s). Without a new one, pickups of this waste will be blocked.'],
    ],
    'document' => [
        'signature_requested' => ['title' => 'Tracking slip :number to sign', 'body' => 'Tracking slip :number is waiting for your signature.'],
        'signed_copy_requested' => ['title' => 'Tracking slip :number: signed copy to attach', 'body' => 'Every party has signed tracking slip :number. Attach the scan of the copy signed by hand to close it.'],
        'certificate_uploaded' => ['title' => 'Certificate uploaded for pickup :number', 'body' => ':provider uploaded a certificate for pickup :number.'],
    ],
    'report' => [
        'ready' => ['title' => 'Your report is ready', 'body' => 'Report ":name" is available for download.'],
        'failed' => ['title' => 'Report generation failed', 'body' => 'Report ":name" could not be generated.'],
    ],
    'payment' => [
        'declared' => ['title' => 'Bank transfer declared — :company', 'body' => ':company declares a transfer of :amount for invoice :invoice.'],
        'reviewed' => ['title' => 'Transfer :status — invoice :invoice', 'body' => 'Your transfer of :amount for invoice :invoice was :status.'],
    ],
    'invoice' => [
        'issued' => ['title' => 'New invoice :invoice', 'body' => 'Invoice :invoice for :amount is available. Due: :due_date.'],
    ],
    'subscription' => [
        'activated' => ['title' => 'Your DivaWaste subscription is active', 'body' => 'The :plan plan of :company is active since :start. Your first invoice :invoice, for :amount, is available under My subscription. Payment due: :due_date, by bank transfer.'],
        'trial_ending' => ['title' => 'Your free trial ends on :date', 'body' => ':days days of trial remain. Choose a plan to keep using DivaWaste.'],
    ],
    'company' => [
        'pending_review' => ['title' => 'Registration to review — :company', 'body' => ':company (:type) is waiting for its registration to be reviewed.'],
    ],
];
