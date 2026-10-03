<?php

namespace App\Support;

use App\Models\TimeEntry;

/** Formato único de lançamento na API (contracts/openapi.yaml → Entry). */
final class TimeEntryPresenter
{
    public static function present(TimeEntry $entry): array
    {
        return [
            'id' => (string) $entry->id,
            'workItemId' => $entry->devops_work_item_id,
            'localDate' => $entry->local_date,
            'timezone' => $entry->timezone,
            'durationSeconds' => $entry->duration_seconds,
            'startTime' => $entry->localStartTime(),
            'endTime' => $entry->localEndTime(),
            'source' => $entry->source,
            // Lançamentos antigos de projeto que não usa faturável ficaram marcados: não valem.
            'billable' => $entry->billable && (bool) $entry->project?->uses_billable,
            'activityTypeId' => $entry->activity_type_id !== null ? (string) $entry->activity_type_id : null,
            'note' => $entry->note,
            'revision' => $entry->revision,
        ];
    }
}
