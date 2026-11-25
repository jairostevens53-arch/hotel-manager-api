<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $rooms = Room::with([
            'currentStay.rate:id,name,hours',
            'currentStay.guest:id,name',
            'tasks' => fn ($query) => $query->whereIn('status', ['pendiente', 'asignada', 'en_progreso'])
                ->select('id', 'room_id', 'type', 'status', 'assigned_to', 'description'),
        ])
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('active', true))
            ->when($request->filled('floor'), fn ($q) => $q->where('floor', $request->floor))
            ->orderBy('floor')
            ->orderBy('number')
            ->get();

        $summary = [
            'total' => $rooms->count(),
            'disponible' => $rooms->where('status', 'disponible')->count(),
            'ocupada_estandar' => $rooms->where('status', 'ocupada')->where('type', 'estandar')->count(),
            'ocupada_especial' => $rooms->where('status', 'ocupada')->where('type', 'especial')->count(),
            'limpieza' => $rooms->where('status', 'limpieza')->count(),
            'mantenimiento' => $rooms->where('status', 'mantenimiento')->count(),
        ];

        return response()->json(['summary' => $summary, 'rooms' => $rooms]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:10', 'unique:rooms,number'],
            'floor' => ['required', 'integer', 'min:0', 'max:50'],
            'type' => ['required', Rule::in(['estandar', 'especial'])],
            'beds' => ['required', 'integer', 'min:1', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $room = Room::create($data + ['status' => 'disponible', 'active' => true]);

        return response()->json($room, 201);
    }

    public function update(Request $request, Room $room)
    {
        $data = $request->validate([
            'number' => ['sometimes', 'string', 'max:10', Rule::unique('rooms', 'number')->ignore($room->id)],
            'floor' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'type' => ['sometimes', Rule::in(['estandar', 'especial'])],
            'beds' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($room->status === 'ocupada' && (($data['active'] ?? true) === false)) {
            return response()->json(['message' => 'No puedes sacar de servicio una habitación ocupada.'], 409);
        }

        $room->update($data);

        return response()->json($room);
    }

    // "Marcar lista" (limpieza -> disponible) y cambios a mantenimiento.
    public function updateStatus(Request $request, Room $room)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['disponible', 'limpieza', 'mantenimiento'])],
        ]);

        if ($room->status === 'ocupada') {
            return response()->json(['message' => 'La habitación está ocupada: finaliza la estadía primero.'], 409);
        }

        $role = $request->user()->role;

        if ($role === 'limpieza' && ! ($room->status === 'limpieza' && $data['status'] === 'disponible')) {
            return response()->json(['message' => 'Limpieza solo puede marcar disponible una habitación en limpieza.'], 403);
        }

        if ($role === 'mantenimiento' && ! ($room->status === 'mantenimiento' && $data['status'] === 'disponible')) {
            return response()->json(['message' => 'Mantenimiento solo puede liberar una habitación en mantenimiento.'], 403);
        }

        $room->update(['status' => $data['status']]);

        return response()->json($room);
    }
}
