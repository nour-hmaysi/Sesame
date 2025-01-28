<?php

namespace App\Http\Controllers;

use App\Product;
use App\Ingredient;
use App\IngredientStock;
use App\DamageProduct;
use App\DamageIngredient;
use App\IngredientStockDetails;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // Import the DB facade


class DamageController extends Controller
{

    public function index(){
    // Load ingredient damages with the necessary relationships
    $ingredientDamages = DamageIngredient::with([
        'ingredient',
        'stock.IngredientStockDetails', // Load IngredientStockDetails through stock
        'user'
    ])

        ->latest()
        ->get();

    // Fetch product damages filtered by organization_id
    $productDamages = DamageProduct::with('product', 'user')
        ->where('organization_id', org_id())
        ->latest()
        ->get();
     return view('pages.damage.index', compact('ingredientDamages', 'productDamages'));
}

    public function indexIngredients()
    {
        $ingredientDamages = DamageIngredient::with('ingredient', 'stock', 'user')

            ->latest()
            ->get();

        return view('pages.damage.index_ingredients', compact('ingredientDamages'));
    }
    public function indexProducts()
    {
        $productDamages = DamageProduct::with('product', 'user')
            ->where('organization_id', org_id())
            ->latest()
            ->get();

        return view('pages.damage.index_products', compact('productDamages'));
    }

    public function createDamageIngredient()
    {
        $stocks = IngredientStock::all();

        return view('pages.damage.create_ingredient', compact('stocks'));
    }
    public function storeDamageIngredient(Request $request)
    {
        $validated = $request->validate([
            'stock_id' => 'required|exists:ingredient_stocks,id',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string',
        ]);

        // Fetch the stock and its related IngredientStockDetails
        $stock = IngredientStock::with('IngredientStockDetails')->find($request->stock_id);

        if (!$stock) {
            return redirect()->back()->with('error', 'Stock not found.');
        }

        // Get the first IngredientStockDetails record
        $stockDetail = $stock->IngredientStockDetails->first();

        if (!$stockDetail) {
            return redirect()->back()->with('error', 'No stock details available for the selected stock.');
        }

        $quantityUsage = $stockDetail->quantity_usage;

        // Check stock availability
        if ($request->quantity > $quantityUsage) {
            return redirect()->back()->with('error', 'Insufficient stock available for this operation.');
        }

        try {
            DB::beginTransaction();

            // Deduct quantity from IngredientStockDetails
            $stockDetail->quantity_usage -= $request->quantity;
            $stockDetail->save();

            // Record the damage
            DamageIngredient::create([
                'ingredient_id' => $stockDetail->ingredient_id,
                'stock_id' => $stock->id,
                'quantity' => $request->quantity,
                'reason' => $request->reason,
                'user_id' => Auth::id(),
                'organization_id' => org_id(),
            ]);

            DB::commit();

            return redirect()->route('damage.index')->with('success', 'Ingredient damage recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'An error occurred: ' . $e->getMessage());
        }
    }


    private function checkAndDeductStock($ingredientId, $requiredQuantity)
    {
        DB::beginTransaction();
        try {
            $ingredientStocks = IngredientStock::where('ingredient_id', $ingredientId)
//                ->where('organization_id', org_id())
                ->where('unit_use', '>', 0)
                ->orderBy('created_at', 'asc')
                ->get();

            $remainingQuantity = $requiredQuantity;

            foreach ($ingredientStocks as $stock) {
                if ($stock->unit_use >= $remainingQuantity) {
                    $stock->unit_use -= $remainingQuantity;
                    $stock->save();
                    DB::commit();
                    return true;
                } else {
                    $remainingQuantity -= $stock->unit_use;
                    $stock->unit_use = 0;
                    $stock->save();
                }
            }

            DB::rollBack();
            return false; // Insufficient stock
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function editDamageIngredient($id)
    {
        $damageIngredient = DamageIngredient::findOrFail($id);
        $ingredients = Ingredient::all();
        $stocks = IngredientStock::all();
        return view('pages.damage.edit_ingredient', compact('damageIngredient', 'ingredients', 'stocks'));
    }

    public function createDamageProduct()
    {
        $products = Product::all();
        return view('pages.damage.create_product', compact('products'));
    }

    public function storeDamageProduct(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string',
        ]);

        // Fetch the product with its ingredients
        $product = Product::with(['productIngredients' => function ($query) {
            $query->with(['ingredientStockDetails' => function ($query) {
                $query->join('ingredient_stocks as stocks', 'ingredient_stock_details.order_detail_id', '=', 'stocks.id')
                    ->orderBy('stocks.expiry_date', 'asc'); // FIFO: Oldest stocks first
            }]);
        }])->findOrFail($validated['product_id']);




        if (!$product) {
            return redirect()->back()->with('error', 'Product not found.');
        }

        try {
            DB::beginTransaction();

            foreach ($product->productIngredients as $ingredient) {
                // Calculate the required quantity for this ingredient
                $requiredQuantity = $ingredient->unit * $validated['quantity'];

                // Fetch the related stock details in FIFO order
                $stockDetails = $ingredient->ingredientStockDetails;

                $remainingQuantity = $requiredQuantity;

                foreach ($stockDetails as $stockDetail) {
                    if ($stockDetail->quantity_usage >= $remainingQuantity) {
                        // Deduct the remaining quantity and exit the loop
                        $stockDetail->quantity_usage -= $remainingQuantity;
                        $stockDetail->save();
                        $remainingQuantity = 0;
                        break; // Exit the loop
                    } else {
                        // Deduct the available quantity and continue
                        $remainingQuantity -= $stockDetail->quantity_usage;
                        $stockDetail->quantity_usage = 0;
                        $stockDetail->save();
                    }
                }

                // If there's still a required quantity after checking all stocks, throw an error
                if ($remainingQuantity > 0) {
                    DB::rollBack();
                    return redirect()->back()->with(
                        'error',
                        "Insufficient stock for ingredient: {$ingredient->name}."
                    );
                }
            }

            // Record the product damage
            DamageProduct::create([
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'reason' => $request->reason,
                'user_id' => Auth::id(),
//                'organization_id' => org_id(),
            ]);

            DB::commit();

            return redirect()->route('damage.products.index')->with('success', 'Product damage recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'An error occurred: ' . $e->getMessage());
        }
    }



    public function destroyDamageIngredient($id)

    {
        try {
            $damageIngredient = DamageIngredient::findOrFail($id);
            $damageIngredient->delete();

            return redirect()->route('damage.index')->with('success', 'Ingredient damage record deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('damage.index')->with('error', 'An error occurred: ' . $e->getMessage());
        }
    }
    public function destroyDamageProduct($id)
    {
        try {
            $damageProduct = DamageProduct::findOrFail($id);
            $damageProduct->delete();

            return redirect()->route('damage.products.index')->with('success', 'Product damage record deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('damage.products.index')->with('error', 'An error occurred: ' . $e->getMessage());
        }
    }



}
