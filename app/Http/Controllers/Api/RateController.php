<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RateController extends Controller
{
    public function index(Request $request)
    {
        // Los recepcionistas solo ven las tarifas activas; el admin puede pedir todas.
        $onlyActive = $request->user()->isAdmin() ? $request->boolean('only_active', true) : true;

        $rates = Rate::when($onlyActive, fn ($q) => $q->where('active', true))
            ->when($request->filled('room_type'), fn ($q) => $q->where('room_type', $request->query('room_type')))
            ->when($request->filled('beds'), fn ($q) => $q->where('beds', $request->integer('beds')))
            ->orderBy('hours')
            ->get();

        return response()->json($rates);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'hours' => ['required', 'integer', 'min:1', 'max:168'],
            'price' => ['required', 'numeric', 'min:0'],
            'extra_hour_price' => ['nullable', 'numeric', 'min:0'],
            'room_type' => ['required', Rule::in(['estandar', 'especial'])],
            'beds' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        if ($data['room_type'] === 'estandar' && (int) $data['beds'] !== 1) {
            return response()->json(['message' => 'Las habitaciones comunes tienen una cama.'], 422);
        }

        $data['extra_hour_price'] = $data['extra_hour_price'] ?? 0;

        $rate = Rate::create($data + ['active' => true]);

        return response()->json($rate, 201);
    }

    public function update(Request $request, Rate $rate)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'extra_hour_price' => ['sometimes', 'numeric', 'min:0'],
            'room_type' => ['sometimes', 'required', Rule::in(['estandar', 'especial'])],
            'beds' => ['sometimes', 'required', 'integer', 'min:1', 'max:4'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $roomType = $data['room_type'] ?? $rate->room_type;
        $beds = $data['beds'] ?? $rate->beds;
        $active = $data['active'] ?? $rate->active;
        if ($active && ($roomType === null || $beds === null)) {
            return response()->json(['message' => 'Una tarifa activa debe tener tipo de habitación y cantidad de camas definidos.'], 422);
        }
        if ($roomType === 'estandar' && (int) $beds !== 1) {
            return response()->json(['message' => 'Las habitaciones comunes tienen una cama.'], 422);
        }

        $rate->update($data);

        return response()->json($rate);
    }
}
