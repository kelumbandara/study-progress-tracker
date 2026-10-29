<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\StudentProfile;
use App\Models\StudySetting;
use Carbon\CarbonImmutable;

/** The one student and the study settings, and "today" in the SERVER's configured time zone (not the phone's clock). */
trait ResolvesStudent
{
    protected function student(): StudentProfile
    {
        return StudentProfile::current();
    }

    protected function settings(): StudySetting
    {
        return StudySetting::query()->where('student_id', $this->student()->id)->firstOrFail();
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->settings()->timezone)->startOfDay();
    }
}
