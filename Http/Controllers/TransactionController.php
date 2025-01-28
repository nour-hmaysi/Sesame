<?php
namespace App\Http\Controllers;

use App\AdjustmentPayment;
use App\ChartOfAccounts;
use App\AccountType;
use App\Comment;
use App\CreditApplied;
use App\CreditNote;
use App\Currency;
use App\DebitApplied;
use App\DebitNote;
use App\Invoice;
use App\InvoiceFiles;
use App\InvoiceHasItems;
use App\InvoiceType;
use App\Item;
use App\ObAdjustment;
use App\ObPartners;
use App\Partner;
use App\PaymentRecDocuments;
use App\PaymentReceived;
use App\PaymentTerms;
use App\Project;
use App\PurchaseInvoice;
use App\SalesInvoice;
use App\Status;
use App\Tax;
use App\Transaction;
use App\TransactionDetails;
use App\TransactionDocuments;
use App\TransactionInvoice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class TransactionController extends Controller{
    public static function showPaymentsReceived()
    {
        $organizationId = org_id();

//        show payment or advanced payment
        $paymentsReceived = PaymentReceived::with(['Transaction','TransactionType'])
            ->where('organization_id', $organizationId)
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
                'partner_id' => $payment->partner ? $payment->partner->id : '',
                'currency' => GlobalController::GetCurrencyName($payment->currency),
                'amount' => $payment->amount,
                'unused_amount' => $payment->unused_amount,
                'note' => $payment->note,
                'reference_number' => $referenceNumber,
            ];

        });
        return view('pages.payments.customer.index', compact(['paymentsReceived']));
    }
    public static function showPaymentsMade()
    {
        $organizationId = org_id();

//        show payment or advanced payment
        $paymentsReceived = PaymentReceived::with(['Transaction','TransactionType'])
            ->where('organization_id', $organizationId)
            ->whereIn('type_id', [2, 17]) // payment made
            ->get();
        $paymentsReceived = $paymentsReceived->map(function ($payment) {
            // Check if transactions exist before attempting to access their details
            $transactions = $payment->Transaction;
            if ($transactions) {
                $firstDebitDetail = null;
                if ($transactions->count() > 1) {
//                    get type payment or advance
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
                'partner_id' => $payment->partner ? $payment->partner->id : '',
                'currency' => GlobalController::GetCurrencyName($payment->currency),
                'amount' => $payment->amount,
                'unused_amount' => $payment->unused_amount,
                'note' => $payment->note,
                'reference_number' => $referenceNumber,
            ];

        });
        return view('pages.payments.payable.index', compact(['paymentsReceived']));
    }

    public function createPaymentsReceived($invoice_id = NULL)
    {
        $organizationId = org_id();
//        receivables
        $partners = Partner::where('partner_type', 2)
            ->where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->get();
        $paymentAccounts = PaymentAccounts();
        $currentPaymentsCount = 0;
        $counter = 0;
        $paymentsCount = 1;
        do {
            $counter += 1;
            $currentPaymentsCount = PaymentReceived::where('organization_id', $organizationId)
                ->whereIn('type_id', [1, 3])
                ->count();
            if($currentPaymentsCount > 0){
                $paymentsCount = $currentPaymentsCount + $counter;
                $paymentExist = PaymentReceived::where('organization_id', $organizationId)
                    ->where('payment_number', $paymentsCount)
                    ->whereIn('type_id', [1, 3])
                    ->exists();
            }else{
                $paymentExist = false;
            }


        } while ($paymentExist);

        if($invoice_id != NULL){
            $invoice_id = Crypt::decryptString($invoice_id);
            $invoice = SalesInvoice::find($invoice_id);
        }else{
            $invoice = NULL;
        }

        return view('pages.payments.customer.create', compact(['partners', 'paymentAccounts', 'paymentsCount' , 'invoice' ]));
    }
    public function createPaymentsMade($invoice_id = NULL)
    {
        $organizationId = org_id();
//        supplier
        $partners = Suppliers();
        $paymentAccounts = PaymentAccounts();
        $currentPaymentsCount = 0;
        $counter = 0;
        $paymentsCount = 1;
        do {
            $counter += 1;
            $currentPaymentsCount = PaymentReceived::where('organization_id', $organizationId)
                ->whereIn('type_id', [2, 17])
                ->count();
            if($currentPaymentsCount > 0){
                $paymentsCount = $currentPaymentsCount + $counter;
                $paymentExist = PaymentReceived::where('organization_id', $organizationId)
                    ->where('payment_number', $paymentsCount)
                    ->whereIn('type_id', [2, 17])
                    ->exists();
            }else{
                $paymentExist = false;
            }


        } while ($paymentExist);

        if($invoice_id != NULL){
            $invoice_id = Crypt::decryptString($invoice_id);
            $invoice = PurchaseInvoice::find($invoice_id);
        }else{
            $invoice = NULL;
        }

        return view('pages.payments.payable.create', compact(['partners', 'paymentAccounts', 'paymentsCount' , 'invoice' ]));
    }
    public function updatePaymentsReceived(Request $request, $id)
    {
        $organizationId = org_id();
        $bankCharge = $request->bank_charge;
        $paidBy = $request->paid_by_id;
        $amount = $request->amount;
        $refunded_amount = $request->refunded_amount;
        $date = $request->date;
        $account_id = $request->account_id;
        $reference_number = $request->reference_number;
        $internal_note = $request->internal_note;
        $mode = $request->mode;
        $invoice_ids = $request->invoice_id;
        $is_invoices = $request->is_invoice;
        $payment_amount = $request->payment_amount;
        $dueAmounts = $request->due_amount;
        $paymentAmounts = $request->input('payment_amount', []);
        $totalPaymentAmount = array_sum($paymentAmounts);
        $paymentAmountsCollection = collect($paymentAmounts);
        $filteredAmounts = $paymentAmountsCollection->filter(function ($amount) {
            return $amount > 0;
        });
        $countInvoice = $filteredAmounts->count();
        $receivableAccount = GetReceivableAccount();
        $advPaymentAccount = GetAdvPaymentAccount();
        $bankChargeAccount = GetBankChargeAccount();
        $currencyName = currencyName();
        $unusedAmount = ($amount - $refunded_amount) - $totalPaymentAmount ;
        $isInvoice = false;

        // Initialize variables
        $totalPaidAmount = 0;
        $errors = [];
        if($payment_amount){
            foreach ($payment_amount as $index => $paid_Amount) {
                $paid_Amount = floatval($paid_Amount) ?: 0; // Ensure it's a float
                $dueAmount = floatval($dueAmounts[$index]) ?: 0; // Corresponding due amount

                // Add to the total paid amount
                $totalPaidAmount += $paid_Amount;
            }
            $totalExceed = $amount - $totalPaidAmount;
            if ($totalExceed < 0) {
                $errors[] = "Total payment amount cannot exceed the amount received ($amount).";
            }
            if (!empty($errors)) {
                return redirect()->back()->withErrors($errors)->withInput();
            }

        }

        $isAdv = 3;
        foreach ($paymentAmounts as $paidAmount) {
            if ($paidAmount > 0) {
                $isInvoice = true;
                $isAdv = 1;
                break;
            }
        }
        $id = Crypt::decryptString($id);
        $payment = PaymentReceived::findorfail($id);


        if(empty($payment->first())){
            return redirect()->back()->with('error', 'Please make sure that the payment record are correct.');
        }


        $referenceNumber = $request->input('reference_number');
        if ($referenceNumber !== $payment->reference_number) {
            $referenceExist = checkReferenceExists($referenceNumber);
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'reference_number' => __('messages.reference_exists'),
                ])->withInput();
            }
        }

        $payment_id = $payment->id;
        $payment_number = $payment->payment_number;


        $selectedFiles = $request->input('current_files');
        if ($selectedFiles) {
            $selectedFilesFiltered = array_map('intval', $selectedFiles);
            $selectedFilesFiltered = array_filter($selectedFilesFiltered, function ($value) {
                return $value !== NULL;
            });
            //        remove deleted  files
            PaymentRecDocuments::where('payment_id', $id)
                ->whereNotIn('id', $selectedFilesFiltered)
                ->delete();
        }else{
            PaymentRecDocuments::where('payment_id', $id)
                ->delete();
        }
        if ($request->hasFile('files')) {
            $fileNamesToStore = [];
            foreach ($request->file('files') as $file) {
                $fileName = uploadFile($file, 'payment_received');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new PaymentRecDocuments();
                $invoiceFile->payment_id = $id;
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }

        if (validateVATDate($payment->date) || validateVATDate($date)) {
            $payment->reference_number = $reference_number;
            $payment->note = $internal_note;
            $payment->save();
            return redirect()->back()->withInput()->with('warning', errorMsg().', Only basic details can be updated.');
        }



//        return var_dump($payment_id);
//        update basic details about the payment
        $payment->amount = $amount;
        $payment->unused_amount = $unusedAmount;
        $payment->reference_number = $reference_number;
        $payment->mode = $mode;
        $payment->bank_charge = $bankCharge;
        $payment->date = $date;
        $payment->note = $internal_note;
        $payment->type_id = $isAdv;
        //        store attachments

//        delete the transaction related to the payment
        $invoiceAmounts = [];

        DB::transaction(function () use ($payment_id, $reference_number, $amount, &$invoiceAmounts) {
            $transactionsToDelete = Transaction::where('payment_id', $payment_id)
                ->where('organization_id', org_id())
                ->whereIn('transaction_type_id', [1,3,4,26])
                ->get();
            foreach ($transactionsToDelete as $transaction) {
                if($transaction->TransactionInvoice->first()){
                    $invoice_id = $transaction->TransactionInvoice->first()->invoice_id;
                    $invAmount = $transaction->amount;
//                    b3ml store lal amount la kl invoice, rah tlzamni bl comment history
                    if (!isset($invoiceAmounts[$invoice_id])) {
                        $invoiceAmounts[$invoice_id] = 0;
                    }
                    $invoiceAmounts[$invoice_id] += $invAmount;

//                    braje3 l amount lal invoice
                    $salesInvoice = SalesInvoice::find($invoice_id);
                    if ($salesInvoice) {
                        $salesInvoice->status = 4;
                        $salesInvoice->amount_due += $invAmount;
                        $salesInvoice->amount_received -= $invAmount;
                        $salesInvoice->save();
                    }
                }
//                b3mel delete lal transaction lmarbuta 3al payment w brja3 bzidon based 3al condition li 3ndi ta7et
                $transaction->TransactionDetails()->delete();
                $transaction->TransactionInvoice()->delete();
                $transaction->delete();
            }
        });
//        return var_dump($invoiceAmounts);
        if($isInvoice){
            if($totalPaymentAmount == $amount && $countInvoice == 1){
                $invoiceName = '';
                foreach ($invoice_ids as $index => $id) {
                    $ref_inv_number = '';
                    if ($payment_amount[$index] > 0) {
                      if($is_invoices[$index] == 1){
                        // update the due amount of the invoice
                        $salesInvoice = SalesInvoice::find($invoice_ids[$index]);
                        if ($salesInvoice) {
                            if( $salesInvoice->amount_due - $payment_amount[$index] == 0){
                                $salesInvoice->status = 7; // paid
                            }
                            if($salesInvoice->order_number){
                                $ref_inv_number = $salesInvoice->order_number;
                            }
                            $salesInvoice->amount_due -= $payment_amount[$index];
                            $salesInvoice->amount_received += $payment_amount[$index];
                            $invoiceName .= $salesInvoice->invoice_number.',';
                            $salesInvoice->save();
                        }
                        //      transaction type invoice payment
                        $request1['transaction_type_id'] = 4;
                        $request1['amount'] =  $totalPaymentAmount;
                        $request1['internal_note'] = $internal_note;
                        $request1['payment_id'] = $payment_id;
                        $request1['payment_number'] = $payment_number;
                        $request1['reference_number'] = $ref_inv_number;
                        $request1['paid_by_id'] = $paidBy;
                        $request1['date'] = $date;
                        $transaction = GlobalController::InsertNewTransaction($request1);
//                    deposit to account bank or cash
                        $request2['transaction_id'] = $transaction;
                        $request2['amount'] = $totalPaymentAmount - $bankCharge;
                        $request2['account_id'] = $account_id;
                        $request2['is_debit'] = 1;
                        GlobalController::InsertNewTransactionDetails($request2);
                        if($bankCharge > 0){
//                      deposit to bank charges
                            $bankChargeRequest['transaction_id'] = $transaction;
                            $bankChargeRequest['amount'] = $bankCharge;
                            $bankChargeRequest['account_id'] = $bankChargeAccount;
                            $bankChargeRequest['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($bankChargeRequest);
                        }
//                    credit from receivable account
                        $request3['transaction_id'] = $transaction;
                        $request3['amount'] = $totalPaymentAmount;
                        $request3['account_id'] = $receivableAccount;
                        $request3['is_debit'] = 0;
                        $request3['created_by'] = 1;
                        GlobalController::InsertNewTransactionDetails($request3);

                        // connect the invoice with the transaction
                        $invoiceRequest['transaction_id'] = $transaction;
                        $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                        $invoiceRequest['invoice_type_id'] = 3;
                        GlobalController::InsertNewTransactionInvoice($invoiceRequest);

                        if ($invoiceAmounts[$invoice_ids[$index]] != $payment_amount[$index]) {
                            // Insert comment to invoice
                            $comment = 'Payment#' . $payment_number . ' details modified. Applied amount changed from '
                                . $currencyName . '' . $invoiceAmounts[$invoice_ids[$index]] . ' to ' . $currencyName . '' .  $payment_amount[$index]  .'.';
                            GlobalController::InsertNewComment(3, $invoice_ids[$index], NULL, $comment);
                        }
                    }else{
//                                 for opening balance only
                          $invoiceName .= 'Opening Balance';
                          //            opening balance payment
                          $request1['transaction_type_id'] = 26;
                          $request1['amount'] =  $amount;
                          $request1['internal_note'] = $internal_note;
                          $request1['payment_id'] = $payment_id;
                          $request1['payment_number'] = $payment_number;
                          $request1['reference_number'] = 'Opening Balance';
                          $request1['paid_by_id'] = $paidBy;
                          $request1['date'] = $date;
                          $transaction = GlobalController::InsertNewTransaction($request1);
//          deposit to account bank or cash
                          $request2['transaction_id'] = $transaction;
                          $request2['amount'] = $amount - $bankCharge;
                          $request2['account_id'] = $account_id;
                          $request2['is_debit'] = 1;
                          GlobalController::InsertNewTransactionDetails($request2);
                          if($bankCharge > 0){
//          deposit to bank charges
                              $bankChargeRequest['transaction_id'] = $transaction;
                              $bankChargeRequest['amount'] = $bankCharge;
                              $bankChargeRequest['account_id'] = $bankChargeAccount;
                              $bankChargeRequest['is_debit'] = 1;
                              GlobalController::InsertNewTransactionDetails($bankChargeRequest);
                          }
//          credit from receivable account
                          $request3['transaction_id'] = $transaction;
                          $request3['amount'] = $amount;
                          $request3['account_id'] = $receivableAccount;
                          $request3['is_debit'] = 0;
                          GlobalController::InsertNewTransactionDetails($request3);
                          // Insert comment to partner
                           $comment = 'Payment#' . $payment_number . ' details modified. Amount from opening balance applied '
                               . $currencyName . ' ' . $amount;
                           GlobalController::InsertNewComment(11, $paidBy, NULL, $comment);

                      }
                }
                }
            }
            else{
                //            if the amount received is totally for invoices
//            transaction type payment as payment received
                $request1['transaction_type_id'] = 1;
                $request1['amount'] =  $amount;
                $request1['internal_note'] = $internal_note;
                $request1['payment_id'] = $payment_id;
                $request1['payment_number'] = $payment_number;
                $request1['reference_number'] = $reference_number;
                $request1['paid_by_id'] = $paidBy;
                $request1['date'] = $date;
                $transaction = GlobalController::InsertNewTransaction($request1);
//                    deposit to account bank or cash
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $amount - $bankCharge;
                $request2['account_id'] = $account_id;
                $request2['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($request2);
                if($bankCharge > 0){
//          deposit to bank charges
                    $bankChargeRequest['transaction_id'] = $transaction;
                    $bankChargeRequest['amount'] = $bankCharge;
                    $bankChargeRequest['account_id'] = $bankChargeAccount;
                    $bankChargeRequest['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($bankChargeRequest);
                }
//                    credit from advanced account
                $request3['transaction_id'] = $transaction;
                $request3['amount'] = $amount;
                $request3['account_id'] = $advPaymentAccount;
                $request3['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($request3);
//                    more than one invoice
                $invoiceName = '';
                if (isset($invoice_ids)) {
                    foreach ($invoice_ids as $index => $id) {
                        $ref_inv_number = '';
                        if ($payment_amount[$index] > 0) {
                            if($is_invoices[$index] == 1){
//                    update the due amount of the invoice
                            $salesInvoice = SalesInvoice::find($invoice_ids[$index]);
                            if ($salesInvoice) {
                                if( $salesInvoice->amount_due - $payment_amount[$index] == 0){
                                    $salesInvoice->status = 7; // paid
                                }
                                if($salesInvoice->order_number){
                                    $ref_inv_number = $salesInvoice->order_number;
                                }
                                $salesInvoice->amount_due -= $payment_amount[$index];
                                $salesInvoice->amount_received += $payment_amount[$index];
                                $invoiceName .= $salesInvoice->invoice_number.',';
                                $salesInvoice->save();
                            }
                            // transaction as invoice payment
                            $request1['transaction_type_id'] = 4;
                            $request1['amount'] =  $payment_amount[$index];
                            $request1['internal_note'] = $internal_note;
                            $request1['payment_id'] = $payment_id;
                            $request1['payment_number'] = $payment_number;
                            $request1['reference_number'] = $ref_inv_number;
                            $request1['paid_by_id'] = $paidBy;
                            $request1['date'] = $date;
                            $transaction = GlobalController::InsertNewTransaction($request1);
                            // transaction Details for each invoice
                            // deposit to adv payment
                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $payment_amount[$index];
                            $request2['account_id'] = $advPaymentAccount;
                            $request2['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($request2);
                            // credit from receivable account
                            $request3['transaction_id'] = $transaction;
                            $request3['amount'] = $payment_amount[$index];
                            $request3['account_id'] = $receivableAccount;
                            $request3['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($request3);
                            // connect the invoice with the transaction
                            $invoiceRequest['transaction_id'] = $transaction;
                            $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                            $invoiceRequest['invoice_type_id'] = 3;
                            GlobalController::InsertNewTransactionInvoice($invoiceRequest);
                            // Check conditions and insert comments
                            if (isset($invoice_ids[$index]) && isset($invoiceAmounts[$invoice_ids[$index]]) && $invoiceAmounts[$invoice_ids[$index]] > 0) {
                                if ($invoiceAmounts[$invoice_ids[$index]] != $payment_amount[$index]) {
                                    // Insert comment to invoice
                                    $comment = 'Payment#' . $payment_number . ' details modified. Applied amount changed from '
                                        . $currencyName . '' . $invoiceAmounts[$invoice_ids[$index]] . ' to ' . $currencyName . '' .  $payment_amount[$index]  .'.';
                                    GlobalController::InsertNewComment(3, $invoice_ids[$index], NULL, $comment);
                                }
                            }else if(!isset($invoiceAmounts[$invoice_ids[$index]])){
                                // Insert comment to invoice
                                $comment = 'Payment#' . $payment_number . ' details modified. Amount of ' . $currencyName . ' ' . $payment_amount[$index] .' added to the invoice.';
                                GlobalController::InsertNewComment(3, $invoice_ids[$index], NULL, $comment);
                            }
                        }else{
//                                    for opening balance
                                $invoiceName .= 'Opening Balance, ';
                                // transaction as opening payment
                                $request1['transaction_type_id'] = 26;
                                $request1['amount'] =  $payment_amount[$index];
                                $request1['internal_note'] = $internal_note;
                                $request1['payment_id'] = $payment_id;
                                $request1['payment_number'] = $payment_number;
                                $request1['reference_number'] = 'Opening Balance';
                                $request1['paid_by_id'] = $paidBy;
                                $request1['date'] = $date;
                                $transaction = GlobalController::InsertNewTransaction($request1);

                                // deposit to adv payment
                                $request2['transaction_id'] = $transaction;
                                $request2['amount'] = $payment_amount[$index];
                                $request2['account_id'] = $advPaymentAccount;
                                $request2['is_debit'] = 1;
                                GlobalController::InsertNewTransactionDetails($request2);
                                // credit from receivable account
                                $request3['transaction_id'] = $transaction;
                                $request3['amount'] = $payment_amount[$index];
                                $request3['account_id'] = $receivableAccount;
                                $request3['is_debit'] = 0;
                                GlobalController::InsertNewTransactionDetails($request3);

                                // Insert comment to partner
                                $comment = 'Payment#' . $payment_number . ' details modified. Amount from opening balance applied '
                                    . $currencyName . ' ' . $payment_amount[$index];
                                GlobalController::InsertNewComment(11, $paidBy, NULL, $comment);
                            }

                        }
                    }
                }

            }

            //insert comment to payment
            $comment =  'Payment details modified.';
            GlobalController::InsertNewComment(9, $payment_id,NULL, $comment);
            //      Insert comment to customer
            $title =  'Payments Received Updated';
            $comment =  'Payment#' . $payment_number . 'details modified.';
            GlobalController::InsertNewComment(11, $paidBy, $title, $comment);

        }
        else{
//            advanced payment
            $request1['transaction_type_id'] = 3;
            $request1['amount'] =  $amount;
            $request1['internal_note'] = $internal_note;
            $request1['payment_id'] = $payment_id;
            $request1['payment_number'] = $payment_number;
            $request1['reference_number'] = $reference_number;
            $request1['paid_by_id'] = $paidBy;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
//          deposit to account bank or cash
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $amount - $bankCharge;
            $request2['account_id'] = $account_id;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);
            if($bankCharge > 0){
//          deposit to bank charges
                $bankChargeRequest['transaction_id'] = $transaction;
                $bankChargeRequest['amount'] = $bankCharge;
                $bankChargeRequest['account_id'] = $bankChargeAccount;
                $bankChargeRequest['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($bankChargeRequest);
            }
//          credit from advance account
            $request3['transaction_id'] = $transaction;
            $request3['amount'] = $amount;
            $request3['account_id'] = $advPaymentAccount;
            $request3['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request3);
            //insert comment to payment
            $comment =  'Payment details modified.';
            GlobalController::InsertNewComment(9, $payment_id, NULL, $comment);
            //      Insert comment to customer
            $title =  'Payments Received Updated';
            $comment =  'Payment#' . $payment_number . 'details modified.';
            GlobalController::InsertNewComment(11, $paidBy, $title, $comment);

        }
        $payment->save();


        return redirect()->route('TransactionController.showPaymentsReceived', ['#row-' . $payment_id]);
    }
    public function editPaymentsReceived($id){
        $organizationId = org_id();

//        payment
        $id = Crypt::decryptString($id);
        $payment = PaymentReceived::findorfail($id);

        $files = PaymentRecDocuments::where('payment_id', $id)
            ->get();
        $paymentDetails = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'partner'])
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();

        $openingBalanceData = self::getOpeningBalanceData($payment->paid_by_id);

        $firstDebitDetail = null;
        $transactions = $paymentDetails->Transaction;
//        return var_dump($paymentDetails);
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
                $firstDebitDetail = $firstTransaction->transactionDetails()->where('is_debit', 1)
                    ->whereHas('account', function ($query) {
                        $query->whereIn('type_id', [15, 16]);
                    })
                    ->first();
            }
        }

//        receivables
        $partners = Partner::where('partner_type', 2)
            ->where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->get();
        $paymentAccounts = PaymentAccounts();
        $paymentRefund = PaymentReceived::where('organization_id', $organizationId)
            ->where('id', $id)
            ->whereHas('transaction', function($query) {
                $query->where('transaction_type_id', 6);
            })
            ->with(['transaction' => function($query) {
                $query->where('transaction_type_id', 6);
            }])
            ->get()
            ->sum(function($paymentReceived) {
                return $paymentReceived->transaction->sum('amount');
            });

        return view('pages.payments.customer.edit', compact(['partners','openingBalanceData', 'paymentAccounts' , 'files' , 'paymentDetails' , 'payment', 'firstDebitDetail' , 'paymentRefund' ]));
    }
    public function editPaymentsMade($id){
        $organizationId = org_id();

//        payment
        $id = Crypt::decryptString($id);
        $payment = PaymentReceived::findorfail($id);
        $files = PaymentRecDocuments::where('payment_id', $id)
            ->get();
        $paymentDetails = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'partner'])
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();

        $firstDebitDetail = null;
        $transactions = $paymentDetails->Transaction;
        $openingBalanceData = self::getPayableOpeningBalanceData($payment->paid_by_id);

//        return var_dump($paymentDetails);
        if ($transactions->count() > 1) {
//                    get tye payment or advance
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
                $firstDebitDetail = $firstTransaction->transactionDetails()->where('is_debit', 0)
                    ->whereHas('account', function ($query) {
                        $query->whereIn('type_id', [15, 16]);
                    })
                    ->first();
            }
        }

//        suppliers
        $partners = Suppliers();
        $paymentAccounts = PaymentAccounts();
        $paymentRefund = PaymentReceived::where('organization_id', $organizationId)
            ->where('id', $id)
            ->whereHas('transaction', function($query) {
                $query->where('transaction_type_id', 16);
            })
            ->with(['transaction' => function($query) {
                $query->where('transaction_type_id', 16);
            }])
            ->get()
            ->sum(function($paymentReceived) {
                return $paymentReceived->transaction->sum('amount');
            });

        return view('pages.payments.payable.edit', compact(['partners','openingBalanceData', 'paymentAccounts' ,'files' , 'paymentDetails' , 'payment', 'firstDebitDetail' , 'paymentRefund' ]));
    }
    public function updatePaymentsMade(Request $request, $id)
    {
        $organizationId = org_id();
        $paidBy = $request->paid_by_id;
        $amount = $request->amount;
        $refunded_amount = $request->refunded_amount;
        $date = $request->date;
        $account_id = $request->account_id;
        $reference_number = $request->reference_number;
        $internal_note = $request->internal_note;
        $mode = $request->mode;
        $is_invoices = $request->is_invoice;
        $invoice_ids = $request->invoice_id;
        $payment_amount = $request->payment_amount;
        $dueAmounts = $request->due_amount;
        $paymentAmounts = $request->input('payment_amount', []);
        $totalPaymentAmount = array_sum($paymentAmounts);
        $paymentAmountsCollection = collect($paymentAmounts);
        $filteredAmounts = $paymentAmountsCollection->filter(function ($amount) {
            return $amount > 0;
        });
        $countInvoice = $filteredAmounts->count();
        $payableAccount = GetPayableAccount();
        $advPaymentAccount = GetPrepaidPaymentAccount();
        $currencyName = currencyName();
        $unusedAmount = ($amount - $refunded_amount) - $totalPaymentAmount;
        $isInvoice = false;
        $isAdv = 17;

        $totalPaidAmount = 0;
        $errors = [];
        if($payment_amount){
            foreach ($payment_amount as $index => $paid_Amount) {
                $paid_Amount = floatval($paid_Amount) ?: 0; // Ensure it's a float
                $dueAmount = floatval($dueAmounts[$index]) ?: 0; // Corresponding due amount

                // Add to the total paid amount
                $totalPaidAmount += $paid_Amount;
            }
            $totalExceed = $amount - $totalPaidAmount;
            if ($totalExceed < 0) {
                $errors[] = "Total payment amount cannot exceed the amount received ($amount).";
            }
            if (!empty($errors)) {
                return redirect()->back()->withErrors($errors)->withInput();
            }

        }
        foreach ($paymentAmounts as $paidAmount) {
            if ($paidAmount > 0) {
                $isInvoice = true;
                $isAdv = 2;
                break;
            }
        }
        $id = Crypt::decryptString($id);
        $payment = PaymentReceived::findorfail($id);
        if(empty($payment->first())){
            return redirect()->back()->with('error', 'Please make sure that the payment record are correct.');
        }
        $referenceNumber = $request->input('reference_number');
        if ($referenceNumber !== $payment->reference_number) {
            $referenceExist = checkReferenceExists($referenceNumber);
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'reference_number' => __('messages.reference_exists'),
                ])->withInput();
            }
        }


        $selectedFiles = $request->input('current_files');
        if ($selectedFiles) {
            $selectedFilesFiltered = array_map('intval', $selectedFiles);
            $selectedFilesFiltered = array_filter($selectedFilesFiltered, function ($value) {
                return $value !== NULL;
            });
            //        remove deleted  files
            PaymentRecDocuments::where('payment_id', $id)
                ->whereNotIn('id', $selectedFilesFiltered)
                ->delete();
        }else{
            PaymentRecDocuments::where('payment_id', $id)
                ->delete();
        }
        if ($request->hasFile('files')) {
            $fileNamesToStore = [];
            foreach ($request->file('files') as $file) {
                $fileName = uploadFile($file, 'payment_received');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new PaymentRecDocuments();
                $invoiceFile->payment_id = $id;
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }
        if (validateVATDate($payment->date)) {
            $payment->reference_number = $reference_number;
            $payment->note = $internal_note;
            $payment->save();
            return redirect()->back()->withInput()->with('warning', errorMsg().', Only basic details can be updated.');
        }
        $payment_id = $payment->id;
        $payment_number = $payment->payment_number;

//        return var_dump($payment_id);
//        update basic details about the payment
        $payment->amount = $amount;
        $payment->unused_amount = $unusedAmount;
        $payment->reference_number = $reference_number;
        $payment->mode = $mode;
        $payment->date = $date;
        $payment->note = $internal_note;
        $payment->type_id = $isAdv;



//        delete the transaction related to the payment
        $invoiceAmounts = [];

        DB::transaction(function () use ($payment_id, $reference_number, $amount, &$invoiceAmounts) {

            $transactionsToDelete = Transaction::where('payment_id', $payment_id)
                ->where('organization_id', org_id())
                ->whereIn('transaction_type_id', [2,17,15, 26])
                ->get();

            foreach ($transactionsToDelete as $transaction) {

                if($transaction->TransactionInvoice->first()){
                    $invoice_id = $transaction->TransactionInvoice->first()->invoice_id;
                    $invAmount = $transaction->amount;
//                    b3ml store lal amount la kl invoice, rah tlzamni bl comment history
                    if (!isset($invoiceAmounts[$invoice_id])) {
                        $invoiceAmounts[$invoice_id] = 0;
                    }
                    $invoiceAmounts[$invoice_id] += $invAmount;

//                    braje3 l amount lal invoice
                    $salesInvoice = PurchaseInvoice::find($invoice_id);
                    if ($salesInvoice) {
                        $salesInvoice->status = 4;
                        $salesInvoice->amount_due += $invAmount;
                        $salesInvoice->amount_received -= $invAmount;
                        $salesInvoice->save();
                    }
                }
//                b3mel delete lal transaction lmarbuta 3al payment w brja3 bzidon based 3al condition li 3ndi ta7et
                $transaction->TransactionDetails()->delete();
                $transaction->TransactionInvoice()->delete();
                $transaction->delete();
            }
        });
//        return var_dump($invoiceAmounts);
        if($isInvoice){

            if($totalPaymentAmount == $amount && $countInvoice == 1){
//                if the payment received is for one invoice only
                $invoiceName = '';
                foreach ($invoice_ids as $index => $id) {
                    $ref_inv_number = '';
                    if ($payment_amount[$index] > 0) {
                        if($is_invoices[$index] == 1){
                            // update the due amount of the invoice
                        $salesInvoice = PurchaseInvoice::find($invoice_ids[$index]);
                        if ($salesInvoice) {
                            if( $salesInvoice->amount_due - $payment_amount[$index] == 0){
                                $salesInvoice->status = 7; // paid
                            }
                            if($salesInvoice->order_number){
                                $ref_inv_number = $salesInvoice->order_number;
                            }
                            $salesInvoice->amount_due -= $payment_amount[$index];
                            $salesInvoice->amount_received += $payment_amount[$index];
                            $invoiceName .= $salesInvoice->invoice_number.',';
                            $salesInvoice->save();
                        }
                        //      transaction type invoice payment
                        $request1['transaction_type_id'] = 15;
                        $request1['amount'] =  $amount;
                        $request1['internal_note'] = $internal_note;
                        $request1['payment_id'] = $payment_id;
                        $request1['payment_number'] = $payment_number;
                        $request1['reference_number'] = $ref_inv_number;
                        $request1['paid_by_id'] = $paidBy;
                        $request1['date'] = $date;
                        $transaction = GlobalController::InsertNewTransaction($request1);
//                    deposit to account bank or cash
                        $request2['transaction_id'] = $transaction;
                        $request2['amount'] = $amount ;
                        $request2['account_id'] = $account_id;
                        $request2['is_debit'] = 0;
                        GlobalController::InsertNewTransactionDetails($request2);
//                    credit from receivable account
                        $request3['transaction_id'] = $transaction;
                        $request3['amount'] = $amount;
                        $request3['account_id'] = $payableAccount;
                        $request3['is_debit'] = 1;
                        GlobalController::InsertNewTransactionDetails($request3);

                        // connect the invoice with the transaction
                        $invoiceRequest['transaction_id'] = $transaction;
                        $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                        $invoiceRequest['invoice_type_id'] = 4;
                        GlobalController::InsertNewTransactionInvoice($invoiceRequest);

                        if ($invoiceAmounts[$invoice_ids[$index]] != $payment_amount[$index]) {
                            // Insert comment to invoice
                            $comment = 'Payment#' . $payment_number . ' details modified. Amount changed from '
                                . $currencyName . ' ' . $invoiceAmounts[$invoice_ids[$index]] . ' to ' . $currencyName . ' ' .  $payment_amount[$index]  .'.';
                            GlobalController::InsertNewComment(4, $invoice_ids[$index], NULL, $comment);
                        }
                    }else{

//                                 for opening balance only
                            $invoiceName .= 'Opening Balance';

                            //            opening balance payment
                            $request1['transaction_type_id'] = 26;
                            $request1['amount'] =  $payment_amount[$index];
                            $request1['internal_note'] = $internal_note;
                            $request1['payment_id'] = $payment_id;
                            $request1['payment_number'] = $payment_number;
                            $request1['reference_number'] = 'Opening Balance';
                            $request1['paid_by_id'] = $paidBy;
                            $request1['date'] = $date;
                            $transaction = GlobalController::InsertNewTransaction($request1);

                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $payment_amount[$index];
                            $request2['account_id'] = $account_id;
                            $request2['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($request2);

                            $request3['transaction_id'] = $transaction;
                            $request3['amount'] = $payment_amount[$index];
                            $request3['account_id'] = $payableAccount;
                            $request3['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($request3);

                            // Insert comment to partner
                            $comment = 'Payment#' . $payment_number . ' details modified. Amount for opening balance applied '
                                . $currencyName . ' ' . $payment_amount[$index];
                            GlobalController::InsertNewComment(12, $paidBy, NULL, $comment);
                        }

                    }
                }
            }else{
                //            if the amount received is totally for invoices
//            transaction type payment as payment received
                $request1['transaction_type_id'] = 2;
                $request1['amount'] =  $amount;
                $request1['internal_note'] = $internal_note;
                $request1['payment_id'] = $payment_id;
                $request1['payment_number'] = $payment_number;
                $request1['reference_number'] = $payment->reference_number;
                $request1['paid_by_id'] = $paidBy;
                $request1['date'] = $date;
                $transaction = GlobalController::InsertNewTransaction($request1);
//                    deposit to account bank or cash
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $amount ;
                $request2['account_id'] = $account_id;
                $request2['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($request2);
//                    credit from advanced account
                $request3['transaction_id'] = $transaction;
                $request3['amount'] = $amount;
                $request3['account_id'] = $advPaymentAccount;
                $request3['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($request3);
//                    more than one invoice
                $invoiceName = '';
                if (isset($invoice_ids)) {
                    foreach ($invoice_ids as $index => $id) {
                        $ref_inv_number = '';
                        if ($payment_amount[$index] > 0) {
                            if($is_invoices[$index] == 1){
//                    update the due amount of the invoice
                            $salesInvoice = PurchaseInvoice::find($invoice_ids[$index]);
                            if ($salesInvoice) {
                                if( $salesInvoice->amount_due - $payment_amount[$index] == 0){
                                    $salesInvoice->status = 7; // paid
                                }
                                if($salesInvoice->order_number){
                                    $ref_inv_number = $salesInvoice->order_number;
                                }
                                $salesInvoice->amount_due -= $payment_amount[$index];
                                $salesInvoice->amount_received += $payment_amount[$index];
                                $invoiceName .= $salesInvoice->invoice_number.',';
                                $salesInvoice->save();
                            }
                            // transaction as invoice payment
                            $request1['transaction_type_id'] = 15;
                            $request1['amount'] =  $payment_amount[$index];
                            $request1['internal_note'] = $internal_note;
                            $request1['payment_id'] = $payment_id;
                            $request1['payment_number'] = $payment_number;
                            $request1['reference_number'] = $ref_inv_number;
                            $request1['paid_by_id'] = $paidBy;
                            $request1['date'] = $date;
                            $transaction = GlobalController::InsertNewTransaction($request1);
                            // transaction Details for each invoice
                            // deposit to adv payment
                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $payment_amount[$index];
                            $request2['account_id'] = $advPaymentAccount;
                            $request2['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($request2);
                            // credit from receivable account
                            $request3['transaction_id'] = $transaction;
                            $request3['amount'] = $payment_amount[$index];
                            $request3['account_id'] = $payableAccount;
                            $request3['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($request3);
                            // connect the invoice with the transaction
                            $invoiceRequest['transaction_id'] = $transaction;
                            $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                            $invoiceRequest['invoice_type_id'] = 4;
                            GlobalController::InsertNewTransactionInvoice($invoiceRequest);
                            // Check conditions and insert comments
                            if (isset($invoice_ids[$index]) && isset($invoiceAmounts[$invoice_ids[$index]]) && $invoiceAmounts[$invoice_ids[$index]] > 0) {
                                if ($invoiceAmounts[$invoice_ids[$index]] != $payment_amount[$index]) {
                                    // Insert comment to invoice
                                    $comment = 'Payment#' . $payment_number . ' details modified. Amount changed from '
                                        . $currencyName . ' ' . $invoiceAmounts[$invoice_ids[$index]] . ' to ' . $currencyName . ' ' .  $payment_amount[$index]  .'.';
                                    GlobalController::InsertNewComment(4, $invoice_ids[$index], NULL, $comment);
                                }
                            }else if(!isset($invoiceAmounts[$invoice_ids[$index]])){
                                // Insert comment to invoice
                                $comment = 'Payment#' . $payment_number . ' details modified. Amount of ' . $currencyName . ' ' . $payment_amount[$index] .' added to the invoice.';
                                GlobalController::InsertNewComment(4, $invoice_ids[$index], NULL, $comment);
                            }
                        }  else{

//                                 for opening balance only
                        $invoiceName .= 'Opening Balance';

                        //            opening balance payment
                        $request1['transaction_type_id'] = 26;
                        $request1['amount'] =  $payment_amount[$index];
                        $request1['internal_note'] = $internal_note;
                        $request1['payment_id'] = $payment_id;
                        $request1['payment_number'] = $payment_number;
                        $request1['reference_number'] = 'Opening Balance';
                        $request1['paid_by_id'] = $paidBy;
                        $request1['date'] = $date;
                        $transaction = GlobalController::InsertNewTransaction($request1);

                        $request2['transaction_id'] = $transaction;
                        $request2['amount'] = $payment_amount[$index];
                        $request2['account_id'] = $advPaymentAccount;
                        $request2['is_debit'] = 0;
                        GlobalController::InsertNewTransactionDetails($request2);

                        $request3['transaction_id'] = $transaction;
                        $request3['amount'] = $payment_amount[$index];
                        $request3['account_id'] = $payableAccount;
                        $request3['is_debit'] = 1;
                        GlobalController::InsertNewTransactionDetails($request3);

                                // Insert comment to partner
                                $comment = 'Payment#' . $payment_number . ' details modified. Amount from opening balance applied '
                                    . $currencyName . ' ' . $payment_amount[$index];
                                GlobalController::InsertNewComment(12, $paidBy, NULL, $comment);
                    }
                        }
                    }
                }

            }

            //insert comment to payment
            $comment =  'Payment details modified.';
            GlobalController::InsertNewComment(10, $payment_id,NULL, $comment);
            //      Insert comment to customer
            $title =  'Payments Made Updated';
            $comment =  'Payment#' . $payment_number . 'details modified.';
            GlobalController::InsertNewComment(12, $paidBy, $title, $comment);

        }
        else{
//            advanced payment
            $request1['transaction_type_id'] = 17;
            $request1['amount'] =  $amount;
            $request1['internal_note'] = $internal_note;
            $request1['payment_id'] = $payment_id;
            $request1['payment_number'] = $payment_number;
            $request1['reference_number'] = $payment->reference_number;
            $request1['paid_by_id'] = $paidBy;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
//          deposit to account bank or cash
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $amount ;
            $request2['account_id'] = $account_id;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
//          credit from advance account
            $request3['transaction_id'] = $transaction;
            $request3['amount'] = $amount;
            $request3['account_id'] = $advPaymentAccount;
            $request3['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request3);
            //insert comment to payment
            $comment =  'Payment details modified.';
            GlobalController::InsertNewComment(10, $payment_id, NULL, $comment);
            //      Insert comment to customer
            $title =  'Payments Made Updated';
            $comment =  'Payment#' . $payment_number . 'details modified.';
            GlobalController::InsertNewComment(12, $paidBy, $title, $comment);

        }
        $payment->save();


        return redirect()->route('TransactionController.showPaymentsMade', ['#row-' . $payment_id]);
    }
    public function storePaymentsReceived(Request $request)
    {
        $organizationId = org_id();

        $bankCharge = $request->bank_charge;
        $paidBy = $request->paid_by_id;
        $amount = $request->amount;
        $date = $request->date;
        $payment_number = $request->payment_number;
        $account_id = $request->account_id;
        $mode = $request->mode;
        $reference_number = $request->reference_number;
        $internal_note = $request->internal_note;
        $is_invoices = $request->is_invoice;
        $payment_amount = $request->payment_amount;
        $invoice_ids = $request->invoice_id;
        $dueAmounts = $request->due_amount;
        $paymentAmounts = $request->input('payment_amount', []);
        $totalPaymentAmount = array_sum($paymentAmounts);
        $paymentAmountsCollection = collect($paymentAmounts);
        $filteredAmounts = $paymentAmountsCollection->filter(function ($amount) {
            return $amount > 0;
        });
        $countInvoice = $filteredAmounts->count();
        $receivableAccount = GetReceivableAccount();
        $advPaymentAccount = GetAdvPaymentAccount();
        $bankChargeAccount = GetBankChargeAccount();
        $currencyName = currencyName();
        $unusedAmount = $amount - $totalPaymentAmount ;

        if (validateVATDate($request->date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }


        $referenceExist = checkReferenceExists($reference_number);
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }


        // Initialize variables
        $totalPaidAmount = 0;
        $errors = [];
        if($payment_amount){
            foreach ($payment_amount as $index => $paid_Amount) {
                $paid_Amount = floatval($paid_Amount) ?: 0; // Ensure it's a float
                $dueAmount = floatval($dueAmounts[$index]) ?: 0; // Corresponding due amount
                // Check if the payment exceeds the due amount
                if ($paid_Amount > $dueAmount) {
                    $errors[] = "Payment cannot exceed the due amount of $dueAmount.";
                }

                // Add to the total paid amount
                $totalPaidAmount += $paid_Amount;
            }
            $totalExceed = $amount - $totalPaidAmount;
            if ($totalExceed < 0) {
                $errors[] = "Total payment amount cannot exceed the amount received ($amount).";
            }
            if (!empty($errors)) {
                return redirect()->back()->withErrors($errors)->withInput();
            }

        }

        // Check if it's related to an invoice
        $isInvoice = false;
        $isAdv = 3;// adv payment
        foreach ($paymentAmounts as $paidAmount) {
            if ($paidAmount > 0) {
                $isInvoice = true;
                $isAdv = 1; //payment received
                break;
            }
        }

//        INSERT NEW PAYMENT RECEIVED
        $payment['type_id'] = $isAdv; // payment received
        $payment['amount'] =  $amount;
        $payment['unused_amount'] =  $unusedAmount;
        $payment['internal_note'] = $internal_note;
        $payment['bank_charge'] = $bankCharge;
        $payment['payment_number'] = $payment_number;
        $payment['mode'] = $mode;
        $payment['reference_number'] = $reference_number;
        $payment['paid_by_id'] = $paidBy;
        $payment['date'] = $date;
        $payment_id = GlobalController::InsertNewPaymentReceived($payment);

//        store attachments
        if ($request->hasFile('files')) {
            $fileNamesToStore = [];
            foreach ($request->file('files') as $file) {
                $fileName = uploadFile($file, 'payment_received');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new PaymentRecDocuments();
                $invoiceFile->payment_id = $payment_id;
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }


        if($isInvoice){
//            if the amount received is totally for invoices
            if($totalPaymentAmount == $amount && $countInvoice == 1){
//                if the payment received is for one invoice only
                $invoiceName = '';
                foreach ($invoice_ids as $index => $id) {
                    $ref_inv_number = '';
                    if ($payment_amount[$index] > 0) {
                        if($is_invoices[$index] == 1){
                            // update the due amount of the invoice
                            $salesInvoice = SalesInvoice::find($invoice_ids[$index]);
                            if ($salesInvoice) {
                                if( $salesInvoice->amount_due - $payment_amount[$index] == 0){
                                    $salesInvoice->status = 7; // paid
                                }
                                if($salesInvoice->order_number){
                                    $ref_inv_number = $salesInvoice->order_number;
                                }
                                $salesInvoice->amount_due -= $payment_amount[$index];
                                $salesInvoice->amount_received += $payment_amount[$index];
                                $invoiceName .= $salesInvoice->invoice_number.',';
                                $salesInvoice->save();
                            }
                            $request1['transaction_type_id'] = 4;
                            $request1['amount'] =  $totalPaymentAmount;
                            $request1['payment_id'] = $payment_id;
                            $request1['payment_number'] = $payment_number;
                            $request1['reference_number'] = $ref_inv_number;
                            $request1['paid_by_id'] = $paidBy;
                            $request1['date'] = $date;
                            $transaction = GlobalController::InsertNewTransaction($request1);
//                    deposit to account bank or cash
                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $totalPaymentAmount - $bankCharge;
                            $request2['account_id'] = $account_id;
                            $request2['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($request2);
                            if($bankCharge > 0){
//          deposit to bank charges
                                $bankChargeRequest['transaction_id'] = $transaction;
                                $bankChargeRequest['amount'] = $bankCharge;
                                $bankChargeRequest['account_id'] = $bankChargeAccount;
                                $bankChargeRequest['is_debit'] = 1;
                                GlobalController::InsertNewTransactionDetails($bankChargeRequest);
                            }
//                    credit from receivable account
                            $request3['transaction_id'] = $transaction;
                            $request3['amount'] = $totalPaymentAmount;
                            $request3['account_id'] = $receivableAccount;
                            $request3['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($request3);

                            // connect the invoice with the transaction
                            $invoiceRequest['transaction_id'] = $transaction;
                            $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                            $invoiceRequest['invoice_type_id'] = 3;
                            GlobalController::InsertNewTransactionInvoice($invoiceRequest);

//                             Insert comment to invoice
                            $comment =  'Payment of '.$currencyName.' '.$payment_amount[$index].' made.';
                            GlobalController::InsertNewComment(3, $invoice_ids[$index],NULL, $comment);

                        }else{
//                                 for opening balance only
                            $invoiceName .= 'Opening Balance';

                            //            opening balance payment
                            $request1['transaction_type_id'] = 26;
                            $request1['amount'] =  $amount;
                            $request1['internal_note'] = $internal_note;
                            $request1['payment_id'] = $payment_id;
                            $request1['payment_number'] = $payment_number;
                            $request1['reference_number'] = 'Opening Balance';
                            $request1['paid_by_id'] = $paidBy;
                            $request1['date'] = $date;
                            $transaction = GlobalController::InsertNewTransaction($request1);
//          deposit to account bank or cash
                            $request2['transaction_id'] = $transaction;
                            $request2['amount'] = $amount - $bankCharge;
                            $request2['account_id'] = $account_id;
                            $request2['is_debit'] = 1;
                            GlobalController::InsertNewTransactionDetails($request2);
                            if($bankCharge > 0){
//          deposit to bank charges
                                $bankChargeRequest['transaction_id'] = $transaction;
                                $bankChargeRequest['amount'] = $bankCharge;
                                $bankChargeRequest['account_id'] = $bankChargeAccount;
                                $bankChargeRequest['is_debit'] = 1;
                                GlobalController::InsertNewTransactionDetails($bankChargeRequest);
                            }
//          credit from receivable account
                            $request3['transaction_id'] = $transaction;
                            $request3['amount'] = $amount;
                            $request3['account_id'] = $receivableAccount;
                            $request3['is_debit'] = 0;
                            GlobalController::InsertNewTransactionDetails($request3);
                            // Insert comment to partner
                            $comment = 'Payment of' . $currencyName . ' ' . $payment_amount[$index] . ' applied for opening balance ';
                            GlobalController::InsertNewComment(11, $paidBy, NULL, $comment);
                        }
                    }
                }
                //insert comment to payment
                $comment =  'Payment of amount '.$currencyName.' '.$amount.' received and applied for '.rtrim($invoiceName, ",").'.';
                GlobalController::InsertNewComment(9, $payment_id,NULL, $comment);
                //      Insert comment to customer
                $title =  'Payments Received added';
                $comment =  'Payment of '.$currencyName.' '.$amount.' made and applied for '.rtrim($invoiceName, ",").'.';
                GlobalController::InsertNewComment(11, $paidBy, $title, $comment);
//                }
            }
            else{
                //      transaction type payment as payment received
                $request1['transaction_type_id'] = 1;
                $request1['amount'] =  $amount;
                $request1['internal_note'] = $internal_note;
                $request1['payment_id'] = $payment_id;
                $request1['payment_number'] = $payment_number;
                $request1['reference_number'] = $reference_number;
                $request1['paid_by_id'] = $paidBy;
                $request1['date'] = $date;
                $transaction = GlobalController::InsertNewTransaction($request1);
//                    deposit to account bank or cash
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $amount - $bankCharge;
                $request2['account_id'] = $account_id;
                $request2['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($request2);
                if($bankCharge > 0){
//          deposit to bank charges
                    $bankChargeRequest['transaction_id'] = $transaction;
                    $bankChargeRequest['amount'] = $bankCharge;
                    $bankChargeRequest['account_id'] = $bankChargeAccount;
                    $bankChargeRequest['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($bankChargeRequest);
                }
//                    credit from advanced account
                $request3['transaction_id'] = $transaction;
                $request3['amount'] = $amount;
                $request3['account_id'] = $advPaymentAccount;
                $request3['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($request3);
//                    more than one invoice
                $invoiceName = '';
                $totalAmount = 0;
                if (isset($invoice_ids)) {
                    foreach ($invoice_ids as $index => $id) {
                        $ref_inv_number = '';
                        if ($payment_amount[$index] > 0) {
                            if($is_invoices[$index] == 1){
                                //update the due amount of the invoice
                                $salesInvoice = SalesInvoice::find($invoice_ids[$index]);
                                $totalAmount += $payment_amount[$index];
                                if ($salesInvoice) {
                                    if( $salesInvoice->amount_due - $payment_amount[$index] == 0){
                                        $salesInvoice->status = 7; // paid
                                    }
                                    if($salesInvoice->order_number){
                                        $ref_inv_number = $salesInvoice->order_number;
                                    }
                                    $salesInvoice->amount_due -= $payment_amount[$index];
                                    $salesInvoice->amount_received += $payment_amount[$index];
                                    $invoiceName .= $salesInvoice->invoice_number.',';
                                    $salesInvoice->save();
                                }
                                // transaction as invoice payment
                                $request1['transaction_type_id'] = 4;
                                $request1['amount'] =  $payment_amount[$index];
                                $request1['payment_id'] = $payment_id;
                                $request1['payment_number'] = $payment_number;
                                $request1['reference_number'] = $ref_inv_number;
                                $request1['paid_by_id'] = $paidBy;
                                $request1['date'] = $date;
                                $transaction = GlobalController::InsertNewTransaction($request1);

                                // transaction Details for each invoice
                                // deposit to adv payment
                                $request2['transaction_id'] = $transaction;
                                $request2['amount'] = $payment_amount[$index];
                                $request2['account_id'] = $advPaymentAccount;
                                $request2['is_debit'] = 1;
                                GlobalController::InsertNewTransactionDetails($request2);
                                // credit from receivable account
                                $request3['transaction_id'] = $transaction;
                                $request3['amount'] = $payment_amount[$index];
                                $request3['account_id'] = $receivableAccount;
                                $request3['is_debit'] = 0;
                                GlobalController::InsertNewTransactionDetails($request3);

                                // connect the invoice with the transaction
                                $invoiceRequest['transaction_id'] = $transaction;
                                $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                                $invoiceRequest['invoice_type_id'] = 3;
                                GlobalController::InsertNewTransactionInvoice($invoiceRequest);

                                $comment =  'Payment of '.$currencyName.' '.$payment_amount[$index].' made.';
                                GlobalController::InsertNewComment(3, $invoice_ids[$index],NULL, $comment);
                            }else{
//                                    for opening balance
                                $invoiceName .= 'Opening Balance, ';
                                // transaction as opening payment
                                $request1['transaction_type_id'] = 26;
                                $request1['amount'] =  $payment_amount[$index];
                                $request1['payment_id'] = $payment_id;
                                $request1['payment_number'] = $payment_number;
                                $request1['reference_number'] = 'Opening Balance';
                                $request1['paid_by_id'] = $paidBy;
                                $request1['date'] = $date;
                                $transaction = GlobalController::InsertNewTransaction($request1);

                                // deposit to adv payment
                                $request2['transaction_id'] = $transaction;
                                $request2['amount'] = $payment_amount[$index];
                                $request2['account_id'] = $advPaymentAccount;
                                $request2['is_debit'] = 1;
                                GlobalController::InsertNewTransactionDetails($request2);
                                // credit from receivable account
                                $request3['transaction_id'] = $transaction;
                                $request3['amount'] = $payment_amount[$index];
                                $request3['account_id'] = $receivableAccount;
                                $request3['is_debit'] = 0;
                                GlobalController::InsertNewTransactionDetails($request3);

                                // Insert comment to partner
                                $comment = 'Payment of' . $currencyName . ' ' . $payment_amount[$index] . ' applied for opening balance ';
                                GlobalController::InsertNewComment(11, $paidBy, NULL, $comment);
                            }

                        }
                    }
                }
                //insert comment to payment
                $comment =  'Payment of amount '.$currencyName.' '.$totalAmount.' applied for '.rtrim($invoiceName, ",").'.';
                GlobalController::InsertNewComment(9, $payment_id,NULL, $comment);
                //      Insert comment to customer
                $title =  'Payments Received added';
                $comment =  'Payment of '.$currencyName.' '.$amount.' made and applied for '.rtrim($invoiceName, ",").'.';
                GlobalController::InsertNewComment(11, $paidBy, $title, $comment);

            }

        }

        if($isAdv == 3){
            //            advanced payment
            $request1['transaction_type_id'] = 3;
            $request1['amount'] =  $amount;
            $request1['internal_note'] = $internal_note;
            $request1['payment_id'] = $payment_id;
            $request1['payment_number'] = $payment_number;
            $request1['reference_number'] = $reference_number;
            $request1['paid_by_id'] = $paidBy;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
//          deposit to account bank or cash
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $amount - $bankCharge;
            $request2['account_id'] = $account_id;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);
            if($bankCharge > 0){
//          deposit to bank charges
                $bankChargeRequest['transaction_id'] = $transaction;
                $bankChargeRequest['amount'] = $bankCharge;
                $bankChargeRequest['account_id'] = $bankChargeAccount;
                $bankChargeRequest['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($bankChargeRequest);
            }
//          credit from advance account
            $request3['transaction_id'] = $transaction;
            $request3['amount'] = $amount;
            $request3['account_id'] = $advPaymentAccount;
            $request3['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request3);
            //insert comment to payment
            $comment =  'Payment of amount '.$currencyName.' '.$amount.' made.';
            GlobalController::InsertNewComment(9, $payment_id, NULL, $comment);
            //      Insert comment to customer
            $title =  'Payments Received added';
            $comment =  'Payment of '.$currencyName.' '.$amount.' made.';
            GlobalController::InsertNewComment(11, $paidBy, $title, $comment);

        }

        return redirect()->route('TransactionController.showPaymentsReceived', ['#row-' . $payment_id]);

    }
    public function storePaymentsMade(Request $request)
    {
        $organizationId = org_id();

        $paidBy = $request->paid_by_id;
        $amount = $request->amount;
        $date = $request->date;
        $payment_number = $request->payment_number;
        $account_id = $request->account_id;
        $mode = $request->mode;
        $reference_number = $request->reference_number;
        $internal_note = $request->internal_note;
        $invoice_ids = $request->invoice_id;
        $is_invoices = $request->is_invoice;
        $payment_amount = $request->payment_amount;
        $dueAmounts = $request->due_amount;
        $paymentAmounts = $request->input('payment_amount', []);
        $totalPaymentAmount = array_sum($paymentAmounts);
        $paymentAmountsCollection = collect($paymentAmounts);
        $filteredAmounts = $paymentAmountsCollection->filter(function ($amount) {
            return $amount > 0;
        });
        $countInvoice = $filteredAmounts->count();
        $payableAccount = GetPayableAccount();
        $advPaymentAccount = GetPrepaidPaymentAccount();
        $currencyName = currencyName();
        $unusedAmount = $amount - $totalPaymentAmount;
        if (validateVATDate($request->date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }

        $referenceExist = checkReferenceExists($reference_number);
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }

        // Initialize variables
        $totalPaidAmount = 0;
        $errors = [];
        if($payment_amount){
            foreach ($payment_amount as $index => $paid_Amount) {
                $paid_Amount = floatval($paid_Amount) ?: 0; // Ensure it's a float
                $dueAmount = floatval($dueAmounts[$index]) ?: 0; // Corresponding due amount

                // Check if the payment exceeds the due amount
                if ($paid_Amount > $dueAmount) {
                    $errors[] = "Payment cannot exceed the due amount of $dueAmount.";
                }

                // Add to the total paid amount
                $totalPaidAmount += $paid_Amount;
            }
            $totalExceed = $amount - $totalPaidAmount;
            if ($totalExceed < 0) {
                $errors[] = "Total payment amount cannot exceed the paid amoount ($amount).";
            }
            if (!empty($errors)) {
                return redirect()->back()->withErrors($errors)->withInput();
            }
        }

        // Check if it's related to an bill
        $isInvoice = false;
        $isAdv = 17;
        foreach ($paymentAmounts as $paidAmount) {
            if ($paidAmount > 0) {
                $isInvoice = true;
                $isAdv = 2;
                break;
            }
        }

//        INSERT NEW PAYMENT RECEIVED
        $payment['type_id'] = $isAdv; // payment made
        $payment['amount'] =  $amount;
        $payment['unused_amount'] =  $unusedAmount;
        $payment['currency'] = currencyID();
        $payment['internal_note'] = $internal_note;
        $payment['payment_number'] = $payment_number;
        $payment['mode'] = $mode;
        $payment['reference_number'] = $reference_number;
        $payment['paid_by_id'] = $paidBy;
//        $request1['organization_id'] = $organizationId;
        $payment['date'] = $date;
        $payment_id = GlobalController::InsertNewPaymentReceived($payment);

//        store attachments
        if ($request->hasFile('files')) {
            $fileNamesToStore = [];
            foreach ($request->file('files') as $file) {
                $fileName = uploadFile($file, 'payment_received');
                $fileNamesToStore[] = $fileName;
                $invoiceFile = new PaymentRecDocuments();
                $invoiceFile->payment_id = $payment_id;
                $invoiceFile->name = $fileName;
                $invoiceFile->save();
            }
        }


        if($isInvoice){
//            if the amount received is totally for invoices
            if($totalPaymentAmount == $amount && $countInvoice == 1){
//                if the payment received is for one invoice only

                    $invoiceName = '';
                    $totalAmount = 0;
                     foreach ($invoice_ids as $index => $id) {
                         $ref_inv_number = '';
                         if ($payment_amount[$index] > 0) {
                             $totalAmount += $payment_amount[$index];
                             if($is_invoices[$index] == 1){
                                 // update the due amount of the invoice
                             $salesInvoice = PurchaseInvoice::find($invoice_ids[$index]);
                             if ($salesInvoice) {
                                 if ($salesInvoice->amount_due - $payment_amount[$index] == 0) {
                                     $salesInvoice->status = 7; // paid
                                 }
                                 if ($salesInvoice->order_number) {
                                     $ref_inv_number = $salesInvoice->order_number;
                                 }
                                 $salesInvoice->amount_due -= $payment_amount[$index];
                                 $salesInvoice->amount_received += $payment_amount[$index];
                                 $invoiceName .= $salesInvoice->invoice_number . ',';
                                 $salesInvoice->save();
                             }
                             $request1['transaction_type_id'] = 15;
                             $request1['amount'] = $amount;
                             $request1['internal_note'] = $internal_note;
                             $request1['payment_id'] = $payment_id;
                             $request1['payment_number'] = $payment_number;
                             $request1['reference_number'] = $ref_inv_number;
                             $request1['paid_by_id'] = $paidBy;
                             $request1['date'] = $date;
                             $transaction = GlobalController::InsertNewTransaction($request1);
//                    credit to account bank or cash
                             $request2['transaction_id'] = $transaction;
                             $request2['amount'] = $amount;
                             $request2['account_id'] = $account_id;
                             $request2['is_debit'] = 0;
                             GlobalController::InsertNewTransactionDetails($request2);
//                    debit from payable account
                             $request3['transaction_id'] = $transaction;
                             $request3['amount'] = $amount;
                             $request3['account_id'] = $payableAccount;
                             $request3['is_debit'] = 1;
                             GlobalController::InsertNewTransactionDetails($request3);


                             // connect the invoice with the transaction
                             $invoiceRequest['transaction_id'] = $transaction;
                             $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                             $invoiceRequest['invoice_type_id'] = 4;
                             GlobalController::InsertNewTransactionInvoice($invoiceRequest);

//                             Insert comment to invoice
                             $comment = 'Payment of ' . $currencyName . ' ' . $payment_amount[$index] . ' made.';
                             GlobalController::InsertNewComment(4, $invoice_ids[$index], NULL, $comment);
                         }else{
//                                 for opening balance only
                                 $invoiceName .= 'Opening Balance';

                                 //            opening balance payment
                                 $request1['transaction_type_id'] = 26;
                                 $request1['amount'] =  $amount;
                                 $request1['internal_note'] = $internal_note;
                                 $request1['payment_id'] = $payment_id;
                                 $request1['payment_number'] = $payment_number;
                                 $request1['reference_number'] = 'Opening Balance';
                                 $request1['paid_by_id'] = $paidBy;
                                 $request1['date'] = $date;
                                 $transaction = GlobalController::InsertNewTransaction($request1);
//                    credit to account bank or cash
                                 $request2['transaction_id'] = $transaction;
                                 $request2['amount'] = $amount ;
                                 $request2['account_id'] = $account_id;
                                 $request2['is_debit'] = 0;
                                 GlobalController::InsertNewTransactionDetails($request2);
//                    debit from payable account
                                 $request3['transaction_id'] = $transaction;
                                 $request3['amount'] = $amount;
                                 $request3['account_id'] = $payableAccount;
                                 $request3['is_debit'] = 1;
                                 GlobalController::InsertNewTransactionDetails($request3);

                                 // Insert comment to partner
                                 $comment = 'Payment of' . $currencyName . ' ' . $payment_amount[$index] . ' applied for opening balance ';
                                 GlobalController::InsertNewComment(12, $paidBy, NULL, $comment);
                             }
                         }
                     }

                    //insert comment to payment
                    $comment =  'Payment of amount '.$currencyName.' '.$totalAmount.' applied for '.rtrim($invoiceName, ",").'.';
                    GlobalController::InsertNewComment(10, $payment_id,NULL, $comment);
                    //      Insert comment to customer
                    $title =  'Payments Made added';
                    $comment =  'Payment of '.$currencyName.' '.$amount.' made and applied for '.rtrim($invoiceName, ",").'.';
                    GlobalController::InsertNewComment(12, $paidBy, $title, $comment);
//                }
            }else{
                    //      transaction type payment as payment received
                    $request1['transaction_type_id'] = 2;
                    $request1['amount'] =  $amount;
                    $request1['internal_note'] = $internal_note;
                    $request1['payment_id'] = $payment_id;
                    $request1['payment_number'] = $payment_number;
                    $request1['reference_number'] = $reference_number;
                    $request1['paid_by_id'] = $paidBy;
                    $request1['date'] = $date;
                    $transaction = GlobalController::InsertNewTransaction($request1);
//                    deposit to account bank or cash
                    $request2['transaction_id'] = $transaction;
                    $request2['amount'] = $amount ;
                    $request2['account_id'] = $account_id;
                    $request2['is_debit'] = 0;
                    GlobalController::InsertNewTransactionDetails($request2);
//                    debit from advanced account
                    $request3['transaction_id'] = $transaction;
                    $request3['amount'] = $amount;
                    $request3['account_id'] = $advPaymentAccount;
                    $request3['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($request3);
//                    more than one invoice
                    $invoiceName = '';
                    $totalAmount = '';
                    if (isset($invoice_ids)) {
                        foreach ($invoice_ids as $index => $id) {
                            $ref_inv_number = '';

                            if ($payment_amount[$index] > 0) {
                                $totalAmount += $payment_amount[$index];
                                if($is_invoices[$index] == 1){
//                    update the due amount of the invoice
                                $salesInvoice = PurchaseInvoice::find($invoice_ids[$index]);
                                if ($salesInvoice) {
                                    if ($salesInvoice->amount_due - $payment_amount[$index] == 0) {
                                        $salesInvoice->status = 7; // paid
                                    }
                                    if ($salesInvoice->order_number) {
                                        $ref_inv_number = $salesInvoice->order_number;
                                    }
                                    $salesInvoice->amount_due -= $payment_amount[$index];
                                    $salesInvoice->amount_received += $payment_amount[$index];
                                    $invoiceName .= $salesInvoice->invoice_number . ',';
                                    $salesInvoice->save();
                                }
                                // transaction as invoice payment
                                $request1['transaction_type_id'] = 15;
                                $request1['amount'] = $payment_amount[$index];
                                $request1['internal_note'] = $internal_note;
                                $request1['payment_id'] = $payment_id;
                                $request1['payment_number'] = $payment_number;
                                $request1['reference_number'] = $ref_inv_number;
                                $request1['paid_by_id'] = $paidBy;
                                $request1['date'] = $date;
                                $transaction = GlobalController::InsertNewTransaction($request1);

                                // transaction Details for each invoice
                                // deposit to adv payment
                                $request2['transaction_id'] = $transaction;
                                $request2['amount'] = $payment_amount[$index];
                                $request2['account_id'] = $advPaymentAccount;
                                $request2['is_debit'] = 0;
                                GlobalController::InsertNewTransactionDetails($request2);
                                // credit from receivable account
                                $request3['transaction_id'] = $transaction;
                                $request3['amount'] = $payment_amount[$index];
                                $request3['account_id'] = $payableAccount;
                                $request3['is_debit'] = 1;
                                GlobalController::InsertNewTransactionDetails($request3);

                                // connect the invoice with the transaction
                                $invoiceRequest['transaction_id'] = $transaction;
                                $invoiceRequest['invoice_id'] = $invoice_ids[$index];
                                $invoiceRequest['invoice_type_id'] = 4;
                                GlobalController::InsertNewTransactionInvoice($invoiceRequest);

                                $comment = 'Payment of ' . $currencyName . ' ' . $payment_amount[$index] . ' made.';
                                GlobalController::InsertNewComment(4, $invoice_ids[$index], NULL, $comment);
                            }else{
                                    //                                 for opening balance only
                                    $invoiceName .= 'Opening Balance';
                                    //            opening balance payment
                                    $request1['transaction_type_id'] = 26;
                                    $request1['amount'] =  $payment_amount[$index];
                                    $request1['internal_note'] = $internal_note;
                                    $request1['payment_id'] = $payment_id;
                                    $request1['payment_number'] = $payment_number;
                                    $request1['reference_number'] = 'Opening Balance';
                                    $request1['paid_by_id'] = $paidBy;
                                    $request1['date'] = $date;
                                    $transaction = GlobalController::InsertNewTransaction($request1);
//                    credit to adv
                                    $request2['transaction_id'] = $transaction;
                                    $request2['amount'] = $payment_amount[$index] ;
                                    $request2['account_id'] = $advPaymentAccount;
                                    $request2['is_debit'] = 0;
                                    GlobalController::InsertNewTransactionDetails($request2);
//                    debit from payable account
                                    $request3['transaction_id'] = $transaction;
                                    $request3['amount'] = $payment_amount[$index];
                                    $request3['account_id'] = $payableAccount;
                                    $request3['is_debit'] = 1;
                                    GlobalController::InsertNewTransactionDetails($request3);

                                    // Insert comment to partner
                                    $comment = 'Payment of' . $currencyName . ' ' . $payment_amount[$index] . ' applied for opening balance ';
                                    GlobalController::InsertNewComment(12, $paidBy, NULL, $comment);
                                }
                            }
                        }
                    }
                    //insert comment to payment
                    $comment =  'Payment of amount '.$currencyName.' '.$totalAmount.' applied for '.rtrim($invoiceName, ",").'.';
                    GlobalController::InsertNewComment(10, $payment_id,NULL, $comment);
                    //      Insert comment to customer
                    $title =  'Payments Made added';
                    $comment =  'Payment of '.$currencyName.' '.$amount.' made and applied for '.rtrim($invoiceName, ",").'.';
                    GlobalController::InsertNewComment(12, $paidBy, $title, $comment);

                }

        }
        else{
//            advanced payment
            $request1['transaction_type_id'] = 17;
            $request1['amount'] =  $amount;
            $request1['internal_note'] = $internal_note;
            $request1['payment_id'] = $payment_id;
            $request1['payment_number'] = $payment_number;
            $request1['reference_number'] = $reference_number;
            $request1['paid_by_id'] = $paidBy;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
//          deposit to account bank or cash
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $amount ;
            $request2['account_id'] = $account_id;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
//          credit from advance account
            $request3['transaction_id'] = $transaction;
            $request3['amount'] = $amount;
            $request3['account_id'] = $advPaymentAccount;
            $request3['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request3);
            //insert comment to payment
            $comment =  'Payment of amount '.$currencyName.' '.$amount.' made.';
            GlobalController::InsertNewComment(10, $payment_id, NULL, $comment);
            //      Insert comment to customer
            $title =  'Payments Made added';
            $comment =  'Payment of '.$currencyName.' '.$amount.' made.';
            GlobalController::InsertNewComment(12, $paidBy, $title, $comment);

        }

        return redirect()->route('TransactionController.showPaymentsMade', ['#row-' . $payment_id]);

    }
    public function viewPaymentDetails($encryptedId){
        $id = Crypt::decryptString($encryptedId);
        $organizationId = org_id();
        //        get transactions related to this payment to show on journal
        $paymentJournal = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'TransactionType'])
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();
        $journalContent='';
        if($paymentJournal){
            $currencyName = GlobalController::GetCurrencyName($paymentJournal->currency);
            foreach ($paymentJournal->Transaction as $transaction) {
                $transactionName = '';

                if($transaction->transaction_type_id == 4){
                    $transactionName = count($transaction->TransactionInvoice) > 0 ? ' - '.$transaction->TransactionInvoice->first()->SalesInvoice->invoice_number : '';
                }else{
                    $transactionName = $transaction->payment_number ? ' - '.$transaction->payment_number : '';
                }
                $journalContent .= '<b class="font-large text-capitalize">'.GlobalController::GetTransactionName($transaction->transaction_type_id).$transactionName.'</b>';
                $journalContent .= '<table class="table mt-3 journal-table"><thead><tr><th>Account</th><th class="amount-end">Debit</th><th class="amount-end">Credit</th> </tr></thead><tbody>';
                foreach($transaction->TransactionDetails as $detail){
                    $journalContent .= '<tr><td>'.$detail->account->name.'</td>';
                    $journalContent .= '<td class="amount-end"><b>'.($detail->is_debit === 1 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</b>'.'</td>';
                    $journalContent .= '<td class="amount-end"><b>'.($detail->is_debit === 0 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</b>'.'</td></tr>';
                }
                $journalContent .='</tbody></table>';
            }
        }




        $paymentDetails = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'partner'])
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();
        $openingBalanceData = self::getOpeningBalanceData($paymentDetails->paid_by_id);

        $firstDebitDetail = null;
        $transactions = $paymentDetails->Transaction;
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

        return view('pages.template.paymentDetails', compact([ 'journalContent', 'paymentDetails','openingBalanceData', 'firstDebitDetail']));



    }
    public function viewPaymentReceipt($encryptedId){
        $id = Crypt::decryptString($encryptedId);
        $organizationId = org_id();

        $paymentDetails = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'partner'])
            ->where('id', $id)
            ->first();
        $openingBalanceData = self::getOpeningBalanceData($paymentDetails->paid_by_id);


        return  view('pages.template.paymentReceipt', compact([ 'paymentDetails', 'openingBalanceData']));



    }
    public function viewPaymentMadeReceipt($encryptedId){

        $id = Crypt::decryptString($encryptedId);
        $organizationId = org_id();

        $paymentDetails = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'partner'])
            ->where('id', $id)
            ->first();
        $openingBalanceData = self::getPayableOpeningBalanceData($paymentDetails->paid_by_id);


        return  view('pages.template.paymentMadeReceipt', compact([ 'paymentDetails', 'openingBalanceData']));



    }
    public function viewPaymentMadeDetails($encryptedId){
        $id = Crypt::decryptString($encryptedId);
        $organizationId = org_id();
        //        get transactions related to this payment to show on journal
        $paymentJournal = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'TransactionType'])
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();
        $journalContent='';
        if($paymentJournal){
            $currencyName = GlobalController::GetCurrencyName($paymentJournal->currency);
            foreach ($paymentJournal->Transaction as $transaction) {
                $transactionName = '';
                if($transaction->transaction_type_id == 15){
                    $transactionName = $transaction->TransactionInvoice ? ' - '.$transaction->TransactionInvoice->first()->PurchaseInvoice->invoice_number : '';
                }else{
                    $transactionName = $transaction->payment_number ? ' - '.$transaction->payment_number : '';
                }
                $journalContent .= '<b class="font-large text-capitalize">'.GlobalController::GetTransactionName($transaction->transaction_type_id).$transactionName.'</b>';
                $journalContent .= '<table class="table mt-3 journal-table"><thead><tr><th>Account</th><th class="amount-end">Debit</th><th class="amount-end">Credit</th> </tr></thead><tbody>';
                foreach($transaction->TransactionDetails as $detail){
                    $journalContent .= '<tr><td>'.$detail->account->name.'</td>';
                    $journalContent .= '<td class="amount-end"><b>'.($detail->is_debit === 1 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</b>'.'</td>';
                    $journalContent .= '<td class="amount-end"><b>'.($detail->is_debit === 0 ? number_format($detail->amount, 2, '.', ',') : "0.00" ).'</b>'.'</td></tr>';
                }
                $journalContent .='</tbody></table>';
            }
        }




        $paymentDetails = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'partner'])
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();
        $openingBalanceData = self::getPayableOpeningBalanceData($paymentDetails->paid_by_id);

        $firstDebitDetail = null;
        $transactions = $paymentDetails->Transaction;
        if ($transactions->count() > 1) {
//                    get tye payment or advance
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

        return view('pages.template.paymentMadeDetails', compact([ 'journalContent','openingBalanceData', 'paymentDetails', 'firstDebitDetail']));



    }
//    get approved invoices by partner id
    public function getInvoicesByPartnerId(Request $request){
        $organizationId = org_id();
        $id = $request->input('id');
        if ($request->has('excluded_id')) {
            $excluded_id = $request->input('excluded_id');
            if (!is_array($excluded_id)) {
                $excluded_id = [$excluded_id];
            }
            $invoices = SalesInvoice::where('partner_id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 4)
                ->whereNotIn('id', $excluded_id)
                ->where('amount_due', '>', 0)
                ->get();
        }else{
            $invoices = SalesInvoice::where('partner_id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 4)
                ->where(function ($query) {
                    $query->where('amount_due', '>', 0);
                })
                ->get();
        }
        if ($request->has('is_ob') && $request->input('is_ob') == 1) {
            $openingBalanceData = [];
        }else{
            $openingBalanceData = self::getDebitOpeningBalanceValue($id);

        }

        $invoice_html = '';
        if(!$openingBalanceData && !$invoices->isNotEmpty()){
            if (!$request->has('excluded_id')) {
                $invoice_html = '<tr><td colspan="5" align="center">There are no unpaid invoices associated with this customer.</td></tr>';
            }
        }
        if($openingBalanceData){
            $invoice_html .="<tr>
                         <td>
                             <input type='hidden'  value='0' name='is_invoice[]'>
                             <input type='hidden'  value='".$openingBalanceData['amount']."' name='due_amount[]'>
                             <input type='hidden'  value='".$openingBalanceData['partner_id']."' name='invoice_id[]'>
                             ".DateFormat($openingBalanceData['date'])."
                         </td>
                         <td>
                             Customer Opening Balance
                         </td>
                         <td class=\"text-end\">
                             ".currencyName().number_format($openingBalanceData['full_amount'], 2, '.', ',')."
                         </td>
                         <td class=\"text-end\">
                             ".currencyName().number_format($openingBalanceData['amount'], 2, '.', ',')."
                         </td>
                         <td>
                         <div class=\"input-group text-end \">
                                 <div class=\"input-group-prepend\"><span class=\"input-group-text\">".currencyName()."</span></div>
                                 <input class=\"form-control \" data-due=\" ".$openingBalanceData['amount']." \"  
                                  name=\"payment_amount[]\" 
                                  type=\"number\" step='any'>
                             </div>
                         </td>
                     </tr>";
        }
        if($invoices->isNotEmpty()){
            foreach ($invoices as $invoice){
                $invoice_id = $invoice->id;
                $invoice_number = $invoice->invoice_number;
                $date = $invoice->invoice_date;
                $due_date = $invoice->due_date;
                $amount_due = $invoice->amount_due;
                $total_amount = $invoice->total;
//            $currency = $invoice->Currency->name;
                $currency = currencyName();
                $invoice_html .="<tr>
                                <td>
                                    <input type='hidden'  value='1' name='is_invoice[]'>
                                    <input type='hidden'  value='".$amount_due."' name='due_amount[]'>
                                    <input type='hidden'  value='".$invoice_id."' name='invoice_id[]'>
                                    ".DateFormat($date)."<br>
                                    <small><span class='text-muted'>Due Date: </span>".DateFormat($due_date)."</small>
                                </td>
                                <td>
                                    <a target='_blank' href='".route('InvoiceController.showInvoice', ['sales_invoice#row-' . $invoice_id])."'>".$invoice_number."</a>
                                </td>
                                <td class=\"text-end\">
                                    ".$currency.number_format($total_amount, 2, '.', ',')."
                                </td>
                                <td class=\"text-end\">
                                    ".$currency.number_format($amount_due, 2, '.', ',')."
                                </td>
                                <td>
                                <div class=\"input-group text-end \">
                                        <div class=\"input-group-prepend\"><span class=\"input-group-text\">".$currency."</span></div>
                                        <input class=\"form-control \" data-due=\" ".$amount_due." \"   
                                        name=\"payment_amount[]\" 
                                        type=\"number\"  step='any' >
                                    </div>
                                </td>
                            </tr>";
            }
        }



        return response()->json(['invoices'=> $invoice_html]);
    }
//    for customer
    public static function  getDebitOpeningBalanceValue($partner_id){

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalance = $openingBalanceOfCustomer = $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;
        $openingBalanceData = [];
        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                ->where('partner_id', $partner_id)
                ->select('debit_amount', 'credit_amount')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
                $openingBalanceOfCustomer = $customerOpeningBalanceDebit - $customerOpeningBalanceCredit;

//                bas e3ml record la paymnt mn 7sebo
                $creditApplied = CreditApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');
//                bas yedfa3 li 3leh
                $debitApplied = Transaction::where('organization_id', org_id())
                    ->where('transaction_type_id', 26)
                    ->where('paid_by_id', $partner_id)
                    ->sum('amount');
                $customerOpeningBalanceCredit -= $creditApplied;
                $customerOpeningBalanceDebit -= $debitApplied;
            }
            $openingBalance = $customerOpeningBalanceDebit - $customerOpeningBalanceCredit;
            if($openingBalance >0){
//            ana badi meno
                $openingBalanceData =[
                    'date' => $openingBalanceDate->date,
                    'amount' => $openingBalance,
                    'full_amount' => $openingBalanceOfCustomer,
                    'partner_id' => $partner_id
                ];

            }
        }

        return $openingBalanceData;
    }
//    for payable
    public static function  getPayableDebitOpeningBalanceValue($partner_id){

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalance = $openingBalanceOfCustomer = $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;
        $openingBalanceData = [];
        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                ->where('partner_id', $partner_id)
                ->select('debit_amount', 'credit_amount')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
                $openingBalanceOfCustomer =  $customerOpeningBalanceCredit - $customerOpeningBalanceDebit;

//                bas e3ml record la paymnt mn 7sebo
                $creditApplied = CreditApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');
//                bas yedfa3 li 3leh
                $debitApplied = Transaction::where('organization_id', org_id())
                    ->where('transaction_type_id', 26)
                    ->where('paid_by_id', $partner_id)
                    ->sum('amount');
                $customerOpeningBalanceDebit -= $creditApplied;
                $customerOpeningBalanceCredit -= $debitApplied;
            }
            $openingBalance = $customerOpeningBalanceCredit - $customerOpeningBalanceDebit;
            if($openingBalance >0){
//            hwe bado meni
                $openingBalanceData =[
                    'date' => $openingBalanceDate->date,
                    'amount' => $openingBalance,
                    'full_amount' => $openingBalanceOfCustomer,
                    'partner_id' => $partner_id
                ];

            }
        }

        return $openingBalanceData;
    }
//    customer
    public static function  getOpeningBalanceData($partner_id){

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalance = $openingBalanceOfCustomer = $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;
        $openingBalanceData = [];
        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                ->where('partner_id', $partner_id)
                ->select('debit_amount', 'credit_amount')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
                $openingBalanceOfCustomer = $customerOpeningBalanceDebit - $customerOpeningBalanceCredit;

//                bas e3ml record la paymnt mn 7sebo
                $creditApplied = CreditApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');
//                bas yedfa3 li 3leh
                $debitApplied = Transaction::where('organization_id', org_id())
                    ->where('transaction_type_id', 26)
                    ->where('paid_by_id', $partner_id)
                    ->sum('amount');
                $customerOpeningBalanceCredit -= $creditApplied;
                $customerOpeningBalanceDebit -= $debitApplied;
            }
            $openingBalance = $customerOpeningBalanceDebit - $customerOpeningBalanceCredit;
            if( $openingBalance >=0 ){
//            ana badi meno
                $openingBalanceData =[
                    'date' => $openingBalanceDate->date,
                    'amount' => $openingBalance,
                    'full_amount' => $openingBalanceOfCustomer,
                    'partner_id' => $partner_id
                ];

            }
        }

        return $openingBalanceData;
    }
//    payable
    public static function  getPayableOpeningBalanceData($partner_id){

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalanceData = [];
        $openingBalance = $openingBalanceOfCustomer = $customerOpeningBalanceDebit  = $customerOpeningBalanceCredit  = 0;
        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                ->where('partner_id', $partner_id)
                ->select('debit_amount', 'credit_amount')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
                $openingBalanceOfCustomer = $customerOpeningBalanceCredit - $customerOpeningBalanceDebit;
//                bas e3ml record la paymnt mn 7sebo
                $creditApplied = DebitApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');
//                bas edfa3 li 3layi
                $debitApplied = Transaction::where('organization_id', org_id())
                    ->where('transaction_type_id', 26)
                    ->where('paid_by_id', $partner_id)
                    ->sum('amount');
                $customerOpeningBalanceDebit -= $creditApplied;
                $customerOpeningBalanceCredit -= $debitApplied;
            }

            $openingBalance = $customerOpeningBalanceCredit - $customerOpeningBalanceDebit;
            if( $openingBalance >=0 ){
//            hwe bado meni
                $openingBalanceData =[
                    'date' => $openingBalanceDate->date,
                    'amount' => $openingBalance,
                    'full_amount' => $openingBalanceOfCustomer,
                    'partner_id' => $partner_id
                ];

            }
        }
        return $openingBalanceData;
    }
    public function getBillsByPartnerId(Request $request){
        $organizationId = org_id();
        $id = $request->input('id');
        if ($request->has('excluded_id')) {
            $excluded_id = $request->input('excluded_id');
            if (!is_array($excluded_id)) {
                $excluded_id = [$excluded_id];
            }
            $invoices = PurchaseInvoice::where('partner_id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 4)
                ->whereNotIn('id', $excluded_id)
                ->where('amount_due', '>', 0)
                ->get();
        }else{
            $invoices = PurchaseInvoice::where('partner_id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 4)
                ->where(function ($query) {
                    $query->where('amount_due', '>', 0);
                })
                ->get();
        }

        if ($request->has('is_ob') && $request->input('is_ob') == 1) {
            $openingBalanceData = [];
        }else{
            $openingBalanceData = self::getPayableDebitOpeningBalanceValue($id);

        }
        $invoice_html = '';
        if(!$openingBalanceData && !$invoices->isNotEmpty()){
            if (!$request->has('excluded_id')) {
                $invoice_html = '<tr><td colspan="5" align="center">There are no unpaid bills associated with this supplier.</td></tr>';
            }
        }
        if($openingBalanceData){
            $invoice_html .="<tr>
                         <td>
                             <input type='hidden'  value='0' name='is_invoice[]'>
                             <input type='hidden'  value='".$openingBalanceData['amount']."' name='due_amount[]'>
                             <input type='hidden'  value='".$openingBalanceData['partner_id']."' name='invoice_id[]'>
                             ".DateFormat($openingBalanceData['date'])."
                         </td>
                         <td>
                             Supplier Opening Balance
                         </td>
                         <td class=\"text-end\">
                             ".currencyName().number_format($openingBalanceData['full_amount'], 2, '.', ',')."
                         </td>
                         <td class=\"text-end\">
                             ".currencyName().number_format($openingBalanceData['amount'], 2, '.', ',')."
                         </td>
                         <td>
                         <div class=\"input-group text-end \">
                                 <div class=\"input-group-prepend\"><span class=\"input-group-text\">".currencyName()."</span></div>
                                 <input class=\"form-control \" data-due=\" ".$openingBalanceData['amount']." \"   
                                 name=\"payment_amount[]\" type=\"number\" step='any'
                                 >
                             </div>
                         </td>
                     </tr>";
        }
        if($invoices->isNotEmpty()){
            foreach ($invoices as $invoice){
                $invoice_id = $invoice->id;
                $invoice_number = $invoice->invoice_number;
                $date = $invoice->invoice_date;
                $due_date = $invoice->due_date;
                $amount_due = $invoice->amount_due;
                $total_amount = $invoice->total;
//            $currency = $invoice->Currency->name;
                $currency = currencyName();
                $invoice_html .="<tr>
                                <td>
                                    <input type='hidden'  value='1' name='is_invoice[]'>
                                    <input type='hidden'  value='".$amount_due."' name='due_amount[]'>
                                    <input type='hidden'  value='".$invoice_id."' name='invoice_id[]'>
                                    ".DateFormat($date)."<br>
                                    <small><span class='text-muted'>Due Date: </span>".DateFormat($due_date)."</small>
                                </td>
                                <td>
                                    <a target='_blank' href='".route('InvoiceController.showInvoice', ['sales_invoice#row-' . $invoice_id])."'>".$invoice_number."</a>
                                </td>
                                 <td class=\"text-end\">
                                    ".$currency.number_format($total_amount, 2, '.', ',')."
                                </td>
                                <td class=\"text-end\">
                                    ".$currency.number_format($amount_due, 2, '.', ',')."
                                </td>
                                <td>
                                <div class=\"input-group text-end \">
                                        <div class=\"input-group-prepend\"><span class=\"input-group-text\">".$currency."</span></div>
                                        <input class=\"form-control \" data-due=\" ".$amount_due." \"  
                                         name=\"payment_amount[]\" type=\"number\" step='any'  >
                                    </div>
                                </td>
                            </tr>";
            }
        }



        return response()->json(['invoices'=> $invoice_html]);
    }
    public function checkPaymentNumber(Request $request)
    {
        $organizationId = org_id();
        $paymentNumber = $request->input('payment_number');
        $exists = PaymentReceived::where('payment_number', $paymentNumber)
            ->where('organization_id', $organizationId)
            ->whereIn('type_id', [1,3])
            ->exists();

        return response()->json(['exists' => $exists]);
    }
    public function checkPaymentMadeNumber(Request $request)
    {
        $organizationId = org_id();
        $paymentNumber = $request->input('payment_number');
        $exists = PaymentReceived::where('payment_number', $paymentNumber)
            ->where('organization_id', $organizationId)
            ->whereIn('type_id', [2,17])
            ->exists();

        return response()->json(['exists' => $exists]);
    }
//REFUND
    public function refundPaymentsReceived($id)
    {
        $organizationId = org_id();
        $id = Crypt::decryptString($id);

        $payment = PaymentReceived::findorfail($id);
        if($payment){
            $paymentAccounts = PaymentAccounts();
            return view('pages.payments.customer.refund', compact(['payment', 'paymentAccounts' ]));
        }
    }
    public function refundPaymentsMade($id)
    {
        $organizationId = org_id();
        $id = Crypt::decryptString($id);

        $payment = PaymentReceived::findorfail($id);
        if($payment){
            $paymentAccounts = PaymentAccounts();
            return view('pages.payments.payable.refund', compact(['payment', 'paymentAccounts' ]));
        }
    }
//REFUND Credit Note
    public function refundCreditNote($id)
    {
        $id = Crypt::decryptString($id);
        $creditNote = CreditNote::findorfail($id);
        if($creditNote){
            $paymentAccounts = PaymentAccounts();
            return view('pages.payments.customer.refundCN', compact(['creditNote', 'paymentAccounts' ]));
        }
    }
//REFUND Credit Note
    public function refundDebitNote($id)
    {
        $id = Crypt::decryptString($id);
        $creditNote = DebitNote::findorfail($id);
        if($creditNote){
            $paymentAccounts = PaymentAccounts();
            return view('pages.payments.payable.refundDN', compact(['creditNote', 'paymentAccounts' ]));
        }
    }
//    store payment refund on adv payment
    public function storePaymentRefund(Request $request)
    {
        $organizationId = org_id();
        $payment_id = $request->input('payment_id');
        $refunded_amount = $request->input('amount');
        if($refunded_amount > 0){
            if (validateVATDate($request->input('date'))) {
                return redirect()->back()->withInput()->with('error', errorMsg());
            }
            $account_id = $request->input('account_id');
            $advPaymentAccount = GetAdvPaymentAccount();
            $payment = PaymentReceived::find($payment_id);
            $payment_number = $payment->payment_number;
            $paidBy = $payment->paid_by_id;
            if (validateVATDate($payment->date)) {
                return redirect()->back()->withInput()->with('error', errorMsg());
            }

            $referenceExist = checkReferenceExists($request->input('reference_number'));
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'error' => __('messages.reference_exists')
                ])->withInput();
            }

//        update the unused amount for the payment
            if($refunded_amount > $payment->unused_amount){
                return redirect()->back()->with('error', 'Please make sure that the amount is not greater than the balance '.$payment->unused_amount.'.');
            }
            $payment->unused_amount -= $refunded_amount;
//        add refund transaction
            $request1['transaction_type_id'] = 6;
            $request1['amount'] =  $refunded_amount;
            $request1['description'] = $request->input('description');
            $request1['payment_id'] = $payment_id;
            $request1['payment_number'] = $payment_number;
            $request1['reference_number'] = $request->input('reference_number');
            $request1['paid_by_id'] = $paidBy;
            $request1['date'] = $request->input('date');
            $transaction = GlobalController::InsertNewTransaction($request1);
//      credit from account bank or cash
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $refunded_amount;
            $request2['account_id'] = $account_id;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
//      Debit to Adv Payment
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $refunded_amount;
            $request2['account_id'] = $advPaymentAccount;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);
            $currencyName = currencyName();
            $payment->save();

            $comment = 'Payment#'.$payment_number.'has been modified. '.$currencyName . ' ' .$refunded_amount.' refunded.';
            GlobalController::InsertNewComment(9, $payment_id, NULL, $comment);
            GlobalController::InsertNewComment(11, $paidBy, NULL, $comment);
        }

        return redirect()->route('TransactionController.showPaymentsReceived', ['#row-' . $payment_id]);


    }
    public function storePaymentMadeRefund(Request $request)
    {
        $organizationId = org_id();
        $payment_id = $request->input('payment_id');
        $refunded_amount = $request->input('amount');
        if($refunded_amount > 0){
            $account_id = $request->input('account_id');
            $advPaymentAccount = GetPrepaidPaymentAccount();
            $payment = PaymentReceived::find($payment_id);
            $payment_number = $payment->payment_number;
            $paidBy = $payment->paid_by_id;
            if (validateVATDate($request->input('date'))) {
                return redirect()->back()->withInput()->with('error', errorMsg());
            }
//        update the unused amount for the payment
            if($refunded_amount > $payment->unused_amount){
                return redirect()->back()->with('error', 'Please make sure that the amount is not greater than the balance '.$payment->unused_amount.'.');
            }

            if (validateVATDate($payment->date)) {
                return redirect()->back()->withInput()->with('error', errorMsg());
            }

            $referenceExist = checkReferenceExists($request->input('reference_number'));
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'error' => __('messages.reference_exists')
                ])->withInput();
            }

            $payment->unused_amount -= $refunded_amount;
