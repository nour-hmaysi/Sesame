<?php

namespace App\Http\Controllers;

use App\AssetType;
use App\ChartOfAccounts;
use App\Asset;
use App\DefaultChartOfAccounts;
use App\DepreciationRecord;
use App\DepreciationType;
use App\ExpenseDocuments;
use App\Organization;
use App\Partner;
use App\Transaction;
use App\TransactionAsset;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class IndexController extends Controller
{

    public function dashboard(){





//        amount due from sales invoice and bills
        $totalRecPay = GetTotalReceivableAndPayable();

//        total bank and cash
        $totalCash = GetTotalCash();



        
        
        return view('dashboard', compact([
            'totalRecPay',
            'totalCash',
        ]));

    }

public function getTopExpenseAccounts(Request $request)
{
    $startDate = $request->input('startDate');
    $endDate = $request->input('endDate');
    $ExpenseAccountsIds = GetExpenseAccountIds();

    // Fetch total expenses by account, considering is_debit
    $expensesByAccount = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->leftJoin('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->whereIn('transaction_details.account_id', $ExpenseAccountsIds)
        ->where('transaction.organization_id', org_id())
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->select('chart_of_account.name as account_name','transaction_details.account_id', DB::raw('SUM(CASE WHEN transaction_details.is_debit = 1 THEN transaction_details.amount ELSE -transaction_details.amount END) as total_amount'))
        ->groupBy('transaction_details.account_id')
        ->orderBy('total_amount', 'desc')
        ->limit(5)
        ->get();

    // Calculate the total amount of top 5 accounts
    $totalAmount = $expensesByAccount->sum('total_amount');

    // Prepare data for the pie chart
    $data = $expensesByAccount->map(function ($account) use ($totalAmount) {
        return [
            'account_id' => $account->account_name,
            'amount' => $account->total_amount,
            'percentage' => $totalAmount ? ($account->total_amount / $totalAmount) * 100 : 0
        ];
    });

    return response()->json($data);
}

public function getTotalIncomeExpense(Request $request)
{
    $startDate = Carbon::parse($request->input('startDate'));
    $endDate = Carbon::parse($request->input('endDate'));

    $ExpenseAccountsIds = GetExpenseAccountIds();

    $expenseTransactions = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->whereIn('transaction_details.account_id', $ExpenseAccountsIds)
        ->where('transaction.organization_id', org_id())
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->select('transaction_details.*', 'transaction.date')
        ->get();

    $IncomeAccountsIds = GetIncomeAccountIds();

    $incomeTransactions = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->whereIn('transaction_details.account_id', $IncomeAccountsIds)
        ->where('transaction.organization_id', org_id())
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->select('transaction_details.*', 'transaction.date')
        ->get();

// Grouping transactions by month
    $incomeByMonth = $incomeTransactions->groupBy(function ($transaction) {
        return Carbon::parse($transaction->date)->format('Y-m');
    })->map(function ($group) {
        return $group->reduce(function ($carry, $transaction) {
            return $carry + ($transaction->is_debit ? -$transaction->amount : $transaction->amount);
        }, 0);
    });

    $expensesByMonth = $expenseTransactions->groupBy(function ($transaction) {
        return Carbon::parse($transaction->date)->format('Y-m');
    })->map(function ($group) {
        return $group->reduce(function ($carry, $transaction) {
            return $carry + ($transaction->is_debit ? $transaction->amount : -$transaction->amount);
        }, 0);
    });

// Generate all months between startDate and endDate
    $months = [];
    $currentMonth = $startDate->copy()->startOfMonth();
    while ($currentMonth->lte($endDate->endOfMonth())) {
        $monthKey = $currentMonth->format('Y-m');
        $months[] = $monthKey;
        $monthNames[] = $currentMonth->format('F Y'); // Full month name with year
        $currentMonth->addMonth();
    }

// Ensure all months are included in the results, defaulting to 0 if not present
    $incomeData = array_map(function ($month) use ($incomeByMonth) {
        return $incomeByMonth->get($month, 0); // Default to 0 if month is not in incomeByMonth
    }, $months);

    $expensesData = array_map(function ($month) use ($expensesByMonth) {
        return $expensesByMonth->get($month, 0); // Default to 0 if month is not in expensesByMonth
    }, $months);

    // Calculate total income and expenses
    $totalIncome = array_sum($incomeData);
    $totalExpenses = array_sum($expensesData);

    return response()->json([
        'months' => $monthNames,
        'incomeData' => $incomeData,
        'expensesData' => $expensesData,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'totalIncome' => $totalIncome,
        'totalExpenses' => $totalExpenses
    ]);
}

}
