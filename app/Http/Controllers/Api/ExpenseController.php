<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Shift;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::with('user:id,name')->latest();

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->shift_id);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $shift = Shift::where('user_id', $request->user()->id)->whereNull('closed_at')->first();

        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de registrar gastos.'], 409);
        }

        $expense = Expense::create([
            'shift_id' => $shift->id,
            'user_id' => $request->user()->id,
            'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2),
        ]);

        return response()->json($expense, 201);
    }
}
