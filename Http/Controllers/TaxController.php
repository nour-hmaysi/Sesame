<?php

namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\Partner;
use App\BankAccount;
use App\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class TaxController extends Controller
{
    public function store(Request $request)
    {
        $tax = Tax::create($request->all());

        return redirect()->route('TaxController.index', ['#row-' . $tax->id]);
    }
    public function create()
    {

        return view('pages.tax.create');
    }
    public function index()
    {
        $organizationId = org_id();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();

            return view('pages.tax.index', compact('taxes'));
    }
    public function delete($id)
    {
//        $bankAccount = BankAccount::findOrFail($id);
//        $bankAccount->deleted = 1;
//        $bankAccount->save();
//        return response()->json(['message' => 'success']);
    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $tax = Tax::findOrFail($id);
        return view('pages.tax.edit', compact(['tax']));
    }
    public function update(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $tax = Tax::findOrFail($id);
        $tax->update($request->all());

        return redirect()->route('TaxController.index', ['#row-' . $id]);
    }
}
