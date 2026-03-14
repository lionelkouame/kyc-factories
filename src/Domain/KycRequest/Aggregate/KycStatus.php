<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Aggregate;

enum KycStatus: string
{
    case Submitted = 'submitted';
    case DocumentUploaded = 'document_uploaded';
    case DocumentRejected = 'document_rejected';
    case OcrCompleted = 'ocr_completed';
    case OcrFailed = 'ocr_failed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case UnderManualReview = 'under_manual_review';
}