//        add refund transaction
            $request1['transaction_type_id'] = 16;
            $request1['amount'] =  $refunded_amount;
            $request1['description'] = $request->input('description');
            $request1['payment_id'] = $payment_id;
            $request1['payment_number'] = $payment_number;
            $request1['reference_number'] = $request->input('reference_number');
            $request1['paid_by_id'] = $paidBy;
            $request1['date'] = $request->input('date');
            $transaction = GlobalController::InsertNewTransaction($request1);
//      Debit from account bank or cash
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $refunded_amount;
            $request2['account_id'] = $account_id;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);
//      Credit to Adv Payment
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $refunded_amount;
            $request2['account_id'] = $advPaymentAccount;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
            $currencyName = currencyName();
            $payment->save();

            $comment = 'Payment#'.$payment_number.'has been modified. '.$currencyName . ' ' .$refunded_amount.' refunded.';
            GlobalController::InsertNewComment(10, $payment_id, NULL, $comment);
            GlobalController::InsertNewComment(12, $paidBy, NULL, $comment);
        }

        return redirect()->route('TransactionController.showPaymentsMade', ['#row-' . $payment_id]);


    }
//    store credit note refund amount
    public function storeCreditRefund(Request $request)
    {

        try{
            $organizationId = org_id();
            $credit_id = $request->input('invoice_id');
            $refunded_amount = $request->input('amount');

            $referenceExist = checkReferenceExists($request->input('reference_number'));
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'error' => __('messages.reference_exists')
                ])->withInput();
            }

            if($refunded_amount > 0){
                $reference_number = $request->input('reference_number');
                $date = $request->input('date');
                $description = $request->input('description');
                $account_id = $request->input('account_id');
                $currencyName = currencyName();
                $receivableAccount = GetReceivableAccount();
                $creditNote = CreditNote::find($credit_id);

                if (validateVATDate($creditNote->invoice_date)) {
                    return redirect()->back()->withInput()->with('error', errorMsg());
                }

                if (validateVATDate($date)) {
                    return redirect()->back()->withInput()->with('error', errorMsg());
                }
                $credit_number = $creditNote->invoice_number;
                $customer_id = $creditNote->partner_id;
                if($refunded_amount > $creditNote->amount_due){
                    return redirect()->back()->with('error', 'Please make sure that the amount is not greater than the balance '.$creditNote->amount_due.'.');
                }
                if( $creditNote->amount_due - $refunded_amount == 0){
                    $creditNote->status = 9; // paid
                }
                $creditNote->amount_due -= $refunded_amount;
                $creditNote->amount_received += $refunded_amount;
                //        add refund payment record
                //        INSERT NEW PAYMENT
                $payment['type_id'] = 13; // credit refund
                $payment['amount'] =  $refunded_amount;
                $payment['unused_amount'] =  0;
                $payment['internal_note'] = $description;
                $payment['reference_number'] = $reference_number;
                $payment['paid_by_id'] = $customer_id;
                $payment['date'] = $date;
                $payment_id = GlobalController::InsertNewPaymentReceived($payment);
//        add refund transaction
                $request1['transaction_type_id'] = 13;
                $request1['amount'] =  $refunded_amount;
                $request1['description'] = $description;
                $request1['payment_id'] = $payment_id;
                $request1['reference_number'] = $reference_number;
                $request1['paid_by_id'] = $customer_id;
                $request1['date'] = $date;
                $transaction = GlobalController::InsertNewTransaction($request1);
//      credit from account bank or cash
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $refunded_amount;
                $request2['account_id'] = $account_id;
                $request2['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($request2);
//      Debit to receivable Payment
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $refunded_amount;
                $request2['account_id'] = $receivableAccount;
                $request2['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($request2);

                $invoiceRequest['transaction_id'] = $transaction;
                $invoiceRequest['invoice_id'] = $credit_id;
                $invoiceRequest['invoice_type_id'] = 6;
                GlobalController::InsertNewTransactionInvoice($invoiceRequest);
                $creditNote->save();

                $comment = 'Credit#'.$credit_number.' refunded, Amount of  ' . $currencyName .' '. $refunded_amount .'.';
                GlobalController::InsertNewComment(6, $credit_id, NULL, $comment);
                GlobalController::InsertNewComment(11, $customer_id, NULL, $comment);
            }
            return redirect()->route('InvoiceController.showInvoice', ['credit_note']) ;

        }catch (ModelNotFoundException $e) {
            return redirect()->back()->with('error', 'Can not store payment.');
        }
    }
