<?php

namespace App\Domain\Federation\Enums;

enum PublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case PartiallyRevoked = 'partially_revoked';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
