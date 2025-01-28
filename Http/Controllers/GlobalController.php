<?php
namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\AccountType;
use App\Comment;
use App\CreditApplied;
use App\CreditNote;
use App\Currency;
use App\DebitApplied;
use App\DebitNote;
use App\Invoice;
use App\ObAdjustment;
use App\ObPartners;
use App\Partner;
use App\PartnersContactPerson;
use App\PaymentReceived;
use App\PurchaseInvoice;
use App\SalesInvoice;
use App\Status;
use App\Tax;
use App\Transaction;
use App\TransactionDetails;
use App\TransactionInvoice;
use App\TransactionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
//use Barryvdh\DomPDF\Facade as PDF;
use Dompdf\Dompdf ;
use Dompdf\Options;
class GlobalController extends Controller{

    public function showComments($type_id, $id){
//        return 'hi';
        $comments = Comment::where('organization_id', org_id())
            ->where('type_id', $type_id)
            ->where('related_to_id', $id)
            ->orderby('id', 'DESC')
            ->get();
        return view('pages.template.comments', compact(['comments', 'type_id', 'id']));

    }
    public function addComment(Request $request)
    {
        // Validate the request data
        $request->validate([
            'type_id' => 'required|integer',
            'related_to_id' => 'required|integer',
            'comment' => 'required|string|max:255',
        ]);

       Comment::create([
            'type_id' => $request->type_id,
            'related_to_id' => $request->related_to_id,
            'text' => $request->comment,
            'is_comment' => 1,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Comment added successfully']);
    }
    public static function GetContact($id)
    {
        $partner = Partner::find($id);
        if (!$partner) {
            return '<option value="">No contact found</option>';
        }
        $contactEmail = $partner->email;
        $contactsEmail = PartnersContactPerson::where('partner_id', $id)
            ->pluck('email');
        $html = '<option value="' . htmlspecialchars($contactEmail, ENT_QUOTES) . '">' . htmlspecialchars($contactEmail, ENT_QUOTES) . '</option>';
        foreach ($contactsEmail as $email) {
            if($email != ''){
                $html .= '<option value="' . htmlspecialchars($email, ENT_QUOTES) . '">' . htmlspecialchars($email, ENT_QUOTES) . '</option>';
            }
        }

        return $html;
    }
    public static function GetOrgID($id)
    {
        $organizationId = 1;
        return $organizationId;
    }
    public static function GetStatus($id)
    {
        $status = Status::findOrFail($id);
        return $status->name;
    }
    public static function getAccountName($id)
    {
        $account = ChartOfAccounts::findOrFail($id);
        return $account->name;
    }
    public static function getVatName($id)
    {
        $vatName = Tax::findOrFail($id);
        return $vatName->name;
    }
    public static function getInvoiceNumberById($id)
    {
        $invoice = SalesInvoice::findOrFail($id);
        return $invoice->invoice_number;
    }
    public static function getBillNumberById($id)
    {
        $invoice = PurchaseInvoice::findOrFail($id);
        return $invoice->invoice_number;
    }
//    get credit note amount by invoice id
    public static function getCreditNoteAmount($id)
    {
        $organizationId = org_id();
        $result = 0;
        $invoice = CreditNote::where('organization_id', $organizationId)
        ->where('invoice_id', $id)
        ->whereIn('status', [4,8,9])
        ->get();
        if($invoice){
            foreach($invoice as $i){
                $result += $i->total;
            }
        }
        return $result;
    }
//    get credit note amount by invoice id
    public static function getDebitNoteAmount($id)
    {
        $organizationId = org_id();
        $result = 0;
        $invoice = DebitNote::where('organization_id', $organizationId)
        ->where('invoice_id', $id)
        ->whereIn('status', [4,8,9])
        ->get();
        if($invoice){
            foreach($invoice as $i){
                $result += $i->total;
            }
        }
        return $result;
    }
    public static function GetTransactionName($id)
    {
        $transactionType = TransactionType::findOrFail($id);
        return $transactionType->name;
    }
    public static function GetAdvPaymentAccount($organizationId)
    {
        $account = ChartOfAccounts::where('organization_id', $organizationId)
            ->where('type_id', 20)
            ->first();
        return $account->id;
    }
    public static function GetBankChargeAccount($organizationId)
    {
        $account = ChartOfAccounts::where('organization_id', $organizationId)
            ->where('type_id', 23)
            ->first();
        return $account->id;
    }
    public static function GetCurrencyName($currency_id)
    {
        $currency = Currency::where('id', $currency_id)
            ->first();
        return $currency->name;
    }
    public static function GetReceivableAccount($organizationId)
    {
        $account = ChartOfAccounts::where('organization_id', $organizationId)
            ->where('type_id', 19)
            ->first();
        return $account->id;
    }
    public static function GetPayableAccount($organizationId)
    {
        $account = ChartOfAccounts::where('organization_id', $organizationId)
            ->where('type_id', 12)
            ->first();
        return $account->id;
    }
    public static function GetOutputVATAccount($organizationId)
    {
        $account = ChartOfAccounts::where('organization_id', $organizationId)
            ->where('type_id', 13)
            ->first();
        return $account->id;
    }
    public static function GetInputVATAccount($organizationId)
    {
        $account = ChartOfAccounts::where('organization_id', $organizationId)
            ->where('type_id', 17)
            ->first();
        return $account->id;
    }
    public static function InsertNewTransaction($request)
    {
        $transaction = new Transaction();
        $transaction->transaction_type_id = $request['transaction_type_id'];
        $transaction->amount = $request['amount'];
        $transaction->currency = currencyID();
        $transaction->description = isset($request['description']) ? $request['description'] : NULL;
        $transaction->internal_note = isset($request['internal_note']) ? $request['internal_note'] : NULL;
        $transaction->reference_number = isset($request['reference_number']) ? $request['reference_number'] : NULL;
        $transaction->taxable_amount = isset($request['taxable_amount']) ? $request['taxable_amount'] : NULL;
        $transaction->non_taxable_amount = isset($request['non_taxable_amount']) ? $request['non_taxable_amount'] : NULL;
        $transaction->payment_id = isset($request['payment_id']) ? $request['payment_id'] : NULL;
        $transaction->payment_number = isset($request['payment_number']) ? $request['payment_number'] : NULL;
        $transaction->paid_by_id = isset($request['paid_by_id']) ? $request['paid_by_id'] : NULL;
        $transaction->date = isset($request['date']) ? $request['date'] : \Carbon\Carbon::now();
        $transaction->save();
        return $transaction->id;
    }
    public static function InsertNewPaymentReceived($request)
    {
        $transaction = new PaymentReceived();
        $transaction->type_id = $request['type_id'];
        $transaction->amount = $request['amount'];
        $transaction->unused_amount = $request['unused_amount'];
        $transaction->bank_charge = isset($request['bank_charge']) ? $request['bank_charge'] : 0;
        $transaction->mode =  isset($request['mode']) ? $request['mode'] : NULL;
        $transaction->currency = currencyID();
        $transaction->note = isset($request['internal_note']) ? $request['internal_note'] : NULL;
        $transaction->payment_number = isset($request['payment_number']) ? $request['payment_number'] : NULL;
        $transaction->reference_number = isset($request['reference_number']) ? $request['reference_number'] : NULL;
        $transaction->paid_by_id = isset($request['paid_by_id']) ? $request['paid_by_id'] : NULL;
        $transaction->date = isset($request['date']) ? $request['date'] : \Carbon\Carbon::now();
        $transaction->save();
        return $transaction->id;
    }
    public static function InsertNewTransactionDetails($request)
    {
        $transaction = new TransactionDetails();
        $transaction->transaction_id = $request['transaction_id'];
        $transaction->account_id = $request['account_id'];
        $transaction->amount = $request['amount'];
        $transaction->is_debit = $request['is_debit'];
        $transaction->description = isset($request['description']) ? $request['description'] : NULL;
        $transaction->save();
        return $transaction->id;
    }
    public static function InsertNewTransactionInvoice($request)
    {
        $transaction = new TransactionInvoice();
        $transaction->transaction_id = $request['transaction_id'];
        $transaction->invoice_id = $request['invoice_id'];
        $transaction->invoice_type_id = $request['invoice_type_id'];
        $transaction->save();
        return $transaction->id;
    }
    public static function InsertNewTransactionProject($request)
    {
        $transaction = new TransactionInvoice();
        $transaction->transaction_id = $request['transaction_id'];
        $transaction->project_id = $request['project_id'];
        $transaction->save();
        return $transaction->id;
    }
    public static function InsertNewComment($type_id, $related_to_id,$title, $text)
    {
        $comment = new Comment();
        $comment->type_id =  $type_id;
        $comment->related_to_id =  $related_to_id;
        $comment->title =  $title;
        $comment->text =  $text;
        $comment->save();
    }
    public static function getCustomerBalance($id){
        $unusedAmounts = PaymentReceived::where('organization_id', org_id())
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [1, 3])
            ->where('unused_amount', '>', 0)
            ->orderBy('created_at')
            ->sum('unused_amount');

        $creditAmount = CreditNote::where('organization_id', org_id())
            ->where('partner_id', $id)
            ->whereIn('status', [9, 8])
            ->where('amount_due', '>', 0)
            ->orderBy('created_at')
            ->sum('amount_due');

         $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalance = $customerOpeningBalanceDebit  = $creditApplied = $customerOpeningBalanceCredit  = 0;

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
                $customerOpeningBalanceCredit -= $creditApplied;

            }

        }