//    store debit note refund amount
    public function storeDebitRefund(Request $request)
    {

        try{
            $organizationId = org_id();
            $credit_id = $request->input('invoice_id');
            $refunded_amount = $request->input('amount');

            $referenceExist = checkReferenceExists($request->input('reference_number'));
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'error' => __('messages.reference_exists')
                ])->withInput();
            }

            if($refunded_amount > 0){
                $reference_number = $request->input('reference_number');
                $date = $request->input('date');
                $description = $request->input('description');
                $account_id = $request->input('account_id');
                $currencyName = currencyName();
                $payableAccount = GetPayableAccount();
                $creditNote = DebitNote::find($credit_id);
                $credit_number = $creditNote->invoice_number;
                $customer_id = $creditNote->partner_id;

                if (validateVATDate($creditNote->invoice_date) || validateVATDate($date)) {
                    return redirect()->back()->withInput()->with('error', errorMsg());
                }
                if($refunded_amount > $creditNote->amount_due){
                    return redirect()->back()->with('error', 'Please make sure that the amount is not greater than the balance '.$creditNote->amount_due.'.');
                }
                if( $creditNote->amount_due - $refunded_amount == 0){
                    $creditNote->status = 9; // paid
                }
                $creditNote->amount_due -= $refunded_amount;
                $creditNote->amount_received += $refunded_amount;
                //        add refund payment record
                //        INSERT NEW PAYMENT
                $payment['type_id'] = 18; // credit refund
                $payment['amount'] =  $refunded_amount;
                $payment['unused_amount'] =  0;
                $payment['internal_note'] = $description;
                $payment['reference_number'] = $reference_number;
                $payment['paid_by_id'] = $customer_id;
                $payment['date'] = $date;
                $payment_id = GlobalController::InsertNewPaymentReceived($payment);
//        add refund transaction
                $request1['transaction_type_id'] = 18;
                $request1['amount'] =  $refunded_amount;
                $request1['description'] = $description;
                $request1['payment_id'] = $payment_id;
                $request1['reference_number'] = $reference_number;
                $request1['paid_by_id'] = $customer_id;
                $request1['date'] = $date;
                $transaction = GlobalController::InsertNewTransaction($request1);
//      credit from account bank or cash
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $refunded_amount;
                $request2['account_id'] = $account_id;
                $request2['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($request2);
//      Debit to receivable Payment
                $request2['transaction_id'] = $transaction;
                $request2['amount'] = $refunded_amount;
                $request2['account_id'] = $payableAccount;
                $request2['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($request2);

                $invoiceRequest['transaction_id'] = $transaction;
                $invoiceRequest['invoice_id'] = $credit_id;
                $invoiceRequest['invoice_type_id'] = 7;
                GlobalController::InsertNewTransactionInvoice($invoiceRequest);
                $creditNote->save();

                $comment = 'Debit#'.$credit_number.' refunded, Amount of  ' . $currencyName .' '. $refunded_amount .'.';
                GlobalController::InsertNewComment(7, $credit_id, NULL, $comment);
                GlobalController::InsertNewComment(12, $customer_id, NULL, $comment);
            }
            return redirect()->route('InvoiceController.showInvoice', ['debit_note']) ;

        }catch (ModelNotFoundException $e) {
            return redirect()->back()->with('error', 'Can not store payment.');
        }
    }
    //    delete payment
    public function deleteReceivedPayment($id){
        $id = Crypt::decryptString($id);
     $transactionsNotToDelete = Transaction::where('payment_id', $id)
         ->where('organization_id', org_id())
         ->whereIn('transaction_type_id', [6, 13])//payment or credit note refund
         ->get();

        if($transactionsNotToDelete->isNotEmpty()){
         return response()->json(['status'=> 'error', 'message'=> 'Payment cannot be deleted as one or more refunds have been recorded for the payment.']);
     }
        $payment = PaymentReceived::find($id);


        if (validateVATDate($payment->date)) {
            return response()->json(['status'=> 'error', 'message'=> errorMsg()]);

        }

        if($payment){
         $transactionsToDelete = Transaction::where('payment_id', $id)
             ->where('organization_id', org_id())
             ->whereIn('transaction_type_id', [1,3,4,26])
             ->get();
            if($transactionsToDelete->isNotEmpty()){
                return response()->json(['status'=> 'error', 'message'=> 'Payment cannot be deleted as one or more invoice payment have been recorded.']);
            }
//         foreach ($transactionsToDelete as $transaction) {
//             if($transaction->transaction_type_id == 26){
////                 badi shayek iza def3a mn l opening balance
//             }
//             if($transaction->TransactionInvoice->first()){
//                 $invoice_id = $transaction->TransactionInvoice->first()->invoice_id;
//                 $invAmount = $transaction->amount;
////             braje3 l amount lal invoice
//                 $salesInvoice = SalesInvoice::find($invoice_id);
//                 if ($salesInvoice) {
//                     $salesInvoice->status = 4;
//                     $salesInvoice->amount_due += $invAmount;
//                     $salesInvoice->amount_received -= $invAmount;
//                     $salesInvoice->save();
//                 }
//             }
//             $transaction->TransactionDetails()->delete();
//             $transaction->TransactionInvoice()->delete();
//             $transaction->delete();
//             $payment->delete();
//         }
             $payment->delete();
            $comment = 'Payment#'.$payment->payment_number.' deleted.';
            GlobalController::InsertNewComment(11, $payment->paid_by_id, NULL, $comment);
            return response()->json(['status'=> 'success', 'message'=> 'The payment has been deleted.']);
        }else{
            return response()->json(['status'=> 'error', 'message'=> 'Payment is not found.']);

        }

    }
    public function deleteMadePayment($id){
        $id = Crypt::decryptString($id);
     $transactionsNotToDelete = Transaction::where('payment_id', $id)
         ->where('organization_id', org_id())
         ->whereIn('transaction_type_id', [16, 18])//payment or debit note refund
         ->get();

        if($transactionsNotToDelete->isNotEmpty()){
         return response()->json(['status'=> 'error', 'message'=> 'Payment cannot be deleted as one or more refunds have been recorded for the payment.']);
     }
        $payment = PaymentReceived::find($id);

        if (validateVATDate($payment->date)) {
            return response()->json(['status'=> 'error', 'message'=> errorMsg()]);

        }

        if($payment){
         $transactionsToDelete = Transaction::where('payment_id', $id)
             ->where('organization_id', org_id())
             ->whereIn('transaction_type_id', [2,17,15, 26])
             ->get();
            if($transactionsToDelete->isNotEmpty()){
                return response()->json(['status'=> 'error', 'message'=> 'Payment cannot be deleted as one or more bill payment have been recorded.']);
            }
//         foreach ($transactionsToDelete as $transaction) {
//             if($transaction->TransactionInvoice->first()){
//                 $invoice_id = $transaction->TransactionInvoice->first()->invoice_id;
//                 $invAmount = $transaction->amount;
////             braje3 l amount lal invoice
//                 $salesInvoice = PurchaseInvoice::find($invoice_id);
//                 if ($salesInvoice) {
//                     $salesInvoice->status = 4;
//                     $salesInvoice->amount_due += $invAmount;
//                     $salesInvoice->amount_received -= $invAmount;
//                     $salesInvoice->save();
//                 }
//             }
//             $transaction->TransactionDetails()->delete();
//             $transaction->TransactionInvoice()->delete();
//             $transaction->delete();
//             $payment->delete();
//         }

            $payment->delete();
            $comment = 'Payment#'.$payment->payment_number.' deleted.';
            GlobalController::InsertNewComment(12, $payment->paid_by_id, NULL, $comment);
            return response()->json(['status'=> 'success', 'message'=> 'The payment has been deleted.']);
        }else{
            return response()->json(['status'=> 'error', 'message'=> 'Payment is not found.']);

        }

    }
