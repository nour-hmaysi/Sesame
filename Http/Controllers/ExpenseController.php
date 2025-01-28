<?php

namespace App\Http\Controllers;

use App\Asset;
use App\Expense;
use App\AssetType;
use App\ChartOfAccounts;
use App\ExpenseDocuments;
use App\InvoiceFiles;
use App\Partner;
use App\Project;
use App\Tax;
use App\Transaction;
use App\TransactionExpense;
use App\VatType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    public function store(Request $request)
    {

        $expenseData = $request->all();
        if ( validateVATDate($request->starting_date) || validateVATDate($request->date)) {
            return redirect()->back()->withInput()->with('warning', errorMsg());
        }
        $referenceExist = checkReferenceExists($request->input('reference'));

        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }
        if($expenseData['expense_type'] == 1){
            $date = $expenseData['date'];
        }else{
            $date = $expenseData['starting_date'];
        }
//        dd(count($request->multiple_expense_account_id));
        if ($request->has('multiple_expense_account_id') && count($request->multiple_expense_account_id) > 1) {

            $rules = [
                'multiple_expense_account_id'      => ['required', 'array', 'min:1', function ($attribute, $value, $fail) use ($request) {
                    for($index = 1 ; $index <= count($value) ; $index++) {
                        $multiple_expense_account_id= $request->input('multiple_expense_account_id')[$index] ?? null;
                        $multiple_amount = $request->input('multiple_amount')[$index] ?? Null;
                        $multiple_vat = $request->input('multiple_vat')[$index] ?? null;

                        if (empty($multiple_expense_account_id) && empty($multiple_amount) &&
                            empty($multiple_vat) ) {
                            // Skip empty rows
                            continue;
                        }

                        // If the row is not fully empty, validate required fields
                        if (empty($multiple_expense_account_id)) {
                            $fail("Expense account is required.");
                        }
                        if (empty($multiple_vat)) {
                            $fail("The VAT treatment for all account is required.");
                        }
                        if ($multiple_amount <= 0) {
                            $fail("The amount for all accounts is required.");
                        }
                    }
                }],
                'payment_account_id' => 'required',

            ];
            $messages = [
                'payment_account_id.required' => 'Payment account is required.',
            ];
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return redirect()->back()->withErrors($validator)->withInput();
            }

            $paymentAmounts = $request->input('multiple_amount', []);
            $vatAmounts = $request->input('multiple_vat_amount', []);
            $totalPaymentAmount = array_sum($paymentAmounts);
            $totalVatAmount = array_sum($vatAmounts);

