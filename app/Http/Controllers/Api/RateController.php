<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RateController extends Controller
{
    public function index(Request $request)
    {
        $onlyActive = $request->user()->isAdmin()
            ? $request->boolean('only_active', true)
            : true;
        $filters = $request->validate([
            'room_type' => ['sometimes', 'required', Rule::in(['estandar', 'especial'])],
            'beds' => ['sometimes', 'required', 'integer', 'min:1', 'max:4'],
        ]);

        if (($filters['room_type'] ?? null) === 'estandar'
            && isset($filters['beds'])
            && (int) $filters['beds'] !== 1) {
            throw ValidationException::withMessages([
                'beds' => ['Las tarifas estándar deben tener exactamente 1 cama.'],
            ]);
        }

        $rates = Rate::query()
            ->when($onlyActive, fn ($query) => $query->where('active', true))
            ->when(isset($filters['room_type']), fn ($query) => $query->where('room_type', $filters['room_type']))
            ->when(isset($filters['beds']), fn ($query) => $query->where('beds', (int) $filters['beds']))
            ->orderBy('hours')
            ->orderBy('room_type')
            ->orderBy('beds')
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
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($data['room_type'] === 'estandar' && (int) $data['beds'] !== 1) {
            throw ValidationException::withMessages([
                'beds' => ['Las tarifas estándar deben tener exactamente 1 cama.'],
            ]);
        }

        $data['extra_hour_price'] = $data['extra_hour_price'] ?? 0;
        $data['active'] = $data['active'] ?? true;
        $rate = Rate::create($data);

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

        $nextType = $data['room_type'] ?? $rate->room_type;
        $nextBeds = $data['beds'] ?? $rate->beds;
        $nextActive = $data['active'] ?? $rate->active;
        if ($nextActive && (! in_array($nextType, ['estandar', 'especial'], true)
            || $nextBeds === null || $nextBeds < 1 || $nextBeds > 4)) {
            throw ValidationException::withMessages([
                'room_type' => ['Una tarifa activa debe tener tipo de habitación y entre 1 y 4 camas definidos.'],
            ]);
        }
        if ($nextType === 'estandar' && $nextBeds !== null && (int) $nextBeds !== 1) {
            throw ValidationException::withMessages([
                'beds' => ['Las tarifas estándar deben tener exactamente 1 cama.'],
            ]);
        }

        $rate->update($data);

        return response()->json($rate);
    }
}
