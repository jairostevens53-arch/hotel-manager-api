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
            'room_type' => ['nullable', Rule::in(['estandar', 'especial'])],
        ]);

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
            'room_type' => ['nullable', Rule::in(['estandar', 'especial'])],
            'active' => ['sometimes', 'boolean'],
        ]);

        $rate->update($data);

        return response()->json($rate);
    }
}
