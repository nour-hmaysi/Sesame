<?php

namespace App\Http\Controllers;

use App\Asset;
use App\CreditNote;
use App\DebitNote;
use App\InvoiceFiles;
use App\InvoiceType;
use App\ObAdjustment;
use App\ObPartners;
use App\Partner;
use App\PartnersContactPerson;
use App\PaymentReceived;
use App\PaymentTerms;
use App\Project;
use App\PurchaseInvoice;
use App\SalesInvoice;
use App\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartnerController extends Controller
{
    public function store(Request $request)
    {


        $rules = [
            'type' => 'required|in:0,1', // 0 for business, 1 for individual
            'partner_type' => 'nullable|in:1,2', // 1 for payable, 2 for receivable
            'salutation' => 'nullable|string|max:255',
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'ar_salutation' => 'nullable|string|max:255',
            'ar_firstname' => 'nullable|string|max:255',
            'ar_lastname' => 'nullable|string|max:255',
            'company_name' => 'required_if:type,0', // Required if type is 0
            'company_activity' => 'nullable|integer',
            'display_name' => 'required|string|max:255', // Required
            'ar_display_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'location' => 'required|string', // Required
            'additional_phone' => 'nullable|string|max:255',
            'tax_treatment' => 'nullable|in:1,2', // 1: VAT registered, 2: Non VAT registered
            'tax_number' => 'nullable|string',
            'cr_number' => 'nullable|string',
            'place' => 'nullable|string|max:255',
            'attention' => 'nullable|string|max:255',
            'country' => 'required|string|max:255', // Required
            'address' => 'required|string|max:255', // Required
            'district' => 'required|string|max:255', // Required
            'city' => 'required|string|max:255', // Required
            'state' => 'required|string|max:255', // Required
            'zip_code' => 'required|string|max:255', // Required
            'address_phone' => 'nullable|string|max:255',
            'fax_number' => 'nullable|string|max:255',
            'building_number' => 'required|string', // Required
            'ar_building_number' => 'nullable|string',
            'ar_attention' => 'nullable|string|max:255',
            'ar_country' => 'nullable|string|max:255',
            'ar_address' => 'nullable|string|max:255',
            'ar_district' => 'nullable|string|max:255',
            'ar_city' => 'nullable|string|max:255',
            'ar_state' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:255',
        ];


        // Custom error messages
        $messages = [
            'company_name.required_if' => 'The company name is required.',
            'display_name.required' => 'Please select a display name.',
        ];




        // Validate the request data
        try {
            // Validate the request data with custom messages
            $validatedData = $request->validate($rules, $messages);


            // Create a new partner and retrieve its ID
            $partnerData = $request->all();

            if($request->payment_name){
                $payment_term = new PaymentTerms();
                $payment_term->name = $request->payment_name;
                $payment_term->value = $request->payment_value;
                $payment_term->organization_id = $request->organization_id;
                $payment_term->created_by = $request->created_by;
                $payment_term->save();
                $payment_term_id = $payment_term->id;
                $partnerData['payment_terms'] = $payment_term_id;
            }
            $partner = Partner::create($partnerData);

            $partner_id = $partner->id;
            if(isset($request->ids)){
                // Save contact persons associated with the partner
                foreach ($request->ids as $index => $id) {
                    if($request->first_names[$index]){
                        $contactPerson = new PartnersContactPerson();
                        $contactPerson->partner_id = $partner_id;
                        $contactPerson->salutation = $request->salutations[$index];
                        $contactPerson->firstname = $request->first_names[$index];
                        $contactPerson->lastname = $request->last_names[$index];
                        $contactPerson->email = $request->emails[$index];
                        $contactPerson->phone = $request->phones[$index];
                        $contactPerson->created_by = $request->created_by;
                        $contactPerson->save();
                    }
                }
            }

            $commentId = ($partner->partner_type == 1) ? 12 : 11;
            GlobalController::InsertNewComment($commentId, $partner_id, 'Created On', NULL);

            if (!$request->ajax()) {
             if($partner->partner_type == 1){
                 return redirect()->route('PartnerController.viewPayable', '#row-'.$partner_id ) ;
             }else{
                 return redirect()->route('PartnerController.viewReceivable', '#row-'.$partner_id ) ;
             }

            }else{
                return response()->json(['success' => true]);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (!$request->ajax()) {
                return redirect()->back()->withErrors($e->validator)->withInput();
            }else{
                return response()->json(['success' => false, 'errors' => $e->validator->errors()]);
            }
        }
    }
    public function createReceivable()
    {
        $organizationId = org_id();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
                        ->orwhere('by_default', 1)
                        ->get();
        return view('pages.receivable.create', compact('paymentTerms'));
    }
    public function createPayable()
    {
        $organizationId = org_id();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
        return view('pages.payable.create', compact('paymentTerms'));
    }
    public function viewReceivable()
    {
        $organizationId = org_id();

        $receivables = Partner::where('partner_type', 2)
            ->where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->get();

        return view('pages.receivable.index', compact('receivables'));
    }
    public function viewPayable()
    {
        $organizationId = org_id();

        $payables = Partner::where('partner_type', 1)
            ->where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->get();

        return view('pages.payable.index', compact('payables'));
    }
    public function deletePartner($id)
    {
        $id = Crypt::decryptString($id);

        $partner = Partner::findOrFail($id);

        $organizationId = org_id();
        $exists = DB::table('sales_order')->where('partner_id', $id)->where('organization_id', $organizationId)
            ->union(DB::table('quotation')->where('partner_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('sales_invoice')->where('partner_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('purchase_invoice')->where('partner_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('purchase_order')->where('partner_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('project')->where('receivable_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('expense')->where('customer_id', $id)->where('organization_id', $organizationId))
            ->union(DB::table('fixed_asset')->where('payable_id', $id)->where('organization_id', $organizationId))
            ->exists();
        if ($exists) {
            return response()->json(['status' => 'error',
                'message' => 'Cannot be delete. It is referenced in related records']);
        }

        $partner->deleted = 1;
        $partner->save();
        return response()->json(['status' => 'success',
            'message' => 'Deleted.']);
    }
    public function editReceivable($encryptedId)
    {
        $organizationId = org_id();
        $id = Crypt::decryptString($encryptedId);
        $receivable = Partner::findOrFail($id);
        $contactPersons = PartnersContactPerson::with('Partners')
            ->where('partner_id', $id)
            ->get();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();

        return view('pages.receivable.edit', compact(['receivable', 'contactPersons', 'paymentTerms']));
    }
    public function editPayable($encryptedId)
    {
        $organizationId = org_id();
        $id = Crypt::decryptString($encryptedId);
        $payable = Partner::findOrFail($id);
        $contactPersons = PartnersContactPerson::with('Partners')
            ->where('partner_id', $id)
            ->get();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
        return view('pages.payable.edit', compact(['payable', 'contactPersons', 'paymentTerms']));
    }
    public function update(Request $request, $encryptedId)
    {
        $organizationId = org_id();
        $id = Crypt::decryptString($encryptedId);
        $partner = Partner::findOrFail($id);
        $partnerData = $request->all();
        if($request->payment_name){
            $payment_term = new PaymentTerms();
            $payment_term->name = $request->payment_name;
            $payment_term->value = $request->payment_value;
            $payment_term->organization_id = $organizationId;
            $payment_term->created_by = $request->updated_by;
            $payment_term->save();
            $payment_term_id = $payment_term->id;
            $partnerData['payment_terms'] = $payment_term_id;
        }
        $partner->update($partnerData);




        $selectedContact = $request->input('ids');
        if ($selectedContact) {
            $selectedContactFiltered = array_map('intval', $selectedContact);
            $selectedContactFiltered = array_filter($selectedContactFiltered, function ($value) {
                return $value !== NULL;
            });
            PartnersContactPerson::where('partner_id', $id)
                ->whereNotIn('id', $selectedContactFiltered)
                ->delete();
        }else{
            PartnersContactPerson::where('partner_id', $id)
                ->delete();
        }
        if($request->ids){
            // Loop through the fields for contact persons submitted in the request
            foreach ($request->ids as $index => $id) {
                if ($id != 0) {
                    // If the ID is not 0, it means this is an existing contact person and we need to update it
                    $contactPerson = PartnersContactPerson::findOrFail($id);
                    $contactPerson->update([
                        'salutation' => $request->salutations[$index],
                        'firstname' => $request->first_names[$index],
                        'lastname' => $request->last_names[$index],
                        'email' => $request->emails[$index],
                        'phone' => $request->phones[$index],
                        'updated_by' => $request->updated_by,
                    ]);
                } else {
                    // If the ID is 0, it means this is a new contact person and we need to create it
                    if($request->first_names[$index]){
                        $newContactPerson = new PartnersContactPerson([
                            'partner_id' => $partner->id,
                            'salutation' => $request->salutations[$index],
                            'firstname' => $request->first_names[$index],
                            'lastname' => $request->last_names[$index],
                            'email' => $request->emails[$index],
                            'phone' => $request->phones[$index],
                            'created_by' => $request->updated_by
                        ]);
                        $newContactPerson->save();
                    }

                }
            }
        }


        if($partner->partner_type == 1){
//            payable
            GlobalController::InsertNewComment(12, $id, 'Supplier has been edited' ,NULL);
            return redirect()->route('PartnerController.viewPayable', '#row-'.$id ) ;
        }else{
            GlobalController::InsertNewComment(11, $id, 'Customer has been edited' ,NULL);
            return redirect()->route('PartnerController.viewReceivable', '#row-'.$id ) ;
        }

    }
    public function listPartners($type){
        $organizationId = org_id();

        if($type == 1){
//        payables
            $payables = Partner::where('partner_type', 1)
                ->where('organization_id', $organizationId)
                ->where('deleted', 0)
                ->orderBy('id', 'asc')
                ->select('id', 'display_name', 'ar_display_name','payment_terms')
                ->get();
            $content = '';
            foreach($payables as $payable){
                $content .= '<option value="'.$payable->id.'" data-term="'.$payable->payment_terms.'">'.$payable->display_name.'</option>';
            }
        }else{
//           //        receivables
        $receivables = Partner::where('partner_type', 2)
            ->where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->orderBy('id', 'asc')
            ->select('id', 'display_name', 'ar_display_name','payment_terms')
            ->get();
        $content = '';
        foreach($receivables as $receivable){
            $content .= '<option value="'.$receivable->id.'" data-term="'.$receivable->payment_terms.'">'.$receivable->display_name.'</option>';
        }
        }



        return response()->json([
            'options' => $content
        ]);
    }
    public function showInvoice($type, $id)
    {
        $organizationId = org_id();
        $invoiceType = InvoiceType::where('name', 'like', '%' . $type . '%')->first();
        $invoiceTypeId = $invoiceType->id;
        $invoiceName = $invoiceType->display_name;
        $invoiceLinkName = $invoiceType->name;
        $invoiceTableName = $invoiceType->table_name;
        $invoices = DB::table($invoiceTableName . ' as A')
            ->select('A.*', 'B.display_name as partner_name', 'C.name as project_name')
            ->leftJoin('partner as B', 'B.id', '=', 'A.partner_id')
            ->leftJoin('project as C', 'C.id', '=', 'A.project_id')
            ->where('A.organization_id', $organizationId)
            ->where('A.deleted', 0)
            ->where('A.partner_id', $id)
            ->get();
//        if($invoiceTypeId == 4 || $invoiceTypeId == 5){
//            return view('pages.transactions.payable.index', compact(['invoices', 'invoiceTypeId', 'invoiceName', 'invoiceLinkName', 'invoiceTableName']));
//        }else{
//            return view('pages.transactions.invoice', compact(['invoices', 'invoiceTypeId', 'invoiceName', 'invoiceLinkName', 'invoiceTableName']));
//        }
        $data = ['invoices'=>$invoices ,
            'invoiceTypeId'=> $invoiceTypeId,
            'invoiceName'=> $invoiceName,
            'invoiceLinkName'=> $invoiceLinkName,
            'invoiceTableName'=> $invoiceTableName];
        return $data;
    }
    public static function showPaymentsReceived($id)
    {
        $organizationId = org_id();
        $partnerType = Partner::where('id', $id)
            ->where('organization_id', org_id())
            ->select('partner_type')
            ->first();
        if($partnerType->partner_type == 1){
//            supplier
//        show payment or advanced payment
            $paymentsReceived = PaymentReceived::with(['Transaction','TransactionType'])
                ->where('organization_id', $organizationId)
                ->where('paid_by_id', $id)
                ->whereIn('type_id', [2, 17])
                ->get();
            $paymentsReceived = $paymentsReceived->map(function ($payment) {
                // Check if transactions exist before attempting to access their details
                $transactions = $payment->Transaction;
                if ($transactions) {
                    $firstDebitDetail = null;
                    if ($transactions->count() > 1) {
                        foreach ($transactions as $transaction) {
                            if (in_array($transaction->transaction_type_id, [2, 17])) {
                                $firstDebitDetail = $transaction->TransactionDetails()->where('is_debit', 0)
                                    ->whereHas('account', function ($query) {
                                        $query->whereIn('type_id', [15, 16]);
                                    })
                                    ->first();
                                if ($firstDebitDetail) {
                                    break;
                                }
                            }
                        }
                    } else {
                        // Single transaction: find first detail where is_debit is 1
                        $firstTransaction = $transactions->first();
                        if ($firstTransaction) {
                            $firstDebitDetail = $firstTransaction->TransactionDetails()->where('is_debit', 0)
                                ->whereHas('account', function ($query) {
                                    $query->whereIn('type_id', [15, 16]);
                                })
                                ->first();
                        }
                    }
                    $invoiceNumbers = $payment->Transaction->flatMap(function ($transaction) {
                        return $transaction->TransactionInvoice ? $transaction->TransactionInvoice->pluck('PurchaseInvoice.invoice_number') : collect();
                    })->toArray();

                    $referenceNumber = $payment->Transaction->pluck('reference_number')->first();
                } else {
                    $firstDebitDetail = null;
                    $invoiceNumbers = [];
                    $referenceNumber = null;
                }
                return [
                    'id' => $payment->id,
                    'date' => $payment->date,
                    'payment_number' => $payment->payment_number,
                    'account_name' => $firstDebitDetail ? $firstDebitDetail->account->name : '',
                    'transaction_type_name' => $payment->transactionType ? $payment->transactionType->name : '',
                    'invoice_numbers' => $invoiceNumbers,
                    'partner_name' => $payment->partner ? $payment->partner->display_name : '',
                    'currency' => GlobalController::GetCurrencyName($payment->currency),
                    'amount' => $payment->amount,
                    'unused_amount' => $payment->unused_amount,
                    'note' => $payment->note,
                    'reference_number' => $referenceNumber,
                ];

            });
        }else{
//            customer
            //        show payment or advanced payment
            $paymentsReceived = PaymentReceived::with(['Transaction','TransactionType'])
                ->where('organization_id', $organizationId)
                ->where('paid_by_id', $id)
                ->whereIn('type_id', [1, 3])
                ->get();
            $paymentsReceived = $paymentsReceived->map(function ($payment) {
                // Check if transactions exist before attempting to access their details
                $transactions = $payment->Transaction;
                if ($transactions) {
                    $firstDebitDetail = null;
                    if ($transactions->count() > 1) {
//                    get tye payment or advance
                        foreach ($transactions as $transaction) {
                            if (in_array($transaction->transaction_type_id, [1, 3])) {
                                $firstDebitDetail = $transaction->TransactionDetails()->where('is_debit', 1)
                                    ->whereHas('account', function ($query) {
                                        $query->whereIn('type_id', [15, 16]);
                                    })
                                    ->first();
                                if ($firstDebitDetail) {
                                    break;
                                }
                            }
                        }
                    } else {
                        // Single transaction: find first detail where is_debit is 1
                        $firstTransaction = $transactions->first();
                        if ($firstTransaction) {
                            $firstDebitDetail = $firstTransaction->TransactionDetails()->where('is_debit', 1)
                                ->whereHas('account', function ($query) {
                                    $query->whereIn('type_id', [15, 16]);
                                })
                                ->first();
                        }
                    }
                    $invoiceNumbers = $payment->Transaction->flatMap(function ($transaction) {
                        return $transaction->TransactionInvoice ? $transaction->TransactionInvoice->pluck('SalesInvoice.invoice_number') : collect();
                    })->toArray();

                    $referenceNumber = $payment->Transaction->pluck('reference_number')->first();
                } else {
                    $firstDebitDetail = null;
                    $invoiceNumbers = [];
                    $referenceNumber = null;
                }
                return [
                    'id' => $payment->id,
                    'date' => $payment->date,
                    'payment_number' => $payment->payment_number,
                    'account_name' => $firstDebitDetail ? $firstDebitDetail->account->name : '',
                    'transaction_type_name' => $payment->transactionType ? $payment->transactionType->name : '',
                    'invoice_numbers' => $invoiceNumbers,
                    'partner_name' => $payment->partner ? $payment->partner->display_name : '',
                    'currency' => GlobalController::GetCurrencyName($payment->currency),
                    'amount' => $payment->amount,
                    'unused_amount' => $payment->unused_amount,
                    'note' => $payment->note,
                    'reference_number' => $referenceNumber,
                ];

            });
        }

//        return view('pages.transactions.payments', compact(['paymentsReceived']));
        return $paymentsReceived;
    }
    public function showExpenses($id)
    {
        $organizationId = org_id();
        $partnerType = Partner::where('id', $id)
            ->where('organization_id', org_id())
            ->select('partner_type')
            ->first();
        if($partnerType->partner_type == 1){
//            supplier
            $assets = Asset::where('payable_id', $id)
                ->where('organization_id', org_id())
                ->get();
            return $assets;
        }else{
//            customer
            $expenses = DB::table('expense as A')
                ->select(
                    DB::raw('SUM(A.amount) as amount'),
                    DB::raw('SUM(A.vat_amount) as vat_amount'),
                    DB::raw('GROUP_CONCAT(A.id) as account_ids'),
                    DB::raw('GROUP_CONCAT(B.name) as account_name'),
                    'C.name as project_name',
                    'D.display_name as customer_name',
                    'E.name as payment_account',
                    'A.reference',
                    'A.date',
                    'A.id',
                    'A.expense_type'
                )
                ->leftJoin('chart_of_account as B', 'A.expense_account_id', '=', 'B.id')
                ->leftJoin('project as C', 'A.project_id', '=', 'C.id')
                ->leftJoin('partner as D', 'A.customer_id', '=', 'D.id')
                ->leftJoin('chart_of_account as E', 'A.payment_account_id', '=', 'E.id')
                ->where('A.organization_id', org_id())
                ->where('A.customer_id', $id)
                ->groupBy('A.related_to')
                ->get();
//        return view('pages.transactions.expense', compact('expenses'));
            return $expenses;
        }

    }
    public function showProjects($id)
    {
        $projects = Project::where('deleted', 0)
            ->where('organization_id', org_id())
            ->where('receivable_id',$id)
            ->get();
        return $projects;
//        return view('pages.transactions.project', compact('projects'));
    }
    public function moreDetails($id)
    {
        $projects = $this->showProjects($id);
        $expenses = $this->showExpenses($id);
        $paymentsReceived = $this->showPaymentsReceived($id);
        $sales_invoice = $this->showInvoice('sales_invoice', $id);
        $sales_order = $this->showInvoice('sales_order', $id);
        $quotation = $this->showInvoice('quotation', $id);
        $credit_note = $this->showInvoice('credit_note', $id);
        $partner = Partner::find($id);
        return view('pages.receivable.moreDetails', compact('id', 'projects', 'expenses',
            'paymentsReceived', 'sales_invoice', 'sales_order', 'quotation', 'credit_note', 'partner'));
    }
    public function morePayableDetails($id)
    {
        $assets = $this->showExpenses($id);
        $paymentsReceived = $this->showPaymentsReceived($id);
        $purchase_invoice = $this->showInvoice('purchase_invoice', $id);
        $purchase_order = $this->showInvoice('purchase_order', $id);
        $debit_note = $this->showInvoice('debit_note', $id);
        $partner = Partner::find($id);
        return view('pages.payable.moreDetails', compact('id', 'assets',
            'paymentsReceived', 'purchase_invoice', 'purchase_order', 'debit_note', 'partner'));
    }
    public function getStatement(Request $request, $id){

        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');
        $partner = Partner::find($id);
        $openingBalance = $this->calculateOpeningBalance($id, $startDate);
        // Calculate transactions within the date range
        $transactions = $this->calculateTransactions($id, $startDate, $endDate);
//        $currentOpeningBalance = Partner::where('id', $id)
//            ->whereBetween('created_at', [$startDate, $endDate])
//            ->select('opening_balance', 'created_at')
//            ->first();

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->whereBetween('date', [$startDate, $endDate])
            ->first();
        $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;

        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                ->where('partner_id', $id)
                ->select('debit_amount', 'credit_amount')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
            }
        }
        $currentOpeningBalance = $customerOpeningBalanceDebit - $customerOpeningBalanceCredit;
        $data = [
            'openingBalance' => $openingBalance,
            'currentOpeningBalance' => $currentOpeningBalance,
            'transactions' => $transactions,
            'openingBalanceDate' => $openingBalanceDate,
        ];
        return view('pages.receivable.statement', compact('partner','openingBalance',
            'currentOpeningBalance', 'transactions','startDate','endDate', 'openingBalanceDate'));

    }
    private function calculateOpeningBalance($id, $startDate) {
        //        opening balance entered by the user
//        $customerOpeningBalance = Partner::where('id', $id)
//                ->where('created_at', '<', $startDate)
//                ->select('opening_balance')
//                ->first();

        $orgID = Partner::find($id)->organization_id;
        $openingBalanceDate = ObAdjustment::where('organization_id', $orgID)
            ->where('date','<', $startDate)
            ->first();
        $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;

        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', $orgID)
                ->where('partner_id', $id)
                ->select('debit_amount', 'credit_amount')
                ->first();
        if($customerOpeningBalance){
            $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
            $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
        }
        }
        // Calculate unpaid invoices before the start date
        $invoicesAmount = SalesInvoice::where('partner_id', $id)
            ->whereIn('status', [4, 7]) // Approved or paid
            ->where('invoice_date', '<', $startDate)
            ->sum('total');

        // Calculate unallocated payments before the start date
        $paymentsAmount = PaymentReceived::where('paid_by_id', $id)
            ->whereIn('type_id', [1, 3]) // Advanced or payment
            ->where('date', '<', $startDate)
            ->sum('amount');

        // Calculate total opening balance
        $openingBalance =  $customerOpeningBalanceDebit - $customerOpeningBalanceCredit + $invoicesAmount - $paymentsAmount  ;
        return $openingBalance;
    }
    private function calculateTransactions($id, $startDate, $endDate) {
        $invoiceSum = $creditNoteSum = $paymentReceivedSum = $creditRefundSum = 0;
        $orgID = Partner::find($id)->organization_id;

        $paymentReceivedSum = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [1, 3]) // Advanced or payment
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');
        $invoiceSum = SalesInvoice::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [4, 7]) // Approved, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->sum('total');
        $paymentRefundSum = Transaction::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('transaction_type_id', [6]) // refund
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->sum('amount');
        $creditRefundSum = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [13]) // credit note refund
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');
        $creditNoteSum = CreditNote::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [8, 9])//open, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->sum('total');

        $invoiceSum -= $creditNoteSum;
        $paymentReceivedSum -= $creditRefundSum;
        $paymentReceivedSum -= $paymentRefundSum;

        $paymentReceived = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [1, 3]) // Advanced or payment
            ->whereBetween('date', [$startDate, $endDate])
            ->select('created_at','id', 'date', 'payment_number', 'amount', 'unused_amount', 'note', DB::raw("'payment' as type"))
            ->orderBy('date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $paymentRefund = Transaction::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('transaction_type_id', [6]) // refund
            ->whereBetween('date', [$startDate, $endDate])
            ->select('created_at','id', 'date', 'payment_number', 'amount',  'internal_note', DB::raw("'refund' as type"))
            ->orderBy('date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $creditRefund = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [13]) // credit note refund
            ->whereBetween('date', [$startDate, $endDate])
            ->select('created_at','id', 'date', 'payment_number', 'amount', 'unused_amount', 'note', DB::raw("'creditRefund' as type"))
            ->orderBy('date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $invoices = SalesInvoice::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [4,7])//approved, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->select('created_at','invoice_date as date', 'invoice_number', 'due_date', 'total', 'terms_conditions', DB::raw("'invoice' as type"))
            ->orderBy('invoice_date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $creditNote = CreditNote::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [8, 9])//open, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->select('created_at','invoice_date as date', 'invoice_number', 'total', 'due_date', 'terms_conditions', DB::raw("'creditNote' as type"))
            ->orderBy('invoice_date')
            ->orderBy('created_at')
            ->get()
            ->toArray();


        $transactions = array_merge($paymentReceived,  $invoices, $creditNote, $paymentRefund, $creditRefund);

        $transactions = collect($transactions)->sortBy([
            ['date', 'asc'],
            ['created_at', 'asc']
        ]);

        return [
            'paymentReceivedSum' => $paymentReceivedSum,
            'invoiceSum' => $invoiceSum,
            'transactions' => $transactions,

        ];
    }
    public function getPayableStatement(Request $request, $id){

        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');
        $partner = Partner::find($id);
        $orgID = $partner->organization_id;

        $openingBalance = $this->calculatePayableOpeningBalance($id, $startDate);
        // Calculate transactions within the date range
        $transactions = $this->calculatePayableTransactions($id, $startDate, $endDate);
//        $currentOpeningBalance = Partner::where('id', $id)
//            ->whereBetween('created_at', [$startDate, $endDate])
//            ->select('opening_balance', 'created_at')
//            ->first();
        $openingBalanceDate = ObAdjustment::where('organization_id', $orgID)
            ->whereBetween('date', [$startDate, $endDate])
            ->first();
        $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;

        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', $orgID)
                ->where('partner_id', $id)
                ->select('debit_amount', 'credit_amount')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
            }
        }

        $currentOpeningBalance = $customerOpeningBalanceCredit - $customerOpeningBalanceDebit;

        $data = [
            'openingBalance' => $openingBalance,
            'currentOpeningBalance' => $currentOpeningBalance,
            'transactions' => $transactions,
        ];
        return view('pages.payable.statement', compact('partner','openingBalance', 'currentOpeningBalance',
            'transactions','startDate','endDate','openingBalanceDate'));

    }
    private function calculatePayableOpeningBalance($id, $startDate) {
        //        opening balance entered by the user
//        $customerOpeningBalance = Partner::where('id', $id)
//                ->where('created_at', '<', $startDate)
//                ->select('opening_balance')
//                ->first();
//
//        if($customerOpeningBalance){
//            $customerOpeningBalance = $customerOpeningBalance->opening_balance;
//        }else{
//            $customerOpeningBalance = 0;
//        }
        $orgID = Partner::find($id)->organization_id;

        $openingBalanceDate = ObAdjustment::where('organization_id', $orgID)
            ->where('date','<', $startDate)
            ->first();
        $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;

        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', $orgID)
                ->where('partner_id', $id)
                ->select('debit_amount', 'credit_amount')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
            }
        }

        // Calculate unpaid invoices before the start date
        $invoicesAmount = PurchaseInvoice::where('partner_id', $id)
            ->whereIn('status', [4, 7]) // Approved or paid
            ->where('invoice_date', '<', $startDate)
            ->sum('total');

        // Calculate unallocated payments before the start date
        $paymentsAmount = PaymentReceived::where('paid_by_id', $id)
            ->whereIn('type_id', [2, 17]) // Advanced or payment
            ->where('date', '<', $startDate)
            ->sum('amount');

        // Calculate total opening balance
        $openingBalance =   $customerOpeningBalanceCredit - $customerOpeningBalanceDebit + $invoicesAmount - $paymentsAmount  ;
        return $openingBalance;
    }
    private function calculatePayableTransactions($id, $startDate, $endDate) {
        $invoiceSum = $debitNoteSum = $paymentReceivedSum = $creditRefundSum = 0;
        $orgID = Partner::find($id)->organization_id;

        $paymentReceivedSum = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [2, 17]) // Advanced or payment
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');
        $invoiceSum = PurchaseInvoice::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [4, 7]) // Approved, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->sum('total');
        $paymentRefundSum = Transaction::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('transaction_type_id', [16]) // refund
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->sum('amount');
        $creditRefundSum = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [18]) // credit note refund
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');
        $debitNoteSum = DebitNote::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [8, 9])//open, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->sum('total');

        $invoiceSum -= $debitNoteSum;
        $paymentReceivedSum -= $creditRefundSum;
        $paymentReceivedSum -= $paymentRefundSum;

        $paymentReceived = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [2, 17]) // Advanced or payment
            ->whereBetween('date', [$startDate, $endDate])
            ->select('created_at','id', 'date', 'payment_number', 'amount', 'unused_amount', 'note', DB::raw("'payment' as type"))
            ->orderBy('date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $paymentRefund = Transaction::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('transaction_type_id', [16]) // refund
            ->whereBetween('date', [$startDate, $endDate])
            ->select('created_at','id', 'date', 'payment_number', 'amount',  'internal_note', DB::raw("'refund' as type"))
            ->orderBy('date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $creditRefund = PaymentReceived::where('organization_id', $orgID)
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [18]) // credit note refund
            ->whereBetween('date', [$startDate, $endDate])
            ->select('created_at','id', 'date', 'payment_number', 'amount', 'unused_amount', 'note', DB::raw("'creditRefund' as type"))
            ->orderBy('date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $invoices = PurchaseInvoice::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [4,7])//approved, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->select('created_at','invoice_date as date', 'invoice_number', 'due_date', 'total', 'terms_conditions', DB::raw("'invoice' as type"))
            ->orderBy('invoice_date')
            ->orderBy('created_at')
            ->get()
            ->toArray();
        $creditNote = DebitNote::where('organization_id', $orgID)
            ->where('partner_id', $id)
            ->whereIn('status', [8, 9])//open, paid
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->select('created_at','invoice_date as date', 'invoice_number', 'total', 'due_date', 'terms_conditions', DB::raw("'creditNote' as type"))
            ->orderBy('invoice_date')
            ->orderBy('created_at')
            ->get()
            ->toArray();



        $transactions = array_merge($paymentReceived,  $invoices, $creditNote, $paymentRefund, $creditRefund);

        $transactions = collect($transactions)->sortBy([
            ['date', 'asc'],
            ['created_at', 'asc']
        ]);
        return [
            'paymentReceivedSum' => $paymentReceivedSum,
            'invoiceSum' => $invoiceSum,
            'transactions' => $transactions,

        ];
    }
//    public function listPayables(){
//        $organizationId = 1;
//        $payables = Partner::where('partner_type', 1)
//            ->where('organization_id', $organizationId)
//            ->where('deleted', 0)
//            ->orderBy('id', 'asc')
//            ->select('id', 'display_name', 'ar_display_name','payment_terms')
//            ->get();
//        $content = '';
//        foreach($payables as $payable){
//            $content .= '<option value="'.$payable->id.'" data-term="'.$payable->payment_terms.'">'.$payable->display_name.'</option>';
//        }
//        return response()->json([
//            'options' => $content
//        ]);
//    }
}
