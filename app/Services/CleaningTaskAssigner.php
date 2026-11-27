<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class CleaningTaskAssigner
{
    /** Choose an idle cleaner with the fewest lifetime assignments. */
    public function next(): ?User
    {
        $cleaners = User::where('role', 'limpieza')
            ->where('active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($cleaners->isEmpty()) {
            return null;
        }

        $assignedCounts = Task::where('type', 'limpieza')
            ->whereIn('assigned_to', $cleaners->pluck('id'))
            ->selectRaw('assigned_to, COUNT(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $busyIds = Task::where('type', 'limpieza')
            ->whereIn('assigned_to', $cleaners->pluck('id'))
            ->whereIn('status', ['pendiente', 'asignada', 'en_progreso'])
            ->distinct()
            ->pluck('assigned_to');

        return $cleaners
            ->reject(fn (User $cleaner) => $busyIds->contains($cleaner->id))
            ->sortBy(fn (User $cleaner) => [
                (int) ($assignedCounts[$cleaner->id] ?? 0),
                $cleaner->id,
            ])
            ->first();
    }

    /** Assign queued check-out cleanings whenever a cleaner becomes available. */
    public function dispatchWaiting(): void
    {
        while ($cleaner = $this->next()) {
            $task = Task::where('type', 'limpieza')
                ->where('status', 'pendiente')
                ->whereNull('assigned_to')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return;
            }

            $task->update(['assigned_to' => $cleaner->id, 'status' => 'asignada']);
        }
    }
}