//    SHOW BALANCE OF CUSTOMER
    public function showCustomerBalance($id, $invoiceId){
        $invoiceId = Crypt::decryptString($invoiceId);
        $invoice = SalesInvoice::find($invoiceId);

        if($invoice->amount_due == 0){
            return redirect()->back()->with('error', 'There is no Due Amount for this invoice!');
        }
        $customerName = PartnerInfo($id)['name'];
        $unusedAmounts = PaymentReceived::where('organization_id', org_id())
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [1, 3])
            ->where('unused_amount', '>', 0)
            ->orderBy('date')
            ->get();
        $creditAmount = CreditNote::where('organization_id', org_id())
            ->where('partner_id', $id)
            ->whereIn('status', [8])
            ->where('amount_due', '>', 0)
            ->orderBy('created_at')
            ->get();

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalance = $customerOpeningBalanceDebit  = $creditApplied = $customerOpeningBalanceCredit  = 0;
        $openingBalanceData = [];

        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                ->where('partner_id', $id)
                ->select('debit_amount', 'credit_amount', 'id')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;

                $creditApplied = CreditApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');
//                bas yedfa3 li 3leh
                $debitApplied = Transaction::where('organization_id', org_id())
                    ->where('transaction_type_id', 26)
                    ->where('paid_by_id', $id)
                    ->sum('amount');
                $customerOpeningBalanceCredit -= $creditApplied;
                $customerOpeningBalanceDebit -= $debitApplied;
            }
            $openingBalance = $customerOpeningBalanceDebit - $customerOpeningBalanceCredit;
            if($openingBalance < 0){
                $openingBalanceData =[
                    'date' => $openingBalanceDate->date,
                    'amount' => abs($openingBalance),
                    'partner_id' => $id
                ];
            }
        }



        return view('pages.payments.customer.balance', compact(['openingBalanceData','customerName','creditAmount', 'unusedAmounts' , 'invoice' ]));

    }