//                ADD transaction
            $request1['transaction_type_id'] = 11; //Expense
            $request1['amount'] =  $totalPaymentAmount + $totalVatAmount;
            $request1['taxable_amount'] =  $totalPaymentAmount ;
            $request1['reference_number'] = $request->reference;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
            $totalTaxableAmount = $totalNonTaxableAmount = 0;

            $expenseData['expense_account_id'] =  $request->multiple_expense_account_id[1];
            $expenseData['amount'] =  $request->multiple_amount[1];
            $expenseData['details'] =  $request->multiple_details[1];
            $expenseData['vat_amount'] =  $request->multiple_vat_amount[1];
            $expenseData['vat'] =  $request->multiple_vat[1];
            $expenseData['vat_number'] =  $request->multiple_vat_number[1];
            if($request->multiple_vat_amount[1]> 0){
                $totalTaxableAmount += $request->multiple_amount[1];
            }else{
                $totalNonTaxableAmount += $request->multiple_amount[1];
            }

            $primaryExpense = Expense::create($expenseData);
            $related_to = $primaryExpense->id;
            $primaryExpense->update(['related_to' => $related_to]);
            $expenseData['related_to'] = $related_to;

            $request3['transaction_id'] = $transaction;
            $request3['amount'] = $request->multiple_amount[1];
            $request3['account_id'] = $request->multiple_expense_account_id[1];
            $request3['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request3);

            for ($i = 2; $i < count($request->multiple_expense_account_id); $i++) {
                $expenseData['expense_account_id'] =  $request->multiple_expense_account_id[$i];
                $expenseData['amount'] =  $request->multiple_amount[$i];
                $expenseData['details'] =  $request->multiple_details[$i];
                $expenseData['vat_amount'] =  $request->multiple_vat_amount[$i];
                $expenseData['vat'] =  $request->multiple_vat[$i];
                $expenseData['vat_number'] =  $request->multiple_vat_number[$i];

                if($request->multiple_vat_amount[$i]> 0){
                    $totalTaxableAmount += $request->multiple_amount[$i];
                }else{
                    $totalNonTaxableAmount += $request->multiple_amount[$i];
                }

                Expense::create($expenseData);
//      Debit expense
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $request->multiple_amount[$i];
                $request2['account_id'] = $request->multiple_expense_account_id[$i];
                $request2['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($request2);
            }
            $currentTransaction = Transaction::find($transaction);
            $currentTransaction->non_taxable_amount = $totalNonTaxableAmount;
            $currentTransaction->taxable_amount = $totalTaxableAmount;
            $currentTransaction->save();
        }
        else{
            $rules = [
                'expense_account_id' => 'required',
                'amount' => 'required',
                'payment_account_id' => 'required',
                'vat' => 'required',
            ];

            // Custom error messages
            $messages = [
                'expense_account_id.required' => 'Expense account is required.',
                'amount.required' => 'Cost is required.',
                'vat.required' => 'VAT treatment is required.',
                'payment_account_id.required' => 'Payment account is required.',
            ];
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return redirect()->back()->withErrors($validator)->withInput();
            }
            $related_to = Expense::create($expenseData);
            $related_to = $related_to->id;
            $expense = Expense::findOrFail($related_to);
            $expense->update(['related_to' => $related_to]);
            $totalPaymentAmount = $expense->amount;
            $totalVatAmount = $expense->vat_amount;

            //                ADD transaction
            $request1['transaction_type_id'] = 11; //Expense
            $request1['amount'] =  $totalPaymentAmount + $totalVatAmount;
            $totalVatAmount > 0 ? $request1['taxable_amount'] =  $totalPaymentAmount : $request1['non_taxable_amount'] =  $totalPaymentAmount ;
            $request1['reference_number'] = $request->reference;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
//      Debit expense
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $totalPaymentAmount;
            $request2['account_id'] = $request->expense_account_id;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);
        }

