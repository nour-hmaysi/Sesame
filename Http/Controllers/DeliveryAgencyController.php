<?php

namespace App\Http\Controllers;

use App\Category;
use App\DeliveryAgency;
use Illuminate\Http\Request;

class DeliveryAgencyController extends Controller
{
    public function index()
    {
        $agencies = DeliveryAgency::where('organization_id', org_id())->get();
        return view('pages.delivery_agencies.index', compact('agencies'));
    }

    public function create()
    {
        return view('pages.delivery_agencies.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tax_rate' => 'required|numeric|min:0',
        ]);
        $organizationId = org_id();
        $validated['organization_id'] = $organizationId;
        DeliveryAgency::create($validated);

        return redirect()->route('delivery-agencies.index')->with('success', 'Delivery agency created successfully.');
    }

    public function edit($id)
    {
        $agency = DeliveryAgency::findOrFail($id);
        return view('pages.delivery_agencies.edit', compact('agency'));
    }
    public function show( $agency )
    {
        $agency = DeliveryAgency::findOrFail($agency);
        return view('pages.delivery_agencies.show', compact('agency'));
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tax_rate' => 'required|numeric|min:0',
        ]);

        $agency = DeliveryAgency::findOrFail($id);
        $agency->update($validated);

        return redirect()->route('delivery-agencies.index')->with('success', 'Delivery agency updated successfully.');
    }

    public function destroy($id)
    {
        $agency = DeliveryAgency::findOrFail($id);
        $agency->delete();

        return redirect()->route('delivery-agencies.index')->with('success', 'Delivery agency deleted successfully.');
    }
}
