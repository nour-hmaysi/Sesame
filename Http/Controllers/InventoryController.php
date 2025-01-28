<?php

namespace App\Http\Controllers;

use App\Category;
use App\Inventory;
use App\InventoryStock;
use App\InventoryUsage;
use Illuminate\Http\Request;
use App\Notifications\LowStockNotification;

class InventoryController extends Controller
{
    public function index()
    {
        $inventories = Inventory::with(['category', 'inventoryStocks'])->get();
        return view('pages.inventories.index', compact('inventories'));
    }

    public function create()
    {
        $categories = Category::all();
        return view('pages.inventories.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $inventory = Inventory::create($request->all());
        return redirect()->route('inventories.index')->with('success', 'Inventory created successfully');
    }

    public function show(Inventory $inventory)
    {
        $inventory->load('inventoryStocks', 'inventoryUsages');
        return view('pages.inventories.show', compact('inventory'));
    }

    public function edit(Inventory $inventory)
    {
        $categories = Category::all();
        return view('inventories.edit', compact('inventory', 'categories'));
    }

    public function update(Request $request, Inventory $inventory)
    {
        $inventory->update($request->all());
        return redirect()->route('inventories.index')->with('success', 'Inventory updated successfully');
    }

    public function destroy(Inventory $inventory)
    {
        $inventory->delete();
        return redirect()->route('inventories.index')->with('success', 'Inventory deleted successfully');
    }

    public function useInventory(Request $request, Inventory $inventory)
    {
        $quantity = $request->input('quantity');
        $remaining = $quantity;

        foreach ($inventory->inventoryStocks()->where('is_active', true)->orderBy('created_at')->get() as $stock) {
            if ($remaining <= 0) {
                break;
            }

            if ($stock->quantity >= $remaining) {
                $stock->quantity -= $remaining;
                $stock->save();
                $remaining = 0;
            } else {
                $remaining -= $stock->quantity;
                $stock->quantity = 0;
                $stock->is_active = false;
                $stock->save();
            }
        }

        InventoryUsage::create([
            'inventory_id' => $inventory->id,
            'quantity_used' => $quantity,
        ]);

        // Check if stock is low and send notification
        if ($inventory->inventoryStocks()->sum('quantity') < 10) { // Threshold for low stock
            $user = User::first(); // Assuming you're sending it to the first user (admin)
            $user->notify(new LowStockNotification($inventory));
        }

        return redirect()->route('inventories.index')->with('success', 'Inventory used successfully');
    }
}