//    Store Credit Balance of a customer
    public function storeCustomerBalance(Request $request, $invoiceId){

        $invoiceId = Crypt::decryptString($invoiceId);
        $invoice = SalesInvoice::find($invoiceId);
        $paymentAmount = $request->paid_amount;
        $paymentDate = $request->date;
        $receivableAccount = GetReceivableAccount();
        $advPaymentAccount = GetAdvPaymentAccount();
        if ($paymentAmount) {
            $paymentAmount = array_map('floatval', $paymentAmount);
        }

        if (validateVATDate($paymentDate)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }

        $text = '';
        for ($i = 0; $i < count($paymentAmount); $i++) {
            $ref_inv_number = '';
            if ($paymentAmount[$i] > 0 ) {
                if($request->is_credit[$i] == 0){//deduct from payment
                    $payment_id = $request->id[$i];
                    $payment = PaymentReceived::find($payment_id);
//                    update unused amount
                    $payment->unused_amount -= $paymentAmount[$i];
//                    update due amount and received amount on invoice
                    $invoice->amount_due -= $paymentAmount[$i];
                    $invoice->amount_received += $paymentAmount[$i];
                    if($invoice->amount_due == 0){
                        $invoice->status = 7;//paid
                    }
                    if($invoice->order_number){
                        $ref_inv_number = $invoice->order_number;
                    }
                    $payment->save();
                    $invoice->save();
                    // transaction as invoice payment
                    $request1['transaction_type_id'] = 4;
                    $request1['amount'] =  $paymentAmount[$i];
                    $request1['payment_id'] = $payment_id;
                    $request1['payment_number'] = $payment->payment_number;
                    $request1['reference_number'] = $ref_inv_number;
                    $request1['paid_by_id'] = $payment->paid_by_id;
                    $request1['date'] = $paymentDate;
                    $transaction = GlobalController::InsertNewTransaction($request1);
                    // deposit to adv payment
                    $request2['transaction_id'] = $transaction;
                    $request2['amount'] = $paymentAmount[$i];
                    $request2['account_id'] = $advPaymentAccount;
                    $request2['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($request2);
                    // credit from receivable account
                    $request3['transaction_id'] = $transaction;
                    $request3['amount'] = $paymentAmount[$i];
                    $request3['account_id'] = $receivableAccount;
                    $request3['is_debit'] = 0;
                    GlobalController::InsertNewTransactionDetails($request3);
                    // connect the invoice with the transaction
                    $invoiceRequest['transaction_id'] = $transaction;
                    $invoiceRequest['invoice_id'] = $invoiceId;
                    $invoiceRequest['invoice_type_id'] = 3;
                    GlobalController::InsertNewTransactionInvoice($invoiceRequest);
//                             Insert comment to invoice
                    $comment =  'Payment of '.currencyName().' '. $paymentAmount[$i].' made.';
                    GlobalController::InsertNewComment(3, $invoiceId,NULL, $comment);
                    //insert comment to payment
                    $comment =  'Payment from excess '.currencyName().' '.$paymentAmount[$i].' applied for invoice#'.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(9, $payment_id,NULL, $comment);
                    //      Insert comment to customer
                    $title =  'Payments added from excess';
                    $comment =  'Payment of '.currencyName().' '.$paymentAmount[$i].' made and applied for '.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(11, $payment->paid_by_id, $title, $comment);

                }else if($request->is_credit[$i] == 1){// credit note
                    $cn_id = $request->id[$i];
                    $creditNote = CreditNote::find($cn_id);
//                    update due amount and received amount on invoice
                    $invoice->amount_due -= $paymentAmount[$i];
                    $invoice->amount_received += $paymentAmount[$i];
//                    update due amount and received amount on credit note
                    $creditNote->amount_due -= $paymentAmount[$i];
                    $creditNote->amount_received += $paymentAmount[$i];
                    if($invoice->amount_due == 0){
                        $invoice->status = 7;//paid
                    }
                    if($creditNote->amount_due == 0){
                        $creditNote->status = 9;//closed
                    }
                    $creditNote->save();
                    $invoice->save();
                    CreditApplied::create([
                        'credit_id' => $cn_id,
                        'invoice_id' => $invoiceId,
                        'date' => $paymentDate,
                        'amount' => $paymentAmount[$i],
                        'is_creditnote' => 1,
                    ]);

                    //                             Insert comment to invoice
                    $comment =  'Payment of '.currencyName().' '. $paymentAmount[$i].' made.';
                    GlobalController::InsertNewComment(3, $invoiceId,NULL, $comment);
                    //insert comment to payment
                    $comment =  'Payment from excess '.currencyName().' '.$paymentAmount[$i].' applied for invoice#'.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(6, $cn_id,NULL, $comment);
                    //      Insert comment to customer
                    $title =  'Payments added from excess';
                    $comment =  'Payment of '.currencyName().' '.$paymentAmount[$i].' made and applied for invoice#'.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(11, $invoice->partner_id, $title, $comment);
                }else if($request->is_credit[$i] == 2){// opening balance
                    $partner_id = $request->id[$i];
                    $invoice->amount_due -= $paymentAmount[$i];
                    $invoice->amount_received += $paymentAmount[$i];
                    if($invoice->amount_due == 0){
                        $invoice->status = 7;//paid
                    }
                    $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                        ->where('partner_id', $partner_id)
                        ->select('debit_amount', 'credit_amount','id')
                        ->first();
                    if($customerOpeningBalance){
                        CreditApplied::create([
                            'credit_id' => NULL,
                            'invoice_id' => $invoiceId,
                            'date' => $paymentDate,
                            'amount' => $paymentAmount[$i],
                            'ob_id' => $customerOpeningBalance->id,
                            'is_creditnote' => 0,
                        ]);
                        $invoice->save();
                        //                             Insert comment to invoice
                        $comment =  'Payment of '.currencyName().' '. $paymentAmount[$i].' made.';
                        GlobalController::InsertNewComment(3, $invoiceId,NULL, $comment);
                        //      Insert comment to customer
                        $title =  'Payments added from opening balance';
                        $comment =  'Payment of '.currencyName().' '.$paymentAmount[$i].' applied for invoice#'.$invoice->invoice_number.'.';
                        GlobalController::InsertNewComment(11, $partner_id, $title, $comment);
                    }


                }
            }
        }
        return redirect()->route('InvoiceController.showInvoice', ['sales_invoice#row-' . $invoiceId]);

    }
//    SHOW BALANCE OF CUSTOMER
    public function showPayableBalance($id, $invoiceId){
        $invoiceId = Crypt::decryptString($invoiceId);
        $invoice = PurchaseInvoice::find($invoiceId);

        if($invoice->amount_due == 0){
            return redirect()->back()->with('error', 'There is no Due Amount for this bill!');
        }
        $customerName = PartnerInfo($id)['name'];
        $unusedAmounts = PaymentReceived::where('organization_id', org_id())
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [2, 17])
            ->where('unused_amount', '>', 0)
            ->orderBy('date')
            ->get();
        $creditAmount = DebitNote::where('organization_id', org_id())
            ->where('partner_id', $id)
            ->whereIn('status', [8])
            ->where('amount_due', '>', 0)
            ->orderBy('created_at')
            ->get();

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalance = $customerOpeningBalanceDebit  = $creditApplied = $customerOpeningBalanceCredit  = 0;
        $openingBalanceData = [];

        if($openingBalanceDate){
            $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                ->where('partner_id', $id)
                ->select('debit_amount', 'credit_amount', 'id')
                ->first();
            if($customerOpeningBalance){
                $customerOpeningBalanceDebit = $customerOpeningBalance->debit_amount;
                $customerOpeningBalanceCredit = $customerOpeningBalance->credit_amount;
                $creditApplied = DebitApplied::where('organization_id', org_id())
                    ->where('is_creditnote', 0)
                    ->where('ob_id', $customerOpeningBalance->id)
                    ->sum('amount');

//                bas yedfa3 li 3leh
                $debitApplied = Transaction::where('organization_id', org_id())
                    ->where('transaction_type_id', 26)
                    ->where('paid_by_id', $id)
                    ->sum('amount');

                $customerOpeningBalanceDebit -= $creditApplied;
                $customerOpeningBalanceCredit -= $debitApplied;

            }
            $openingBalance = $customerOpeningBalanceCredit - $customerOpeningBalanceDebit  ;
            if($openingBalance < 0){
//            ana badi meno
                $openingBalanceData =[
                    'date' => $openingBalanceDate->date,
                    'amount' => abs($openingBalance),
                    'partner_id' => $id
                ];
            }
        }



        return view('pages.payments.payable.balance', compact(['openingBalanceData','customerName','creditAmount', 'unusedAmounts' , 'invoice' ]));

    }
