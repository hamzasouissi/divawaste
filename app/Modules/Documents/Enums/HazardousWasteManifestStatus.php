<?php

namespace App\Modules\Documents\Enums;

enum HazardousWasteManifestStatus: string
{
    case Draft = 'draft';
    case PendingProducerSignature = 'pending_producer_signature';
    case PendingTransporterSignature = 'pending_transporter_signature';
    case PendingReceiverSignature = 'pending_receiver_signature';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