        $openingBalance = $customerOpeningBalanceDebit - $customerOpeningBalanceCredit;

        if($openingBalance < 0){
//            y3ni hwe bado meni
            $excessAmount = abs($openingBalance) + $unusedAmounts + $creditAmount;

        }else{
            $excessAmount = $unusedAmounts + $creditAmount;
        }

        return  $excessAmount;
    }
    public static function getPayableBalance($id){
        $unusedAmounts = PaymentReceived::where('organization_id', org_id())
            ->where('paid_by_id', $id)
            ->whereIn('type_id', [2, 17])
            ->where('unused_amount', '>', 0)
            ->orderBy('created_at')
            ->sum('unused_amount');

        $creditAmount = DebitNote::where('organization_id', org_id())
            ->where('partner_id', $id)
            ->whereIn('status', [9, 8])
            ->where('amount_due', '>', 0)
            ->orderBy('created_at')
            ->sum('amount_due');

        $openingBalanceDate = ObAdjustment::where('organization_id', org_id())
            ->first();
        $openingBalance = $customerOpeningBalanceDebit  = $creditApplied = $customerOpeningBalanceCredit  = 0;

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
                $customerOpeningBalanceDebit -= $creditApplied;

            }

        }

        $openingBalance = $customerOpeningBalanceCredit - $customerOpeningBalanceDebit ;

        if($openingBalance < 0){
//            y3ni ana bado meni
            $excessAmount = abs($openingBalance) + $unusedAmounts + $creditAmount;

        }else{
            $excessAmount = $unusedAmounts + $creditAmount;
        }

        return  $excessAmount;

    }
    public static function hasExpenseRefund($expenseId){
        $transactions =  Transaction::with(['TransactionExpense', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->where('transaction_type_id', 20) //refund
            ->whereHas('TransactionExpense', function ($query) use ($expenseId) {
                $query->where('expense_id', $expenseId);
            })
            ->first();
        if($transactions){
            return 'Has Refund';
        }else return '';
    }


    public function generatePdfFromUrl(Request $request)
    {

        $url = $request->input('url');

        // Fetch HTML content from the URL
        $html = file_get_contents($url);

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

        // Return PDF as binary data
        return response($dompdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="document.pdf"');

//        return $dompdf->stream('document.pdf', [
//            'Attachment' => 1 // 1 to force download, 0 to display inline
//        ]);
    }

}