//      credit
        $request2['transaction_id'] = $transaction;
        $request2['amount'] = $totalPaymentAmount + $totalVatAmount;
        $request2['account_id'] = $request->payment_account_id;
        $request2['is_debit'] = 0;
        GlobalController::InsertNewTransactionDetails($request2);
        //      Debit vat
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $totalVatAmount;
            $request2['account_id'] = GetInputVATAccount();
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);


        TransactionExpense::create([
           'transaction_id' => $transaction,
           'expense_id' => $related_to
        ]);
        if ($request->hasFile('receipt')) {
            $fileNamesToStore = [];
            foreach ($request->file('receipt') as $file) {
                $fileName = uploadFile($file, 'expenses');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new ExpenseDocuments();
                $invoiceFile->expense_id = $related_to;
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }
        $cmntAmount= $totalPaymentAmount + $totalVatAmount;
        $comment = 'Expense created for '.currencyName() .' '.$cmntAmount.'.';
        GlobalController::InsertNewComment(14, $related_to, NULL, $comment);
        return redirect()->route('ExpenseController.index', ['#row-' . $related_to]);
    }
    public function create()
    {
        $organizationId = org_id();
//        $projects = Project::get();
        $projects = Project::with('customer')
            ->where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $expenseAccounts =  ExpenseAccounts();
        $paymentAccounts =  PaymentAccounts();
        $customers = Customers();
        $vat_types = Tax::where('organization_id', $organizationId)->orderBy('value', 'desc')->get();

        return view('pages.expense.create', compact(['expenseAccounts','paymentAccounts', 'customers', 'projects', 'vat_types']));
    }
    public function index()
    {
        $organizationId = org_id();
        $expenses = DB::table('expense as A')
            ->select(
                DB::raw('SUM(A.amount) as amount'),
                DB::raw('SUM(A.vat_amount) as vat_amount'),
                DB::raw('GROUP_CONCAT(A.id) as account_ids'),
                DB::raw('GROUP_CONCAT(B.name) as account_name'),
                DB::raw('GROUP_CONCAT(A.details) as account_details'),
                'C.name as project_name',
                'D.display_name as customer_name',
                'E.name as payment_account',
                'A.reference',
                'A.date',
                'A.id',
                'A.project_id',
                'A.customer_id',
                'A.refunded_amount',
                'A.expense_type'
            )
            ->leftJoin('chart_of_account as B', 'A.expense_account_id', '=', 'B.id')
            ->leftJoin('project as C', 'A.project_id', '=', 'C.id')
            ->leftJoin('partner as D', 'A.customer_id', '=', 'D.id')
            ->leftJoin('chart_of_account as E', 'A.payment_account_id', '=', 'E.id')
            ->where('A.organization_id', $organizationId)
            ->groupBy('A.related_to')
            ->get();
        return view('pages.expense.index', compact('expenses'));
    }
    public function deleteExpense($id)
    {
//        need to check if vat reported
//        delete expense
        $expense = Expense::findOrFail($id);

        if ( validateVATDate($expense->starting_date) || validateVATDate($expense->date)) {
            return response()->json(['status' => 'error',
                'message'=> errorMsg()]);
        }
//        delete related expense
        $related_expenses = Expense::where('related_to', $id)->get();
        foreach ($related_expenses as $related_expense) {
            $related_expense->delete();
        }
//        delete the transactions
        $transactions = Transaction::with(['TransactionExpense', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->whereIn('transaction_type_id', [20, 11]) //expense and refund
            ->whereHas('TransactionExpense', function ($query) use ($id) {
                $query->where('expense_id', $id);
            })
            ->get();
        $transactions->each(function ($transaction) {
            if ($transaction->TransactionDetails) {
                $transaction->TransactionDetails->each(function ($detail) {
                    $detail->delete();
                });
            }
            if ($transaction->TransactionExpense) {
                $transaction->TransactionExpense->each(function ($expense) {
                    $expense->delete();
                });
            }
            $transaction->delete();
        });
        $expense->delete();


        return response()->json(['status' => 'success',
            'message'=>'Expense deleted']);
    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $expense = Expense::where('related_to', $id)->get();
        $organizationId = org_id();
        $projects = Project::with('customer')
            ->where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $expenseAccounts =  ExpenseAccounts();
        $paymentAccounts =  PaymentAccounts();
        $customers = Customers();
        $vat_types = Tax::where('organization_id', $organizationId)->orderBy('value', 'desc')->get();

        $files = ExpenseDocuments::where('expense_id',$id)->get();
        return view('pages.expense.edit', compact(['expense', 'expenseAccounts','paymentAccounts', 'vat_types', 'projects', 'customers', 'files']));
    }
    public function refund($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $expense = Expense::where('related_to', $id)->get();
        $organizationId = org_id();
        $projects = Project::with('customer')
            ->where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $expenseAccounts =  ExpenseAccounts();
        $paymentAccounts =  PaymentAccounts();
        $customers = Customers();
        $vat_types = Tax::where('organization_id', $organizationId)->orderBy('value', 'desc')->get();
        return view('pages.expense.refund', compact(['expense', 'expenseAccounts','paymentAccounts', 'vat_types', 'projects', 'customers']));
    }
    public function update(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $expense = Expense::findOrFail($id);

        if ( validateVATDate($expense->starting_date) || validateVATDate($expense->date) ||
            validateVATDate($request->starting_date) || validateVATDate($request->date) ) {
            return redirect()->back()->withInput()->with('warning', errorMsg());
        }

        $referenceNumber = $request->input('reference');
        if ($referenceNumber !== $expense->reference) {
            $referenceExist = checkReferenceExists($referenceNumber);
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'reference_number' => __('messages.reference_exists'),
                ])->withInput();
            }
        }
        $applyChanges = false;
        if($request->has('apply_change')){// change amount for the old transaction(delete + re-add)
            $applyChanges = true;
        }