//    Store Credit Balance of a customer
    public function storePayableBalance(Request $request, $invoiceId){

        $invoiceId = Crypt::decryptString($invoiceId);
        $invoice = PurchaseInvoice::find($invoiceId);
        $paymentAmount = $request->paid_amount;
        $paymentDate = $request->date;
        $receivableAccount = GetReceivableAccount();
        $advPaymentAccount = GetAdvPaymentAccount();
        if ($paymentAmount) {
            $paymentAmount = array_map('floatval', $paymentAmount);
        }

        if (validateVATDate($paymentDate)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }

        $text = '';
        for ($i = 0; $i < count($paymentAmount); $i++) {
            $ref_inv_number = '';
            if ($paymentAmount[$i] > 0 ) {
                if($request->is_credit[$i] == 0){//deduct from payment
                    $payment_id = $request->id[$i];
                    $payment = PaymentReceived::find($payment_id);
//                    update unused amount
                    $payment->unused_amount -= $paymentAmount[$i];
//                    update due amount and received amount on invoice
                    $invoice->amount_due -= $paymentAmount[$i];
                    $invoice->amount_received += $paymentAmount[$i];
                    if($invoice->amount_due == 0){
                        $invoice->status = 7;//paid
                    }
                    if($invoice->order_number){
                        $ref_inv_number = $invoice->order_number;
                    }
                    $payment->save();
                    $invoice->save();
                    // transaction as invoice payment
                    $request1['transaction_type_id'] = 15;
                    $request1['amount'] =  $paymentAmount[$i];
                    $request1['payment_id'] = $payment_id;
                    $request1['payment_number'] = $payment->payment_number;
                    $request1['reference_number'] = $ref_inv_number;
                    $request1['paid_by_id'] = $payment->paid_by_id;
                    $request1['date'] = $paymentDate;
                    $transaction = GlobalController::InsertNewTransaction($request1);
                    // deposit to adv payment
                    $request2['transaction_id'] = $transaction;
                    $request2['amount'] = $paymentAmount[$i];
                    $request2['account_id'] = $advPaymentAccount;
                    $request2['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($request2);
                    // credit from receivable account
                    $request3['transaction_id'] = $transaction;
                    $request3['amount'] = $paymentAmount[$i];
                    $request3['account_id'] = $receivableAccount;
                    $request3['is_debit'] = 0;
                    GlobalController::InsertNewTransactionDetails($request3);
                    // connect the invoice with the transaction
                    $invoiceRequest['transaction_id'] = $transaction;
                    $invoiceRequest['invoice_id'] = $invoiceId;
                    $invoiceRequest['invoice_type_id'] = 4;
                    GlobalController::InsertNewTransactionInvoice($invoiceRequest);
//                             Insert comment to invoice
                    $comment =  'Payment of '.currencyName().' '. $paymentAmount[$i].' made.';
                    GlobalController::InsertNewComment(4, $invoiceId,NULL, $comment);
                    //insert comment to payment
                    $comment =  'Payment from excess '.currencyName().' '.$paymentAmount[$i].' applied for Bill#'.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(10, $payment_id,NULL, $comment);
                    //      Insert comment to customer
                    $title =  'Payments added from excess';
                    $comment =  'Payment of '.currencyName().' '.$paymentAmount[$i].' applied for Bill#'.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(12, $payment->paid_by_id, $title, $comment);

                }else if($request->is_credit[$i] == 1){// credit note
                    $cn_id = $request->id[$i];
                    $creditNote = DebitNote::find($cn_id);
//                    update due amount and received amount on invoice
                    $invoice->amount_due -= $paymentAmount[$i];
                    $invoice->amount_received += $paymentAmount[$i];
//                    update due amount and received amount on credit note
                    $creditNote->amount_due -= $paymentAmount[$i];
                    $creditNote->amount_received += $paymentAmount[$i];
                    if($invoice->amount_due == 0){
                        $invoice->status = 7;//paid
                    }
                    if($creditNote->amount_due == 0){
                        $creditNote->status = 9;//closed
                    }
                    $creditNote->save();
                    $invoice->save();
                    DebitApplied::create([
                        'debit_id' => $cn_id,
                        'invoice_id' => $invoiceId,
                        'date' => $paymentDate,
                        'amount' => $paymentAmount[$i],
                        'is_creditnote' => 1,
                    ]);

                    //                             Insert comment to invoice
                    $comment =  'Payment of '.currencyName().' '. $paymentAmount[$i].' made.';
                    GlobalController::InsertNewComment(4, $invoiceId,NULL, $comment);
                    //insert comment to payment
                    $comment =  'Payment from excess '.currencyName().' '.$paymentAmount[$i].' applied for Bill#'.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(7, $cn_id,NULL, $comment);
                    //      Insert comment to customer
                    $title =  'Payments added from excess';
                    $comment =  'Payment of '.currencyName().' '.$paymentAmount[$i].' made and applied for Bill#'.$invoice->invoice_number.'.';
                    GlobalController::InsertNewComment(12, $invoice->partner_id, $title, $comment);
                }else if($request->is_credit[$i] == 2){// opening balance
                    $partner_id = $request->id[$i];
                    $invoice->amount_due -= $paymentAmount[$i];
                    $invoice->amount_received += $paymentAmount[$i];
                    if($invoice->amount_due == 0){
                        $invoice->status = 7;//paid
                    }
                    $customerOpeningBalance = ObPartners::where('organization_id', org_id())
                        ->where('partner_id', $partner_id)
                        ->select('debit_amount', 'credit_amount','id')
                        ->first();
                    if($customerOpeningBalance){
                        DebitApplied::create([
                            'debit_id' => NULL,
                            'invoice_id' => $invoiceId,
                            'date' => $paymentDate,
                            'amount' => $paymentAmount[$i],
                            'ob_id' => $customerOpeningBalance->id,
                            'is_creditnote' => 0,
                        ]);
                        $invoice->save();
                        //                             Insert comment to invoice
                        $comment =  'Payment of '.currencyName().' '. $paymentAmount[$i].' made.';
                        GlobalController::InsertNewComment(4, $invoiceId,NULL, $comment);
                        //      Insert comment to customer
                        $title =  'Payments added from opening balance';
                        $comment =  'Payment of '.currencyName().' '.$paymentAmount[$i].' made and applied for Bill#'.$invoice->invoice_number.'.';
                        GlobalController::InsertNewComment(12, $partner_id, $title, $comment);
                    }


                }
            }
        }
        return redirect()->route('InvoiceController.showInvoice', ['purchase_invoice#row-' . $invoiceId]);

    }
//    store transfer transaction
    public function storeTransferAccount(Request $request)
    {
        $rules = [
            'from_account_id' => 'required',
            'to_account_id' => 'required|different:from_account_id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:1',
        ];
        $messages = [
            'to_account_id.different' => 'The transfer should be between two different accounts.',
            'amount.min' => 'The amount must be at least 1.',
        ];

         $request->validate($rules, $messages);

        $fromAccount = $request->input('from_account_id');
        $toAccount = $request->input('to_account_id');
        $referenceNumber = $request->input('reference_number');
        $description = $request->input('description');
        $date = $request->input('date');
        $amount = $request->input('amount');
        $transferType = $request->input('transfer_type');

        if (validateVATDate($date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }

        $referenceExist = checkReferenceExists($request->input('reference_number'));
//        return var_dump($referenceExist);
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }


        if($amount > 0){
            $request1['transaction_type_id'] = $transferType; //transfer
            $request1['amount'] =  $amount;
            $request1['description'] = $description;
            $request1['reference_number'] = $referenceNumber;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
//      credit
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $amount;
            $request2['account_id'] = $fromAccount;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
//      Debit
            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $amount;
            $request2['account_id'] = $toAccount;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);

            $comment = 'Transfer of amount '.currencyName().$amount.' applied from'.GlobalController::getAccountName($fromAccount).
                'to '.GlobalController::getAccountName($toAccount) . '.';
            GlobalController::InsertNewComment(18, $transaction, 'Journal Created', NULL);
            GlobalController::InsertNewComment(18, $transaction, NULL, $comment);
            if ($request->hasFile('files')) {
                $fileNamesToStore = [];
                foreach ($request->file('files') as $file) {
                    $fileName = uploadFile($file, 'journal');
                    $fileNamesToStore[] = $fileName;
                    $invoiceFile = new TransactionDocuments();
                    $invoiceFile->transaction_id = $transaction;
                    $invoiceFile->name = $fileName;
                    $invoiceFile->save();
                }
            }
            return redirect()->route('TransactionController.showJournal', ['#row-' . $transaction]);
        }
        return redirect()->back()->withInput()->with('error', 'Please fill all required fields.');

    }
