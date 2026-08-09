<?php

namespace App\Services\StaffWorkQueue;

interface StaffWorkQueueSourceV2 extends StaffWorkQueueSource
{
    public function descriptor(): StaffWorkQueueSourceDescriptor;

    public function loadResult(): StaffWorkQueueSourceResult;
}