//        delete old transaction
        $transactions = Transaction::with(['TransactionExpense', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->where('transaction_type_id', 11) //expense
            ->whereHas('TransactionExpense', function ($query) use ($id) {
                $query->where('expense_id', $id);
            })
            ->get();
        $expenseData = $request->all();

        if($expense->expense_type == 1){
            $date = $expenseData['date'];
        }else{
            $date = $expenseData['starting_date'];
        }
        if($applyChanges){
            $transactions->each(function ($transaction) {
                if ($transaction->TransactionDetails) {
                    $transaction->TransactionDetails->each(function ($detail) {
                        $detail->delete();
                    });
                }
                if ($transaction->TransactionExpense) {
                    $transaction->TransactionExpense->each(function ($e) {
                        $e->delete();
                    });
                }
                $transaction->delete();
            });
            $expenseData['recurring_date'] = Carbon::parse($expenseData['starting_date'])->addMonths($expenseData['repetitive']);

        }
        $expenseData['related_to'] = $id;



        if ($expenseData['amount'] != $expense->amount) {
            $comment = 'Expense details modified. Amount changed from '
                . currencyName() . ' ' . $expense->amount . ' to ' . currencyName() . ' ' .  $expenseData['amount']  .'.';
        }else{
            $comment = 'Expense details modified.';
        }
        GlobalController::InsertNewComment(14, $id, NULL, $comment);


        if ($request->has('multiple_id') && count($request->multiple_id) > 1) {
            $selectedFiles=  $request->multiple_id;
            if (!in_array($id, $selectedFiles)) {
                $expenseData['related_to'] = $request->multiple_id[1];
            }
            //remove deleted expense account
            Expense::where('related_to', $id)
                ->whereNotIn('id', $selectedFiles)
                ->delete();

//            TRANSACTION
            $paymentAmounts = $request->input('multiple_amount', []);
            $vatAmounts = $request->input('multiple_vat_amount', []);
            $totalPaymentAmount = array_sum($paymentAmounts);
            $totalVatAmount = array_sum($vatAmounts);
            if($applyChanges){
                //                ADD transaction
                $request1['transaction_type_id'] = 11; //Expense
                $request1['amount'] =  $totalPaymentAmount + $totalVatAmount;
                $request1['taxable_amount'] =  $totalPaymentAmount ;
                $request1['reference_number'] = $request->reference;
                $request1['date'] = $date;
                $transaction = GlobalController::InsertNewTransaction($request1);
            }
            $totalTaxableAmount = $totalNonTaxableAmount = 0;

            for ($i = 1; $i < count($request->multiple_id); $i++) {
                $expenseData['expense_account_id'] =  $request->multiple_expense_account_id[$i];
                $expenseData['amount'] =  $request->multiple_amount[$i];
                $expenseData['vat_amount'] =  $request->multiple_vat_amount[$i];
                $expenseData['vat'] =  $request->multiple_vat[$i];
                $expenseData['details'] =  $request->multiple_details[$i];
                $expenseData['vat_number'] =  $request->multiple_vat_number[$i];
                if($request->multiple_vat_amount[$i]> 0){
                    $totalTaxableAmount += $request->multiple_amount[$i];
                }else{
                    $totalNonTaxableAmount += $request->multiple_amount[$i];
                }
                if ($request->multiple_id[$i] != 0) {
                    $updated_expense = Expense::findOrFail($request->multiple_id[$i]);
                    $updated_expense->update($expenseData);
                } else {
                    Expense::create($expenseData);
                }
                if($applyChanges) {
                    //      Debit expense
                    $request2['transaction_id'] = $transaction;
                    $request2['amount'] = $request->multiple_amount[$i];
                    $request2['account_id'] = $request->multiple_expense_account_id[$i];
                    $request2['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($request2);
                }
            }
            $currentTransaction = Transaction::find($transaction);
            $currentTransaction->taxable_amount = $totalTaxableAmount;
            $currentTransaction->non_taxable_amount = $totalNonTaxableAmount;
            $currentTransaction->save();

        }else{
            $expense->update($expenseData);
            $totalPaymentAmount = $expense->amount;
            $totalVatAmount = $expense->vat_amount;
            if($applyChanges) {
                //                ADD transaction
                $request1['transaction_type_id'] = 11; //Expense
                $request1['amount'] =  $totalPaymentAmount + $totalVatAmount;
                $totalVatAmount > 0 ? $request1['taxable_amount'] =  $totalPaymentAmount : $request1['non_taxable_amount'] =  $totalPaymentAmount ;
                $request1['reference_number'] = $request->reference;
                $request1['date'] = $date;
                $transaction = GlobalController::InsertNewTransaction($request1);
                //      Debit expense
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $totalPaymentAmount;
                $request2['account_id'] = $request->expense_account_id;
                $request2['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($request2);
            }

        }
        if($applyChanges){
            //      credit
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $totalPaymentAmount + $totalVatAmount;
            $request2['account_id'] = $request->payment_account_id;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
            //      Debit vat
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $totalVatAmount;
            $request2['account_id'] = GetInputVATAccount();
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);

            TransactionExpense::create([
                'transaction_id' => $transaction,
                'expense_id' => $expenseData['related_to']
            ]);
        }

        ExpenseDocuments::where('expense_id', $id)
            ->update(['expense_id' => $expenseData['related_to']]);

        $selectedFiles = $request->input('current_files');
        if ($selectedFiles) {
            $selectedFilesFiltered = array_map('intval', $selectedFiles);
            $selectedFilesFiltered = array_filter($selectedFilesFiltered, function ($value) {
                return $value !== NULL;
            });
            //        remove deleted invoice files
            ExpenseDocuments::where('expense_id', $expenseData['related_to'])
                ->whereNotIn('id', $selectedFilesFiltered)
                ->delete();
        }else{
            ExpenseDocuments::where('expense_id', $expenseData['related_to'])
                ->delete();
        }

        if ($request->hasFile('receipt')) {
            $fileNamesToStore = [];
            foreach ($request->file('receipt') as $file) {

                $fileName = uploadFile($file, 'expenses');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new ExpenseDocuments();
                $invoiceFile->expense_id = $expenseData['related_to'];
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }



        return redirect()->route('ExpenseController.index', ['#row-' . $expenseData['related_to']]);


    }
    public function storeRefund(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $expense = Expense::findOrFail($id);
        if (validateVATDate($expense->date) || validateVATDate($expense->starting_date) || validateVATDate($request->date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }
        $expenseData = $request->all();
        if ($request->has('multiple_id') && count($request->multiple_id) > 1) {
//            TRANSACTION
            $paymentAmounts = $request->input('multiple_amount', []);
            $vatAmounts = $request->input('multiple_vat_amount', []);
            $totalPaymentAmount = array_sum($paymentAmounts);
            $totalVatAmount = array_sum($vatAmounts);
            $totalTaxableAmount = $totalNonTaxableAmount = 0;
            //                ADD transaction
            $request1['transaction_type_id'] = 20; //refund
            $request1['amount'] =  $totalPaymentAmount + $totalVatAmount;
            $request1['reference_number'] = $request->reference_number;
            $request1['date'] = $request->date;
            $request1['description'] = $request->details;
            $transaction = GlobalController::InsertNewTransaction($request1);

            for ($i = 0; $i < count($request->multiple_id); $i++) {
                $expenseData['expense_account_id'] =  $request->multiple_expense_account_id[$i];
                $expenseData['amount'] =  $request->multiple_amount[$i];
                $expenseData['vat_amount'] =  $request->multiple_vat_amount[$i];
                $expenseData['vat'] =  $request->multiple_vat[$i];
                if($request->multiple_vat_amount[$i]> 0){
                    $totalTaxableAmount += $request->multiple_amount[$i];
                }else{
                    $totalNonTaxableAmount += $request->multiple_amount[$i];
                }
                //Credit expense
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $request->multiple_amount[$i];
                $request2['account_id'] = $request->multiple_expense_account_id[$i];
                $request2['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($request2);
            }
            $currentTransaction = Transaction::find($transaction);
            $currentTransaction->non_taxable_amount = $totalNonTaxableAmount;
            $currentTransaction->taxable_amount = $totalTaxableAmount;
            $currentTransaction->save();
        }else{
            $totalPaymentAmount = $request->amount;
            $totalVatAmount = $request->vat_amount;
            //                ADD transaction
            $request1['transaction_type_id'] = 20; //Expense refund
            $request1['amount'] =  $totalPaymentAmount + $totalVatAmount;
            $totalVatAmount > 0 ? $request1['taxable_amount'] =  $totalPaymentAmount : $request1['non_taxable_amount'] =  $totalPaymentAmount ;
            $request1['reference_number'] = $request->reference;
            $request1['date'] = $request->date;
            $transaction = GlobalController::InsertNewTransaction($request1);
            //      Credit expense
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $totalPaymentAmount;
            $request2['account_id'] = $request->expense_account_id;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
        }
            //      Debit
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $totalPaymentAmount + $totalVatAmount;
            $request2['account_id'] = $request->payment_account_id;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);
            //      Debit vat

                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $totalVatAmount;
                $request2['account_id'] = GetInputVATAccount();
                $request2['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($request2);

            TransactionExpense::create([
                'transaction_id' => $transaction,
                'expense_id' => $id
            ]);
            $expense->refunded_amount += $request1['amount'];
            $expense->update();
        $cmntAmount = $totalPaymentAmount + $totalVatAmount;
            $comment = 'Amount of '.currencyName() .' '.$cmntAmount.' refunded.';
            GlobalController::InsertNewComment(14, $id, NULL, $comment);


        return redirect()->route('ExpenseController.index', ['#row-' . $id]);


    }
    public function viewDetails($id){
        $expense = DB::table('expense as A')
            ->select(
                DB::raw('SUM(A.amount) as amount'),
                DB::raw('SUM(A.vat_amount) as vat_amount'),
                DB::raw('GROUP_CONCAT(A.id) as account_ids'),
                DB::raw('GROUP_CONCAT(B.name) as account_name'),
                DB::raw('GROUP_CONCAT(F.name) as vat_name'),
                DB::raw('GROUP_CONCAT(A.details) as account_details'),
                'C.name as project_name',
                'C.id as project_id',
                'D.display_name as customer_name',
                'D.id as customer_id',
                'E.name as payment_account',
                'A.reference',
                'A.date',
                'A.id',
                'A.details',
                'A.expense_type'
            )
            ->leftJoin('chart_of_account as B', 'A.expense_account_id', '=', 'B.id')
            ->leftJoin('project as C', 'A.project_id', '=', 'C.id')
            ->leftJoin('partner as D', 'A.customer_id', '=', 'D.id')
            ->leftJoin('chart_of_account as E', 'A.payment_account_id', '=', 'E.id')
            ->leftJoin('tax as F', 'F.id', '=', 'A.vat')
            ->where('A.organization_id', org_id())
            ->where('A.related_to', $id)
            ->groupBy('A.related_to')
            ->get();
        $expenseFiles = ExpenseDocuments::where('expense_id', $id)->get();
        $transactions = Transaction::with(['TransactionExpense', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->where('transaction_type_id', 11) //expense
            ->whereHas('TransactionExpense', function ($query) use ($id) {
                $query->where('expense_id', $id);
            })
            ->get();
        $refundTransactions = Transaction::with(['TransactionExpense', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->where('transaction_type_id', 20) //expense
            ->whereHas('TransactionExpense', function ($query) use ($id) {
                $query->where('expense_id', $id);
            })
            ->get();
        return view('pages.expense.moreDetails', compact(['expense', 'transactions', 'expenseFiles', 'refundTransactions']));

    }
}
