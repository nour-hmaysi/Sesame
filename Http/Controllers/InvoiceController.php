<?php

namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\CreditApplied;
use App\CreditNote;
use App\DebitApplied;
use App\DebitNote;
use App\AccountType;
use App\InvoiceFiles;
use App\InvoiceHasItems;
use App\InvoiceType;
use App\Item;
use App\ObPartners;
use App\Partner;
use App\PaymentTerms;
use App\Project;
use App\PurchaseInvoice;
use App\PurchaseOrder;
use App\Quotation;
use App\SalesInvoice;
use App\SalesOrder;
use App\Tax;
use App\Transaction;
use App\TransactionDocuments;
use App\TransactionType;
use Faker\Provider\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\ValidationException;
use Sabberworm\CSS\Rule\Rule;
use Symfony\Component\Finder\Glob;

class InvoiceController extends Controller
{
//    store customer invoices
    public function store(Request $request)
    {
        $currencyName = currencyName();
        $invoice_type_id = $request->invoice_type;
        $type = InvoiceType::find($invoice_type_id);
        $invoice_type_name = $type->name;
        if ($invoice_type_id == 1) {
            $tableName = 'quotation';
        } else if ($invoice_type_id == 3) {
            $tableName = 'sales_invoice';
        } else if ($invoice_type_id == 2) {
            $tableName = 'sales_order';
        } else if ($invoice_type_id == 6) {
            $tableName = 'credit_note';
        }
        $rules = [
            'invoice_number' => [
                'required',
                'string',
                'max:191',
                \Illuminate\Validation\Rule::unique($tableName)->where(function ($query) use ($request) {
                    return $query->where('organization_id', orgID());
                }),
            ],
            'partner_id'     => 'required|integer',
            'invoice_date'   => 'required|date',
            'item_name'      => ['required', 'array', 'min:1', function ($attribute, $value, $fail) use ($request) {
                // Loop through rows and check if they are empty
                for($index = 0 ; $index <= count($value) ; $index++) {
                    // Check if the row is fully empty
                    $itemName = $request->input('item_name')[$index] ?? null;
                    $quantity = $request->input('quantity')[$index] ?? null;
                    $rate = $request->input('rate')[$index] ?? null;
                    $tax = $request->input('tax')[$index] ?? null;
                    $discount = $request->input('discount')[$index] ?? null;

                    if (empty($itemName) && empty($quantity) && empty($rate) && empty($tax) && empty($discount)) {
                        // Skip empty rows
                        continue;
                    }

                    // If the row is not fully empty, validate required fields
                    if (empty($itemName)) {
                        $fail("The item name for all items is required.");
                    }
                    if (empty($quantity)) {
                        $fail("The quantity for all items is required.");
                    }
                    if (empty($rate)) {
                        $fail("The rate for all items is required.");
                    }
                }
            }],
            'quantity.*'     => 'nullable|numeric|min:1',
            'rate.*'         => 'nullable|numeric|min:1',
            'unit.*'         => 'nullable',
            'discount.*'     => 'nullable|numeric|min:0|max:100',
        ];
        $messages = [
            'invoice_number.unique' => 'The invoice number is already in use.',
            'item_name.*.required' => 'Item details are required.',

        ];
        if (!isset($request->item_name) || count(array_filter($request->item_name)) < 1
            || !isset($request->coa_id) || count(array_filter($request->coa_id)) < 1) {
            return redirect()->back()->withInput()->with('error', 'Fill the table with items.');
        }
        if ($request->total < 1) {
            throw ValidationException::withMessages([
                'total' => ['The amount cannot be zero.'],
            ]);
        }
        if (validateVATDate($request->invoice_date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }
        if(!$request->partner_id){
            return redirect()->back()->withInput()->with('error', 'Choose a client.');
        }
        $validatedData = $request->validate($rules, $messages);

        $referenceExist = checkReferenceExists($request->order_number);
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }

        $requestData = $request->all();

        if ($invoice_type_id == 1) {
            $invoice = Quotation::create($requestData);
        } else if ($invoice_type_id == 3) {
            $invoice = SalesInvoice::create($requestData);
        } else if ($invoice_type_id == 2) {
            $invoice = SalesOrder::create($requestData);
        } else if ($invoice_type_id == 6) {
            $invoice = CreditNote::create($requestData);
        }

        $comment =  $type->display_name.' created for '.$currencyName.' '.$request->total;
        $title = $type->display_name.' Created';

        $invoice_id = $invoice->id;
        GlobalController::InsertNewComment($invoice_type_id, $invoice_id,NULL, $comment);
        GlobalController::InsertNewComment(11, $request->partner_id, $title ,$comment);

        $totalTaxableAmount = $totalNonTaxableAmount = 0;
//        return $request->items;
        if (isset($request->item_name)) {
            for($index = 0; $index < count($request->item_name); $index++) {
                if ($request->item_name[$index]) {
                    $invoiceItem = new InvoiceHasItems();
                    $invoiceItem->invoice_id = $invoice_id;
                    $invoiceItem->invoice_type_id = $invoice_type_id;
                    $invoiceItem->item_id = $request->items[$index];
                    $invoiceItem->item_name = $request->item_name[$index];
                    $invoiceItem->item_description = $request->item_description[$index];
                    $invoiceItem->quantity = $request->quantity[$index];
                    $invoiceItem->rate = $request->rate[$index];
                    $invoiceItem->sale_price = $request->sale_price[$index] ? $request->sale_price[$index] : $request->rate[$index];
                    $invoiceItem->amount = $request->amount[$index];
                    $invoiceItem->final_amount = $request->final_amount[$index];
                    $invoiceItem->discount = $request->discount[$index] ?: 0;
                    $invoiceItem->unit = $request->unit[$index] ?? NULL;
                    $invoiceItem->tax = $request->tax[$index] ?? NULL;
                    $invoiceItem->coa_id = $request->coa_id[$index] ?? GetDefaultSalesAccount();
                    $invoiceItem->save();
                    $taxValue = getTaxValue($invoiceItem->tax);
                    if($taxValue > 0){
                        $totalTaxableAmount += $request->final_amount[$index];
                    }else{
                        $totalNonTaxableAmount += $request->final_amount[$index];
                    }
                }
            }

        }
        if ($request->hasFile('files')) {
            $fileNamesToStore = [];
            foreach ($request->file('files') as $file) {
                $fileName = uploadFile($file, 'invoices');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new InvoiceFiles();
                $invoiceFile->invoice_id = $invoice_id;
                $invoiceFile->invoice_type_id = $invoice_type_id;
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }
        $invoice->taxable_amount = $totalTaxableAmount;
        $invoice->non_taxable_amount = $totalNonTaxableAmount;
        $invoice->save();
//        return redirect()->back()->with('success', 'Record has been created successfully.');
        return redirect()->route('InvoiceController.showInvoice', [$invoice_type_name.'#row-' . $invoice_id]);


    }
//    save purchase invoice/order
    public function storePurchase(Request $request)
    {
        $currencyName = currencyName();
        $invoice_type_id = $request->invoice_type;
        $type = InvoiceType::find($invoice_type_id);
        $invoice_type_name = $type->name;

        if ($invoice_type_id == 4) {
            $tableName = 'purchase_invoice';
        } else if ($invoice_type_id == 5) {
            $tableName = 'purchase_order';
        } else if ($invoice_type_id == 7) {
            $tableName = 'debit_note';
        }
        $rules = [

            'partner_id'     => 'required|integer',
            'invoice_date'   => 'required|date',
            'item_name'      => ['required', 'array', 'min:1', function ($attribute, $value, $fail) use ($request) {
                // Loop through rows and check if they are empty
                for($index = 0 ; $index <= count($value) ; $index++) {
                    // Check if the row is fully empty
                    $itemName = $request->input('item_name')[$index] ?? null;
                    $quantity = $request->input('quantity')[$index] ?? null;
                    $rate = $request->input('rate')[$index] ?? null;
                    $tax = $request->input('tax')[$index] ?? null;
                    $discount = $request->input('discount')[$index] ?? null;

                    if (empty($itemName) && empty($quantity) && empty($rate) && empty($tax) && empty($discount)) {
                        // Skip empty rows
                        continue;
                    }

                    // If the row is not fully empty, validate required fields
                    if (empty($itemName)) {
                        $fail("The item name for all items is required.");
                    }
                    if (empty($quantity)) {
                        $fail("The quantity for all items is required.");
                    }
                    if (empty($rate)) {
                        $fail("The rate for all items is required.");
                    }
                }
            }],
            'quantity.*'     => 'nullable|numeric|min:1',
            'rate.*'         => 'nullable|numeric|min:1',
            'unit.*'         => 'nullable',
            'discount.*'     => 'nullable|numeric|min:0|max:100',
        ];
        $messages = [
            'item_name.*.required' => 'Item details are required.',

        ];
        if (!isset($request->item_name) || count(array_filter($request->item_name)) < 1
            || !isset($request->coa_id) || count(array_filter($request->coa_id)) < 1) {
            return redirect()->back()->withInput()->with('error', 'Fill the table with items.');
        }
        if ($request->total < 1) {
            throw ValidationException::withMessages([
                'total' => ['The amount cannot be zero.'],
            ]);
        }
        if (validateVATDate($request->invoice_date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }
        if(!$request->partner_id){
            return redirect()->back()->withInput()->with('error', 'Choose a supplier.');
        }
        $validatedData = $request->validate($rules, $messages);


        $referenceExist = checkReferenceExists($request->order_number);
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }
        $requestData = $request->all();
        $requestData['taxable_amount'] = $request->original_amount - $request->discount_amount;

        if ($invoice_type_id == 4) {
            $invoice = PurchaseInvoice::create($requestData);
        } else if ($invoice_type_id == 5) {
            $invoice = PurchaseOrder::create($requestData);
        } else if ($invoice_type_id == 7) {
            $invoice = DebitNote::create($requestData);
        }

        $comment =  $type->display_name.' created for '.$currencyName.' '.$request->total;
        $title = $type->display_name.' Created';

        $invoice_id = $invoice->id;
        GlobalController::InsertNewComment($invoice_type_id, $invoice_id, $title, $comment);
        GlobalController::InsertNewComment(12, $request->partner_id, $title ,$comment);
        $totalTaxableAmount = $totalNonTaxableAmount = 0;

//        return $request->items;
        if (isset($request->item_name)) {
            for ($index = 0; $index < count($request->item_name); $index++) {
                if ($request->item_name[$index]) {
                    $invoiceItem = new InvoiceHasItems();
                    $invoiceItem->invoice_id = $invoice_id;
                    $invoiceItem->invoice_type_id = $invoice_type_id;
                    $invoiceItem->item_id = $request->items[$index];
                    $invoiceItem->item_name = $request->item_name[$index];
                    $invoiceItem->item_description = $request->item_description[$index];
                    $invoiceItem->quantity = $request->quantity[$index];
                    $invoiceItem->rate = $request->rate[$index];
                    $invoiceItem->sale_price = $request->sale_price[$index] ? $request->sale_price[$index] : $request->rate[$index];
                    $invoiceItem->amount = $request->amount[$index];
                    $invoiceItem->final_amount = $request->final_amount[$index];
                    $invoiceItem->discount = $request->discount[$index];
                    $invoiceItem->unit = $request->unit[$index] ?? NULL;
                    $invoiceItem->tax = $request->tax[$index] ?? NULL;
                    $invoiceItem->coa_id = $request->coa_id[$index] ?? GetDefaultPurchaseAccount();;
                    $invoiceItem->save();
                    $taxValue = getTaxValue($invoiceItem->tax);
                    if($taxValue > 0){
                        $totalTaxableAmount += $request->final_amount[$index];
                    }else{
                        $totalNonTaxableAmount += $request->final_amount[$index];
                    }
                }
            }
        }
        if ($request->hasFile('files')) {
            $fileNamesToStore = [];
            foreach ($request->file('files') as $file) {

                $fileName = uploadFile($file, 'invoices');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new InvoiceFiles();
                $invoiceFile->invoice_id = $invoice_id;
                $invoiceFile->invoice_type_id = $invoice_type_id;
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }
        $invoice->taxable_amount = $totalTaxableAmount;
        $invoice->non_taxable_amount = $totalNonTaxableAmount;
        $invoice->save();
        return redirect()->route('InvoiceController.showInvoice', [$invoice_type_name.'#row-' . $invoice_id]);

//        return redirect()->back()->with('success', 'Record has been created successfully.');

    }
//create customer invoice
    public function create($invoiceLinkName)
    {
        $organizationId = org_id();
        $invoice = InvoiceType::where('name', 'like', '%' . $invoiceLinkName . '%')->first();
        $invoiceType = $invoice->id;
        $invoiceName = $invoice->display_name;
        $receivables = Customers();
        $payables = Suppliers();
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $projects = Projects();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $saleAccounts = GetSalesAccountIds();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();

        if ($invoiceType == 1) {
            $invoiceCount = Quotation::generateInvoiceNumber();
        } else if ($invoiceType == 3) {
            $invoiceCount = SalesInvoice::generateInvoiceNumber();
        } else if ($invoiceType == 2) {
            $invoiceCount = SalesOrder::generateInvoiceNumber();
        } else if ($invoiceType == 6) {
            $invoiceCount= CreditNote::generateInvoiceNumber();;
        }

        return view('pages.invoice.customer.create', compact(['invoiceCount','receivables', 'payables', 'items', 'projects', 'taxes', 'saleAccounts', 'invoiceType', 'invoiceName', 'paymentTerms']));
    }

    //create customer credit note by invoice id
    public function createCnByInvId($invoice_id)
    {
        $organizationId = org_id();
        $invoice_id = Crypt::decryptString($invoice_id);
        $invoice = SalesInvoice::findorfail($invoice_id);
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', 3)
            ->get();
        $receivables = Partner::where('partner_type', 2)
            ->where('organization_id', $organizationId)
            ->where('id', $invoice->partner_id)
            ->where('deleted', 0)
            ->get();
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $projects = Project::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $saleAccounts = GetSalesAccountIds();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
        $invoiceCount= CreditNote::generateInvoiceNumber();;

        return view('pages.invoice.customer.createcn', compact([ 'invoiceCount','items', 'projects', 'taxes', 'saleAccounts', 'paymentTerms', 'invoiceItems', 'invoice']));
    }
    //create customer credit note by invoice id
    public function createDnByInvId($invoice_id)
    {
        $organizationId = org_id();
        $invoice_id = Crypt::decryptString($invoice_id);
        $invoice = PurchaseInvoice::findorfail($invoice_id);
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', 4)
            ->get();
        $receivables = Partner::where('partner_type', 1)
            ->where('organization_id', $organizationId)
            ->where('id', $invoice->partner_id)
            ->where('deleted', 0)
            ->get();
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $projects = Project::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $saleAccounts = ExpenseAccounts();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
        return view('pages.invoice.payable.createdn', compact([ 'items', 'projects', 'taxes', 'saleAccounts', 'paymentTerms', 'invoiceItems', 'invoice']));
    }
//    create payable invoice
    public function createPurchase($invoiceLinkName)
    {
        $organizationId = org_id();
        $invoice = InvoiceType::where('name', 'like', '%' . $invoiceLinkName . '%')->first();
        $invoiceType = $invoice->id;
        $invoiceName = $invoice->display_name;
        $payables = Suppliers();
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $projects = Projects();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $expenseAccounts = ExpenseAccounts();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
//        $currentInvoiceCount = 0;
//        $counter = 0;
//        $invoiceCount = 1;
//        do {
//            $counter += 1;
//            $currentInvoiceCount = DB::table($invoiceLinkName.' as A')
//                ->where('A.organization_id', $organizationId)
//                ->where('A.deleted', 0)
//                ->count();
//            if($currentInvoiceCount > 0){
//                $invoiceCount = $currentInvoiceCount + $counter;
//                $invoiceExist = DB::table($invoiceLinkName.' as A')
//                    ->where('A.organization_id', $organizationId)
//                    ->where('A.invoice_number', $invoiceCount)
//                    ->where('A.deleted', 0)
//                    ->exists();
//            }else{
//                $invoiceExist = false;
//            }
//        } while ($invoiceExist);

        $invoiceCount='';
        if ($invoiceType == 4) {
            $invoiceCount = PurchaseInvoice::generateInvoiceNumber();
        } else if ($invoiceType == 5) {
            $invoiceCount = PurchaseOrder::generateInvoiceNumber();
        } else if ($invoiceType == 7) {
            $invoiceCount = DebitNote::generateInvoiceNumber();
        }
        return view('pages.invoice.payable.create', compact(['invoiceCount','payables', 'items', 'projects', 'taxes', 'expenseAccounts', 'invoiceType', 'invoiceName', 'paymentTerms']));
    }

//    show index table depends on the type (quotation, order, invoice, purchase order, purchase invoice)
    public function showInvoice($type)
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
            ->get();
        if($invoiceTypeId == 4 || $invoiceTypeId == 5 || $invoiceTypeId == 7){
            return view('pages.invoice.payable.index', compact(['invoices', 'invoiceTypeId', 'invoiceName', 'invoiceLinkName', 'invoiceTableName']));
        }else{
            return view('pages.invoice.customer.index', compact(['invoices', 'invoiceTypeId', 'invoiceName', 'invoiceLinkName', 'invoiceTableName']));
        }
    }
    public function getInvoiceJournal($invoice_id, $invoice_type_id){
        $organizationId = org_id();
        $invoiceJournal = Transaction::with(['TransactionInvoice', 'TransactionDetails'])
            ->where('organization_id', $organizationId)
            ->whereHas('TransactionInvoice', function ($query)use ($invoice_id, $invoice_type_id) {
                $query->where('invoice_id', $invoice_id)
                    ->where('invoice_type_id', $invoice_type_id);
            })
            ->get()
            ->last();
        $content='';
        $creditTotal = $debitTotal = 0;
        foreach ($invoiceJournal->TransactionDetails as $detail) {
            $detail->is_debit === 1 ? $debitTotal += $detail->amount : $creditTotal += $detail->amount;
            $content .= '<tr><td>'.$detail->account->name.'</td>';
            $content .= '<td>'.($detail->is_debit === 1 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</td>';
            $content .= '<td>'.($detail->is_debit === 0 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</td></tr>';
        }
        $content .= '<tr><td></td><td>'.number_format($debitTotal, 2, '.', ',').'</td><td>'.number_format($creditTotal, 2, '.', ',').'</td></tr>';
        return $content;
    }
    public function updateInvoiceStatus( Request $request, $id)
    {
        $org_id = org_id();
        $currentDate = \Carbon\Carbon::now();
        try {
            $isTransaction = false;
            $status = $request->input('status');
            $invoice_type_id = $request->input('type_id');

            $organizationId = org_id();
            $currencyName = currencyName();

            if ($invoice_type_id == 1) {
                $invoice = Quotation::findOrFail($id);
            } else if ($invoice_type_id == 2) {
                $invoice = SalesOrder::findOrFail($id);
            } else if ($invoice_type_id == 3) {
                $invoice = SalesInvoice::findOrFail($id);
                $isTransaction = true;
            } else if ($invoice_type_id == 4) {
                $invoice = PurchaseInvoice::findOrFail($id);
                $isTransaction = true;
            } else if ($invoice_type_id == 5) {
                $invoice = PurchaseOrder::findOrFail($id);
            } else if ($invoice_type_id == 6) {
                $invoice = CreditNote::findOrFail($id);
                $isTransaction = true;
            } else if ($invoice_type_id == 7) {
                $invoice = DebitNote::findOrFail($id);
                $isTransaction = true;
            }

            if($isTransaction){
                if (validateVATDate($invoice->invoice_date)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => errorMsg(),
                    ]);
                }
//                if status is approved, make the accounting entry
                if($status == 4 || $status == 8){
                    $receivableAccount = GetReceivableAccount();
                    $payableAccount = GetPayableAccount();
                    $outputVATAccount = GetOutputVATAccount();
                    $inputVATAccount = GetInputVATAccount();
                    $discountAccount = GetDiscountAccount();
                    $costDiscountAccount = GetCostDiscountAccount();
                    $retentionAccount = GetRetentionAccount();
                    $advPaymentAccount = GetAdvPaymentAccount();
                    $transactionDescription = $invoice->terms_conditions;
//                    total amount with vat to be paid by the customer
                    $transactionAmount = $invoice->total;
                    $transactionTotalAmount = $invoice->original_amount + $invoice->vat_amount;
//                    total vat amount
                    $transactionVatAmount = $invoice->vat_amount;
//                    amount without vat to be deducted from the sale account
                    $transactionNoVatAmount = $invoice->original_amount;
                    $transactionCurrency = $invoice->currency;
                    $taxableAmount = $invoice->taxable_amount;
                    $nontaxableAmount = $invoice->non_taxable_amount;
                    $reference_number = $invoice->order_number;
//                    1 is inclusive, 0 is exclusive
                    $isVat = $invoice->is_tax;
                    $items = InvoiceHasItems::where('invoice_id', $id)
                        ->where('invoice_type_id', $invoice_type_id)
                        ->get();
                    $itemsDetails = [];
                    $sumsBySaleAccount = [];
//                    insert transaction

                    if($invoice_type_id == 6){
//                        if credit note, trans type is refund
                        $request1['transaction_type_id'] = 7;
                    }else if($invoice_type_id == 7){
//                        if debit note, trans type is refund
                        $request1['transaction_type_id'] = 14;
                    }else if($invoice_type_id == 4){
//                        if bill, trans type is bill
                        $request1['transaction_type_id'] = 21;
                    }else{
                        // transaction type transaction
                        $request1['transaction_type_id'] = 5;
                    }
                    $request1['amount'] = $transactionTotalAmount;
                    $request1['currency'] = $transactionCurrency;
                    $request1['description'] = $transactionDescription;
                    $request1['taxable_amount'] = $taxableAmount;
                    $request1['non_taxable_amount'] = $nontaxableAmount;
                    $request1['reference_number'] = $reference_number;
                    $request1['paid_by_id'] = NULL;
                    $request1['date'] = $invoice->invoice_date;
                    $transaction = GlobalController::InsertNewTransaction($request1);

                    $invoiceRequest['transaction_id'] = $transaction;
                    $invoiceRequest['invoice_id'] = $id;
                    $invoiceRequest['invoice_type_id'] = $invoice_type_id;
                    GlobalController::InsertNewTransactionInvoice($invoiceRequest);

                    if($invoice_type_id == 3){
//                        Invoice
                        //  transaction Details for OUTPUT VAT

                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $transactionVatAmount;
                            $request2['account_id'] = $outputVATAccount;
                            $request2['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($request2);


//                    transaction Details for Receivable
                        $request3['transaction_id'] = $transaction;
                        $request3['amount'] = $transactionAmount;
                        $request3['account_id'] = $receivableAccount;
                        $request3['is_debit'] = 1;
                        GlobalController::InsertNewTransactionDetails($request3);
                        if($invoice->retention_amount > 0){
//                    transaction Details for Retention
                            $retention['transaction_id'] = $transaction;
                            $retention['amount'] = $invoice->retention_amount;
                            $retention['account_id'] = $retentionAccount;
                            $retention['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($retention);
                        }
                        if($invoice->adv_amount > 0){
//                    transaction Details for Retention
                            $advPayment['transaction_id'] = $transaction;
                            $advPayment['amount'] = $invoice->adv_amount;
                            $advPayment['account_id'] = $advPaymentAccount;
                            $advPayment['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($advPayment);
                        }
                        if($invoice->discount_amount > 0){
//                    transaction Details for discount
                            $discount['transaction_id'] = $transaction;
                            $discount['amount'] = $invoice->discount_amount;
                            $discount['account_id'] = $discountAccount;
                            $discount['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($discount);
                        }
                    }else    if($invoice_type_id == 6){
//                        Credit Note

                            // transaction Details for OUTPUT VAT
                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $transactionVatAmount;
                            $request2['account_id'] = $outputVATAccount;
                            $request2['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($request2);

//                    transaction Details for Receivable
                        $request3['transaction_id'] = $transaction;
                        $request3['amount'] = $transactionAmount;
                        $request3['account_id'] = $receivableAccount;
                        $request3['is_debit'] = 0;
                        GlobalController::InsertNewTransactionDetails($request3);
//                    transaction Details for discount
                        if($invoice->discount_amount > 0){
                            $discount['transaction_id'] = $transaction;
                            $discount['amount'] = $invoice->discount_amount;
                            $discount['account_id'] = $discountAccount;
                            $discount['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($discount);
                        }
                        if($invoice->retention_amount > 0){
//                    transaction Details for Retention
                            $retention['transaction_id'] = $transaction;
                            $retention['amount'] = $invoice->retention_amount;
                            $retention['account_id'] = $retentionAccount;
                            $retention['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($retention);
                        }
                        if($invoice->adv_amount > 0){
//                    transaction Details for Retention
                            $advPayment['transaction_id'] = $transaction;
                            $advPayment['amount'] = $invoice->adv_amount;
                            $advPayment['account_id'] = $advPaymentAccount;
                            $advPayment['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($advPayment);
                        }
                }else if($invoice_type_id == 4){
//                        BILL/ PURCHASE Invoice

                            // transaction Details for INPUT VAT
                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $transactionVatAmount;
                            $request2['account_id'] = $inputVATAccount;
                            $request2['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($request2);

                        if($invoice->discount_amount > 0){
//                    transaction Details for discount
                            $discount['transaction_id'] = $transaction;
                            $discount['amount'] = $invoice->discount_amount;
                            $discount['account_id'] = $costDiscountAccount;
                            $discount['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($discount);
                        }
//                    transaction Details for Payable
                        $request3['transaction_id'] = $transaction;
                        $request3['amount'] = $transactionAmount;
                        $request3['account_id'] = $payableAccount;
                        $request3['is_debit'] = 0;
                        GlobalController::InsertNewTransactionDetails($request3);
                    }else if($invoice_type_id == 7){
//                        Debit note

                            // transaction Details for INPUT VAT
                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $transactionVatAmount;
                            $request2['account_id'] = $inputVATAccount;
                            $request2['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($request2);

                        if($invoice->discount_amount > 0){
//                    transaction Details for discount
                            $discount['transaction_id'] = $transaction;
                            $discount['amount'] = $invoice->discount_amount;
                            $discount['account_id'] = $costDiscountAccount;
                            $discount['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($discount);
                        }
//                    transaction Details for Payable
                        $request3['transaction_id'] = $transaction;
                        $request3['amount'] = $transactionAmount;
                        $request3['account_id'] = $payableAccount;
                        $request3['is_debit'] = 1;
                        GlobalController::InsertNewTransactionDetails($request3);
                    }
                    $totalDiscountAmount = 0;
                    foreach ($items as $item){
//                        update the item quantity
                        if($item->item_id > 0){
                            $itemRecord = Item::find($item->item_id);
                            if ($itemRecord && $itemRecord->track_inventory == 1) {
                                if($invoice_type_id == 6 ||  $invoice_type_id == 4){
                                    // if credit note or purchase made, return the quantity of item
                                    $itemRecord->stock_quantity += $item->quantity;
                                }else{
                                    $itemRecord->stock_quantity -= $item->quantity;
                                }
                                $itemRecord->save();
                            }
                        }

                        $original_amount = $item->sale_price;

                        $itemsDetails[] = [
                            'item_id' => $item->id,
                            'item_amount' => $item->amount,
                            'item_original_amount' => $original_amount,
                            'item_qty' => $item->quantity,
                            'item_vat' => $item->tax,
                            'item_sale_account' => $item->coa_id,
                        ];

                        // Sum the total original amounts by sale account
                        if (!isset($sumsBySaleAccount[$item->coa_id])) {
                            $sumsBySaleAccount[$item->coa_id] = 0;
                        }
                        $sumsBySaleAccount[$item->coa_id] += $original_amount;
                    }
                    foreach ($sumsBySaleAccount as $account => $sum) {
//                    transaction Details for Sales Account
                        $request['transaction_id'] = $transaction;
                        $request['amount'] = $sum;
                        $request['account_id'] = $account;
                        if($invoice_type_id == 3 || $invoice_type_id == 7 ){
                            $request['is_debit'] = 0;
                        }else if($invoice_type_id == 4 || $invoice_type_id == 6 ){
                            $request['is_debit'] = 1;
                        }
                        GlobalController::InsertNewTransactionDetails($request);
                    }
//                if credit note update amount due of the invoice
                    if($invoice_type_id == 6){
                        if($invoice->invoice_id){
                            $relatedInvoice = SalesInvoice::where('id', $invoice->invoice_id)->first();
                            $relatedInv_due = $relatedInvoice->amount_due;
                            if($relatedInv_due > 0){
                                if($transactionAmount >= $relatedInv_due){
                                    $deductedAmount = $relatedInv_due;
                                }else{
                                    $deductedAmount = $transactionAmount;
                                }
                                if($relatedInvoice->amount_due - $deductedAmount == 0){
                                    $relatedInvoice->status = 7;//paid
                                }
                                if($invoice->amount_due - $deductedAmount == 0){
                                    $status = 9;//closed
                                }
                                $relatedInvoice->amount_due -= $deductedAmount;
                                $relatedInvoice->amount_received += $deductedAmount;

                                $invoice->amount_due -= $deductedAmount;
                                $invoice->amount_received += $deductedAmount;

                                CreditApplied::create([
                                    'credit_id' => $invoice->id,
                                    'invoice_id' => $relatedInvoice->id,
                                    'date' => $invoice->invoice_date,
                                    'amount' => $deductedAmount,
                                    'is_creditnote' => 1,
                                ]);

                                $comment =  'Credit Note '.$invoice->invoice_number.' created from invoice';
                                GlobalController::InsertNewComment(3, $relatedInvoice->id, NULL, $comment);

                                $comment =  'Credit of '.currencyName().$deductedAmount.' Applied from'.$invoice->invoice_number;
                                GlobalController::InsertNewComment(3, $relatedInvoice->id, NULL, $comment);

                                $comment =  'Credit Note created for '.currencyName().$transactionAmount.'from invoice';
                                GlobalController::InsertNewComment(6, $invoice->id, NULL, $comment);

                                $comment =  'Credit of '.currencyName().$deductedAmount.' Applied for'.$relatedInvoice->invoice_number;
                                GlobalController::InsertNewComment(6, $invoice->id, NULL, $comment);

                                $relatedInvoice->save();
                            }
                        }

                    }
//                if debit note update amount due of the bill
                    if($invoice_type_id == 7){
                        if($invoice->invoice_id){
                            $relatedInvoice = PurchaseInvoice::where('id', $invoice->invoice_id)->first();
                            $relatedInv_due = $relatedInvoice->amount_due;
                            if($relatedInv_due > 0){
                                if($transactionAmount >= $relatedInv_due){
                                    $deductedAmount = $relatedInv_due;

                                }else{
                                    $deductedAmount = $transactionAmount;
                                }

                                if($relatedInvoice->amount_due - $deductedAmount == 0){
                                    $relatedInvoice->status = 7;//paid
                                }
                                if($invoice->amount_due - $deductedAmount == 0){
                                    $status = 9;//closed
                                }
                                $relatedInvoice->amount_due -= $deductedAmount;
                                $relatedInvoice->amount_received += $deductedAmount;

                                $invoice->amount_due -= $deductedAmount;
                                $invoice->amount_received += $deductedAmount;

                                DebitApplied::create([
                                    'debit_id' => $invoice->id,
                                    'invoice_id' => $relatedInvoice->id,
                                    'date' => $invoice->invoice_date,
                                    'amount' => $deductedAmount,
                                    'is_creditnote' => 1,
                                ]);
                                $comment =  'Debit Note '.$invoice->invoice_number.' created from purchase invoice';
                                GlobalController::InsertNewComment(4, $relatedInvoice->id, NULL, $comment);

                                $comment =  'Debit of '.currencyName().$deductedAmount.' applied from'.$invoice->invoice_number;
                                GlobalController::InsertNewComment(4, $relatedInvoice->id, NULL, $comment);

                                $comment =  'Debit Note created for '.currencyName().$transactionAmount.'from purchase invoice';
                                GlobalController::InsertNewComment(7, $invoice->id, NULL, $comment);

                                $comment =  'Debit of '.currencyName().$deductedAmount.' applied for'.$relatedInvoice->invoice_number;
                                GlobalController::InsertNewComment(7, $invoice->id, NULL, $comment);

                                $relatedInvoice->save();
                            }
                        }

                    }
                }

            }

            $invoice->status = $status;
            $invoice->save();
            $comment =  'Status Changed to '.GlobalController::GetStatus($status);
            GlobalController::InsertNewComment($invoice_type_id, $invoice->id, NULL, $comment);

            return response()->json([
                'status' => 'success',
                'message' => 'Status Updated Successfully',
                ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'The selected document not found'], 404);
        }
    }
    public function convertInvoiceTo($invoice_id, $invoice_type_id, $convert_to)
    {
        $organizationId = org_id();
        $id = $invoice_id;
        $type = InvoiceType::find($invoice_type_id);
        $invoiceType = $convert_to;
        $convertTo = InvoiceType::find($invoiceType);
        $invoiceLinkName = $convertTo->name;
        $invoiceName = $convertTo->display_name;
        if ($invoice_type_id == 1) {
            $invoice = Quotation::findOrFail($id);
        } else if ($invoice_type_id == 2) {
            $invoice = SalesOrder::findOrFail($id);
        } else if ($invoice_type_id == 3) {
            $invoice = SalesInvoice::findOrFail($id);
        }
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoice_type_id)
            ->get();
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $projects = Project::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $saleAccounts = GetSalesAccountIds();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
        $currentInvoiceCount = 0;
        $counter = 0;
        $invoiceCount = 1;

        do {
            $counter += 1;
            $currentInvoiceCount = DB::table($invoiceLinkName.' as A')
                ->where('A.organization_id', $organizationId)
                ->where('A.deleted', 0)
                ->count();
            if($currentInvoiceCount > 0){
                $invoiceCount = $currentInvoiceCount + $counter;
                $invoiceExist = DB::table($invoiceLinkName.' as A')
                    ->where('A.organization_id', $organizationId)
                    ->where('A.invoice_number', $invoiceCount)
                    ->where('A.deleted', 0)
                    ->exists();
            }else{
                $invoiceExist = false;
            }
        } while ($invoiceExist);

        if($invoiceType == 3){
            $invoiceCount = SalesInvoice::generateInvoiceNumber();
        }
        return view('pages.invoice.customer.copy', compact([ 'invoiceCount','invoiceType','invoiceName','items', 'projects', 'taxes', 'saleAccounts', 'paymentTerms', 'invoiceItems', 'invoice']));


//        try {
//            $invoice_type_id = $request->input('type_id');
//            $convert_to = $request->input('convert_to');
//            $organizationId = org_id();
//            if ($invoice_type_id == 1) {
//                $invoice = Quotation::findOrFail($id);
//            } else if ($invoice_type_id == 2) {
//                $invoice = SalesOrder::findOrFail($id);
//            }
//            $invoice_id = $invoice->id;
//
//            if ($convert_to == 2) {
//                $newInvoice = new SalesOrder();
//                $comment = "Converted to sales order";
//            } else if ($convert_to == 3) {
//                $newInvoice = new SalesInvoice();
//                $comment = "Converted to sales invoice";
//            }
//            $newInvoice->fill($invoice->getAttributes());
//
//            GlobalController::InsertNewComment($invoice_type_id, $invoice_id, NULL, $comment);
//
////      $newInvoice->updated_by = Auth::id();
//            $newInvoice->quotation_id = $invoice_id;
//            $newInvoice->invoice_type = $convert_to;
//            $newInvoice->deleted = 1;
//            $newInvoice->created_by = 2;
//            $newInvoice->save();
//            $newInvoiceId = $newInvoice->id;
////            duplicate the items
//            $invoiceItems = InvoiceHasItems::where('invoice_id', $invoice_id)
//                ->where('invoice_type_id', $invoice_type_id)
//                ->get();
//            foreach ($invoiceItems as $item) {
//                $newItem = $item->replicate();
//                $newItem->invoice_id = $newInvoiceId;
//                $newItem->invoice_type_id = $convert_to;
//                $newItem->save();
//            }
//
//
////            duplicate the files
//            $invoiceFiles = InvoiceFiles::where('invoice_id', $invoice_id)
//                ->where('invoice_type_id', $invoice_type_id)
//                ->get();
//            foreach ($invoiceFiles as $files) {
//                $files = $item->replicate();
//                $files->invoice_id = $newInvoiceId;
//                $files->invoice_type_id = $convert_to;
//                $files->save();
//            }
//
//            $encryptedid = Crypt::encryptString($newInvoice->id . '-' . $convert_to);
//            return response()->json([
//                'message' => 'The selected document has been converted.',
//                'invoice_id' => $encryptedid
//            ]);
//
//        } catch (ModelNotFoundException $e) {
//            return response()->json(['error' => 'The selected document not found'], 404);
//        }
    }
    public function convertPurchaseTo($invoice_id, $invoice_type_id, $convert_to)
    {
        $organizationId = org_id();
        $id = $invoice_id;
        $type = InvoiceType::find($invoice_type_id);
        $invoiceType = $convert_to;
        $convertTo = InvoiceType::find($invoiceType);
        $invoiceLinkName = $convertTo->name;
        $invoiceName = $convertTo->display_name;
        if ($invoice_type_id == 4) {
            $invoice = PurchaseInvoice::findOrFail($id);
        } else if ($invoice_type_id == 5) {
            $invoice = PurchaseOrder::findOrFail($id);
        }
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoice_type_id)
            ->get();
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $projects = Project::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $expenseAccounts = ExpenseAccounts();

        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();


        return view('pages.invoice.payable.copy', compact([ 'invoiceType','invoiceName','items', 'projects', 'taxes', 'expenseAccounts', 'paymentTerms', 'invoiceItems', 'invoice']));


//        try {
//            $invoice_type_id = $request->input('type_id');
//            $convert_to = $request->input('convert_to');
//            $organizationId = org_id();
//            if ($invoice_type_id == 1) {
//                $invoice = Quotation::findOrFail($id);
//            } else if ($invoice_type_id == 2) {
//                $invoice = SalesOrder::findOrFail($id);
//            }
//            $invoice_id = $invoice->id;
//
//            if ($convert_to == 2) {
//                $newInvoice = new SalesOrder();
//                $comment = "Converted to sales order";
//            } else if ($convert_to == 3) {
//                $newInvoice = new SalesInvoice();
//                $comment = "Converted to sales invoice";
//            }
//            $newInvoice->fill($invoice->getAttributes());
//
//            GlobalController::InsertNewComment($invoice_type_id, $invoice_id, NULL, $comment);
//
////      $newInvoice->updated_by = Auth::id();
//            $newInvoice->quotation_id = $invoice_id;
//            $newInvoice->invoice_type = $convert_to;
//            $newInvoice->deleted = 1;
//            $newInvoice->created_by = 2;
//            $newInvoice->save();
//            $newInvoiceId = $newInvoice->id;
////            duplicate the items
//            $invoiceItems = InvoiceHasItems::where('invoice_id', $invoice_id)
//                ->where('invoice_type_id', $invoice_type_id)
//                ->get();
//            foreach ($invoiceItems as $item) {
//                $newItem = $item->replicate();
//                $newItem->invoice_id = $newInvoiceId;
//                $newItem->invoice_type_id = $convert_to;
//                $newItem->save();
//            }
//
//
////            duplicate the files
//            $invoiceFiles = InvoiceFiles::where('invoice_id', $invoice_id)
//                ->where('invoice_type_id', $invoice_type_id)
//                ->get();
//            foreach ($invoiceFiles as $files) {
//                $files = $item->replicate();
//                $files->invoice_id = $newInvoiceId;
//                $files->invoice_type_id = $convert_to;
//                $files->save();
//            }
//
//            $encryptedid = Crypt::encryptString($newInvoice->id . '-' . $convert_to);
//            return response()->json([
//                'message' => 'The selected document has been converted.',
//                'invoice_id' => $encryptedid
//            ]);
//
//        } catch (ModelNotFoundException $e) {
//            return response()->json(['error' => 'The selected document not found'], 404);
//        }
    }


    public function deleteInvoice($id, $invoice_type_id)
    {
        if ($invoice_type_id == 1) {
            $invoice = Quotation::findOrFail($id);
        } else if ($invoice_type_id == 2) {
            $invoice = SalesOrder::findOrFail($id);
        } else if ($invoice_type_id == 3) {
            $invoice = SalesInvoice::findOrFail($id);
        }else if ($invoice_type_id == 4) {
            $invoice = PurchaseInvoice::findOrFail($id);
        }else if ($invoice_type_id == 5) {
            $invoice = PurchaseOrder::findOrFail($id);
        }else if ($invoice_type_id == 6) {
            $invoice = CreditNote::findOrFail($id);
        }else if ($invoice_type_id == 7) {
            $invoice = DebitNote::findOrFail($id);
        }
        if($invoice->status == 1){
            $invoice->delete();
            return response()->json([
                'status' => 'success',
                'message' => 'Deleted Successfully',
            ]);
        }else{
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot Delete',
            ]);        }

       
    }

    public function edit($type, $encryptedId)
    {
//        invoice id - invoice type
        $ids = Crypt::decryptString($encryptedId);
        $organizationId = org_id();
//        return $ids;
        $ids = explode("-", $ids);
        $invoice_id = $ids[0];
        $invoiceType = $ids[1];
        $invoiceTypeDetails = InvoiceType::findOrFail($invoiceType);
        $invoiceName = $invoiceTypeDetails->display_name;
        $tableName = $invoiceTypeDetails->table_name;
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $saleAccounts = GetSalesAccountIds();
        $invoice = DB::table($tableName . ' as A')
            ->select('A.*',
                'B.display_name as partner_name',
                'B.id as partner_id',
                'B.email',
                'B.phone',
                'B.country',
                'B.address',
                'B.district',
                'B.city',
                'B.state',
                'B.zip_code',
                'B.address_phone',
                'B.fax_number',
                'B.remarks',
                'C.name as project_name')
            ->leftJoin('partner as B', 'B.id', '=', 'A.partner_id')
            ->leftJoin('project as C', 'C.id', '=', 'A.project_id')
            ->where('A.id', $invoice_id)
            ->where('A.organization_id', $organizationId)
            ->where('A.deleted', 0)
            ->first();
        $invoiceFiles = InvoiceFiles::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();
        $receivables = Customers();
        $projects = Project::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
        return view('pages.invoice.customer.edit', compact(['invoice', 'items', 'paymentTerms', 'taxes', 'saleAccounts', 'invoiceType', 'invoiceName', 'invoiceFiles', 'invoiceItems', 'receivables', 'projects']));
    }

//    edit purchase invoice
    public function editPurchase($type, $encryptedId)
    {
//        invoice id - invoice type
        $ids = Crypt::decryptString($encryptedId);
        $organizationId = org_id();
//        return $ids;
        $ids = explode("-", $ids);
        $invoice_id = $ids[0];
        $invoiceType = $ids[1];
        $invoiceTypeDetails = InvoiceType::findOrFail($invoiceType);
        $invoiceName = $invoiceTypeDetails->display_name;
        $tableName = $invoiceTypeDetails->table_name;
        $items = Item::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $expenseAccounts = ExpenseAccounts();
        $invoice = DB::table($tableName . ' as A')
            ->select('A.*',
                'B.display_name as partner_name',
                'B.id as partner_id',
                'B.email',
                'B.phone',
                'B.country',
                'B.address',
                'B.district',
                'B.city',
                'B.state',
                'B.zip_code',
                'B.address_phone',
                'B.fax_number',
                'B.remarks',
                'C.name as project_name')
            ->leftJoin('partner as B', 'B.id', '=', 'A.partner_id')
            ->leftJoin('project as C', 'C.id', '=', 'A.project_id')
            ->where('A.id', $invoice_id)
            ->where('A.organization_id', $organizationId)
            ->where('A.deleted', 0)
            ->first();
        $invoiceFiles = InvoiceFiles::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();
        $payables = Partner::where('partner_type', 1)
            ->where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->get();
        $projects = Project::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $paymentTerms = PaymentTerms::where('organization_id', $organizationId)
            ->orwhere('by_default', 1)
            ->get();
        return view('pages.invoice.payable.edit', compact(['invoice', 'items', 'paymentTerms', 'taxes', 'expenseAccounts', 'invoiceType', 'invoiceName', 'invoiceFiles', 'invoiceItems', 'payables', 'projects']));
    }

    public function update(Request $request, $encryptedId)
    {
        $organizationId = org_id();
        $ids = Crypt::decryptString($encryptedId);
        $ids = explode("-", $ids);
        $invoice_id = $ids[0];
        $invoiceType = $ids[1];
        $editable = true;
        $checkReceivedAmount = false;

        if ($invoiceType == 1) {
            $myInvoice = Quotation::findOrFail($invoice_id);
        } else if ($invoiceType == 3) {
            $myInvoice = SalesInvoice::findOrFail($invoice_id);
            $editable = false;
        } else if ($invoiceType == 2) {
            $myInvoice = SalesOrder::findOrFail($invoice_id);
        } else if ($invoiceType == 5) {
            $myInvoice = PurchaseOrder::findOrFail($invoice_id);
        } else if ($invoiceType == 4) {
            $myInvoice = PurchaseInvoice::findOrFail($invoice_id);
            $editable = false;
        } else if ($invoiceType == 6) {
            $myInvoice = CreditNote::findOrFail($invoice_id);
            $checkReceivedAmount = true;
            $editable = false;
        } else if ($invoiceType == 7) {
            $myInvoice = DebitNote::findOrFail($invoice_id);
            $checkReceivedAmount = true;
            $editable = false;
        }

        $referenceNumber = $request->input('order_number');
        if ($referenceNumber !== $myInvoice->order_number) {
            $referenceExist = checkReferenceExists($referenceNumber);
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'reference_number' => __('messages.reference_exists'),
                ])->withInput();
            }
        }

        $invoiceStatus = $myInvoice->status;
        $updateTransaction = false;
        if(in_array($invoiceType, [4,6,7,3]) && in_array($myInvoice->status , [4,8])){
            $updateTransaction = true;
        }
        $alldata = $request->all();
        if(!$editable){
            if ($request->input('total') != $myInvoice->total || $request->input('vat_amount') != $myInvoice->vat_amount){
                if (validateVATDate($myInvoice->invoice_date)) {
                    $updateTransaction = false;
                    return redirect()->back()->withInput()->with('warning', errorMsg());
                }
            }
        }


        $rules = [
            'invoice_date'   => 'required|date',
            'item_name'      => ['required', 'array', 'min:1', function ($attribute, $value, $fail) use ($request) {
                // Loop through rows and check if they are empty
                for($index = 0 ; $index <= count($value) ; $index++) {
                    // Check if the row is fully empty
                    $itemName = $request->input('item_name')[$index] ?? null;
                    $quantity = $request->input('quantity')[$index] ?? null;
                    $rate = $request->input('rate')[$index] ?? null;
                    $tax = $request->input('tax')[$index] ?? null;
                    $discount = $request->input('discount')[$index] ?? null;

                    if (empty($itemName) && empty($quantity) && empty($rate) && empty($tax) && empty($discount)) {
                        // Skip empty rows
                        continue;
                    }

                    // If the row is not fully empty, validate required fields
                    if (empty($itemName)) {
                        $fail("The item name for all items is required.");
                    }
                    if (empty($quantity)) {
                        $fail("The quantity for all items is required.");
                    }
                    if (empty($rate)) {
                        $fail("The rate for all items is required.");
                    }
                }
            }],
            'quantity.*'     => 'nullable|numeric|min:1',
            'rate.*'         => 'nullable|numeric|min:1',
            'discount.*'     => 'nullable|numeric|min:0|max:100',
        ];
        $messages = [
            'item_name.*.required' => 'Item details are required.',
        ];
        if (!isset($request->item_name) || count(array_filter($request->item_name)) < 1
            || !isset($request->coa_id) || count(array_filter($request->coa_id)) < 1) {
            return redirect()->back()->withInput()->with('error', 'Fill the table with items.');
        }
        if ($request->total < 1) {
            throw ValidationException::withMessages([
                'total' => ['The amount cannot be zero.'],
            ]);
        }
        $validatedData = $request->validate($rules, $messages);



        if (in_array($myInvoice->status, [7, 9]) ) {
            $modifiableFields = [
                'order_number',
                'supply_date',
                'subject',
                'project_id',
                'terms_conditions',
                'notes',
                'files',
                'due_date',
                'terms',
                'invoice_number',
            ];
            $validatedData = $request->only($modifiableFields);
            $myInvoice->update($validatedData);
            return redirect()->back()->with('error', 'Information data can be modified only');

        }



//        if amount received is more than the total amount for credit note and debit
        if($checkReceivedAmount && ($request->input('total') < $myInvoice->amount_received)){
            return redirect()->back()->with('warning', 'Please make sure that the credit notes amount is not lesser than '.$myInvoice->amount_received.' because as many credits have been refunded to the customer.');
        }
//        if status is not draft and is invoice or bil
//        if($invoiceStatus != 1 && !$editable){
//            return redirect()->back()->with('warning', 'Cannot be modified.');
//        }
        $comment ='has been edited.';
        GlobalController::InsertNewComment($invoiceType, $invoice_id, NULL, $comment);

        $selectedFiles = $request->input('current_files');
        if ($selectedFiles) {
           $selectedFilesFiltered = array_map('intval', $selectedFiles);
           $selectedFilesFiltered = array_filter($selectedFilesFiltered, function ($value) {
               return $value !== NULL;
           });
           //        remove deleted invoice files
           InvoiceFiles::where('invoice_id', $invoice_id)
               ->where('invoice_type_id', $invoiceType)
               ->whereNotIn('id', $selectedFilesFiltered)
               ->delete();
        }else{
            //        remove deleted invoice files
            InvoiceFiles::where('invoice_id', $invoice_id)
                ->where('invoice_type_id', $invoiceType)
                ->delete();
        }
            //upload new files
            if ($request->hasFile('files')) {
                $fileNamesToStore = [];
                foreach ($request->file('files') as $file) {
                    $fileName = uploadFile($file, 'invoices');
                    $fileNamesToStore[] = $fileName;
                    $invoiceFile = new InvoiceFiles();
                    $invoiceFile->invoice_id = $invoice_id;
                    $invoiceFile->invoice_type_id = $invoiceType;
                    $invoiceFile->name = $fileName;
                    $invoiceFile->save();
                }
            }
        $totalTaxableAmount = $totalNonTaxableAmount = 0;

        if ($request->has('invoice_item_id')) {
                //        remove deleted items
                $selectedItems = $request->input('invoice_item_id');
                $selectedItemsFiltered = array_map('intval', $selectedItems);
                $selectedItemsFiltered = array_filter($selectedItemsFiltered, function ($value) {
                    return $value !== NULL;
                });
                if (!empty($selectedItemsFiltered)) {
                    if($invoiceType == 6){//if credit note y3ni badi na2es mn l item quantity

                    }


                    InvoiceHasItems::where('invoice_id', $invoice_id)
                        ->where('invoice_type_id', $invoiceType)
                        ->whereNotIn('id', $selectedItemsFiltered)
                        ->delete();
                }


                for ($i = 0; $i < count($selectedItems); $i++) {
                    //        update items
                    if ($selectedItems[$i] != 0) {
                        $updatedInvoiceItem = InvoiceHasItems::findOrFail($selectedItems[$i]);
                        $invoiceItem['invoice_id'] = $invoice_id;
                        $invoiceItem['invoice_type_id'] = $invoiceType;
                        $invoiceItem['item_id'] = $request->items[$i] ? $request->items[$i] : NULL;
                        $invoiceItem['item_name'] = $request->item_name[$i] ? $request->item_name[$i] : NULL;
                        $invoiceItem['item_description'] = $request->item_description[$i] ? $request->item_description[$i] : NULL;
                        $invoiceItem['quantity'] = $request->quantity[$i];
                        $invoiceItem['rate'] = $request->rate[$i];
                        $invoiceItem['sale_price'] = $request->sale_price[$i] ? $request->sale_price[$i] : $request->rate[$i];
                        $invoiceItem['amount'] = $request->amount[$i];
                        $invoiceItem['final_amount'] = $request->final_amount[$i];
                        $invoiceItem['discount'] = $request->discount[$i];
                        $invoiceItem['unit'] = $request->unit[$i] ?? NULL;
                        $invoiceItem['tax'] = $request->tax[$i] ?? NULL;
                        $invoiceItem['coa_id'] = $request->coa_id[$i];
                        $updatedInvoiceItem->update($invoiceItem);
                        $taxValue = getTaxValue($invoiceItem['tax']);
                        if($taxValue > 0){
                            $totalTaxableAmount += $request->final_amount[$i];
                        }else{
                            $totalNonTaxableAmount += $request->final_amount[$i];
                        }
//                        if($item->items[$i] != NULL){
//                            $product = Item::where('id', $item->items[$i])->first();
//                            if ($product) {
//                                $newQuantity = $product->stock_quantity - $item->quantity;
//                                $product->update(['stock_quantity' => $newQuantity]);
//                            }
//                        }
//                        if($invoiceType == 6){//if credit note y3ni badi zid item quantity l2n ana 3am a3melo irja3
//
//                        }
                    }
                    else {
//                    add new item
                        if(isset($request->item_name[$i])){
                            $invoiceItem = new InvoiceHasItems();
                            $invoiceItem->invoice_id = $invoice_id;
                            $invoiceItem->invoice_type_id = $invoiceType;
                            $invoiceItem->item_id = $request->items[$i] ? $request->items[$i] : NULL;
                            $invoiceItem->item_name = $request->item_name[$i] ? $request->item_name[$i] : NULL;
                            $invoiceItem->item_description = $request->item_description[$i] ? $request->item_description[$i] : NULL;
                            $invoiceItem->quantity = $request->quantity[$i];
                            $invoiceItem->rate = $request->rate[$i];
                            $invoiceItem->sale_price = $request->sale_price[$i] ? $request->sale_price[$i] : $request->rate[$i];
                            $invoiceItem->amount = $request->amount[$i];
                            $invoiceItem->final_amount = $request->final_amount[$i];
                            $invoiceItem->discount = $request->discount[$i];
                            $invoiceItem->unit = $request->unit[$i] ?? NULL;
                            $invoiceItem->tax = $request->tax[$i] ?? NULL;
                            $invoiceItem->coa_id = $request->coa_id[$i];
                            $invoiceItem->save();
                            $taxValue = getTaxValue($invoiceItem->tax);
                            if($taxValue > 0){
                                $totalTaxableAmount += $request->final_amount[$i];
                            }else{
                                $totalNonTaxableAmount += $request->final_amount[$i];
                            }
                        }

//                        if($invoiceType == 6){//if credit note y3ni badi zid item quantity l2n ana 3am a3melo irja3
//
//                        }
                    }
                }
            }
//      $alldata['deleted'] = 0;
        $alldata['amount_due'] = $request->input('total') ;
        $alldata['taxable_amount'] = $totalTaxableAmount;
        $alldata['non_taxable_amount'] = $totalNonTaxableAmount;

        if ($invoiceType == 6 || $invoiceType == 7) {
            $alldata['amount_due'] = $request->input('total') - $myInvoice->amount_received;
            if ($request->input('total') != $myInvoice->total) {
//                if ($myInvoice->status != 1) {
//                    $updateTransaction = true;
//                    $alldata['status'] = 8; //open
//                }
            }
        }
        $myInvoice->update($alldata);
//        if ($updateTransaction && !validateVATDate($myInvoice->invoice_date)) {
//            // Get the most recent transaction of type refund related to the invoice
//            $transaction = Transaction::with(['TransactionInvoice', 'TransactionDetails'])
//                ->where('organization_id', $organizationId)
//                ->whereIn('transaction_type_id', [5,7,14,21])
//                ->whereHas('TransactionInvoice', function ($query) use ($invoice_id, $invoiceType) {
//                    $query->where('invoice_id', $invoice_id)
//                        ->where('invoice_type_id', $invoiceType);
//                })
//                ->latest()
//                ->first();
//
//            if ($transaction) {
//                try {
//                    // Delete transaction details and invoice
//                    if ($transaction->TransactionDetails()) {
//                        $transaction->TransactionDetails()->delete();
//                    }
//                    if ($transaction->TransactionInvoice()) {
//                        $transaction->TransactionInvoice()->delete();
//                    }
//                    $transaction->delete();
//                } catch (\Exception $e) {
//                    return redirect()->back()->with('error', 'Error occurred: ' . $e->getMessage());
//                }
//            }
//
//            // Set status based on invoice type
//            $status = in_array($invoiceType, [3,4]) ? 4 : 8;
//
//            // Update the invoice status
//            $request2 = new Request([
//                'status' => $status,
//                'type_id' => $invoiceType,
//            ]);
//            $updatedStatus = $this->updateInvoiceStatus($request2, $invoice_id);
//
//            if (!$updatedStatus) {
//                // Set error message in session
//                return redirect()->back()->with('error', 'Failed to update transaction ');
//            }
//        }
        $type = InvoiceType::find($invoiceType);
        $invoice_type_name = $type->name;
        return redirect()->route('InvoiceController.showInvoice', [$invoice_type_name.'#row-' . $myInvoice->id]);
    }
    public function viewInvoice($type, $encryptedId)
    {
        $ids = Crypt::decryptString($encryptedId);
        $ids = explode("-", $ids);
        $invoice_id = $ids[0];
        $invoiceType = $ids[1];
        if ($invoiceType == 1) {
//            quotation
            $myInvoice = Quotation::findOrFail($invoice_id);
        } else if ($invoiceType == 3) {
//        sales invoice, final invoice
            $myInvoice = SalesInvoice::findOrFail($invoice_id);
        } else if ($invoiceType == 2) {
//            sales order, proforma
            $myInvoice = SalesOrder::findOrFail($invoice_id);
        } else if ($invoiceType == 4) {
//            purchase invoice/ bill
            $myInvoice = PurchaseInvoice::findOrFail($invoice_id);
        } else if ($invoiceType == 5) {
//            purchase order
            $myInvoice = PurchaseOrder::findOrFail($invoice_id);
        } else if ($invoiceType == 6) {
            $myInvoice = CreditNote::findOrFail($invoice_id);
        } else if ($invoiceType == 7) {
            $myInvoice = DebitNote::findOrFail($invoice_id);
        }
        $organizationId = $myInvoice->organization_id;

        $invoiceTypeDetails = InvoiceType::findOrFail($invoiceType);
        $invoiceName = $invoiceTypeDetails->display_name;
        $tableName = $invoiceTypeDetails->table_name;
        $invoice = DB::table($tableName . ' as A')
            ->select('A.*',
                'B.display_name as partner_name',
                'B.email',
                'B.phone',
                'D.name as payment_term',
                'C.name as project_name')
            ->leftJoin('partner as B', 'B.id', '=', 'A.partner_id')
            ->leftJoin('project as C', 'C.id', '=', 'A.project_id')
            ->leftJoin('payment_terms as D', 'D.id', '=', 'A.terms')
            ->where('A.id', $invoice_id)
            ->where('A.organization_id', $organizationId)
            ->where('A.deleted', 0)
            ->first();
        $invoiceFiles = InvoiceFiles::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();

        $transaction_type_id = 5;//type transaction, y3ni the transaction created on invoice approved
        if ($invoiceType == 6) {
            $transaction_type_id = 7;//type credit, when the credit note is approved
        }else if ($invoiceType == 7) {
            $transaction_type_id = 14;//type debit, when the debit note is approved
        }else if ($invoiceType == 4) {
            $transaction_type_id = 21;//type bill, when the bill is approved
        }
//        get transactions type transaction and related to the invoice
        $invoiceJournal = Transaction::with(['TransactionInvoice', 'TransactionDetails'])
            ->where('organization_id', $organizationId)
            ->where('transaction_type_id', $transaction_type_id)
            ->whereHas('TransactionInvoice', function ($query)use ($invoice_id, $invoiceType) {
                $query->where('invoice_id', $invoice_id)
                    ->where('invoice_type_id', $invoiceType);
            })
            ->get()
            ->last();
        $transaction_type_id = 4;
        if ($invoiceType == 6) {
            $transaction_type_id = 6;//payment refund
        }else if ($invoiceType == 7) {
            $transaction_type_id = 16;//payment made refund
        }
//        get transactions type inoice payment and related to the invoice
        $paymentJournal = Transaction::with(['TransactionInvoice', 'TransactionDetails'])
            ->where('organization_id', $organizationId)
            ->where('transaction_type_id', $transaction_type_id)
            ->whereHas('TransactionInvoice', function ($query)use ($invoice_id, $invoiceType) {
                $query->where('invoice_id', $invoice_id)
                    ->where('invoice_type_id', $invoiceType);
            })
            ->orderBy('created_at')
            ->get();

        $journalContent='';
        if($invoiceJournal){
            $totalDebitAmount = $totalCreditAmount = 0;
            foreach ($invoiceJournal->TransactionDetails as $detail) {
               $totalDebitAmount += $detail->is_debit == 1 ? $detail->amount: 0;
               $totalCreditAmount += $detail->is_debit == 0 ? $detail->amount:  0;
                $journalContent .= '<tr><td>'.$detail->account->name.'</td>';
                $journalContent .= '<td class="text-end">'.($detail->is_debit === 1 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</td>';
                $journalContent .= '<td class="text-end">'.($detail->is_debit === 0 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</td></tr>';
            }
            $journalContent .='<tr class="grey-border-top">
                                <td></td>
                                <td class="text-end">'.number_format($totalDebitAmount, 2, '.', ',') .'</td>
                                <td class="text-end">'.number_format($totalCreditAmount, 2, '.', ',') .'</td>
                                </tr>';
        }


        if($invoiceType == 4 || $invoiceType == 5 ){
            return view('pages.invoice.payable.invoice', compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles', 'invoiceItems']));
        }else if($invoiceType == 2 || $invoiceType == 3  || $invoiceType == 1 ){
            return view('pages.invoice.customer.invoice', compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles','invoiceItems']));
        }else if($invoiceType == 6 ){
            return view('pages.invoice.customer.CnInvoice', compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles','invoiceItems']));
        }else if($invoiceType == 7 ){
            return view('pages.invoice.payable.DnInvoice', compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles','invoiceItems']));
        }
    }
    public function viewInvoiceMoreDetails($type, $encryptedId)
    {
        $ids = Crypt::decryptString($encryptedId);
        $organizationId = org_id();
        $ids = explode("-", $ids);
        $invoice_id = $ids[0];
        $invoiceType = $ids[1];
        $invoiceTypeDetails = InvoiceType::findOrFail($invoiceType);
        $invoiceName = $invoiceTypeDetails->display_name;
        $tableName = $invoiceTypeDetails->table_name;
        $invoice = DB::table($tableName . ' as A')
            ->select('A.*',
                'B.display_name as partner_name',
                'B.email',
                'B.phone',
                'D.name as payment_term',
                'C.name as project_name')
            ->leftJoin('partner as B', 'B.id', '=', 'A.partner_id')
            ->leftJoin('project as C', 'C.id', '=', 'A.project_id')
            ->leftJoin('payment_terms as D', 'D.id', '=', 'A.terms')
            ->where('A.id', $invoice_id)
            ->where('A.organization_id', $organizationId)
            ->where('A.deleted', 0)
            ->first();
        $invoiceFiles = InvoiceFiles::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();
        $invoiceItems = InvoiceHasItems::where('invoice_id', '=', $invoice_id)
            ->where('invoice_type_id', '=', $invoiceType)
            ->get();

        $transaction_type_id = 5;//type transaction, y3ni the transaction created on invoice approved
        if ($invoiceType == 6) {
            $transaction_type_id = 7;//type credit, when the credit note is approved
        }else if ($invoiceType == 7) {
            $transaction_type_id = 14;//type debit, when the debit note is approved
        }else if ($invoiceType == 4) {
            $transaction_type_id = 21;//type bill, when the bill is approved
        }
//        get transactions type transaction and related to the invoice
        $invoiceJournal = Transaction::with(['TransactionInvoice', 'TransactionDetails'])
            ->where('organization_id', $organizationId)
            ->where('transaction_type_id', $transaction_type_id)
            ->whereHas('TransactionInvoice', function ($query)use ($invoice_id, $invoiceType) {
                $query->where('invoice_id', $invoice_id)
                    ->where('invoice_type_id', $invoiceType);
            })
            ->get()
            ->last();
        $transaction_type_id = 4;
        //        get opening balance adj
        $customerOpeningBalance = ObPartners::where('organization_id', org_id())
            ->where('partner_id', $invoice->partner_id)
            ->select('debit_amount', 'credit_amount', 'id')
            ->first();

        $paymentAdjustment = [];
        $openingBalanceAdj = 0;
        if ($invoiceType == 3 ) {
            $paymentAdjustment = CreditApplied::where('organization_id', $organizationId)
                ->where('invoice_id', $invoice_id)// type deduct from credit note
                ->where('is_creditnote', 1)// type deduct from credit note
                ->orderBy('created_at')
                ->get();
            if($customerOpeningBalance){
                $openingBalanceAdj = CreditApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('invoice_id', $invoice_id)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');
            }

        }else if ($invoiceType == 4 ){
            //        get adjustment payments
            $paymentAdjustment = DebitApplied::where('organization_id', $organizationId)
                ->where('invoice_id', $invoice_id)// type deduct from credit note
                ->where('is_creditnote', 1)// type deduct from credit note
                ->orderBy('created_at')
                ->get();

            if($customerOpeningBalance){
                $openingBalanceAdj = DebitApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('invoice_id', $invoice_id)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');
            }

        }

        if ($invoiceType == 6) {
            $transaction_type_id = 6;//payment refund
            //        get adjustment payments

        }else if ($invoiceType == 7) {
            $transaction_type_id = 16;//payment made refund

        }else if ($invoiceType == 4) {
            $transaction_type_id = 15;//bill payment

        }
//        get transactions type inoice payment and related to the invoice
        $paymentJournal = Transaction::with(['TransactionInvoice', 'TransactionDetails'])
            ->where('organization_id', $organizationId)
            ->where('transaction_type_id', $transaction_type_id)
            ->whereHas('TransactionInvoice', function ($query)use ($invoice_id, $invoiceType) {
                $query->where('invoice_id', $invoice_id)
                    ->where('invoice_type_id', $invoiceType);
            })
            ->orderBy('created_at')
            ->get();

        $journalContent='';
        if($invoiceJournal){
            $totalDebitAmount = $totalCreditAmount = 0;
            foreach ($invoiceJournal->TransactionDetails as $detail) {
               $totalDebitAmount += $detail->is_debit == 1 ? $detail->amount: 0;
               $totalCreditAmount += $detail->is_debit == 0 ? $detail->amount:  0;
                $journalContent .= '<tr><td>'.$detail->account->name.'</td>';
                $journalContent .= '<td class="text-end">'.($detail->is_debit === 1 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</td>';
                $journalContent .= '<td class="text-end">'.($detail->is_debit === 0 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</td></tr>';
            }
            $journalContent .='<tr class="grey-border-top">
                                <td></td>
                                <td class="text-end">'.number_format($totalDebitAmount, 2, '.', ',').'</td>
                                <td class="text-end">'.number_format($totalCreditAmount, 2, '.', ',').'</td>
                                </tr>';
        }



        if ($invoiceType == 4 || $invoiceType == 5 || $invoiceType == 7) {
            return view('pages.invoice.payable.moreDetails',
                compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles', 'invoiceItems', 'openingBalanceAdj','journalContent', 'paymentJournal', 'paymentAdjustment']));

        }else {
            return view('pages.invoice.customer.moreDetails',
                compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles', 'invoiceItems', 'openingBalanceAdj','journalContent', 'paymentJournal', 'paymentAdjustment']));

        }

    }
    public function pdf($invoiceType, $encryptedId){
        $dompdf = new Dompdf(array('enable_remote' => true));

        $html = self::viewInvoice($invoiceType, $encryptedId)->render();
//        if($invoiceType == 4 || $invoiceType == 5){
//            $html = view('pages.invoice.payable.invoice', compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles', 'invoiceItems']))->render();
//        }else{
//            $html = view('pages.invoice.customer.invoice', compact(['invoice', 'invoiceType', 'invoiceName', 'invoiceFiles','invoiceItems']))->render();
//        }

        // Instantiate Dompdf with options
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);

        // Load HTML content
        $dompdf->loadHtml($html);

        // Render PDF (optional settings for PDF)
        $dompdf->setPaper('A4', 'portrait');

        // Render the HTML as PDF
        $dompdf->render();

        // Output the generated PDF (download or view)
        return $dompdf->stream('document.pdf');
    }
//    get  invoices by partner id
    public function getInvoicesByPartnerId(Request $request){

        $organizationId = org_id();
        $id = $request->input('id');
        $partnerType = Partner::where('id', $id)
            ->where('organization_id', org_id())
            ->select('partner_type')
            ->first();
        if($partnerType->partner_type == 1){
            $invoices = PurchaseInvoice::where('partner_id', $id)
                ->where('organization_id', $organizationId)
                ->whereNotIn('status', [1, 5, 6])
                ->select('id', 'invoice_number')
                ->get();
        }else{
            $invoices = SalesInvoice::where('partner_id', $id)
                ->where('organization_id', $organizationId)
                ->whereNotIn('status', [1, 5, 6])
                ->select('id', 'invoice_number')
                ->get();
        }

        $invoice_html = '';
        $invoice_html .="<option disabled selected>Select an invoice</option>";
        if($invoices->isNotEmpty()){
            foreach ($invoices as $invoice){
                $invoice_id = $invoice->id;
                $invoice_number = $invoice->invoice_number;
                $invoice_html .="<option value=".$invoice_id.">".$invoice_number."</option>";
            }
        }
        return response()->json(['invoices'=> $invoice_html]);
    }
//    credit used and total refund for credit note
    public static function getCreditUsed($id)
    {
        $creditAppliedSum = CreditApplied::where('credit_id', $id)
            ->where('is_creditnote', 1)// type deduct from credit note
            ->sum('amount');

        $organizationId = org_id();
        $invoiceId = $id;

        $creditRefundSum = DB::select("
            SELECT IFNULL(SUM(B.amount), 0) as creditRefundSum
            FROM payment_received A
            JOIN transaction B ON A.id = B.payment_id
            JOIN transaction_invoice C ON B.id = C.transaction_id
            WHERE A.organization_id = ?
              AND A.type_id = 13
              AND B.transaction_type_id = 13
              AND C.invoice_type_id = 6
              AND C.invoice_id = ?
        ", [$organizationId, $invoiceId]);

// Extracting the result
        $creditRefundSum = $creditRefundSum[0]->creditRefundSum;

        return ['creditAppliedSum' => $creditAppliedSum,
            'creditRefundSum' => $creditRefundSum
            ];
    }
//    credit used and total refund for debit note
    public static function getDebitUsed($id)
    {
        $creditAppliedSum = DebitApplied::where('debit_id', $id)
            ->where('is_creditnote', 1)// type deduct from credit note
            ->sum('amount');

        $organizationId = org_id();
        $invoiceId = $id;

        $creditRefundSum = DB::select("
            SELECT IFNULL(SUM(B.amount), 0) as creditRefundSum
            FROM payment_received A
            JOIN transaction B ON A.id = B.payment_id
            JOIN transaction_invoice C ON B.id = C.transaction_id
            WHERE A.organization_id = ?
              AND A.type_id = 18
              AND B.transaction_type_id = 18
              AND C.invoice_type_id = 7
              AND C.invoice_id = ?
        ", [$organizationId, $invoiceId]);

// Extracting the result
        $creditRefundSum = $creditRefundSum[0]->creditRefundSum;

        return ['creditAppliedSum' => $creditAppliedSum,
            'creditRefundSum' => $creditRefundSum
            ];
    }
//    credit applied and total payment  for invoice
    public static function getCreditApplied($id, $invoiceType)
    {
        $creditAppliedSum = CreditApplied::where('invoice_id', $id)
            ->where('is_creditnote', 1)// type deduct from credit note
            ->sum('amount');

        $organizationId = org_id();
        $invoiceId = $id;

        $paymentSum = DB::select("
            SELECT IFNULL(SUM(B.amount), 0) as paymentMadeSum
            FROM payment_received A
            JOIN transaction B ON A.id = B.payment_id
            JOIN transaction_invoice C ON B.id = C.transaction_id
            WHERE A.organization_id = ?
              AND A.type_id = 1
              AND B.transaction_type_id = 4
              AND C.invoice_type_id = ?
              AND C.invoice_id = ?
        ", [$organizationId, $invoiceType, $invoiceId]);

// Extracting the result
        $paymentSum = $paymentSum[0]->paymentMadeSum;

        return ['creditAppliedSum' => $creditAppliedSum,
            'paymentMadeSum' => $paymentSum
            ];
    }
//    credit applied and total payment  for invoice
    public static function getDebitApplied($id, $invoiceType)
    {
        $creditAppliedSum = DebitApplied::where('invoice_id', $id)
            ->where('is_creditnote', 1)
            ->sum('amount');

        $organizationId = org_id();
        $invoiceId = $id;

        $paymentSum = DB::select("
            SELECT IFNULL(SUM(B.amount), 0) as paymentMadeSum
            FROM payment_received A
            JOIN transaction B ON A.id = B.payment_id
            JOIN transaction_invoice C ON B.id = C.transaction_id
            WHERE A.organization_id = ?
              AND A.type_id = 2
              AND B.transaction_type_id = 15
              AND C.invoice_type_id = ?
              AND C.invoice_id = ?
        ", [$organizationId, $invoiceType, $invoiceId]);

// Extracting the result
        $paymentSum = $paymentSum[0]->paymentMadeSum;

        return ['creditAppliedSum' => $creditAppliedSum,
            'paymentMadeSum' => $paymentSum
            ];
    }
    public function checkInvoiceNumber(Request $request)
    {
        $organizationId = org_id();
        $invoiceNumber = $request->input('invoice_number');
        $partnerId = $request->input('partner_id');
        $invoiceId = $request->input('invoice_type_id');
        $exists = true;
        if($invoiceId == 5){
            $exists = PurchaseOrder::where('invoice_number', $invoiceNumber)
                ->where('organization_id', $organizationId)
                ->where('partner_id', $partnerId)
                ->exists();
        }
        if($invoiceId == 4){
            $exists = PurchaseInvoice::where('invoice_number', $invoiceNumber)
                ->where('organization_id', $organizationId)
                ->where('partner_id', $partnerId)
                ->exists();
        }
        if($invoiceId == 7){
            $exists = DebitNote::where('invoice_number', $invoiceNumber)
                ->where('organization_id', $organizationId)
                ->where('partner_id', $partnerId)
                ->exists();
        }
        return response()->json(['exists' => $exists]);
    }
    public function getInvoiceItem(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        $invoiceTypeId = $request->input('invoice_type_id');

        $items = Item::where('deleted', 0)
            ->where('organization_id', org_id())
            ->get();
        $taxes = Tax::where('deleted', 0)
            ->where('organization_id', org_id())
            ->get();
        $saleAccounts = GetSalesAccountIds();
        $expenseAccounts = ExpenseAccounts();
        if($invoiceTypeId == 6){
            $invoiceItems = InvoiceHasItems::where('invoice_id', $invoiceId)
                ->where('invoice_type_id', 3)
                ->get();
            $html = view('pages.invoice.partials.items', compact('invoiceItems', 'items', 'saleAccounts', 'taxes'))->render();
        }else{
            $invoiceItems = InvoiceHasItems::where('invoice_id', $invoiceId)
                ->where('invoice_type_id', 4)
                ->get();
            $html = view('pages.invoice.partials.purchase_items', compact('invoiceItems', 'items', 'expenseAccounts', 'taxes'))->render();
        }
        return response()->json(['html' => $html]);
    }
    public function getInvoicesByPId(Request $request)
    {
        $partnerId = $request->input('partner_id');
        $invoiceTypeId = $request->input('invoice_type_id');

        if($invoiceTypeId == 6){
            $invoices = SalesInvoice::where('partner_id', $partnerId)
                ->where('organization_id', org_id())
                ->whereIn('status', [4, 7])
                ->get();
        }else{
            $invoices = PurchaseInvoice::where('partner_id', $partnerId)
                ->where('organization_id', org_id())
                ->whereIn('status', [4, 7])
                ->get();
        }
        $html = view('pages.invoice.partials.invoices', compact('invoices'))->render();

        return response()->json(['html' => $html]);
    }
}