//    store transfer transaction
    public function storeManualJV(Request $request)
    {


        $rules = [
            'account_id' => 'required|array|min:3', // Ensure account_id is an array and has at least 2 items
            'debit' => 'required|array', // Ensure you have debit/credit selection for each row
            'debit.*' => 'required|boolean', // Ensure is_debit is provided for each row
            'amount' => 'required|array', // Ensure amount array exists
            'amount.*' => 'required|numeric', // Ensure each amount is valid and positive
            'total_amount' => 'required|numeric|min:0.01', // Ensure total amount is valid
            'date' => 'required|date', // Ensure date is provided
        ];

        $messages = [
            'account_id.required' => 'You must select at least one account.',
            'account_id.min' => 'You must select at least two accounts.',
            'amount.*.required' => 'Each account amount is required.',
            'amount.*.numeric' => 'Each account amount must be a valid number.',
            'debit.required' => 'Each account must specify if it is a debit or credit.',
            'debit.*.boolean' => 'Debit/credit information is invalid.',
            'total_amount.required' => 'The total amount is required.',
        ];

// Validate input
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, $messages);
        $referenceExist = checkReferenceExists($request->input('reference_number'));
//        return var_dump($referenceExist);
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }

// Custom validation for debit and credit sums
        $validator->after(function ($validator) use ($request) {
            $accountAmount = $request->input('amount', []);
            $isDebit = $request->input('debit', []);
            $totalAmount = $request->input('total_amount');

            $debitSum = 0;
            $creditSum = 0;

            // Calculate the total debit and credit sums
            foreach ($accountAmount as $index => $amount) {
                if (isset($isDebit[$index])) {
                    if ($isDebit[$index]) {
                        $debitSum += $amount;
                    } else {
                        $creditSum += $amount;
                    }
                }
            }

            // Ensure debits equal credits and total amount matches
            if ($debitSum !== $creditSum) {
                $validator->errors()->add('amount', 'The debit and credit amounts must be equal.');
            }
            if ($debitSum != $totalAmount) {

                $validator->errors()->add('total_amount', 'The total amount must equal the sum of the account amounts.');
            }
        });

