<?php

namespace App\Http\Controllers;

use App\Ingredient;
use App\IngredientStock;
use App\IngredientStockDetails;
use App\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngredientStockController extends Controller
{
    public function index()
    {
        // Use nested eager loading to load ingredients through IngredientStockDetails
        $stocks = IngredientStock::with(['IngredientStockDetails.ingredient', 'supplier'])->get();

        return view('pages.ingredient_stocks.index', compact('stocks'));
    }


    // Show form to create a new ingredient stock
    public function create()
    {
        $ingredients = Ingredient::all();
        $suppliers = Supplier::all();

        return view('pages.ingredient_stocks.create', compact('ingredients', 'suppliers'));
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice_date' => 'required|date',
            'order_number' => 'required|string',
            'supplier_invoice_number' => 'required|string',
            'total_price' => 'required|numeric',
            'ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'ingredients.*.bulk_unit' => 'required|string',
            'ingredients.*.bulk_quantity' => 'required|numeric',
            'ingredients.*.total_usage_quantity' => 'required|numeric',
            'ingredients.*.factor' => 'required|numeric',
            'ingredients.*.usage_unit' => 'required|string',
            'ingredients.*.expiry_date' => 'required|date',
            'ingredients.*.price_per_bulk_unit' => 'required|numeric',
        ]);
         $organizationId = org_id();


        DB::beginTransaction();
        try {
             // Create the stock entry
            $ingredientStock = IngredientStock::create([
                 'supplier_id' => $request->supplier_id,
                'supplier_invoice_date' => $request->supplier_invoice_date,
                'order_number' => $request->order_number,
                'supplier_invoice_number' => $request->supplier_invoice_number,
                'total_price' => $request->total_price,
                'organization_id'=>$organizationId,
             ]);

            // Save each ingredient detail in order_detail_ingredients
            foreach ($request->ingredients as $ingredientData) {
                IngredientStockDetails::create([
                    'order_detail_id' => $ingredientStock->id,
                    'ingredient_id' => $ingredientData['ingredient_id'],
                    'price' => $ingredientData['price_per_bulk_unit'],
                    'quantity' => $ingredientData['bulk_quantity'],
                    'quantity_usage' => $ingredientData['total_usage_quantity'],
                    'factor' => $ingredientData['factor'],
                    'unit_buy' => $ingredientData['bulk_unit'],
                    'unit_use' => $ingredientData['usage_unit'],
                    'expiry_date' => $ingredientData['expiry_date'],
                    'organization_id'=>$organizationId
                ]);
            }


            DB::commit();
            return redirect()->route('ingredient_stocks.index')->with('success', 'Ingredient Stock created successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to create ingredient stock: ' . $e->getMessage());
        }
    }


    // Edit existing ingredient stock record
    public function edit($id)
    {
        $ingredientStock = IngredientStock::findOrFail($id);
        $ingredients = Ingredient::all();
        $suppliers = Supplier::all();
        return view('pages.ingredient_stocks.edit', compact('ingredientStock', 'ingredients', 'suppliers'));
    }
    public function update(Request $request, IngredientStock $ingredientStock)
    {
        $validatedData = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice_date' => 'required|date',
            'supplier_invoice_number' => 'required|string',
            'order_number' => 'required|string',
            'total_price' => 'required|numeric',
            'ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'ingredients.*.expiry_date' => 'required|date',
            'ingredients.*.bulk_unit' => 'required|string',
            'ingredients.*.total_usage_quantity' => 'required|numeric',
            'ingredients.*.bulk_quantity' => 'required|numeric',
            'ingredients.*.factor' => 'required|numeric',
            'ingredients.*.usage_unit' => 'required|string',
            'ingredients.*.price' => 'required|numeric',
        ]);

        DB::transaction(function () use ($ingredientStock, $request) {
            // Update the main stock record
            $ingredientStock->update([
                'supplier_id' => $request->supplier_id,
                'supplier_invoice_date' => $request->supplier_invoice_date,
                'supplier_invoice_number' => $request->supplier_invoice_number,
                'order_number' => $request->order_number,
                'total_price' => $request->total_price,
            ]);

            foreach ($request->ingredients as $ingredientData) {
                IngredientStockDetails::where([
                    'order_detail_id' => $ingredientStock->id,
                    'ingredient_id' => $ingredientData['ingredient_id'],
                ])->update([
                    'quantity_usage' => $ingredientData['total_usage_quantity'],
                    'price' => $ingredientData['price'],
                    'quantity' => $ingredientData['bulk_quantity'],
                    'factor' => $ingredientData['factor'],
                    'unit_buy' => $ingredientData['bulk_unit'],
                    'unit_use' => $ingredientData['usage_unit'],
                    'expiry_date' => $ingredientData['expiry_date'],
                ]);


//                if ($ingredientStockDetail) {
//                    // Update only the specific row
//                    $ingredientStockDetail->update([
//                        'quantity_usage' => $ingredientData['total_usage_quantity'],
//                        'price' => $ingredientData['price'],
//                        'quantity' => $ingredientData['bulk_quantity'],
//                        'factor' => $ingredientData['factor'],
//                        'unit_buy' => $ingredientData['bulk_unit'],
//                        'unit_use' => $ingredientData['usage_unit'],
//                        'expiry_date' => $ingredientData['expiry_date'],
//                    ]);
//                } else {
//                    // Create a new row if it doesn't exist
//                    IngredientStockDetails::create([
//                        'order_detail_id' => $ingredientStock->id,
//                        'ingredient_id' => $ingredientData['ingredient_id'],
//                        'quantity_usage' => $ingredientData['total_usage_quantity'],
//                        'price' => $ingredientData['price'],
//                        'quantity' => $ingredientData['bulk_quantity'],
//                        'factor' => $ingredientData['factor'],
//                        'unit_buy' => $ingredientData['bulk_unit'],
//                        'unit_use' => $ingredientData['usage_unit'],
//                        'expiry_date' => $ingredientData['expiry_date'],
//                    ]);
//                }
            }
        });

        return redirect()->route('ingredient_stocks.index')->with('success', 'Ingredient Stock updated successfully!');
    }




    // Delete ingredient stock
    public function destroy($id)
    {
        $ingredientStock = IngredientStock::findOrFail($id);
        $ingredientStock->delete();
        return redirect()->route('ingredient_stocks.index')->with('success', 'Ingredient Stock deleted successfully.');
    }

    // Show details of a single ingredient stock
    public function show($id)
    {
        $ingredientStock = IngredientStock::with(['supplier', 'IngredientStockDetails.ingredient'])->findOrFail($id);
        return view('pages.ingredient_stocks.show', compact('ingredientStock'));
    }
}
