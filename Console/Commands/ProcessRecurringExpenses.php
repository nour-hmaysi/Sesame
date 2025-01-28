<?php

namespace App\Console\Commands;

use App\Expense;
use App\Http\Controllers\GlobalController;
use App\Transaction;
use App\TransactionExpense;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessRecurringExpenses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expenses:process-recurring';
    protected $description = 'Process recurring expenses';


    public function __construct()
    {
        parent::__construct();
    }
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::channel('expense')->info('Recurring expenses started on ' . Carbon::now());

        $today = Carbon::today();
//        get the expenses
        $expenses = Expense::whereDate('starting_date', '<=', $today)
            ->where('organization_id', org_id())
            ->where('expense_type', 2)
            ->where(function ($query) use ($today) {
                $query->whereNull('ending_date')
                    ->orWhere('ending_date', '>=', $today);
            })
            ->groupBy('related_to')
            ->get();

        foreach ($expenses as $expense) {
            $nextDate = Carbon::parse($expense->recurring_date);
//            when the function should proceed, if the recurring date is less than today or equal
            while ($nextDate->lte($today)) {
                $request1 = [
                    'transaction_type_id' => 11, // Expense
                    'amount' => $expense->amount + $expense->vat_amount, // Total amount including VAT
                    'reference_number' => $expense->reference,
                    'date' => $nextDate->toDateString(), // Recurring date
                ];
                $transaction = GlobalController::InsertNewTransaction($request1);

                $expenseAccount['transaction_id'] = $transaction;
                $expenseAccount['amount'] =  $expense->amount;
                $expenseAccount['account_id'] = $expense->expense_account_id;
                $expenseAccount['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($expenseAccount);

//                get the related to expenses
                $related_expenses = Expense::where('related_to', $expense->id)
                    ->where('id', '<>', $expense->id)
                    ->get();

                $totalvat = $expense->vat_amount;
                $totalamount = $expense->amount;

                $totalTaxableAmount = $totalNonTaxableAmount = 0;
                if($totalvat > 0){
                    $totalTaxableAmount += $totalamount;
                }else{
                    $totalNonTaxableAmount += $totalamount;
                }
                foreach ($related_expenses as $e) {
                    $totalvat += $e->vat_amount;
                    $totalamount += $e->amount;
                    $e->recurring_date = $nextDate->copy()->addMonths($expense->repetitive);
                    $e->save();
                    $expenseAccount['transaction_id'] = $transaction;
                    $expenseAccount['amount'] =  $e->amount;
                    $expenseAccount['account_id'] = $e->expense_account_id;
                    $expenseAccount['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($expenseAccount);
                    if($e->vat_amount > 0){
                        $totalTaxableAmount += $e->amount;
                    }else{
                        $totalNonTaxableAmount += $e->amount;
                    }
                }
                //      credit
                $paymentAccount['transaction_id'] = $transaction;
                $paymentAccount['amount'] =  $totalamount + $totalvat;
                $paymentAccount['account_id'] = $expense->payment_account_id;
                $paymentAccount['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($paymentAccount);
                //      Debit vat
                    $vatAccount['transaction_id'] = $transaction;
                    $vatAccount['amount'] = $totalvat;
                    $vatAccount['account_id'] = GetInputVATAccount();
                    $vatAccount['is_debit'] = 1;
                    GlobalController::InsertNewTransactionDetails($vatAccount);

                TransactionExpense::create([
                    'transaction_id' => $transaction,
                    'expense_id' => $expense->related_to
                ]);
                $transactionDetails= Transaction::find($transaction);
                $transactionDetails->amount = $totalamount + $totalvat;
                $transactionDetails->taxable_amount = $totalTaxableAmount;
                $transactionDetails->non_taxable_amount = $totalNonTaxableAmount;
                $transactionDetails->save();

                $expense->recurring_date = $nextDate->copy()->addMonths($expense->repetitive);

                $expense->save();
                $nextDate = $nextDate->addMonths($expense->repetitive);

            }

        }
        Log::channel('expense')->info('Recurring expenses processed successfully on ' . Carbon::now());

        return Command::SUCCESS;

    }
}
