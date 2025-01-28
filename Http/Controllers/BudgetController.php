<?php

namespace App\Http\Controllers;

use App\AssetType;
use App\Budget;
use App\ChartOfAccounts;
use App\Asset;
use App\DepreciationRecord;
use App\DepreciationType;
use App\ExpenseDocuments;
use App\InvoiceHasItems;
use App\Partner;
use App\Transaction;
use App\TransactionAsset;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $years = Budget::where('organization_id', org_id())->select('year')->distinct()->orderBy('year', 'desc')->get();
        $year = $request->input('year', $years->first()->year ?? null);

        $budgetAccounts = collect();
        if ($year) {
            $budgetAccounts = Budget::where('organization_id', org_id())
                ->where('year', $year)
                ->get();
        }

        return view('pages.budget_accounts.index', compact('budgetAccounts', 'years', 'year'));
    }

    public function create()
    {
//        $accounts = ChartOfAccounts::all();
        $accounts = ChartOfAccounts::with('children', 'accountType')
            ->where('organization_id', org_id())
            ->where('deleted', 0)
            ->whereNull('parent_account_id')
            ->get();
        return view('pages.budget_accounts.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $year = $request->year;
        foreach ($request->amount as $index => $id) {
            if ($request->amount[$index]) {
                Budget::create([
                    'account_id' => $request->account_id[$index],
                    'amount' => $request->amount[$index],
                    'organization_id' => org_id(),
                    'year' => $year
                ]);
            }
        }
        return redirect()->route('BudgetController.index')->with('success', 'Budget account created successfully.');
    }

    public function edit($year)
    {
        $orgId = org_id();
        $chartOfAccounts = ChartOfAccounts::with('children', 'accountType')
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->whereNull('parent_account_id')
            ->get();

        // Retrieve budget accounts for the organization and year
        $budgetAccounts = Budget::where('organization_id', $orgId)
            ->where('year', $year)
            ->get();

        // Create an array to store budget amounts indexed by account_id
        $budgetAmounts = [];
        foreach ($budgetAccounts as $budgetAccount) {
            $budgetAmounts[$budgetAccount->account_id] = $budgetAccount->amount;
        }

        // Add budget amounts to each chart of account, defaulting to 0 if not set
        foreach ($chartOfAccounts as $account) {
            $account->amount = $budgetAmounts[$account->id] ?? 0;
        }
        return view('pages.budget_accounts.edit', compact('chartOfAccounts', 'year'));
    }

    public function update(Request $request)
    {
        $year = $request->year;
        foreach ($request->amount as $index => $id) {
            if ($request->amount[$index]) {
                $budgetAccount = Budget::where('organization_id', org_id())
                    ->where('account_id', $request->account_id[$index])
                    ->where('year', $year)
                    ->first();

                if ($budgetAccount) {
                    $budgetAccount->update([
                        'amount' => $request->amount[$index],
                        'year' => $year,
                    ]);
                } else {
                    // Create a new budget account
                    Budget::create([
                        'organization_id' => org_id(),
                        'account_id' => $request->account_id[$index],
                        'year' => $year,
                        'amount' => $request->amount[$index],
                    ]);
                }
            }
        }
        return redirect()->route('BudgetController.index',['year'=>$year])->with('success', 'Budget account updated successfully.');
    }

    public function destroy($id)
    {
        $account = Budget::find($id);
        $account->delete();
        return response()->json(['status' => 'success',
            'message' => 'Deleted ']);

    }
}
