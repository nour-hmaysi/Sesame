<?php

namespace App\Http\Controllers;

use App\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{

    public function index()
    {

        $suppliers = Supplier::where('organization_id', org_id())->get();
        return view('pages.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('pages.suppliers.create');
    }

    public function store(Request $request)
    {
        $organizationId = org_id();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_info' => 'nullable|string',
            'location' => 'nullable|string',
            'item_type' => 'nullable|string'

        ]);
        $validated['organization_id'] = $organizationId;

        Supplier::create($validated);

        return redirect()->route('suppliers.index')->with('success', 'Supplier created successfully');
    }

    public function show(Supplier $supplier)
    {
        return view('pages.suppliers.show', compact('supplier'));
    }

    public function edit(Supplier $supplier)
    {
        return view('pages.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_info' => 'nullable|string',
            'location' => 'nullable|string',
            'item_type' => 'nullable|string',
        ]);

        $supplier->update($validated);

        return redirect()->route('suppliers.index')->with('success', 'Supplier updated successfully');
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->inventoryStocks()->count() > 0) {
            return redirect()->route('suppliers.index')->with('error', 'Cannot delete supplier because it is associated with inventory stocks');
        }

        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted successfully');
    }
}
