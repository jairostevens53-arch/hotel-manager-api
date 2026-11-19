<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    public function index(Request $request)
    {
        $query = Guest::query()->latest('id');

        if ($request->filled('q')) {
            $query->search((string) $request->query('q'));
        }

        return response()->json($query->paginate(max(1, min((int) $request->query('per_page', 20), 100))));
    }

    public function show(Guest $guest)
    {
        return response()->json($guest->loadCount(['reservations', 'stays']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'document' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(Guest::create($data), 201);
    }

    public function update(Request $request, Guest $guest)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'document' => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $guest->update($data);

        return response()->json($guest->fresh());
    }

    public function destroy(Guest $guest)
    {
        if ($guest->reservations()->exists() || $guest->stays()->exists()) {
            return response()->json(['message' => 'No puedes archivar un huésped con historial de reservas o estadías.'], 409);
        }

        $guest->delete();

        return response()->json(['message' => 'Huésped archivado.']);
    }
}
