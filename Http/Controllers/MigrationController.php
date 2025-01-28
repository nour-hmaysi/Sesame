<?php

namespace App\Http\Controllers;

use App\AssetType;
use App\Budget;
use App\ChartOfAccounts;
use App\Asset;
use App\CreditApplied;
use App\DebitApplied;
use App\DepreciationRecord;
use App\DepreciationType;
use App\ExpenseDocuments;
use App\InvoiceHasItems;
use App\ObAccounts;
use App\ObAdjustment;
use App\ObPartners;
use App\Partner;
use App\Transaction;
use App\TransactionAdjustment;
use App\TransactionAsset;
use App\TransactionDetails;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MigrationController extends Controller
{
    public function index()
    {
        $date = ObAdjustment::where('organization_id', org_id())->select('date')->first();

        $openingBalances = ObAccounts::where('organization_id', org_id())->get();
        $PartnerOpeningBalances = ObPartners::where('organization_id', org_id())->get();

        return view('pages.migration.index', compact('openingBalances','PartnerOpeningBalances','date'));
    }

    public function create($type)
    {
        $validTypes = ['accounts', 'partners'];
        if (!in_array($type, $validTypes)) {
            abort(404);
        }
        $date = ObAdjustment::where('organization_id', org_id())->select('date')->first();
        $accounts = '';
        if($type == 'accounts'){
            $accounts = ChartOfAccounts::with('children', 'accountType')
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->whereNull('parent_account_id')
                ->get();
        }else if($type == 'partners'){
            $accounts = Partner::where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderBy('partner_type')
                ->get();
        }



        return view('pages.migration.create', compact('accounts','date', 'type'));
    }
    public function edit($type)
    {
        $validTypes = ['accounts', 'partners'];
        if (!in_array($type, $validTypes)) {
            abort(404);
        }
        $date = ObAdjustment::where('organization_id', org_id())->select('date')->first();
        if($type == 'accounts'){
            $accounts = ChartOfAccounts::with('children', 'accountType')
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->whereNull('parent_account_id')
                ->get();
            $openingBalances = ObAccounts::where('organization_id', org_id())->get();
        }else{
            $accounts = Partner::where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderBy('partner_type')
                ->get();
            $openingBalances = ObPartners::where('organization_id', org_id())->get();
        }
        return view('pages.migration.edit', compact('openingBalances','accounts','date','type'));
    }

    public function store($type, Request $request)
    {
        $date = $request->date;
        $balances = $request->balances;
        $org_id = org_id();
        $receivableAccount = GetReceivableAccount();
        $payableAccount = GetPayableAccount();
        $adjustmentAccount = GetAdjustmentAccount();
        ObAdjustment::updateOrCreate(
            ['organization_id' => $org_id],
            ['date' => $date ]
        );
        foreach ($balances as $balance) {
            $debitBalance = $balance['debit_amount'] ?? 0;
            $creditBalance = $balance['credit_amount'] ?? 0;
            if ($debitBalance > 0 || $creditBalance > 0) {
                if ($type == 'accounts') {
                    $account = ObAccounts::updateOrCreate(
                        [
                            'organization_id' => $org_id,
                            'account_id' => $balance['account_id']
                        ],
                        [
                            'debit_amount' => $debitBalance,
                            'credit_amount' => $creditBalance,
                        ]
                    );
                    $description = 'Opening Balance ';
                    if($debitBalance > 0){
                        self::InsertTransactions(24, $debitBalance, $date,  $adjustmentAccount,$balance['account_id'],1, $account->id, 1, $description);
                    }

                    if($creditBalance > 0){
                        self::InsertTransactions(24, $creditBalance, $date, $balance['account_id'], $adjustmentAccount,1, $account->id,0, $description);
                    }
                }else if($type == 'partners'){
                    $partner = ObPartners::updateOrCreate(
                        [
                            'organization_id' => $org_id,
                            'partner_id' => $balance['account_id']
                        ],
                        [
                            'debit_amount' => $debitBalance,
                            'credit_amount' => $creditBalance,
                        ]
                    );
                    $partnerName = PartnerInfo($balance['account_id'])['name'];
                    $description = 'Opening Balance - '.$partnerName;
                    if($debitBalance > 0){
                        if($balance['partner_type'] == 1 ){//supplier
                            $debitAcc = $payableAccount;
                            $comment_type_id = 12;
                        }else{
                            $debitAcc = $receivableAccount;
                            $comment_type_id = 11;
                        }
                        self::InsertTransactions(25, $debitBalance, $date, $adjustmentAccount, $debitAcc,0, $partner->id, 1, $description);
                    }
                    if($creditBalance > 0){
                        if($balance['partner_type'] == 1 ){//supplier
                            $creditAcc = $payableAccount;
                            $comment_type_id = 12;
                        }else{
                            $creditAcc = $receivableAccount;
                            $comment_type_id = 11;
                        }
                        self::InsertTransactions(25, $creditBalance, $date, $creditAcc, $adjustmentAccount,0, $partner->id, 0, $description);
                    }
                    $comment = 'Opening Balance added for '.currencyName().$debitBalance.' in debit balance & '.currencyName().$creditBalance.' in credit balance.';
                    GlobalController::InsertNewComment($comment_type_id, $balance['account_id'], 'Opening Balance Adjustment', $comment);

                }
            }
        }
        return redirect()->route('MigrationController.index');
    }
    public static function InsertTransactions( $type_id, $amount, $date, $creditAccount, $debitAccount, $is_account ,$ob_id, $is_debit, $description){
        $request1['transaction_type_id'] = $type_id; //account opening balance
        $request1['description'] = $description;
        $request1['amount'] = $amount;
        $request1['date'] = $date;
        $transaction = GlobalController::InsertNewTransaction($request1);
        $request2['transaction_id'] = $transaction;
        $request2['amount'] = $amount;
        $request2['account_id'] = $creditAccount;
        $request2['is_debit'] = 0;
        GlobalController::InsertNewTransactionDetails($request2);
        $adj['transaction_id'] = $transaction;
        $adj['amount'] = $amount;
        $adj['account_id'] = $debitAccount;
        $adj['is_debit'] = 1;
        GlobalController::InsertNewTransactionDetails($adj);
        TransactionAdjustment::create([
            'transaction_id' => $transaction,
            'is_account' => $is_account,
            'ob_id' => $ob_id,
            'is_debit' => $is_debit
        ]);
    }


    public function update(Request $request,$type, $organization_id)
    {

        $date = $request->date;
        $balances = $request->balances;
        $receivableAccount = GetReceivableAccount();
        $payableAccount = GetPayableAccount();
        $adjustmentAccount = GetAdjustmentAccount();
        $canNotChange = false;
        $msg = 'Can not change opening balance amount of some account , there are transactions allocated to them.';
        foreach ($balances as $balance) {
            $debitBalance = $balance['debit_amount'] ?? 0;
            $creditBalance = $balance['credit_amount'] ?? 0;

            $obId = $balance['ob_id'] ?? 0;
            $previousDebitAmount = $previousCreditAmount = 0;
            if ($type == 'accounts') {
                if($obId > 0){
                    $previousInfo = ObAccounts::find($obId);
                    $previousDebitAmount = $previousInfo->debit_amount;
                    $previousCreditAmount = $previousInfo->credit_amount;
                    if($previousDebitAmount == $debitBalance && $previousCreditAmount == $creditBalance){
                        continue;
                    }
                }
            }else if($type == 'partners'){
                if($obId > 0){
                    $previousInfo = ObPartners::find($obId);
                    $previousDebitAmount = $previousInfo->debit_amount;
                    $previousCreditAmount = $previousInfo->credit_amount;
                    if($previousDebitAmount == $debitBalance && $previousCreditAmount == $creditBalance){
                        continue;
                    }

                    $creditApplied = CreditApplied::where('organization_id', org_id())
                        ->where('is_creditnote', 0)
                        ->where('ob_id', $obId)
                        ->first();
                    $debitAccount = DebitApplied::where('organization_id', org_id())
                        ->where('is_creditnote', 0)
                        ->where('ob_id', $obId)
                        ->first();
                    if($creditApplied || $debitAccount){
                        $canNotChange = true;
                        continue;
                    }
                }
            }

            if ($debitBalance > 0 || $creditBalance > 0) {
                if ($type == 'accounts') {
                     $account = ObAccounts::updateOrCreate(
                        [
                            'organization_id' => $organization_id,
                            'account_id' => $balance['account_id']
                        ],
                        [
                            'debit_amount' => $debitBalance,
                            'credit_amount' => $creditBalance,
                        ]
                    );
                    $obId = $account->id;
                    $description = 'Opening Balance';
                    self::updateOrCreateTransaction($previousDebitAmount,$debitBalance,
                     $obId, $date, $balance['account_id'], $adjustmentAccount, 1, 1, 24, $description);

                    self::updateOrCreateTransaction($previousCreditAmount,$creditBalance,
                     $obId, $date,  $adjustmentAccount, $balance['account_id'], 1, 0, 24, $description);
                }else if($type == 'partners'){
                    $partner = ObPartners::updateOrCreate(
                        [
                            'organization_id' => $organization_id,
                            'partner_id' => $balance['account_id']
                        ],
                        [
                            'debit_amount' => $debitBalance,
                            'credit_amount' => $creditBalance,
                        ]
                    );
                    $partnerName = PartnerInfo($balance['account_id'])['name'];
                    $description = 'Opening Balance - '.$partnerName;
                    $obId = $partner->id;
                    if($balance['partner_type'] == 1 ){//supplier
                        $comment_type_id = 12;
                        $relatedAccount = $payableAccount;
                    }else{
                        $comment_type_id = 11;
                        $relatedAccount = $receivableAccount;
                    }
                    self::updateOrCreateTransaction($previousDebitAmount,$debitBalance,
                        $obId, $date, $relatedAccount, $adjustmentAccount, 0, 1, 25, $description);

                    self::updateOrCreateTransaction($previousCreditAmount,$creditBalance,
                        $obId, $date,  $adjustmentAccount, $relatedAccount, 0, 0, 25, $description);
                    $comment = 'Opening Balance Changed for '.currencyName().$debitBalance.' in debit balance & '.currencyName().$creditBalance.' in credit balance.';
                    GlobalController::InsertNewComment($comment_type_id, $balance['account_id'], 'Opening Balance Adjustment', $comment);
                }
            } else {
//                delete transaction if exist
                if ($type == 'accounts') {
                    // Delete the opening balance if both debit and credit are zero
                    if($obId > 0){
                        $description = 'Opening Balance ';
                        self::updateOrCreateTransaction($previousDebitAmount,$debitBalance,
                            $obId, $date, $balance['account_id'], $adjustmentAccount, 1, 1, 24, $description);

                        self::updateOrCreateTransaction($previousCreditAmount,$creditBalance,
                            $obId, $date,  $adjustmentAccount, $balance['account_id'], 1, 0, 24, $description);
                    }
                    ObAccounts::where('organization_id', $organization_id)
                        ->where('account_id', $balance['account_id'])
                        ->delete();
                }else if($type == 'partners'){
                    if($obId > 0){
                        $partnerName = PartnerInfo($balance['account_id'])['name'];
                        $description = 'Opening Balance - '.$partnerName;
                        self::updateOrCreateTransaction($previousDebitAmount,$debitBalance,
                            $obId, $date, $balance['account_id'], $adjustmentAccount, 0, 1, 25, $description);

                        self::updateOrCreateTransaction($previousCreditAmount,$creditBalance,
                            $obId, $date,  $adjustmentAccount, $balance['account_id'], 0, 0, 25, $description);
                    }
                    ObPartners::where('organization_id', $organization_id)
                        ->where('partner_id', $balance['account_id'])
                        ->delete();
                }

            }
        }

        if($canNotChange){
            return redirect()->route('MigrationController.index')->with('error', $msg);

        }else{
            return redirect()->route('MigrationController.index')->with('success', 'Record has been created successfully.');
        }
    }

    public static function updateOrCreateTransaction($previousAmount,$currentAmount,
                                                     $obId, $date, $debitAccount, $creditAmount, $is_account, $is_debit, $transaction_type_id, $description){
        if($previousAmount > 0 && $currentAmount == 0){
//          delete is debit transaction
            $existingTransaction = Transaction::where('transaction_type_id', $transaction_type_id)
                ->whereHas('transactionAdjustment', function($query) use ($is_account, $obId, $is_debit) {
                    $query->where('is_account', $is_account)
                        ->where('ob_id', $obId)
                        ->where('is_debit', $is_debit);
                })->first();
            if($existingTransaction){
                TransactionDetails::where('transaction_id', $existingTransaction->id)->delete();

                TransactionAdjustment::where('transaction_id', $existingTransaction->id)
                    ->where('ob_id', $obId)
                    ->where('is_account', $is_account)
                    ->where('is_debit', $is_debit)
                    ->delete();
                $existingTransaction->delete();
            }


        }else if($previousAmount == 0 && $currentAmount > 0){
//          create new transaction
            self::InsertTransactions($transaction_type_id, $currentAmount, $date,  $creditAmount,$debitAccount,$is_account, $obId, $is_debit, $description);

        }else if($previousAmount > 0 && $currentAmount > 0){
//                        update existing transaction
            $existingTransaction = Transaction::where('transaction_type_id', $transaction_type_id)
                ->whereHas('transactionAdjustment', function($query) use ( $obId, $is_debit, $is_account) {
                    $query->where('is_account', $is_account)
                        ->where('ob_id', $obId)
                        ->where('is_debit', $is_debit);
                })->first();
            if($existingTransaction){
                TransactionDetails::where('transaction_id', $existingTransaction->id)->delete();

                $existingTransaction->update(['amount' => $currentAmount ]);

                $debitRequest['transaction_id'] = $existingTransaction->id;
                $debitRequest['amount'] = $currentAmount;
                $debitRequest['account_id'] = $debitAccount;
                $debitRequest['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($debitRequest);

                $creditRequest['transaction_id'] = $existingTransaction->id;
                $creditRequest['amount'] = $currentAmount;
                $creditRequest['account_id'] = $creditAmount;
                $creditRequest['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($creditRequest);
            }

        }
    }

//    public function destroy($id)
//    {
//        $account = Budget::find($id);
//        $account->delete();
//        return response()->json(['status' => 'success',
//            'message' => 'Deleted ']);
//
//    }
}
