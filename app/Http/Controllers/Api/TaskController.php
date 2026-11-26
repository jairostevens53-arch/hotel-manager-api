<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Task::with('room:id,number,floor,status', 'requester:id,name', 'assignee:id,name,role')->latest();

        if (in_array($user->role, ['limpieza', 'mantenimiento'], true)) {
            $query->where('type', $user->role)->where(function ($tasks) use ($user) {
                $tasks->where('assigned_to', $user->id)
                    ->orWhere(fn ($unassigned) => $unassigned
                        ->whereNull('assigned_to')
                        ->where('status', 'pendiente'));
            });
        } elseif ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->query('assigned_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('room_id')) {
            $query->where('room_id', $request->query('room_id'));
        }
        if ($request->filled('type') && in_array($user->role, ['admin', 'recepcionista'], true)) {
            $query->where('type', $request->query('type'));
        }

        return response()->json($query->paginate(max(1, min((int) $request->query('per_page', 20), 100))));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'type' => ['required', Rule::in(['limpieza', 'mantenimiento'])],
            'priority' => ['nullable', Rule::in(['baja', 'normal', 'alta', 'urgente'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($data['assigned_to']) && ! $this->assigneeMatchesType($data['assigned_to'], $data['type'])) {
            return response()->json(['message' => 'El usuario asignado no tiene el rol requerido para esta tarea.'], 422);
        }

        $task = DB::transaction(function () use ($data, $request) {
            $room = Room::whereKey($data['room_id'])->lockForUpdate()->firstOrFail();

            $roomCanReceiveTask = $data['type'] === 'limpieza'
                ? in_array($room->status, ['disponible', 'limpieza', 'ocupada'], true)
                : in_array($room->status, ['disponible', 'mantenimiento'], true);

            if (! $room->active || ! $roomCanReceiveTask) {
                return null;
            }

            if (Task::where('room_id', $room->id)->whereIn('status', ['pendiente', 'asignada', 'en_progreso'])->exists()) {
                return null;
            }

            $task = Task::create([
                ...$data,
                'requested_by' => $request->user()->id,
                'status' => empty($data['assigned_to']) ? 'pendiente' : 'asignada',
                'priority' => $data['priority'] ?? 'normal',
                'room_status_before' => $room->status,
            ]);

            // Una limpieza durante una estadía no debe liberar ni cambiar la
            // habitación ocupada; la tarea queda ligada a la habitación.
            if ($room->status !== 'ocupada') {
                $room->update(['status' => $data['type']]);
            }

            return $task;
        });

        if (! $task) {
            return response()->json(['message' => 'La habitación está ocupada, inactiva o ya tiene una tarea abierta.'], 409);
        }

        return response()->json($task->load('room', 'requester:id,name', 'assignee:id,name,role'), 201);
    }

    public function assign(Request $request, Task $task)
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ]);

        if (! $this->assigneeMatchesType($data['assigned_to'], $task->type)) {
            return response()->json(['message' => 'El usuario asignado no tiene el rol requerido para esta tarea.'], 422);
        }

        return DB::transaction(function () use ($data, $task) {
            $lockedTask = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedTask->status, ['pendiente', 'asignada'], true)) {
                return response()->json(['message' => 'No puedes reasignar una tarea que ya está en progreso o finalizada.'], 409);
            }

            $lockedTask->update(['assigned_to' => $data['assigned_to'], 'status' => 'asignada']);

            return response()->json($lockedTask->fresh()->load('room', 'assignee:id,name,role'));
        });
    }

    public function updateStatus(Request $request, Task $task)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['en_progreso', 'completada', 'cancelada'])],
            'result' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $isManager = in_array($user->role, ['admin', 'recepcionista'], true);

        DB::transaction(function () use ($data, $task, $user, $isManager) {
            $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $room = Room::whereKey($task->room_id)->lockForUpdate()->firstOrFail();
            $status = $data['status'];
            $claimUnassigned = ! $isManager
                && $task->assigned_to === null
                && $task->status === 'pendiente'
                && $status === 'en_progreso'
                && $task->type === $user->role;

            if (! $isManager && ! $claimUnassigned
                && ((int) $task->assigned_to !== $user->id || $task->type !== $user->role)) {
                abort(403, 'Solo puedes aceptar una tarea sin asignar de tu tipo o actualizar una asignada a ti.');
            }
            $allowed = match ($task->status) {
                'pendiente', 'asignada' => ['en_progreso', 'cancelada'],
                'en_progreso' => ['completada', 'cancelada'],
                default => [],
            };

            if (! in_array($status, $allowed, true)) {
                abort(409, 'La tarea ya cambió de estado; actualiza la lista e inténtalo de nuevo.');
            }

            $task->update([
                'status' => $status,
                'assigned_to' => $claimUnassigned ? $user->id : $task->assigned_to,
                'result' => $data['result'] ?? $task->result,
                'started_at' => $status === 'en_progreso' ? ($task->started_at ?? now()) : $task->started_at,
                'completed_at' => in_array($status, ['completada', 'cancelada'], true) ? now() : null,
            ]);

            if ($status === 'completada' && $room->status === $task->type) {
                $room->update(['status' => 'disponible']);
            } elseif ($status === 'cancelada' && $room->status === $task->type) {
                $room->update(['status' => $task->room_status_before ?? 'disponible']);
            }
        });

        return response()->json($task->fresh()->load('room', 'requester:id,name', 'assignee:id,name,role'));
    }

    private function assigneeMatchesType(int $userId, string $type): bool
    {
        return User::whereKey($userId)->where('role', $type)->where('active', true)->exists();
    }
}
