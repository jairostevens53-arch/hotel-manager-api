<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('category:id,name')
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('active', true))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->q . '%'))
            ->orderBy('name')
            ->get();

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['min_stock'] = $data['min_stock'] ?? 0;

        $product = Product::create($data);

        return response()->json($product->load('category:id,name'), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load('category:id,name'));
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'category_id' => ['sometimes', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:150'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'min_stock' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $product->update($data);

        return response()->json($product->load('category:id,name'));
    }

    public function destroy(Product $product)
    {
        // No se borra: más adelante las ventas referenciarán al producto.
        $product->update(['active' => false]);

        return response()->json(['message' => 'Producto desactivado.']);
    }
}
