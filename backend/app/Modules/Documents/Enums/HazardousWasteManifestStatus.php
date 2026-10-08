<?php

namespace App\Modules\Documents\Enums;

enum HazardousWasteManifestStatus: string
{
    case Draft = 'draft';
    case PendingProducerSignature = 'pending_producer_signature';
    case PendingTransporterSignature = 'pending_transporter_signature';
    case PendingReceiverSignature = 'pending_receiver_signature';
    // Every party has signed, at least one by hand: the scan of the signed slip is still to be attached.
    case PendingSignedCopy = 'pending_signed_copy';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
