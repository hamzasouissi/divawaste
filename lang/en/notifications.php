<?php

return [
    'stock' => [
        'threshold_warning' => [
            'title' => 'Stock threshold almost reached — :site',
            'body' => 'Waste stock is at :quantity_kg kg for a threshold of :max_quantity_kg kg (:site).',
        ],
        'threshold_exceeded' => [
            'title' => 'Stock threshold exceeded — :site',
            'body' => 'Waste stock is at :quantity_kg kg, above the :max_quantity_kg kg threshold (:site). Plan a pickup.',
        ],
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