// Check if validation fails
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $account = $request->input('account_id');
        $referenceNumber = $request->input('reference_number');
        $description = $request->input('description');
        $internalNote = $request->input('internal_note');
        $date = $request->input('date');
        $totalAmount = $request->input('total_amount');
        $accountAmount = $request->input('amount', []);
        $totalPaymentAmount = array_sum($accountAmount);

        $referenceExist = checkReferenceExists($request->input('reference_number'));
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }

        if (validateVATDate($date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }

        if (isset($request->account_id)) {
            $request1['transaction_type_id'] = 10; //transfer
            $request1['amount'] =  $totalAmount;
            $request1['description'] = $description;
            $request1['internal_note'] = $internalNote;
            $request1['reference_number'] = $referenceNumber;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);
            for ($index = 1; $index < count($request->account_id); $index++) {
                if ($request->account_id[$index]) {
                   if($request->amount[$index] > 0){
                       $request2['transaction_id'] = $transaction;
                       $request2['amount'] = $request->amount[$index];
                       $request2['account_id'] = $request->account_id[$index];
                       $request2['description'] = $request->account_description[$index];
                       $request2['is_debit'] = $request->debit[$index];
                       GlobalController::InsertNewTransactionDetails($request2);
                   }
                }
            }

            $comment = 'Journal Created for '.currencyName().$totalAmount . '.';
            GlobalController::InsertNewComment(18, $transaction, NULL, $comment);

            if ($request->hasFile('files')) {
                $fileNamesToStore = [];
                foreach ($request->file('files') as $file) {
                    $fileName = uploadFile($file, 'journal');
                    $fileNamesToStore[] = $fileName;
                    $invoiceFile = new TransactionDocuments();
                    $invoiceFile->transaction_id = $transaction;
                    $invoiceFile->name = $fileName;
                    $invoiceFile->save();
                }
            }
            return redirect()->route('TransactionController.showJournal', ['#row-' . $transaction]);
        }
        return redirect()->back()->withInput()->with('error', 'Please fill all required fields.');

    }
//    edit transfer transaction
    public function editBankTransferAccount(Request $request)
    {
        $rules = [
            'from_account_id' => 'required',
            'to_account_id' => 'required|different:from_account_id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:1',
        ];
        $messages = [
            'to_account_id.different' => 'The transfer should be between two different accounts.',
            'amount.min' => 'The amount must be at least 1.',
        ];

        $request->validate($rules, $messages);



        $transactionID = $request->input('transaction_id');
        $fromAccount = $request->input('from_account_id');
        $toAccount = $request->input('to_account_id');
        $referenceNumber = $request->input('reference_number');
        $description = $request->input('description');
        $date = $request->input('date');
        $amount = $request->input('amount');
        $transferType = $request->input('transfer_type');
        $transaction = Transaction::find($transactionID);

        if (validateVATDate($transaction->date) || validateVATDate($date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }
        if($referenceNumber != $transaction->reference_number){
            $referenceExist = checkReferenceExists($request->input('reference_number'));
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'error' => __('messages.reference_exists')
                ])->withInput();
            }
        }

        if($amount > 0){

            $transaction->update($request->all());

            $transaction->TransactionDetails()->delete();
//      credit
            $request2['transaction_id'] = $transactionID;
            $request2['amount'] = $amount;
            $request2['account_id'] = $fromAccount;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);
//      Debit
            $request2['transaction_id'] = $transactionID;
            $request2['amount'] = $amount;
            $request2['account_id'] = $toAccount;
            $request2['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request2);

            $selectedFiles = $request->input('current_files');
            if ($selectedFiles) {
                $selectedFilesFiltered = array_map('intval', $selectedFiles);
                $selectedFilesFiltered = array_filter($selectedFilesFiltered, function ($value) {
                    return $value !== NULL;
                });
                //        remove deleted invoice files
                TransactionDocuments::where('transaction_id', $transactionID)
                    ->whereNotIn('id', $selectedFilesFiltered)
                    ->delete();
            }else{
                //        remove deleted invoice files
                TransactionDocuments::where('transaction_id', $transactionID)
                    ->delete();
            }
            if ($request->hasFile('files')) {
                $fileNamesToStore = [];
                foreach ($request->file('files') as $file) {
                    $fileName = uploadFile($file, 'journal');
                    $fileNamesToStore[] = $fileName;
                    $invoiceFile = new TransactionDocuments();
                    $invoiceFile->transaction_id = $transactionID;
                    $invoiceFile->name = $fileName;
                    $invoiceFile->save();
                }
            }
            $comment = 'Transfer of amount '.currencyName().$amount.' applied from'.GlobalController::getAccountName($fromAccount).
                'to '.GlobalController::getAccountName($toAccount) . '.';
            GlobalController::InsertNewComment(18, $transactionID, 'Journal updated', NULL);

            return redirect()->route('TransactionController.showJournal', ['#row-' . $transactionID]);
        }
        return redirect()->back()->withInput()->with('error', 'Please fill all required fields.');

    }
    public function editManualJV(Request $request)
    {
        $rules = [
            'account_id' => 'required|array|min:3', // Ensure account_id is an array and has at least 2 items
            'debit' => 'required|array', // Ensure you have debit/credit selection for each row
            'debit.*' => 'required|boolean', // Ensure is_debit is provided for each row
            'account_amount' => 'required|array', // Ensure amount array exists
            'account_amount.*' => 'required|numeric', // Ensure each amount is valid and positive
            'amount' => 'required|numeric|min:0.01', // Ensure total amount is valid
            'date' => 'required|date', // Ensure date is provided
        ];

        $messages = [
            'account_id.required' => 'You must select at least one account.',
            'account_id.min' => 'You must select at least two accounts.',
            'account_amount.*.required' => 'Each account amount is required.',
            'account_amount.*.numeric' => 'Each account amount must be a valid number.',
            'debit.required' => 'Each account must specify if it is a debit or credit.',
            'debit.*.boolean' => 'Debit/credit information is invalid.',
            'amount.required' => 'The total amount is required.',
        ];
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, $messages);
        $validator->after(function ($validator) use ($request) {
            $accountAmount = $request->input('account_amount', []);
            $isDebit = $request->input('debit', []);
            $totalAmount = $request->input('amount');
            $debitSum = 0;
            $creditSum = 0;
            foreach ($accountAmount as $index => $amount) {
                if (isset($isDebit[$index])) {
                    if ($isDebit[$index]) {
                        $debitSum += $amount;
                    } else {
                        $creditSum += $amount;
                    }
                }
            }
            if ($debitSum !== $creditSum) {
                $validator->errors()->add('amount', 'The debit and credit amounts must be equal.');
            }
            if ($debitSum != $totalAmount) {
                $validator->errors()->add('amount', 'The total amount must equal the sum of the account amounts.');
            }
        });
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $transactionID = $request->input('transaction_id');
        $account = $request->input('account_id');
        $referenceNumber = $request->input('reference_number');
        $description = $request->input('description');
        $date = $request->input('date');
        $totalAmount = $request->input('amount');
        $accountAmount = $request->input('account_amount', []);
        $totalPaymentAmount = array_sum($accountAmount);
        $transaction = Transaction::find($transactionID);
        if (validateVATDate($transaction->date) || validateVATDate($date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }
        if($referenceNumber != $transaction->reference_number){
            $referenceExist = checkReferenceExists($request->input('reference_number'));
            if ($referenceExist) {
                return redirect()->back()->withErrors([
                    'error' => __('messages.reference_exists')
                ])->withInput();
            }
        }

        if($totalAmount > 0){

            $transaction->update($request->all());
            $transaction->TransactionDetails()->delete();
            if (isset($request->account_id)) {
                for ($index = 1; $index < count($request->account_id); $index++) {
                    if ($request->account_id[$index]) {
                        if($request->account_amount[$index] > 0){
                            $request2['transaction_id'] = $transactionID;
                            $request2['amount'] = $request->account_amount[$index];
                            $request2['account_id'] = $request->account_id[$index];
                            $request2['description'] = $request->account_description[$index];
                            $request2['is_debit'] = $request->debit[$index];
                            GlobalController::InsertNewTransactionDetails($request2);
                        }
                    }
                }
            }
            $selectedFiles = $request->input('current_files');
            if($selectedFiles) {
                $selectedFilesFiltered = array_map('intval', $selectedFiles);
                $selectedFilesFiltered = array_filter($selectedFilesFiltered, function ($value) {
                    return $value !== NULL;
                });
                //        remove deleted invoice files
                TransactionDocuments::where('transaction_id', $transactionID)
                    ->whereNotIn('id', $selectedFilesFiltered)
                    ->delete();
            }else{
                //        remove deleted invoice files
                TransactionDocuments::where('transaction_id', $transactionID)
                    ->delete();
            }
            if ($request->hasFile('files')) {
                $fileNamesToStore = [];
                foreach ($request->file('files') as $file) {
                    $fileName = uploadFile($file, 'journal');
                    $fileNamesToStore[] = $fileName;
                    $invoiceFile = new TransactionDocuments();
                    $invoiceFile->transaction_id = $transactionID;
                    $invoiceFile->name = $fileName;
                    $invoiceFile->save();
                }
            }

            $comment = 'Journal updated.';
            GlobalController::InsertNewComment(18, $transactionID, $comment, NULL);

            return redirect()->route('TransactionController.showJournal', ['#row-' . $transactionID]);
    }
        return redirect()->back()->withInput()->with('error', 'Please fill all required fields.');
    }
    public function showJournal(){
        $journals = Transaction::where('organization_id', org_id())
            ->whereIn('transaction_type_id', [9, 10, 11, 12,22, 23,24,25,27, 28, 8, 29]) //Bank Transaction and manual journal, asset,
            // expense, vat report, vat payment, account opening balance, customer opening balance, payroll, owner contr, adv project payment, loan
            ->orderBy('date')
            ->get();
        return view('pages.journal.index', compact(['journals' ]));
    }
    public function viewJournal($id){
        $id = Crypt::decryptString($id);
        $journal = Transaction::where('organization_id', org_id())
            ->where('id', $id)
            ->get()
            ->last();
        return view('pages.journal.moreDetails', compact(['journal' ]));

    }
    public function viewHtmlJournal($id){
        $id = Crypt::decryptString($id);
        $journal = Transaction::where('id', $id)
            ->get()
            ->last();
        return view('pages.journal.template', compact(['journal' ]));

    }
//    delete bank transaction or jounal
    public function deleteTransaction($id){
        $transactionsToDelete = Transaction::where('id', $id)
            ->where('organization_id', org_id())
            ->whereIn('transaction_type_id', [9,10,27, 28, 29])
            ->get();
        foreach ($transactionsToDelete as $transaction){
            if (validateVATDate($transaction->date)) {
                response()->json(['status' => 'error',
                    'message' => errorMsg()]);
            }
        }
        foreach ($transactionsToDelete as $transaction) {
            $transaction->TransactionDetails()->delete();
            $transaction->delete();
        }
        return response()->json(['status' => 'success',
            'message' => 'Transaction deleted successfully']);
    }
    public static function  getTransactionsByPayment($id){
        $paymentJournal = PaymentReceived::with(['Transaction','Transaction.TransactionInvoice', 'TransactionType'])
            ->where('id', $id)
            ->first();

        return $paymentJournal;
    }
    public static function  getTransactionsByCreditRefund($id){
        $paymentJournal = Transaction::with(['TransactionInvoice'])
            ->where('organization_id', org_id())
            ->where('payment_id', $id)
            ->where('transaction_type_id', 13)
            ->first();

        return $paymentJournal;

    }
}
