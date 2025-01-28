<?php
namespace App\Http\Controllers;

use App\ProductIngredientOrder;
use App\Product;
use App\Ingredient;
use Illuminate\Http\Request;

class ProductIngredientOrderController extends Controller
{
    public function index()
    {
        $orders = ProductIngredientOrder::with(['product', 'ingredient'])->get();
        return view('pages.product_ingredient_orders.index', compact('orders'));
    }


    public function create()
    {
        $products = Product::all();
        $ingredients = Ingredient::all();
        return view('pages.product_ingredient_orders.create', compact('products', 'ingredients'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'ingredient_id' => 'required|exists:ingredients,id',
            'is_default' => 'boolean',
            'is_optional' => 'boolean',
            'cost' => 'nullable|numeric',
        ]);

        ProductIngredientOrder::create($validated);

        return redirect()->route('product_ingredient_orders.index')->with('success', 'Product Ingredient Order created successfully');
    }

    public function show(ProductIngredientOrder $productIngredientOrder)
    {
        return view('pages.product_ingredient_orders.show', compact('productIngredientOrder'));
    }

    public function edit(ProductIngredientOrder $productIngredientOrder)
    {
        $products = Product::all();
        $ingredients = Ingredient::all();
        return view('pages.product_ingredient_orders.edit', compact('productIngredientOrder', 'products', 'ingredients'));
    }

    public function update(Request $request, ProductIngredientOrder $productIngredientOrder)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'ingredient_id' => 'required|exists:ingredients,id',
            'is_default' => 'boolean',
            'is_optional' => 'boolean',
            'cost' => 'nullable|numeric',
        ]);

        $productIngredientOrder->update($validated);

        return redirect()->route('product_ingredient_orders.index')->with('success', 'Product Ingredient Order updated successfully');
    }

    public function destroy(ProductIngredientOrder $productIngredientOrder)
    {
        $productIngredientOrder->delete();
        return redirect()->route('product_ingredient_orders.index')->with('success', 'Product Ingredient Order deleted successfully');
    }
}
