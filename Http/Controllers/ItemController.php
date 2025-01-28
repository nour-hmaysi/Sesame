<?php

namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\InvoiceHasItems;
use App\Item;
use App\Partner;
use App\PartnersContactPerson;
use App\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Psy\Util\Str;

class ItemController extends Controller
{
    public function store(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'sale_account' => 'required', // Ensures the account exists in the accounts table
            'sale_price' => 'required|numeric|min:1', // Must be a numeric value and non-negative
            'sale_tax' => 'required', // Ensures the tax exists in the taxes table
        ];

        $messages = [
            'sale_account.required' => 'The sales account field is required.',
            'sale_price.required' => 'The sales price field is required.',
            'sale_price.numeric' => 'The sales price must be a number.',
            'sale_price.min' => 'The sales price must be at least 1.',
            'sale_tax.required' => 'The sales tax field is required.',
        ];
        try {
            // Validate the request data with custom messages
            $validatedData = $request->validate($rules, $messages);

            // Create a new item
            $isChecked = $request->has('track_inventory') ? 1 : 0;
            $requestData = $request->all();
            $requestData['track_inventory'] = $isChecked;
            $requestData['stock_quantity'] = $requestData['opening_stock'];
            Item::create($requestData);

            if ($request->ajax()) {
                return response()->json(['success' => true]);
            } else {
                return redirect()->back()->with('success', 'Item created successfully.');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->ajax()) {
                // If it's an AJAX request, return the validation errors in JSON format
                return response()->json(['success' => false, 'errors' => $e->validator->errors()]);
            } else {
                // If it's a normal request, redirect back with validation errors
                return redirect()->back()->withErrors($e->validator)->withInput();
            }
        }


    }
    public function create()
    {
        $organizationId = org_id();

        $incomeAccounts = GetSalesAccountIds();
        $expenseAccounts =  ExpenseAccounts();
        $partners = Suppliers();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        return view('pages.item.create', compact(['incomeAccounts', 'expenseAccounts', 'partners', 'taxes']));
    }
    public function index()
    {
        $organizationId = org_id();
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        return view('pages.item.index', compact('items'));
    }
    public function deleteItem($id)
    {
        $item = Item::findOrFail($id);

        $itemExist = InvoiceHasItems::where('item_id', $id)
            ->exists();
        if($itemExist){
            return response()->json(['status' => 'error',
                'message' => 'cannot be deleted. There are invoices containing this item.']);
        }else{
            $item->deleted = 1;
            $item->save();
            return response()->json(['status' => 'success', 'message' => 'Deleted Successfully']);
        }
    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $item = Item::findOrFail($id);
        $organizationId = org_id();
        $incomeAccounts = GetSalesAccountIds();
        $expenseAccounts =  ExpenseAccounts();
        $partners = Suppliers();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        return view('pages.item.edit', compact(['item','incomeAccounts', 'expenseAccounts', 'partners', 'taxes']));
    }
    public function update(Request $request, $encryptedId)
    {

        $rules = [
            'name' => 'required|string|max:255',
            'sale_account' => 'required', // Ensures the account exists in the accounts table
            'sale_price' => 'required|numeric|min:1', // Must be a numeric value and non-negative
            'sale_tax' => 'required', // Ensures the tax exists in the taxes table
        ];

        $messages = [
            'sale_account.required' => 'The sales account field is required.',
            'sale_price.required' => 'The sales price field is required.',
            'sale_price.numeric' => 'The sales price must be a number.',
            'sale_price.min' => 'The sales price must be at least 1.',
            'sale_tax.required' => 'The sales tax field is required.',
        ];
        try {
            $validatedData = $request->validate($rules, $messages);
            $id = Crypt::decryptString($encryptedId);
            $item = Item::findOrFail($id);
            $isChecked = $request->has('track_inventory') ? 1 : 0;
            $requestData = $request->all();
            $requestData['track_inventory'] = $isChecked;
            $requestData['stock_quantity'] = $requestData['opening_stock'];
            $item->update($requestData);
            return redirect()->back()->with('success', 'Item updated successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->validator)->withInput();
        }

    }

    public function listItems(){
        $organizationId = org_id();
        $items = Item::where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->orderBy('id', 'asc')
            ->get();
        $content = '';
        foreach($items as $item){
            $content .= '<option value="'.$item->id.'" 
                            data-tax="'.$item->sale_tax.'"
                            data-tax="'.$item->sale_description.'"
                             data-unit="'.$item->unit.'" data-price="'.$item->sale_price.'" data-account="'.$item->sale_account.'"
        >'. $item->name.'</option>';
        }
        return response()->json([
            'options' => $content,
        ]);
    }
    public function listPurchaseItems(){
        $organizationId = org_id();
        $items = Item::where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->orderBy('id', 'asc')
            ->get();
        $content = '';
        foreach($items as $item){
            $content .= '<option value="'.$item->id.'" 
                                        data-tax="'.$item->purchase_description.'"
            data-tax="'.$item->purchase_tax.'" data-unit="'.$item->unit.'" data-price="'.$item->purchase_price.'" data-account="'.$item->purchase_account.'"
        >'. $item->name.'</option>';
        }
        return response()->json([
            'options' => $content,
        ]);
    }
}
