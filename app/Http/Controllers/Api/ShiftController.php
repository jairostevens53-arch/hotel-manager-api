<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function open(Request $request)
    {
        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($data, $user) {
            $alreadyOpen = Shift::where('user_id', $user->id)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                return response()->json(['message' => 'Ya tienes un turno abierto.'], 409);
            }

            $shift = Shift::create([
                'user_id' => $user->id,
                'opened_at' => now(),
                'opening_amount' => $data['opening_amount'],
            ]);

            return response()->json($shift, 201);
        });
    }

    public function current(Request $request)
    {
        $shift = Shift::where('user_id', $request->user()->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            return response()->json(['message' => 'No tienes un turno abierto.'], 404);
        }

        return response()->json($shift);
    }

    public function close(Request $request)
    {
        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $shift = Shift::where('user_id', $request->user()->id)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                return response()->json(['message' => 'No tienes un turno abierto.'], 404);
            }

            $summary = $shift->summary();
            $counted = round((float) $data['counted_amount'], 2);

            $shift->update([
                'closed_at' => now(),
                'expected_amount' => $summary['expected_amount'],
                'counted_amount' => $counted,
                'difference' => round($counted - $summary['expected_amount'], 2),
                'notes' => $data['notes'] ?? null,
            ]);

            return response()->json([
                'shift' => $shift->fresh(),
                'summary' => $summary,
            ]);
        });
    }

    public function index(Request $request)
    {
        $query = Shift::with('user:id,name')->latest('opened_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->boolean('only_closed')) {
            $query->whereNotNull('closed_at');
        }

        if ($request->boolean('with_difference')) {
            $query->where('difference', '!=', 0);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Shift $shift)
    {
        return response()->json([
            'shift' => $shift->load('user:id,name'),
            'summary' => $shift->summary(),
        ]);
    }
}
