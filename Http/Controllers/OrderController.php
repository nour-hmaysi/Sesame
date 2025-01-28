<?php

namespace App\Http\Controllers;

use App\DeliveryAgency;
use App\IngredientStockDetails;
use App\Order;
use App\OrderCustomNote;
use App\OrderDetail;
use App\OrderDetailCustomization;
use App\OrderDetailIngredient;
use App\Product;
use App\Ingredient;
use App\IngredientStock;
use App\ProductIngredient;
use App\ProductOptionalIngredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{

    public function index()
    {
        // Fetch orders in descending order of creation date
        $orders = Order::with('orderDetails.product')
            ->orderBy('created_at', 'desc')
            ->get();


        return view('pages.cashier.index', compact('orders'));
    }

    public function create()
    {
        $products = Product::all();
        return view('pages.orders.create', compact('products'));
    }



    public function saveCustomOrder(Request $request)
    {
        $validatedData = $request->validate([
            'product_id' => 'required|exists:products,id',
            'optional_ingredients' => 'nullable|array',
            'optional_ingredients.*.id' => 'exists:ingredients,id',
            'optional_ingredients.*.quantity' => 'required|integer|min:1',
            'offers' => 'nullable|array',
            'offers.*.id' => 'exists:products,id',
            'offers.*.quantity' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($validatedData) {
            $orderDetail = OrderDetail::create([
                'product_id' => $validatedData['product_id'],
                'quantity' => 1,
                'price' => Product::find($validatedData['product_id'])->price,
            ]);

            if (!empty($detail['optional_ingredients'])) {
                foreach ($detail['optional_ingredients'] as $ingredient) {
                    $totalIngredientPrice = Ingredient::find($ingredient['id'])->cost_of_one_unit * $ingredient['quantity'];

                    OrderDetailCustomization::create([
                        'order_detail_id' => $orderDetail->id,
                        'ingredient_id' => $ingredient['id'],
                        'quantity' => $ingredient['quantity'], // Ensure this is saved
                        'price' => $totalIngredientPrice,
                        'type' => 'add',
                    ]);
                }
            }


// Example for offers
            if (!empty($detail['offers'])) {
                foreach ($detail['offers'] as $offer) {
                    $offerPrice = Product::find($offer['id'])->price * $offer['quantity'];

                    OrderDetailCustomization::create([
                        'order_detail_id' => $orderDetail->id,
                        'product_id' => $offer['id'],
                        'quantity' => $offer['quantity'], // Ensure this is saved
                        'price' => $offerPrice,
                        'type' => 'offer',
                    ]);
                }
            }


        });

        return response()->json(['success' => true, 'message' => 'Custom order saved.']);
    }
    public function show($id)
    {
        // Load the order with all related data
        $order = Order::with([
            'orderDetails.product',
            'orderDetails.customizations.product',
            'orderDetails.customizations.ingredient',
            'orderDetails.ingredients',
            'orderDetails.notes',
        ])->findOrFail($id);

        // Debugging: Inspect the data structure
        Log::info('Order Details:', $order->toArray());

        return view('pages.cashier.view_order', compact('order'));
    }


    public function cashier()
    {
        $products = Product::with([
            'productIngredients.ingredient',
            'optionalIngredients.ingredient',
            'productDiscount.discountedProduct'
        ])->get();

        $categories = Category::all();
        $deliveryAgencies = DeliveryAgency::all();

        // Calculate or fetch the tax value
        $taxValue =TaxValue();


        return view('pages.cashier.index', compact('products', 'categories', 'deliveryAgencies','taxValue'));
    }
    public function confirmOrder(Request $request)
    {
        try {
            // Decode order details from the request
            $orderDetails = json_decode($request->input('order_details'), true);

            // Validate the orderDetails and its structure
            $validator = Validator::make($orderDetails, [
                'orderItems' => 'required|array',
                'orderItems.*.product_id' => 'required|exists:products,id',
                'orderItems.*.quantity' => 'required|integer|min:1',
                'orderItems.*.price' => 'required|numeric|min:0',
                'orderItems.*.optional_ingredients' => 'nullable|array',
                'orderItems.*.optional_ingredients.*.id' => 'required|exists:ingredients,id',
                'orderItems.*.optional_ingredients.*.quantity' => 'required|integer|min:1',
                'orderItems.*.optional_ingredients.*.price' => 'required|numeric|min:0',
                'orderItems.*.removed_ingredients' => 'nullable|array',
                'orderItems.*.removed_ingredients.*.id' => 'exists:ingredients,id',
                'orderItems.*.offers' => 'nullable|array',
                'orderItems.*.offers.*.id' => 'exists:products,id',
                'orderItems.*.offers.*.quantity' => 'required|integer|min:1',
                'orderItems.*.offers.*.price' => 'required|numeric|min:0',
                'orderItems.*.note' => 'nullable|string',
                'deliveryTax' => 'required|numeric|min:0',
                'subtotal' => 'required|numeric|min:0',
                'tax' => 'required|numeric|min:0',
                'total' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                Log::error('Validation Errors:', $validator->errors()->toArray());
                return redirect()->back()->withErrors(['error' => 'Validation failed. Check the input data.']);
            }

            DB::beginTransaction();

            // Create the order
            $organizationId = org_id();
            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'total_cost' => $orderDetails['total'],
                'subtotal' => $orderDetails['subtotal'],
                'tax' => $orderDetails['tax'],
                'deliveryTax' => $orderDetails['deliveryTax'],
                'status' => 'pending',
                'organization_id' => $organizationId,
            ]);

            // Process each item in orderItems
            foreach ($orderDetails['orderItems'] as $item) {
                $productPrice = $item['price'] * $item['quantity'];

                $orderDetail = OrderDetail::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $productPrice,
                    'organization_id' => $organizationId,
                ]);

                // Process optional ingredients
                if (!empty($item['optional_ingredients'])) {
                    foreach ($item['optional_ingredients'] as $ingredient) {
                        $optionalIngredientPrice = $ingredient['price'] * $ingredient['quantity'];

                        OrderDetailCustomization::create([
                            'order_detail_id' => $orderDetail->id,
                            'ingredient_id' => $ingredient['id'],
                            'quantity' => $ingredient['quantity'],
                            'type' => 'add',
                            'price' => $optionalIngredientPrice,
                            'organization_id' => $organizationId,
                        ]);

                        // Decrease stock for optional ingredients
                        $unit = $this->getIngredientUnitoptional($item['product_id'], $ingredient['id']);
                        $this->decreaseStockFIFO($ingredient['id'], $ingredient['quantity'], $item['product_id'], $unit);
                    }
                }

                // Process offers
                if (!empty($item['offers'])) {
                    foreach ($item['offers'] as $offer) {
                        $offerPrice = $offer['price'] * $offer['quantity'];

                        OrderDetailCustomization::create([
                            'order_detail_id' => $orderDetail->id,
                            'product_id' => $offer['id'],
                            'quantity' => $offer['quantity'],
                            'price' => $offerPrice,
                            'type' => 'offer',
                            'organization_id' => $organizationId,
                        ]);

                        // Decrease stock for offered products
                        $offerProduct = Product::find($offer['id']);
                        if ($offerProduct) {
                            foreach ($offerProduct->productIngredients as $ingredient) {
                                $unit = $this->getIngredientUnitoptionalproduct($offer['id'], $ingredient->ingredient_id);
                                $quantityNeeded = $ingredient->unit * $offer['quantity'];
                                $this->decreaseStockFIFO($ingredient->ingredient_id, $quantityNeeded, $offer['id'], $unit);
                            }
                        }
                    }
                }

                // Process removed ingredients
                if (!empty($item['removed_ingredients'])) {
                    foreach ($item['removed_ingredients'] as $ingredient) {
                        OrderDetailCustomization::create([
                            'order_detail_id' => $orderDetail->id,
                            'ingredient_id' => $ingredient['id'],
                            'quantity' => 0,
                            'type' => 'remove',
                            'organization_id' => $organizationId,
                        ]);
                    }
                }

                // Save custom notes
                if (!empty($item['note'])) {
                    OrderCustomNote::create([
                        'order_detail_id' => $orderDetail->id,
                        'note' => $item['note'],
                        'organization_id' => $organizationId,
                    ]);
                }
            }

            DB::commit();

            Log::info('Order Saved Successfully:', ['order' => $order->toArray()]);
            return redirect()->route('cashier.index')->with('success', 'Order confirmed successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error Saving Order:', ['message' => $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'An unexpected error occurred.']);
        }
    }

    public function getIngredientUnitoptional($productId, $ingredientId)
    {
        $productIngredient = ProductOptionalIngredient::where('product_id', $productId)
            ->where('ingredient_id', $ingredientId)
            ->first();

        return $productIngredient ? $productIngredient->unit : null;
    }
    public function getIngredientUnitoptionalproduct($productId, $ingredientId)
    {
        $productIngredient = ProductIngredient::where('product_id', $productId)
            ->where('ingredient_id', $ingredientId)
            ->first();
         return $productIngredient ? $productIngredient->unit : null;
    }
    public function getIngredientUnit($productId, $ingredientId)
    {
        $productIngredient = ProductIngredient::where('product_id', $productId)
            ->where('ingredient_id', $ingredientId)
            ->first();

        return $productIngredient ? $productIngredient->unit : null;
    }
    private function decreaseStockFIFO($ingredientId, $quantityNeeded, $productId,$unit)
    {
        try {
//            dd($productId, $ingredientId);
            // Fetch the unit conversion factor for this product and ingredient


            if (!$unit) {
                throw new \Exception("Unit not found for Ingredient ID: $ingredientId and Product ID: $productId");
            }

            // Adjust the quantity needed based on the unit
            $adjustedQuantityNeeded = $quantityNeeded * $unit;

            // Fetch stocks sorted by expiry date (FIFO) only for this ingredient
            $stocks = IngredientStockDetails::where('ingredient_id', $ingredientId)
                ->orderBy('expiry_date', 'asc')
                ->get();
             foreach ($stocks as $stock) {
                if ($adjustedQuantityNeeded <= 0) {
                    break; // Stop when the required quantity is satisfied
                }

                // Calculate available stock in usage units
                $availableStockInUsageUnits = (float)$stock->quantity_usage; // Convert to usage units

                // Determine the decrement amount
                $decrement = min($availableStockInUsageUnits, $adjustedQuantityNeeded);

                if ($decrement > 0) {
                    $originalQuantity = $stock->quantity; // For debugging and validation
                    $originalQuantityUsage = $stock->quantity_usage;

                    $stock->quantity -= $decrement / (float)$stock->factor; // Convert back to base units
                    $stock->quantity_usage -= $decrement; // Update available usage quantity
                    $adjustedQuantityNeeded -= $decrement; // Reduce the remaining required quantity

                    // Explicitly check if the update is valid before saving
                    if (

                        $stock->quantity_usage >= 0 &&
                        $stock->id // Ensure we are working on a specific row
                    ) {

                        $stock->save();
                    } else {
                        Log::error('Invalid stock update:', [
                            'stock_id' => $stock->id,
                            'ingredient_id' => $ingredientId,
                            'original_quantity' => $originalQuantity,
                            'original_quantity_usage' => $originalQuantityUsage,
                            'new_quantity' => $stock->quantity,
                            'new_quantity_usage' => $stock->quantity_usage,
                        ]);
                        throw new \Exception("Invalid stock update for Stock ID: {$stock->id}");
                    }
                }
            }

            if ($adjustedQuantityNeeded > 0) {
                throw new \Exception("Insufficient stock for Ingredient ID: $ingredientId. Needed: $adjustedQuantityNeeded");
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in decreaseStockFIFO:', ['message' => $e->getMessage()]);
            //dd($e->getMessage()); // Inspect the issue
            //dd($e->getMessage()); // Inspect the issue
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }





    public function getOrderDetails($orderId)
    {
        $order = Order::with(['orderDetails.customizations'])->findOrFail($orderId);

        return view('pages.orders.details', compact('order'));
    }

    // Show all orders
    public function showOrders()
    {
        $orders = Order::with([
            'orderDetails.product',
            'orderDetails.ingredients',
            'orderDetails.customizations.ingredient',
            'orderDetails.notes'
        ])->orderBy('created_at', 'desc')->get();

        return view('pages.cashier.show_orders', compact('orders'));
    }

    // View a single order
    public function viewOrder($id)
    {
        $order = Order::with([
            'orderDetails.product',
            'orderDetails.ingredients',
            'orderDetails.customizations.ingredient',
            'orderDetails.notes'
        ])->findOrFail($id);

        return view('pages.cashier.view_order', compact('order'));
    }


    /**
     * Update the status of an order.
     */
    // Update the status of an order
    public function updateOrderStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,preparing,ready,delivered',
        ]);

        $order = Order::findOrFail($id);
        $order->status = $validated['status'];
        $order->save();

        return redirect()->route('orders.show')->with('success', 'Order status updated successfully!');
    }






}
