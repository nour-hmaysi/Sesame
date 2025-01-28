<?php

namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\Expense;
use App\PaymentReceived;
use App\Payroll;
use App\Project;
use App\Partner;
use App\Transaction;
use App\TransactionProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function store(Request $request)
    {
//        validate name is unique
        $rules = [
            'name' => [
                'required',
                Rule::unique('project')->where(function ($query) use ($request) {
                    return $query->where('organization_id', org_id());
                }),
            ],
            'project_cost' => 'required|numeric',
            'receivable_id' => 'required',
        ];

        $messages = [
            'name.unique' => __('validation.unique', ['attribute' => 'name']),
            'project_cost.required' => __('validation.required', ['attribute' => 'project cost']),
            'receivable_id.required' => __('validation.required', ['attribute' => 'customer']),
        ];


        try {
            // Validate the request data with custom messages
            $validatedData = $request->validate($rules, $messages);

            $data = $request->all();

//        check reference exist
            $referenceExist = checkReferenceExists($request->input('reference_number'));

            if ($referenceExist) {
                if (!$request->ajax()) {
                    return redirect()->back()->withErrors([
                        'error' => __('messages.reference_exists')
                    ])->withInput();
                }else{
                    return response()->json(['success' => false, 'errors' =>  [__('messages.reference_exists')]]);
                }

            }


            //      Insert comment to project
            $title =  'Project Created.';
            $cmnt =  'Project Created For '.currencyName().' '.number_format($request->project_cost, 2, '.', ',')."<br>";

            $advValue = $request->has('advanced_payment') ? $request->adv_value : 0;
            $data['adv_amount'] = $request->has('advanced_payment')  ? $request->adv_amount : 0;

            if(!$request->has('retention_payment')) {
                $request->retention_amount = 0;
                $data['retention_amount'] = 0;
            }else{
                if($request->retention_value > 0){
                    $cmnt .= 'Retention Amount '.currencyName().number_format($request->retention_value, 2, '.', ',')."<br>";
                }
            }
            $project = Project::create($data);

            $hasTransaction = false;
            if( $advValue > 0){

                if (validateVATDate($request->date)) {
                    if (!$request->ajax()) {
                        return redirect()->back()->withInput()->with('error', errorMsg());
                    }else{
                        return response()->json(['success' => false, 'errors' => [errorMsg()]]);
                    }
                }
                $vat_amount = 0;
                if (TaxValue() > 0) {
                    $vat_amount = round($advValue * (TaxValue() / 100), 2);
                }
                if($request->account_id){
                    $account_id = $request->account_id;
                }else{
                    if (!$request->ajax()) {
                        return redirect()->back()->with('error', 'Select Debit Account!.');
                    }else{
                        return response()->json(['success' => false, 'errors' => ['Select Debit Account!.']]);
                    }
                }
                $advPaymentAccount = GetAdvPaymentAccount();
                $outVatAccount = GetOutputVATAccount();
                //        INSERT NEW PAYMENT
                $payment['type_id'] = 8; // project adv Payment
                $payment['amount'] =  $advValue + $vat_amount;
                $payment['unused_amount'] =  0;
                $payment['currency'] = currencyID();
                $payment['reference_number'] = $request->reference_number;
                $payment['paid_by_id'] = $request->receivable_id;
                $payment['date'] = $request->date;
                $payment_id = GlobalController::InsertNewPaymentReceived($payment);

                $request1['transaction_type_id'] = 8; // project adv Payment
                $request1['amount'] =  $advValue + $vat_amount;
                $vat_amount > 0 ? $request1['taxable_amount'] =  $advValue : $request1['non_taxable_amount'] =  $advValue ;
                $request1['currency'] = currencyID();
                $request1['reference_number'] = $request->reference_number;
                $request1['payment_id'] = $payment_id;
                $request1['paid_by_id'] = $request->receivable_id;
                $request1['date'] =  $request->date;
                $transaction = GlobalController::InsertNewTransaction($request1);

//          credit from advance account
                $advPayment['transaction_id'] = $transaction;
                $advPayment['amount'] = $advValue;
                $advPayment['account_id'] = $advPaymentAccount;
                $advPayment['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($advPayment);

//          credit from output vat
                $vatPayment['transaction_id'] = $transaction;
                $vatPayment['amount'] = $vat_amount;
                $vatPayment['account_id'] = $outVatAccount;
                $vatPayment['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($vatPayment);

//          Debit bank/cash account
                $Payment['transaction_id'] = $transaction;
                $Payment['amount'] = $advValue + $vat_amount;
                $Payment['account_id'] = $account_id;
                $Payment['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($Payment);

                $hasTransaction = true;
            }

            if($hasTransaction & $project->id){
                TransactionProject::create([
                    'transaction_id' => $transaction,
                    'project_id' => $project->id
                ]);
                $cmnt .= 'Advance Amount '.currencyName().number_format($request->adv_value, 2, '.', ',')."<br>";
            }

            GlobalController::InsertNewComment(15, $project->id, $title, $cmnt);
            if (!$request->ajax()) {
                return redirect()->route('ProjectController.index', ['row#' . $project->id]);
            } else {
                return response()->json(['success' => true]);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->ajax()) {
                return response()->json(['success' => false, 'errors' => $e->validator->errors()]);
            } else {
                return redirect()->back()->withErrors($e->validator)->withInput();
            }
        }





    }
    public function create()
    {
        $receivables = Customers();
        $paymentAccounts = PaymentAccounts();
        $projectCount = Project::generateAutoNumber();

        return view('pages.project.create', compact([ 'receivables','projectCount','paymentAccounts']));
    }
    public function index()
    {
        $projects = Projects();
        return view('pages.project.index', compact('projects'));
    }
    public function deleteProject($id)
    {
        $id = Crypt::decryptString($id);
        $project = Project::findOrFail($id);
        $organizationId = org_id();
        $exists = DB::table('sales_order')->where('project_id', $id)->where('organization_id', $organizationId)
            ->union(DB::table('quotation')->where('project_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('sales_invoice')->where('project_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('purchase_invoice')->where('project_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('purchase_order')->where('project_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('transaction_project')->where('project_id', $id))
            ->union(DB::table('expense')->where('project_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('payroll')->where('project_id', $id)->where('organization_id', $organizationId))
            ->exists();
        if ($exists) {
            return response()->json(['status' => 'error',
                'message' => 'Cannot delete project. It is referenced in related records']);
        }
        if (validateVATDate($project->date)) {
            return response()->json(['status' => 'error',
                'message' => errorMsg()]);
        }

        $project->deleted = 1;
        $project->save();
        return response()->json(['status' => 'success',
            'message' => 'Project deleted.']);
    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $project = Project::findOrFail($id);
        $receivables = Customers();
        $paymentAccounts = PaymentAccounts();
        return view('pages.project.edit', compact(['project', 'receivables', 'paymentAccounts']));
    }
    public function update(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $project = Project::findOrFail($id);
        $request->validate([
            'name' => [
                'required',
                Rule::unique('project')->where(function ($query) use ($request) {
                    return $query->where('organization_id', org_id());
                })->ignore($project->id),
            ],
            'project_cost' => 'required|numeric',
            'receivable_id' => 'required',
        ], [
            'name.unique' => __('validation.unique', ['attribute' => 'name']),
            'project_cost.required' => __('validation.required', ['attribute' => 'project cost']),
            'receivable_id.required' =>__('validation.required', ['attribute' => 'client']),
        ]);

        $referenceNumber = $request->input('reference_number');
        if ($referenceNumber !== $project->reference_number) {
            $referenceExist = checkReferenceExists($referenceNumber);
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'reference_number' => __('messages.reference_exists'),
                ])->withInput();
            }
        }

        $data = $request->all();
        $project_id = $project->id;
        $advValue = $request->has('advanced_payment') ? $request->adv_value : 0;
        $data['adv_amount'] = $request->has('advanced_payment')  ? $request->adv_amount : 0;
        if (!$request->has('retention_payment')) {
            $request->retention_amount = 0;
            $data['retention_amount'] = 0;
        }
        if( (validateVATDate($request->date) || validateVATDate($project->date)) &&
            ($project->project_cost != $request->project_cost || $project->adv_amount != $request->adv_amount ||  $project->retention_amount != $request->retention_amount )){
            return redirect()->back()->withInput()->with('error', errorMsg());
        }
        $vat_amount = TaxValue() > 0 ? round($advValue * (TaxValue() / 100), 2) : 0;
        $cmnt = '';
        if( $project->project_cost != $request->project_cost ){
            $cmnt = "Cost ".currencyName().number_format($request->project_cost, 2, '.', ',')."<br>";
        }
        if( $project->adv_amount != $request->adv_amount ){
            $cmnt .= 'Advance Amount '.currencyName().number_format($request->adv_value, 2, '.', ',')."<br>";
        }

        if( $project->retention_amount != $request->retention_amount ){
            $cmnt .= 'Retention Amount '.currencyName().number_format($request->retention_amount, 2, '.', ',')."<br>";
        }

        $hasTransaction = false;
        if($project->adv_amount > 0){ //if already have adv payment
            // get transaction type Project Adv Payment  and related to project
            $transaction = Transaction::with(['TransactionProject', 'TransactionDetails'])
                ->where('organization_id', org_id())
                ->where('transaction_type_id', 8)
                ->whereHas('TransactionProject', function ($query) use ($project_id) {
                    $query->where('project_id', $project_id);
                })
                ->get()
                ->last();
            if($transaction){
                $payment_id = $transaction->payment_id;
                // Delete the transaction details and project
                if ($transaction->TransactionDetails()) {
                    $transaction->TransactionDetails()->delete();
                }
                if ($transaction->TransactionProject()) {
                    $transaction->TransactionProject()->delete();
                }
                $transaction->delete();
            }
            if($advValue == 0){
                if($payment_id)
                {
                    $payment = PaymentReceived::find($payment_id);
                    $payment->delete();
                }
            }else{
                //        UPDATE PAYMENT
                $payment = PaymentReceived::find($payment_id);
                $payment->amount = $advValue + $vat_amount;
                $payment->paid_by_id = $request->receivable_id;
                $payment->reference_number = $request->reference_number;
                $payment->date = $request->date;
                $payment->save();
            }

        }else{
            if($advValue > 0){

                $payment['type_id'] = 8; // project adv Payment
                $payment['amount'] =  $advValue + $vat_amount;
                $payment['unused_amount'] =  0;
                $payment['currency'] = currencyID();
                $payment['reference_number'] = $request->reference_number;
                $payment['paid_by_id'] = $request->receivable_id;
                $payment['date'] = $request->date;
                $payment_id = GlobalController::InsertNewPaymentReceived($payment);
            }
        }

        if( $advValue > 0){

            $account_id = $request->account_id;
            $advPaymentAccount = GetAdvPaymentAccount();
            $outVatAccount = GetOutputVATAccount();


            $request1['transaction_type_id'] = 8; // project adv Payment
            $request1['amount'] =  $advValue + $vat_amount;
            $vat_amount > 0 ? $request1['taxable_amount'] =  $advValue : $request1['non_taxable_amount'] =  $advValue ;;
            $request1['currency'] = currencyID();
            $request1['reference_number'] = $request->reference_number;
            $request1['payment_id'] = $payment_id;
            $request1['paid_by_id'] = $request->receivable_id;
            $request1['date'] =  $request->date;
            $transaction = GlobalController::InsertNewTransaction($request1);
//          credit from advance account
            $advPayment['transaction_id'] = $transaction;
            $advPayment['amount'] = $advValue;
            $advPayment['account_id'] = $advPaymentAccount;
            $advPayment['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($advPayment);
//          credit from output vat
                $vatPayment['transaction_id'] = $transaction;
                $vatPayment['amount'] = $vat_amount;
                $vatPayment['account_id'] = $outVatAccount;
                $vatPayment['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($vatPayment);

//          Debit bank/cash account
            $Payment['transaction_id'] = $transaction;
            $Payment['amount'] = $advValue + $vat_amount;
            $Payment['account_id'] = $account_id;
            $Payment['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($Payment);

            $hasTransaction = true;
        }

        //      Insert comment to project
        $title =  'Project Modified.';
        GlobalController::InsertNewComment(15, $project->id, $title, $cmnt);

        if($hasTransaction & $project->id){
            TransactionProject::create([
                'transaction_id' => $transaction,
                'project_id' => $project->id
            ]);
        }
        $project->update($data);
        return redirect()->route('ProjectController.index', ['row#' . $project->id]);
    }


    public function listProjects(){
        $organizationId = org_id();
        $projects = Project::where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->orderBy('id', 'asc')
            ->get();
        $content = '';
        foreach($projects as $project){
            $content .= '<option value="'.$project->id.'" data-retention="'.$project->retention_amount.'"data-adv-amount ="'.$project->adv_amount.'"
            data-customer="'.$project->customer->id.'"
            >'. $project->name.'</option>';
        }

        return response()->json([
            'options' => $content,
        ]);
    }
    public function moreDetails($id){
        $purchaseVat = GetInputVATAccount();
        $project = Project::where('organization_id', org_id())
            ->where('id', $id)
            ->first();
        //     purchase invoice
        $purchases = \App\PurchaseInvoice::where('organization_id', org_id())
            ->whereIn('status', [4,7])
            ->where('project_id', $id)
            ->get();
        //     Debit Note
        $refundPurchase = \App\DebitNote::where('organization_id', org_id())
            ->whereIn('status', [4,8,9])
            ->where('project_id', $id)
            ->get();
//        payroll
        $payroll = Payroll::where('organization_id', org_id())
            ->where('project_id', $id)
            ->orderBy('date')
            ->get();
        $expenses = DB::table('transaction')
            ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
            ->join('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
            ->join('expense', 'transaction_expense.expense_id', '=', 'expense.id')
            ->where('expense.project_id', $id)
            ->where('expense.organization_id', org_id())
            ->where('transaction.transaction_type_id', 11)
            ->where('transaction_details.is_debit', 1)
            ->where('transaction_details.account_id', '!=', $purchaseVat)
            ->select('transaction.date', 'transaction_details.amount', 'expense.reference', 'expense.expense_type', 'expense.related_to')
            ->get();
        $refundExpenses = DB::table('transaction')
            ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
            ->join('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
            ->join('expense', 'transaction_expense.expense_id', '=', 'expense.id')
            ->where('expense.project_id', $id)
            ->where('expense.organization_id', org_id())
            ->where('transaction.transaction_type_id', 20)
            ->where('transaction_details.is_debit', 0)
            ->where('transaction_details.account_id', '!=', $purchaseVat)
            ->select('transaction.date', 'transaction_details.amount', 'expense.details', 'expense.related_to')
            ->get();

        return view('pages.project.moreDetails', compact([ 'expenses', 'refundPurchase', 'refundExpenses', 'purchases', 'project', 'payroll']));
    }

}
