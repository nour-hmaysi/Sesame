<?php

namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\Partner;
use App\BankAccount;
use App\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class BankAccountController extends Controller
{


//    public function exportPdf()
//    {
//        $data = YourModel::all();  // Fetch your table data
//        $pdf = Pdf::loadView('your-pdf-view', compact('data'));
//
//        return $pdf->download('table-export.pdf');
//    }
    public function store(Request $request)
    {
        $rules = [
            'account_type' => 'required|in:0,1',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'iban' => 'required_if:account_type,0|string|max:34',
            'swift' => 'nullable|string|max:11',
//            'currency' => 'required|string',
        ];

        $validatedData = $request->validate($rules);

        $bankAccount = BankAccount::create($request->all());
        $type = $request->account_type;
        if($type == 1){
//            cash
            $accountTye = 16;
            $parentAccountID = GetCashAccount();
        }else{
//            bank
            $accountTye = 15;
            $parentAccountID = GetBankAccount();
        }
        $coa = ChartOfAccounts::create([
            'type_id' => $accountTye,
            'name' =>$request->bank_name,
            'is_default' => 0,
            'parent_account_id' => $parentAccountID,
        ]);
        $bankAccount->coa_id = $coa->id;
        $bankAccount->save();
        return redirect()->route('BankAccountController.index', ['#row-' . $bankAccount->id]);
    }
    public function create()
    {

        return view('pages.bank.create');
    }
    public function index()
    {
        $organizationId = org_id();
        $bankAccounts = BankAccount::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();

            return view('pages.bank.index', compact('bankAccounts'));
    }
    public function deleteBankAccount($id)
    {
        $bankAccount = BankAccount::findOrFail($id);
        if($bankAccount->coa_id){
            $transactions = \App\TransactionDetails::with('transaction')
                ->where('account_id', $bankAccount->coa_id)
                ->whereHas('transaction', function ($query) {
                    $query->where('organization_id', org_id());
                })
                ->first();
            if ( $transactions ) {
                return response()->json(['status'=> 'error', 'message'=> errorTMsg()]);
            }
        }

        $bankAccount->deleted = 1;
        $bankAccount->save();
        return response()->json(['status'=> 'success', 'message'=> 'Account Deleted.']);

    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $bankAccount = BankAccount::findOrFail($id);
        return view('pages.bank.edit', compact(['bankAccount']));
    }
    public function update(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);

        $rules = [
            'account_type' => 'required|in:0,1',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'swift' => 'nullable|string|max:11'
        ];

        $validatedData = $request->validate($rules);

        $bankAccount = BankAccount::findOrFail($id);
        $bankAccount->update($request->all());

        return redirect()->route('BankAccountController.index', ['#row-' . $id]);
    }
}
