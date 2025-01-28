<?php

namespace App\Http\Controllers;

use App\AccountType;
use App\AssetType;
use App\ChartOfAccounts;
use App\Asset;
use App\DepreciationRecord;
use App\DepreciationType;
use App\ExpenseDocuments;
use App\Exports\VatTransactionExport;
use App\Partner;
use App\PaymentReceived;
use App\TaxAudit;
use App\TaxReport;
use App\Transaction;
use App\TransactionAsset;
use App\TransactionDetails;
use App\TransactionTax;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use mysql_xdevapi\Collection;

class ReportsController extends Controller
{
    public function createReportAccount(){
        $chart_of_accounts = ChartOfAccounts::with('children', 'accountType')
            ->where('organization_id', org_id())
            ->where('deleted', 0)
            ->whereNull('parent_account_id')
            ->get();
        return view('pages.reports.accounts', compact('chart_of_accounts'));
    }
    public function getReportAccount(Request $request){
        $chart_of_accounts = ChartOfAccounts::with('children', 'accountType')
            ->where('organization_id', org_id())
            ->where('deleted', 0)
            ->whereNull('parent_account_id')
            ->get();
        $accountId = $request->input('account_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $transactions = AccountReport($accountId, $startDate, $endDate);
        return view('pages.reports.getReport', compact('chart_of_accounts', 'transactions'));
    }
    public function createVatReport(){
        $lastReport = TaxReport::where('organization_id', org_id())
            ->orderBy('id', 'desc')
            ->first();
        $start_date = '';
        if($lastReport){
            $start_date = $lastReport->end_date;
            $start_date = Carbon::parse($start_date);
            $start_date = $start_date->addDay();
        }
        $vatReport = TaxReport::where('organization_id', org_id())
            ->orderBy('id', 'desc')
            ->get();
        return view('pages.reports.vat', compact('start_date', 'vatReport'));
    }
    public function createVatAudit(){
        $vatReport = TaxAudit::where('organization_id', org_id())
            ->orderBy('id', 'desc')
            ->get();
        return view('pages.reports.vataudit', compact( 'vatReport'));
    }
    public function storeVatReport(Request $request){
        $startDate = $request->start_date;
        $endDate = $request->end_date;
        $report = TaxReport::create([
           'start_date' => $startDate,
           'end_date' => $endDate,
        ]);
        $id = Crypt::encryptString($report->id);

        return redirect()->route('ReportsController.getVatReport', [$id]);

    }
    public function storeVatAudit(Request $request){
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $currentDate = dateOfToday();
        $org_id = org_id();
        $created_by = currentUser();
        $fileName ='audit_'.rand(1, 100).$created_by.'_'.$org_id.'_'.$currentDate.'.xlsx';
        $filePath = 'exports/'.$fileName;

        Excel::store(new VatTransactionExport($startDate, $endDate), $filePath, 'public');
        $publicPath = public_path('system/exports/' . $fileName);
        // Ensure the directory exists, and create it if it doesn't
        if (!file_exists(public_path('system/exports'))) {
            mkdir(public_path('system/exports'), 0777, true);
        }
        // Move the file from storage/app/public/exports to public/system/exports
        $storageFilePath = storage_path('app/public/' . $filePath);
        if (file_exists($storageFilePath)) {
            rename($storageFilePath, $publicPath);
        } else {
            throw new \Exception('File not found in storage: ' . $storageFilePath);
        }

        // Move the file from storage to public/system/exports
//        Storage::disk('local')->move($filePath, $publicPath);

        $report = TaxAudit::create([
           'file' => $fileName,
           'start_date' => $startDate,
           'end_date' => $endDate,
        ]);
        $id = $report->id;

        return redirect()->route('ReportsController.createVatAudit', '#row-'.$id);
    }


    public function exportAndSave(Request $request)
    {
        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');


        return var_dump('success');
    }


    public function getVatReport($id){
        $id = Crypt::decryptString($id);

        $report = TaxReport::where('organization_id', org_id())
            ->where('id', $id)
            ->first();
        $startDate = $report->start_date;
        $endDate = $report->end_date;
        $purchaseVat = PurchaseVatReport($report->start_date, $report->end_date);
        $salesVat = SaleVatReport($report->start_date, $report->end_date);
        return view('pages.reports.showVat', compact('report','purchaseVat', 'salesVat'));
    }
    public function getVatTransaction($id, $type){
        $report = TaxReport::where('organization_id', org_id())
            ->where('id', $id)
            ->first();
        $startDate = $report->start_date;
        $endDate = $report->end_date;
        if($type == 1){//sales vat
            $salesVat = SaleVatTransaction($report->start_date, $report->end_date);
            $transactions = $salesVat['vatTransactions'];
        }else if( $type == 2){ // sales non vat
            $salesVat = SaleVatTransaction($report->start_date, $report->end_date);
            $transactions = $salesVat['nonVatTransactions'];
        }else if( $type == 3){ //purchase vat
            $purchaseVat = PurchaseVatTransaction($report->start_date, $report->end_date);
            $transactions = $purchaseVat['vatTransactions'];
        }else if( $type == 4){ //purchase non vat
            $purchaseVat = PurchaseVatTransaction($report->start_date, $report->end_date);
            $transactions = $purchaseVat['nonVatTransactions'];
        }
        return view('pages.reports.showVatTransaction', compact('report','transactions', 'type'));
    }
    public function fileReport(Request $request){
        try {
            DB::beginTransaction();

            $currentDate = dateOfToday();
            $id = $request->input('id');
            $date = $request->input('filed_date');
            $total_sales = $request->input('total_sales');
            $total_purchases = $request->input('total_purchases');

            $report = TaxReport::find($id);
            $report->taxable_amount = $total_sales - $total_purchases;
            $report->amount_due = $total_sales - $total_purchases;
            $report->filed_on = $date;
            $report->is_approved = 1;
            $report->save();

            $endDate = Carbon::parse($report->end_date);
            $month = $endDate->format('F');
            $year = $endDate->format('Y');
            $SalesVat = GetOutputVATAccount();
            $PurchaseVat = GetInputVATAccount();
            $PayableVat = GetPayableVATAccount();

//            if($total_sales - $total_purchases < 0){
//                $request1['amount'] = $total_sales + $total_purchases;
//            }else{
//                $request1['amount'] = $total_sales;
//            }
//



            if($total_sales < $total_purchases){
                $request1['amount'] = $total_purchases;
            }else{
                $request1['amount'] = $total_sales - $total_purchases;
            }


            $request1['transaction_type_id'] = 23; //vat filed
            $request1['date'] = $date;
            $request1['reference_number'] = 'VAT Return for '.$month.' - '.$year;
            $request1['description'] = 'VAT Balance Journal for '.$month.' - '.$year;
            $transaction = GlobalController::InsertNewTransaction($request1);

            $salesRequest['transaction_id'] = $transaction;
            $salesRequest['amount'] = $total_sales;
            $salesRequest['account_id'] = $SalesVat;
            $salesRequest['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($salesRequest);

            $purchaseRequest['transaction_id'] = $transaction;
            $purchaseRequest['amount'] = $total_purchases;
            $purchaseRequest['account_id'] = $PurchaseVat;
            $purchaseRequest['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($purchaseRequest);


            $vatRequest['transaction_id'] = $transaction;
            $vatRequest['amount'] = abs($total_sales - $total_purchases);
            $vatRequest['account_id'] = $PayableVat;
            if($total_sales - $total_purchases < 0){
                $vatRequest['is_debit'] = 1;
            }else{
                $vatRequest['is_debit'] = 0;
            }
            GlobalController::InsertNewTransactionDetails($vatRequest);

            TransactionTax::create([
                'transaction_id' => $transaction,
                'vat_report_id' => $id
            ]);


            DB::commit();
            $id = Crypt::encryptString($id);
            return redirect()->route('ReportsController.getVatReport', [$id]);
        } catch (\Exception $e) {
            DB::rollBack();

            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());

            return response()->json(['status' => 'Task failed. Please try again later.'], 500);
        }


    }
    public function delete($id){
        $report = TaxReport::findOrFail($id);
        $report->delete();

        return response()->json(['status' => 'success',
            'message'=>'Report deleted']);
    }
//    show unpaid tax report

    public function getApprovedVatReport(){
        $reports = TaxReport::where('organization_id', org_id())
            ->where('is_approved', 1)
            ->where('amount_due', '>' ,0)
            ->orderBy('is_paid', 'ASC')
            ->get();
        $paymentHistory = Transaction::where('transaction.organization_id', org_id())
            ->where('transaction_type_id', 22)
            ->join('transaction_vat', 'transaction.id', '=', 'transaction_vat.transaction_id')
            ->join('tax_report', 'transaction_vat.vat_report_id', '=', 'tax_report.id')
            ->where('tax_report.organization_id', org_id())
            ->select(
                'tax_report.end_date as end_date',
                'tax_report.id as report_id',
                'transaction.id as transaction_id',
                'tax_report.taxable_amount as taxable_amount',
                'transaction.date as transaction_date',
                'transaction.amount as transaction_amount',
                'tax_report.amount_due as amount_due')
            ->get();
        return view('pages.reports.taxPayment', compact('reports', 'paymentHistory'));
    }

    public function createTaxPayment($id){
        $report = TaxReport::find($id);
        return view('pages.reports.createTaxPayment', compact('report'));
    }
    public function editTaxPayment($id){
        $id = Crypt::decryptString($id);
        $transaction = Transaction::where('transaction.organization_id', org_id())
            ->where('transaction.transaction_type_id', 22)
            ->where('transaction.id', $id)
            ->join('transaction_vat', 'transaction.id', '=', 'transaction_vat.transaction_id')
            ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
            ->join('tax_report', 'transaction_vat.vat_report_id', '=', 'tax_report.id')
            ->where('tax_report.organization_id', org_id())
            ->where('transaction_details.is_debit', 0)
            ->select(
                'transaction.*',
                'transaction_details.account_id as paid_account',
                'tax_report.end_date as end_date',
                'tax_report.id as report_id',
                'tax_report.taxable_amount as taxable_amount',
                'tax_report.amount_due as amount_due')
            ->first();
        return view('pages.reports.editTaxPayment', compact('transaction'));
    }
    public function storeTaxPayment(Request $request){
        try {
            DB::beginTransaction();

            $reportid = $request->input('report_id');
            $report = TaxReport::find($reportid);
            $reportAmountDue = $report->amount_due;

            $amount = $request->input('amount');
            $note = $request->input('note');
            $reference_number = $request->input('reference_number');
            $date = $request->input('date');
            $paid_account = $request->input('account_id');
            $payableVat = GetPayableVATAccount();

            //        INSERT NEW PAYMENT RECEIVED
            $payment['type_id'] = 22; // tax payment
            $payment['amount'] =  $amount;
            $payment['unused_amount'] =  0;
            $payment['internal_note'] = $note;
            $payment['reference_number'] = $reference_number;
            $payment['date'] = $date;
            $payment_id = GlobalController::InsertNewPaymentReceived($payment);

            $request1['transaction_type_id'] = 22;
            $request1['amount'] =  $amount;
            $request1['description'] = $note;
            $request1['internal_note'] = $note;
            $request1['payment_id'] = $payment_id;
            $request1['reference_number'] = $reference_number;
            $request1['date'] = $date;
            $transaction = GlobalController::InsertNewTransaction($request1);

            $request2['transaction_id'] = $transaction;
            $request2['amount'] = $amount;
            $request2['account_id'] = $paid_account;
            $request2['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($request2);

            $request3['transaction_id'] = $transaction;
            $request3['amount'] = $amount;
            $request3['account_id'] = $payableVat;
            $request3['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($request3);

            TransactionTax::create([
                'transaction_id' => $transaction,
                'vat_report_id' => $reportid
            ]);
            $report->amount_due -= $amount;
            if($report->amount_due == 0){
                $report->is_paid = 1;
                $report->save();
            }


            DB::commit();
//        redirect to journal
            return redirect()->route('TransactionController.showJournal', ['#row-'.$transaction]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());

//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'], 500);
        }

    }
    public function updateTaxPayment($id, Request $request){
        try {
            DB::beginTransaction();
            $transaction = Transaction::find($id);
            $reportid = $request->input('report_id');
            $report = TaxReport::find($reportid);
            $payment_id = $transaction->payment_id;
            $payment = PaymentReceived::find($payment_id);

            $previousAmount = $transaction->amount;
            $reportAmountDue = $report->amount_due;
            $amount = $request->input('amount');
            $note = $request->input('note');
            $reference_number = $request->input('reference_number');
            $date = $request->input('date');
            $paid_account = $request->input('account_id');
            $payableVat = GetPayableVATAccount();


            $payment->reference_number = $reference_number;
            $payment->amount = $amount;
            $payment->note = $note;

            $transaction->reference_number = $reference_number;
            $transaction->amount = $amount;
            $transaction->description = $note;
            $transaction->internal_note = $note;

            $DebitAccount = TransactionDetails::where('transaction_id', $id)
                ->where('is_debit', 1)
                ->first();

            $creditAccount = TransactionDetails::where('transaction_id', $id)
                ->where('is_debit', 0)
                ->first();
            $DebitAccount->amount = $amount;
            $creditAccount->amount = $amount;
            $creditAccount->account_id = $paid_account;

            $report->amount_due += $previousAmount;
            $report->amount_due -= $amount;
            if($report->amount_due == 0){
                $report->is_paid = 1;
            }else{
                $report->is_paid = 0;
            }
            $payment->save();
            $transaction->save();
            $DebitAccount->save();
            $creditAccount->save();
            $report->save();



            DB::commit();
//        redirect to journal
            return redirect()->route('TransactionController.showJournal', ['#row-'.$id]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());

//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }



//    GENERAL LEDGER

    public function generalLedger(Request $request){
        try {
            DB::beginTransaction();

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $reports = [];
            $accountIds = ChartOfAccounts::where('parent_account_id', NULL)
                            ->where('organization_id', org_id())
                            ->where('deleted', 0)
                            ->pluck('id');

            foreach ($accountIds as $accountId) {
                $result = $this->calculateAccountBalance($accountId, $startDate, $endDate);
                $hasNoBalances =
                    $result['debitTotal'] == 0 &&
                    $result['creditTotal'] == 0;

                $hasNoChildren = empty($result['children']);

                if ($hasNoBalances && $hasNoChildren) {
                } else {
                    $reports[] = $result;
                }
            }

            DB::commit();
            return view('pages.reports.generalLedger', compact('reports', 'startDate', 'endDate'));

        } catch (\Exception $e) {
            DB::rollBack();

            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());

//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
//    TRIAL BALANCE
    public function trialBalance(Request $request){
        try {
            DB::beginTransaction();

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $startDate1 = Carbon::parse($startDate);
            $startOfYear = $startDate1->startOfYear();

            $accountTypes = AccountType::where('parent_id', NULL)->get();

//            $accountIds = ChartOfAccounts::where('parent_account_id', NULL)
//                            ->where('organization_id', org_id())
//                            ->where('deleted', 0)
//                            ->pluck('id');
//
//            foreach ($accountIds as $accountId) {
//                $result = $this->calculateAccountTrialBalance($accountId, $startDate, $endDate);
//                $reports[] = $result;
//            }

            $reports = [];
            $salesOpeningBalance = $expenseOpeningBalance = 0;
            foreach ($accountTypes as $accountType) {
                $typeIds = AccountType::where('parent_id', $accountType->id)->pluck('id');
                $accountIds = ChartOfAccounts::where('parent_account_id', NULL)
                    ->whereIn('type_id', $typeIds)
                    ->where('organization_id', org_id())
                    ->where('deleted', 0)
                    ->pluck('id');

                $accountReports = [];

                foreach ($accountIds as $accountId) {

                    if($accountType->id == 4 || $accountType->id == 5){ //income or expense
                        $result = $this->calculateAccountTrialBalanceIncomeNExpense($accountId, $startDate, $endDate);
                    }else{
                        $result = $this->calculateAccountTrialBalance($accountId, $startDate, $endDate);
                    }

                    $hasNoBalances =
                        $result['openingDebitTotal'] == 0 &&
                        $result['openingCreditTotal'] == 0 &&
                        $result['debitTotal'] == 0 &&
                        $result['creditTotal'] == 0;

                    $hasNoChildren = empty($result['children']);

//                    if is financial year, no opening balance or current year
//                    if start of the year, get retained from the previous year, and the opening for sales and expense is 0
//                    if not start of the year, get retained from previous year, and the sales and expense opening is start year till the selected date


                    if ($hasNoBalances && $hasNoChildren) {
                    } else {
                        $accountReports[] = $result;
                    }



                }

                $reports[] = [
                    'type_id' => $accountType->id,
                    'type' => $accountType->name,
                    'accounts' => $accountReports
                ];
            }
//            get RetainedEarning of last year
            $previousDate = Carbon::parse($startDate);
            $previousDate = $previousDate->startOfYear()->subDay();
            $RetainedEarning = self::RetainedEarning($previousDate);

            DB::commit();
            return view('pages.reports.trialBalance', compact('reports', 'startDate', 'endDate', 'RetainedEarning', 'salesOpeningBalance', 'expenseOpeningBalance'));

        } catch (\Exception $e) {
            DB::rollBack();

            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());

//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
//    balance sheet
    public function BalanceSheet(Request $request){
        try {
            DB::beginTransaction();

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

//            assets and liabilities and equity
            $accountTypes = AccountType::where('parent_id', NULL)
                ->whereIn('id', [1, 2, 3])
                ->get();

            $reports = [];

            foreach ($accountTypes as $accountType) {
//                get first level category
                $typeIds = AccountType::where('is_subtype', 1)
                    ->where('parent_id', $accountType->id)->orderby('name')->get();
//                get first level accounts
                $typeSingleIds = AccountType::where('is_subtype', 0)
                    ->where('cat_id', $accountType->id)->orderby('name')->pluck('id')->toArray();
                $subType = [];
                foreach ($typeIds as $typeId) {
                    $id = $typeId->id;

                    $subTypeIds = AccountType::where('is_subtype', 0)
                        ->where('cat_id', $id)->pluck('id')->toArray();

                    $accountIds = ChartOfAccounts::where('parent_account_id', NULL)
                        ->where(function ($query) use ($id, $subTypeIds) {
                            $query->where('type_id', $id)
                                ->orWhereIn('type_id', $subTypeIds);
                        })
                        ->where('organization_id', org_id())
                        ->where('deleted', 0)
                        ->pluck('id');

                    $accountReports = [];

                    foreach ($accountIds as $accountId) {
                        $result = $this->calculateAccountTrialBalance($accountId, $startDate, $endDate);
                        $hasNoBalances =
                            $result['openingDebitTotal'] == 0 &&
                            $result['openingCreditTotal'] == 0 &&
                            $result['debitTotal'] == 0 &&
                            $result['creditTotal'] == 0;

                        $hasNoChildren = empty($result['children']);

                        if ($hasNoBalances && $hasNoChildren) {

                        }else{
                            $accountReports[] = $result;

                        }
                    }
                    $subType[] = [
                        'subType' => $typeId->name,
                        'accounts' => $accountReports
                    ];

                }
                $fLAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                    ->whereIn('type_id', $typeSingleIds)
                    ->where('organization_id', org_id())
                    ->where('deleted', 0)
                    ->pluck('id');

                $fLAccountReports = [];

                foreach ($fLAccountIds as $accountId) {
                    $result = $this->calculateAccountTrialBalance($accountId, $startDate, $endDate);
                    $hasNoBalances =
                        $result['openingDebitTotal'] == 0 &&
                        $result['openingCreditTotal'] == 0 &&
                        $result['debitTotal'] == 0 &&
                        $result['creditTotal'] == 0;

                    $hasNoChildren = empty($result['children']);

                    if ($hasNoBalances && $hasNoChildren) {

                    }else{
                        $fLAccountReports[] = $result;

                    }
                }
                $reports[] = [
                    'id' => $accountType->id,
                    'type' => $accountType->name,
                    'sub-accounts' => $subType,
                    'accounts' => $fLAccountReports
                ];
            }
            $profitNLoss = self::ProfitLossResult($startDate, $endDate);

            DB::commit();
            return view('pages.reports.balanceSheet', compact('reports','profitNLoss', 'startDate', 'endDate'));
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());
//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }
    }
//    Profit & Loss
    public function ProfitLoss(Request $request){
        try {
            DB::beginTransaction();

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

//            income
            $accountTypes = AccountType::where('parent_id', NULL)
                ->whereIn('id', [4])
                ->get();

            $income = [];

            foreach ($accountTypes as $accountType) {
//                get first level category
                $typeIds = AccountType::where('is_subtype', 1)
                    ->where('parent_id', $accountType->id)->get();
//                get first level accounts
                $typeSingleIds = AccountType::where('is_subtype', 0)
                    ->where('cat_id', $accountType->id)->pluck('id')->toArray();
                $subType = [];

                foreach ($typeIds as $typeId) {
                    $id = $typeId->id;
                    $subTypeIds = AccountType::where('is_subtype', 0)
                        ->where('cat_id', $id)->pluck('id')->toArray();
                    $accountIds = ChartOfAccounts::where('parent_account_id', NULL)
                        ->where(function ($query) use ($id, $subTypeIds) {
                            $query->where('type_id', $id)
                                ->orWhereIn('type_id', $subTypeIds);
                        })
                        ->where('organization_id', org_id())
                        ->where('deleted', 0)
                        ->orderby('type_id')
                        ->pluck('id');

                    $accountReports = [];

                    foreach ($accountIds as $accountId) {
                        $result = $this->calculateAccountFromTo($accountId, $startDate, $endDate);
                        $accountReports[] = $result;
                    }
                    $subType[] = [
                        'subType' => $typeId->report_name ?? $typeId->name,
                        'accounts' => $accountReports
                    ];
                }

                $fLAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                    ->whereIn('type_id', $typeSingleIds)
                    ->where('organization_id', org_id())
                    ->where('deleted', 0)
                    ->orderby('type_id')
                    ->pluck('id');

                $fLAccountReports = [];

                foreach ($fLAccountIds as $accountId) {
                    $result = $this->calculateAccountFromTo($accountId, $startDate, $endDate);
                    $fLAccountReports[] = $result;
                }
                $income[] = [
                    'type' => $accountType->report_name ?? $accountType->name,
                    'sub-accounts' => $subType,
                    'accounts' => $fLAccountReports
                ];
            }
//            income
//            cost of revenue
            $costOfRevenue = [];
//                get first level type id: cost of goods and discount
            $COGSSingleID = AccountType::where('is_subtype', 0)
                ->where('cat_id', 7)->pluck('id')->toArray();
            $COGSID = array_merge([7], $COGSSingleID);
            $COGSAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->whereIn('type_id', $COGSID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $COGSAccountReports = [];
            foreach ($COGSAccountIds as $gAccountId) {
                $result = $this->calculateAccountFromTo($gAccountId, $startDate, $endDate);
                $COGSAccountReports[] = $result;
            }
            $costOfRevenue = [
                'type' => 'Cost of revenue',
                'accounts' => $COGSAccountReports
            ];
//            cost of revenue
//            expense
            $costOfExpense = [];
//                get first level type id: cost of goods and discount
            $ExpSingleID = AccountType::where('is_subtype', 0)
                ->where('cat_id', 9)->pluck('id')->toArray();
            $ExpID = array_merge([9], $ExpSingleID);
            $ExpAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->whereIn('type_id', $ExpID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $ExpAccountReports = [];
            foreach ($ExpAccountIds as $eAccountId) {
                $result = $this->calculateAccountFromTo($eAccountId, $startDate, $endDate);
                $ExpAccountReports[] = $result;
            }
            $costOfExpense = [
                'type' => 'Expense',
                'accounts' => $ExpAccountReports
            ];
//            expense
//            depreciation
            $costOfDep = [];
//                get first level type id: cost of goods and discount
            $DepSingleID = AccountType::where('is_subtype', 0)
                ->where('cat_id', 38)->pluck('id')->toArray();
            $DepID = array_merge([38], $DepSingleID);
            $DepAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->whereIn('type_id', $DepID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $DepAccountReports = [];
            foreach ($DepAccountIds as $dAccountId) {
                $result = $this->calculateAccountFromTo($dAccountId, $startDate, $endDate);
                $DepAccountReports[] = $result;
            }
            $costOfDep = [
                'type' => 'Depreciation',
                'accounts' => $DepAccountReports
            ];
//            depreciation
//            TAX
            $costOfTax = [];
            $TaxAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->where('type_id', 40)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $TaxAccountReports = [];
            foreach ($TaxAccountIds as $tAccountId) {
                $result = $this->calculateAccountFromTo($tAccountId, $startDate, $endDate);
                $TaxAccountReports[] = $result;
            }
            $costOfTax = [
                'type' => 'TAX',
                'accounts' => $TaxAccountReports
            ];
//            TAX
            DB::commit();
            return view('pages.reports.profitAndLoss', compact('income','costOfRevenue', 'costOfExpense','costOfDep', 'costOfTax', 'startDate', 'endDate'));
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());

//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }
    }
//    Cah Flow
    public function CashFlow(Request $request){
        try {
            DB::beginTransaction();

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
//            OPERATING
            $profitNLoss = self::ProfitLossResult($startDate, $endDate);
//            depreciation and tax
            $costOfDep = [];
            $DepSingleID = AccountType::where('is_subtype', 0)
                ->where('cat_id', 38)->pluck('id')->toArray();
            $DepID = array_merge([38,40], $DepSingleID);
            $DepAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->whereIn('type_id', $DepID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $DepAccountReports = [];
            foreach ($DepAccountIds as $dAccountId) {
                $result = $this->calculateAccountFromTo($dAccountId, $startDate, $endDate);
                $DepAccountReports[] = $result;
            }
            $costOfDep = [
                'type' => 'Depreciation',
                'accounts' => $DepAccountReports
            ];
//            depreciation and tax

//            Changes in Working
            $costOfRec = [];
            $RecAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->whereIn('type_id', [19, 12, 18])//receivable, payable, inventory
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $RecAccountReports = [];
            foreach ($RecAccountIds as $rAccountId) {
                $result = $this->calculateAccountFromTo($rAccountId, $startDate, $endDate);
                $hasNoBalances =
                    $result['debitTotal'] == 0 &&
                    $result['creditTotal'] == 0;

                $hasNoChildren = empty($result['children']);

                if ($hasNoBalances && $hasNoChildren) {

                }else{
                    $RecAccountReports[] = $result;
                }
            }
            $costOfRec = [
                'type' => 'Receivable',
                'accounts' => $RecAccountReports
            ];
//            Changes in Working


            //            OPERATING

//            INVESTING
//            fixed assets
            $costOfAsset = [];
            $AssetAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->where('type_id', 6)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $AssetAccountReports = [];
            foreach ($AssetAccountIds as $aAccountId) {
                $result = $this->calculateAccountFromTo($aAccountId, $startDate, $endDate);
                $hasNoBalances =
                    $result['debitTotal'] == 0 &&
                    $result['creditTotal'] == 0;

                $hasNoChildren = empty($result['children']);

                if ($hasNoBalances && $hasNoChildren) {

                }else{
                    $AssetAccountReports[] = $result;
                }
            }
            $costOfAsset = [
                'type' => 'Fixed Assets',
                'accounts' => $AssetAccountReports
            ];
//            fixed assets
//            INVESTING

//            FINANCING
//            Equity
//            $costOfEquity = [];
//            $EquityAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
//                ->where('type_id', 10)
//                ->where('organization_id', org_id())
//                ->where('deleted', 0)
//                ->pluck('id');
//            $EquityAccountReports = [];
//            foreach ($EquityAccountIds as $eAccountId) {
//                $result = $this->calculateAccountFromTo($eAccountId, $startDate, $endDate);
//                $EquityAccountReports[] = $result;
//            }
//            $costOfEquity = [
//                'type' => 'Equity',
//                'accounts' => $EquityAccountReports
//            ];
//            Equity
//            LOAN and loan
            $costOfLoan = [];
            $LoanAccountIds = ChartOfAccounts::where('parent_account_id', NULL)
                ->whereIn('type_id', [10,41])
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id');
            $LoanAccountReports = [];
            foreach ($LoanAccountIds as $lAccountId) {
                $result = $this->calculateAccountFromTo($lAccountId, $startDate, $endDate);
                $hasNoBalances =
                    $result['debitTotal'] == 0 &&
                    $result['creditTotal'] == 0;

                $hasNoChildren = empty($result['children']);

                if ($hasNoBalances && $hasNoChildren) {

                }else{
                    $LoanAccountReports[] = $result;
                }
            }
            $costOfLoan = [
                'type' => 'Loan',
                'accounts' => $LoanAccountReports
            ];
//            LOAN

//            FINANCING
            $previousCashFlow = self::CashFlowResult($startDate, $endDate);
            $previousCashFlow = $previousCashFlow['cashFlow'];
            DB::commit();
            return view('pages.reports.cashFlow', compact('profitNLoss', 'costOfAsset','costOfDep', 'costOfRec', 'costOfLoan', 'previousCashFlow', 'startDate', 'endDate'));
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());

//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }
    }
    public static function ProfitLossResult($startDate, $endDate){
        try {
            DB::beginTransaction();


            $previousDate = Carbon::parse($startDate);
            $startOfTheYear = Carbon::parse($startDate)->startOfYear();
            $previousDate = $previousDate->startOfYear()->subDay();

//            income
            $incomeType = AccountType::where('id', 4)
                ->orwhere('parent_id', 4)
                ->pluck('id')
                ->toArray();
            $incomeAccountIds = ChartOfAccounts::WhereIn('type_id', $incomeType)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();

            $incomeResult = self::calculateSumTransaction($incomeAccountIds, $startOfTheYear, $endDate);
            $incomeResult = $incomeResult < 0 ? abs($incomeResult) : -$incomeResult;

            $previousIncomeResult = self::calculatePreviousSumTransaction($incomeAccountIds, $previousDate);
            $previousIncomeResult = $previousIncomeResult < 0 ? abs($previousIncomeResult) : -$previousIncomeResult;
//            income

//            cost of revenue
            $COGSTypeID = AccountType::where('id', 7)
                ->orwhere('cat_id', 7)
                ->pluck('id')
                ->toArray();

            $COGSAccountIds = ChartOfAccounts::WhereIn('type_id', $COGSTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();

            $COGSResult = self::calculateSumTransaction($COGSAccountIds, $startOfTheYear, $endDate);
            $previousCOGSResult = self::calculatePreviousSumTransaction($COGSAccountIds, $previousDate);

            //            cost of revenue

                $grossProfit = $incomeResult - $COGSResult;

                $previousGrossProfit = $previousIncomeResult - $previousCOGSResult;



//            expense
            $ExpTypeID = AccountType::where('id', 9)
                ->orwhere('cat_id', 9)
                ->pluck('id')
                ->toArray();

            $ExpAccountIds = ChartOfAccounts::WhereIn('type_id', $ExpTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();

            $ExpResult = self::calculateSumTransaction($ExpAccountIds, $startOfTheYear, $endDate);
            $previousExpResult = self::calculatePreviousSumTransaction($ExpAccountIds, $previousDate);

            //            expense
//            Dep
            $DepTypeID = AccountType::where('id', 38)
                ->orwhere('cat_id', 38)
                ->pluck('id')
                ->toArray();

            $DepAccountIds = ChartOfAccounts::WhereIn('type_id', $DepTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();

            $DepResult = self::calculateSumTransaction($DepAccountIds, $startOfTheYear, $endDate);
            $previousDepResult = self::calculatePreviousSumTransaction($DepAccountIds, $previousDate);
            //            Dep
//            TAX
            $TaxTypeID = AccountType::where('id', 40)
                ->orwhere('cat_id', 40)
                ->pluck('id')
                ->toArray();

            $TaxAccountIds = ChartOfAccounts::WhereIn('type_id', $TaxTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();

            $TaxResult = self::calculateSumTransaction($TaxAccountIds, $startOfTheYear, $endDate);
            $previousTaxResult = self::calculatePreviousSumTransaction($TaxAccountIds, $previousDate);

//            TAX
            $netProfit = $grossProfit - $ExpResult - $DepResult - $TaxResult;
            $previousNetProfit = $previousGrossProfit - $previousExpResult - $previousDepResult - $previousTaxResult;

            return [
                'netProfit' => $netProfit,
                'previousNetProfit' => $previousNetProfit,
            ];
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());
//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }
    }
    public static function RetainedEarning($startDate){
        try {
            DB::beginTransaction();


            $previousDate = Carbon::parse($startDate);

//            income
            $incomeType = AccountType::where('id', 4)
                ->orwhere('parent_id', 4)
                ->pluck('id')
                ->toArray();
            $incomeAccountIds = ChartOfAccounts::WhereIn('type_id', $incomeType)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();

            $previousIncomeResult = self::calculatePreviousSumTransaction($incomeAccountIds, $previousDate);
            $previousIncomeResult = $previousIncomeResult < 0 ? abs($previousIncomeResult) : -$previousIncomeResult;
//            income

//            cost of revenue
            $COGSTypeID = AccountType::where('id', 7)
                ->orwhere('cat_id', 7)
                ->pluck('id')
                ->toArray();

            $COGSAccountIds = ChartOfAccounts::WhereIn('type_id', $COGSTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();

            $previousCOGSResult = self::calculatePreviousSumTransaction($COGSAccountIds, $previousDate);
            //            cost of revenue


            $previousGrossProfit = $previousIncomeResult - $previousCOGSResult;

//            expense
            $ExpTypeID = AccountType::where('id', 9)
                ->orwhere('cat_id', 9)
                ->pluck('id')
                ->toArray();

            $ExpAccountIds = ChartOfAccounts::WhereIn('type_id', $ExpTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();
            $previousExpResult = self::calculatePreviousSumTransaction($ExpAccountIds, $previousDate);

            //            expense
//            Dep
            $DepTypeID = AccountType::where('id', 38)
                ->orwhere('cat_id', 38)
                ->pluck('id')
                ->toArray();

            $DepAccountIds = ChartOfAccounts::WhereIn('type_id', $DepTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();
            $previousDepResult = self::calculatePreviousSumTransaction($DepAccountIds, $previousDate);
            //            Dep
//            TAX
            $TaxTypeID = AccountType::where('id', 40)
                ->orwhere('cat_id', 40)
                ->pluck('id')
                ->toArray();

            $TaxAccountIds = ChartOfAccounts::WhereIn('type_id', $TaxTypeID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->orderby('type_id')
                ->pluck('id')
                ->toArray();
            $previousTaxResult = self::calculatePreviousSumTransaction($TaxAccountIds, $previousDate);
//            TAX
            $previousNetProfit = $previousGrossProfit - $previousExpResult - $previousDepResult - $previousTaxResult;

            return [
                'amount' => $previousNetProfit,
            ];
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());
//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }
    }
    public static function CashFlowResult($startDate, $endDate){
        try {
            DB::beginTransaction();


            $previousDate = Carbon::parse($startDate);
            $previousDate = $previousDate->subDay();

            $totalCashFlow = 0;
            $profitNLoss = self::ProfitLossResult($startDate, $endDate);
            $previousNetProfit = $profitNLoss['previousNetProfit'] ;
            $totalCashFlow = $previousNetProfit;


//            depreciation and tax
            $DepSingleID = AccountType::where('is_subtype', 0)
                ->where('cat_id', 38)->pluck('id')->toArray();

            $DepID = array_merge([38,40], $DepSingleID);

            $DepAccountIds = ChartOfAccounts::whereIn('type_id', $DepID)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id')
                ->toArray();

            $previousDepResult = self::calculatePreviousSumTransaction($DepAccountIds, $previousDate);
            $totalCashFlow += $previousDepResult;

            $RecAccountIds = ChartOfAccounts::whereIn('type_id', [19, 12, 18])//receivable, payable, inventory
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id')
                ->toArray();
            $previousRecResult = self::calculatePreviousSumTransaction($RecAccountIds, $previousDate);
            $previousRecResult = $previousRecResult < 0 ? abs($previousRecResult) : -$previousRecResult;
            $totalCashFlow += $previousRecResult;

            $AssetAccountIds = ChartOfAccounts::where('type_id', 6)
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id')
                ->toArray();
            $previousAssetResult = self::calculatePreviousSumTransaction($AssetAccountIds, $previousDate);
            $previousAssetResult = $previousAssetResult < 0 ? abs($previousAssetResult) : -$previousAssetResult;
            $totalCashFlow += $previousAssetResult;

            $LoanAccountIds = ChartOfAccounts::whereIn('type_id', [10,41])
                ->where('organization_id', org_id())
                ->where('deleted', 0)
                ->pluck('id')
                ->toArray();
            $previousLoanResult = self::calculatePreviousSumTransaction($LoanAccountIds, $previousDate);
            $previousLoanResult = $previousLoanResult < 0 ? abs($previousLoanResult) : -$previousLoanResult;
            $totalCashFlow += $previousLoanResult;

            return [
                'cashFlow' => $totalCashFlow
            ];
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the exception
            Log::error('Error in submitTask: ' . $e->getMessage());
//            redirect to error page
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }
    }
    public  function calculateAccountBalance($accountId, $startDate, $endDate) {
        try {
            DB::beginTransaction();
            $account = ChartOfAccounts::findOrFail($accountId);

            $OpeningDebitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->where('transaction.date', '<', $startDate)
                ->sum('transaction_details.amount');

            $debitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $OpeningCreditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->where('transaction.date', '<', $startDate)
                ->sum('transaction_details.amount');

            $creditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $children = [];

            foreach ($account->children as $subAccount) {
                $subBalances = $this->calculateAccountBalance($subAccount->id, $startDate, $endDate);
                $hasNoBalances =
                    $subBalances['debitTotal'] == 0 &&
                    $subBalances['creditTotal'] == 0;


                if ($hasNoBalances) {
                } else {
                    $children [] = [
                        'accountId' => $subAccount->id,
                        'accountName' => $subAccount->name,
                        'accountCode' => $subAccount->code,
                        'debitTotal' => $subBalances['debitTotal'],
                        'creditTotal' => $subBalances['creditTotal'],
                    ];
                }

            }
            DB::commit();
            return [
                'accountId' => $accountId,
                'accountName' => $account->name,
                'accountCode' => $account->code,
                'debitTotal' => $OpeningDebitTotal + $debitTotal,
                'creditTotal' => $OpeningCreditTotal + $creditTotal,
                'children' => $children,
            ];


        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
    public  function calculateAccountTrialBalance($accountId, $startDate, $endDate) {
        try {
            DB::beginTransaction();
            $account = ChartOfAccounts::findOrFail($accountId);

            $OpeningDebitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->where('transaction.date', '<', $startDate)
                ->sum('transaction_details.amount');

            $debitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $OpeningCreditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->where('transaction.date', '<', $startDate)
                ->sum('transaction_details.amount');

            $creditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $children = [];

            foreach ($account->children as $subAccount) {
                $subBalances = $this->calculateAccountTrialBalance($subAccount->id, $startDate, $endDate);
                $hasNoBalances =
                    $subBalances['openingDebitTotal'] == 0 &&
                    $subBalances['openingCreditTotal'] == 0 &&
                    $subBalances['debitTotal'] == 0 &&
                    $subBalances['creditTotal'] == 0;


                if ($hasNoBalances) {
                } else {
                    $children [] = [
                        'accountId' => $subAccount->id,
                        'accountName' => $subAccount->name,
                        'accountCode' => $subAccount->code,
                        'openingDebitTotal' => $subBalances['openingDebitTotal'],
                        'debitTotal' => $subBalances['debitTotal'],
                        'creditTotal' => $subBalances['creditTotal'],
                        'openingCreditTotal' => $subBalances['openingCreditTotal'],
                    ];                }

            }
            DB::commit();
            return [
                'accountId' => $accountId,
                'accountName' => $account->name,
                'accountCode' => $account->code,
                'openingDebitTotal' => $OpeningDebitTotal ,
                'debitTotal' =>  $debitTotal,
                'openingCreditTotal' => $OpeningCreditTotal ,
                'creditTotal' =>  $creditTotal,
                'children' => $children,
            ];


        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
    public  function calculateAccountTrialBalanceIncomeNExpense($accountId, $startDate, $endDate) {
        try {
            DB::beginTransaction();
            $account = ChartOfAccounts::findOrFail($accountId);

            $startOfYear = Carbon::parse($startDate)->startOfYear();
            $openingEndDate = Carbon::parse($startDate)->subDay();
            $isStartOfYear = Carbon::parse($startDate)->equalTo($startOfYear);


            if($isStartOfYear){
                $OpeningDebitTotal = $OpeningCreditTotal = 0;
            }else{
                $OpeningDebitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                    ->where('transaction_details.account_id', $accountId)
                    ->where('transaction_details.is_debit', 1)
                    ->where('transaction.organization_id', org_id())
                    ->whereBetween('transaction.date', [$startOfYear, $openingEndDate])
                    ->sum('transaction_details.amount');

                $OpeningCreditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                    ->where('transaction_details.account_id', $accountId)
                    ->where('transaction_details.is_debit', 0)
                    ->where('transaction.organization_id', org_id())
                    ->whereBetween('transaction.date', [$startOfYear, $openingEndDate])
                    ->sum('transaction_details.amount');

            }

            $debitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');


            $creditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $children = [];

            foreach ($account->children as $subAccount) {
                $subBalances = $this->calculateAccountTrialBalanceIncomeNExpense($subAccount->id, $startDate, $endDate);
                $hasNoBalances =
                    $subBalances['openingDebitTotal'] == 0 &&
                    $subBalances['openingCreditTotal'] == 0 &&
                    $subBalances['debitTotal'] == 0 &&
                    $subBalances['creditTotal'] == 0;


                if (!$hasNoBalances) {
                    $children [] = [
                        'accountId' => $subAccount->id,
                        'accountName' => $subAccount->name,
                        'accountCode' => $subAccount->code,
                        'openingDebitTotal' => $subBalances['openingDebitTotal'],
                        'debitTotal' => $subBalances['debitTotal'],
                        'creditTotal' => $subBalances['creditTotal'],
                        'openingCreditTotal' => $subBalances['openingCreditTotal'],
                    ];
                }

            }

            DB::commit();
            return [
                'accountId' => $accountId,
                'accountName' => $account->name,
                'accountCode' => $account->code,
                'openingDebitTotal' => $OpeningDebitTotal ,
                'debitTotal' =>  $debitTotal,
                'openingCreditTotal' => $OpeningCreditTotal ,
                'creditTotal' =>  $creditTotal,
                'children' => $children,
            ];


        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
    public  function calculateAccountFromTo($accountId, $startDate, $endDate) {
        try {
            DB::beginTransaction();
            $account = ChartOfAccounts::findOrFail($accountId);


            $debitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $creditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $children = [];

            foreach ($account->children as $subAccount) {
                $subBalances = $this->calculateAccountFromTo($subAccount->id, $startDate, $endDate);
                if($subBalances['debitTotal'] == 0 && $subBalances['creditTotal'] == 0){

                }else{
                    $children [] = [
                        'accountId' => $subAccount->id,
                        'accountName' => $subAccount->name,
                        'accountCode' => $subAccount->code,
                        'debitTotal' => $subBalances['debitTotal'],
                        'creditTotal' => $subBalances['creditTotal'],
                    ];
                }

            }
            DB::commit();
            return [
                'accountId' => $accountId,
                'accountName' => $account->name,
                'accountCode' => $account->code,
                'debitTotal' =>  $debitTotal,
                'creditTotal' =>  $creditTotal,
                'children' => $children,
            ];


        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
    public  function calculateAccountDebit($accountId, $startDate, $endDate) {
        try {
            DB::beginTransaction();
            $account = ChartOfAccounts::findOrFail($accountId);
            $debitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');
            $children = [];
            foreach ($account->children as $subAccount) {
                $subBalances = $this->calculateAccountDebit($subAccount->id, $startDate, $endDate);

                $children [] = [
                    'accountId' => $subAccount->id,
                    'accountName' => $subAccount->name,
                    'accountCode' => $subAccount->code,
                    'debitTotal' => $subBalances['debitTotal'],
                ];
            }
            DB::commit();
            return [
                'accountId' => $accountId,
                'accountName' => $account->name,
                'accountCode' => $account->code,
                'debitTotal' =>  $debitTotal,
                'children' => $children,
            ];


        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
    public  function calculateAccountCredit($accountId, $startDate, $endDate) {
        try {
            DB::beginTransaction();
            $account = ChartOfAccounts::findOrFail($accountId);
            $creditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->where('transaction_details.account_id', $accountId)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');
            $children = [];
            foreach ($account->children as $subAccount) {
                $subBalances = $this->calculateAccountCredit($subAccount->id, $startDate, $endDate);
                $children [] = [
                    'accountId' => $subAccount->id,
                    'accountName' => $subAccount->name,
                    'accountCode' => $subAccount->code,
                    'creditTotal' => $subBalances['creditTotal'],
                ];
            }
            DB::commit();
            return [
                'accountId' => $accountId,
                'accountName' => $account->name,
                'accountCode' => $account->code,
                'creditTotal' =>  $creditTotal,
                'children' => $children,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }
    }
    public static  function calculateSumTransaction($accountIds, $startDate, $endDate) {
        try {
            DB::beginTransaction();

            $debitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->whereIn('transaction_details.account_id', $accountIds)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $creditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->whereIn('transaction_details.account_id', $accountIds)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->whereBetween('transaction.date', [$startDate, $endDate])
                ->sum('transaction_details.amount');

            $closingBalance = $debitTotal - $creditTotal;


            DB::commit();
            return $closingBalance ?? 0;


        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }
    public static  function calculatePreviousSumTransaction($accountIds, $date) {
        try {
            DB::beginTransaction();

            $debitTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->whereIn('transaction_details.account_id', $accountIds)
                ->where('transaction_details.is_debit', 1)
                ->where('transaction.organization_id', org_id())
                ->where('transaction.date', '<=', $date)
                ->sum('transaction_details.amount');

            $creditTotal = TransactionDetails::join('transaction', 'transaction_details.transaction_id', '=', 'transaction.id')
                ->whereIn('transaction_details.account_id', $accountIds)
                ->where('transaction_details.is_debit', 0)
                ->where('transaction.organization_id', org_id())
                ->where('transaction.date',  '<=', $date)
                ->sum('transaction_details.amount');

            $closingBalance = $debitTotal - $creditTotal;


            DB::commit();
            return $closingBalance ?? 0;


        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in submitTask: ' . $e->getMessage());
            return response()->json(['status' => 'Task failed. Please try again later.'.$e->getMessage()], 500);
        }

    }




}
