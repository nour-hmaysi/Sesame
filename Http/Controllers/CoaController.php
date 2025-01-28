<?php

namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\AccountType;
use App\Project;
use App\TransactionDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CoaController extends Controller
{
    public function store(Request $request)
    {

        $rules = [
            'name' => [
                'required',
                Rule::unique('chart_of_account')->where(function ($query) use ($request) {
                    return $query->where('organization_id', org_id());
                }),
            ],
            'type_id' => ['required'],
            'code' => ['nullable'],
            'parent_account_id' => ['nullable'],
            'description' => ['nullable', 'string', 'max:100'],
        ];

        $messages = [
            'name.unique' => __('validation.unique', ['attribute' => 'name']),
            'type_id.required' => __('validation.required', ['attribute' => 'account type']),
        ];



        try {
            $request->validate($rules, $messages);


            $account = ChartOfAccounts::create($request->all());

            if ($request->ajax()) {
                return response()->json(['success' => true]);
            } else {
                return redirect()->route('chartofaccounts.index', ['#row-' . $account->id]);
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
        $accountTypes = $this->getHierarchicalAccountTypes();
        $parentAccounts = ParentAccounts();

        return view('pages.coa.create', compact('accountTypes', 'parentAccounts'));
    }
    private function getHierarchicalAccountTypes()
    {
        $allAccountTypes = AccountType::where('is_cat', 1)->get()->keyBy('id')->toArray();

        $hierarchicalData = [];
        foreach ($allAccountTypes as $id => $accountType) {
            $parentId = $accountType['parent_id'];
            if ($parentId == 0) {
                $hierarchicalData[$id] = $accountType;
            } else {
                $hierarchicalData[$parentId]['children'][] = $accountType;
            }
        }

        return $hierarchicalData;
    }
    public function index()
    {
        $chart_of_accounts = ChartOfAccounts::with('children', 'accountType')
            ->where('organization_id', org_id())
            ->where('deleted', 0)
            ->whereNull('parent_account_id')
            ->get();

//        $chart_of_accounts = ChartOfAccounts::with('accountType')
//            ->where('organization_id', org_id())
//            ->where('deleted', 0)
//            ->get();

        return view('pages.coa.index', compact('chart_of_accounts'));
    }
//    public function updateAccountStatus(Request $request, $id)
//    {
//        $account = ChartOfAccounts::findOrFail($id);
//        $account->active = $request->input('active');
////        $account->updated_by = Auth::id();
//        $account->save();
//
//        return response()->json(['message' => 'success']);
//    }
    public function deleteAccount($id)
    {
        $account = ChartOfAccounts::findOrFail($id);
        $transaction = TransactionDetails::where('account_id', $id)
            ->get();

        if($transaction->isNotEmpty()){
            return response()->json(['status' => 'error',
                'message' => errorTMsg()]);
        }else{
            $account->deleted = 1;
            $account->save();
            return response()->json(['status' => 'success',
                'message' => 'Account deleted.']);
        }

    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $account = ChartOfAccounts::findOrFail($id);
        $accountTypes = $this->getHierarchicalAccountTypes();
        $parentAccounts = ParentAccounts();

        return view('pages.coa.edit', compact(['account','accountTypes','parentAccounts']));
    }
    public function update(Request $request, $id)
    {
        $account = ChartOfAccounts::findOrFail($id);
        $account->update($request->all());
        return redirect()->route('chartofaccounts.index')->with('success', 'Account updated successfully');
    }

    public function listExpensesAccount(){
      $expenseAccounts =  ChartOfAccounts::select('A.id', 'A.name', 'A.code')
            ->from('chart_of_account as A')
            ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
            ->where('B.parent_id', 5)
            ->where('A.organization_id', org_id())
            ->where('A.deleted', 0)
            ->get();
        $content = '';
        foreach($expenseAccounts as $account){
            $content .= ' <option value="'.$account->id.'">'.$account->name.'</option>';
        }

        return response()->json([
            'expenseOptions' => $content,
        ]);
    }
    public function listAccounts(){
      $allAccounts =  ChartOfAccounts::select('A.id', 'A.name', 'A.code')
          ->from('chart_of_account as A')
          ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
          ->where('A.organization_id', org_id())
          ->where('A.deleted', 0)
          ->orderBy('id', 'asc')
          ->get();
      $expenseAccounts =  ExpenseAccounts();
      $depreciationAccounts =  DepreciationAccounts();
      $PaymentAccounts =  PaymentAccounts();
      $content = '';
      foreach($allAccounts as $account){
          $content .= ' <option value="'.$account->id.'">'.$account->name.'</option>';
      }
      $expense = '';
      if($expenseAccounts){
          foreach($expenseAccounts as $account){
              $expense .= ' <option value="'.$account->id.'">'.$account->name.'</option>';
          }
      }
      $depreciation = '';
      if($depreciationAccounts){
          foreach($depreciationAccounts as $account){
              $depreciation .= ' <option value="'.$account->id.'">'.$account->name.'</option>';
          }
      }
      $payment = '';
      if($PaymentAccounts){
          foreach($PaymentAccounts as $account){
              $payment .= ' <option value="'.$account->id.'">'.$account->name.'</option>';
          }
      }
        return response()->json([
            'options' => $content,
            'expense' => $expense,
            'payment' => $payment,
        ]);
    }
    public function moreDetails($id){
        $account = ChartOfAccounts::find($id);
        $currentDate = \Carbon\Carbon::now();

        $accountTransactions = DB::table('transaction')
            ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
            ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
            ->where('chart_of_account.organization_id', org_id())
            ->where('transaction.organization_id', org_id())
            ->where('transaction_details.account_id', $id)
            ->where('transaction.date', '<=', $currentDate)
            ->orderBy('transaction.date', 'Desc')
            ->select('transaction.date', 'transaction_details.amount', 'transaction_details.is_debit', 'transaction.description', 'transaction.transaction_type_id')
            ->get();

        return view('pages.coa.moreDetails', compact(['accountTransactions', 'account']));
    }
}
