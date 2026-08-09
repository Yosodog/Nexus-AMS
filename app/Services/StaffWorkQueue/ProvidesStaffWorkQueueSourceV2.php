<?php

namespace App\Services\StaffWorkQueue;

trait ProvidesStaffWorkQueueSourceV2
{
    public function descriptor(): StaffWorkQueueSourceDescriptor
    {
        return StaffWorkQueueSourceDescriptor::fromConfig(
            $this->type(),
            $this->label(),
            $this->ability(),
        );
    }

    public function loadResult(): StaffWorkQueueSourceResult
    {
        return StaffWorkQueueSourceResult::complete($this->load());
    }
}
