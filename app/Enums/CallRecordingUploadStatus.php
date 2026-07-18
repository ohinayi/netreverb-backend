<?php

namespace App\Enums;

enum CallRecordingUploadStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Partial = 'partial';
}
