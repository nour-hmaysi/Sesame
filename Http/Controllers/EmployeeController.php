<?php

namespace App\Http\Controllers;

use App\ChartOfAccounts;
use App\Expense;
use App\InvoiceHasItems;
use App\PaymentReceived;
use App\Employee;
use App\Payroll;
use App\Project;
use App\Partner;
use App\Transaction;
use App\TransactionProject;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->all();

        $parentAccount = GetEmployeeAccount();
        $account = ChartOfAccounts::create([
            'type_id' => 34,
            'parent_account_id' => $parentAccount,
            'name' => $data['first_name'].' '. $data['last_name'],
        ]);
        $data['account_id'] = $account->id;
        $payroll = Employee::create($data);
        //      Insert comment to payroll
        $title =  'Employee Created.';
        GlobalController::InsertNewComment(17, $payroll->id, $title, NULL);

        return redirect()->route('EmployeeController.index', ['row#' . $payroll->id]);

    }
    public function payroll(Request $request)
    {
        if (validateVATDate($request->date)) {
            return redirect()->back()->withInput()->with('error', errorMsg());
        }

        $salaryAccount = GetEmployeeSalariesAccount();
        $absenceAccount = GetEmployeeAbsenceAccount();
        $paid_account_id = $request->account_id;
        $date = $request->date;
        $note = $request->note;
        $project = $request->project_id;
        $reference = $request->reference_number;
        $salariesAmount = $request->input('salary', []);
        $basicHousingAmount = $request->input('basic_housing', []);
        $otherAllowanceAmount = $request->input('other_allowance', []);
        $absenceAmount = $request->input('absence', []);
        $totalSalariesAmount = array_sum($salariesAmount);
        $totalBasicHousing = array_sum($basicHousingAmount);
        $totalOtherAllowance = array_sum($otherAllowanceAmount);
        $totalAbsenceAmount = array_sum($absenceAmount);

        $referenceExist = checkReferenceExists($reference);
        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();
        }
        $internalNote = '';

        $request1['transaction_type_id'] = 27;
        $request1['amount'] =  $totalSalariesAmount + $totalBasicHousing + $totalOtherAllowance ;
        $request1['internal_note'] = $internalNote;
        $request1['description'] = $note;
        $request1['reference_number'] = $reference;
        $request1['date'] = $date;
        $transactionID = GlobalController::InsertNewTransaction($request1);

        if (isset($request->employee_id)) {
            for ($index = 0; $index < count($request->employee_id); $index++) {
                if ($request->employee_id[$index]) {
                    //      Insert comment to payroll
                    $title =  'Payroll - '. Carbon::parse($date)->format('d F Y');
                    $cmnt =  'Salary: '.$request->salary[$index].', Basic Housing: '. $request->basic_housing[$index].
                        ', Other Allowance: '.$request->other_allowance[$index].', Absence: '. $request->absence[$index];
                    $internalNote .= ' <b> Payroll For '.$request->employee_name[$index].' : </b> '.$cmnt.".\n";
                    GlobalController::InsertNewComment(17, $request->employee_id[$index], $title, $cmnt);

                    Payroll::create([
                        'employee_id' => $request->employee_id[$index],
                        'date' => $date,
                        'salary' =>$request->salary[$index],
                        'reference_number' =>$reference,
                        'basic_housing' => $request->basic_housing[$index],
                        'other_allowance' => $request->other_allowance[$index],
                        'absence' => $request->absence[$index],
                        'project_id' => $request->project_id[$index],
                        'transaction_id' => $transactionID
                    ]);
                }
            }
        }

        $currentTransaction = Transaction::find($transactionID);
        $currentTransaction->internal_note = $internalNote;
        $currentTransaction->save();
//        credit to account bank or cash
        $request2['transaction_id'] = $transactionID;
        $request2['amount'] =  $totalSalariesAmount + $totalBasicHousing + $totalOtherAllowance - $totalAbsenceAmount;
        $request2['account_id'] = $paid_account_id;
        $request2['is_debit'] = 0;
        GlobalController::InsertNewTransactionDetails($request2);
//        credit to absence
        $absence['transaction_id'] = $transactionID;
        $absence['amount'] = $totalAbsenceAmount;
        $absence['account_id'] = $absenceAccount;
        $absence['is_debit'] = 0;
        GlobalController::InsertNewTransactionDetails($absence);
//        deposit to salaries
        $salaries['transaction_id'] = $transactionID;
        $salaries['amount'] =  $totalSalariesAmount + $totalBasicHousing + $totalOtherAllowance ;
        $salaries['account_id'] = $salaryAccount;
        $salaries['is_debit'] = 1;
        GlobalController::InsertNewTransactionDetails($salaries);

        return redirect()->route('TransactionController.showJournal', ['#row-' . $transactionID]);

    }
    public function create()
    {
        return view('pages.employee.create');
    }
    public function index()
    {
        $organizationId = org_id();
        $payroll = Employee::where('deleted', 0)
            ->where('organization_id', $organizationId)
            ->get();
        $paymentAccounts = PaymentAccounts();
        return view('pages.employee.index', compact('payroll', 'paymentAccounts'));
    }
    public function delete($id)
    {
        $employee = Employee::findOrFail($id);

        $employeeExist = Payroll::where('organization_id', org_id())
            ->where('employee_id', $id)
            ->exists();
        if($employeeExist){
            return response()->json(['status' => 'error',
                'message' => errorTMsg()]);
        }else{
            $employee->delete();
            return response()->json(['status' => 'success', 'message' => 'Deleted Successfully']);
        }

    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $payroll = Employee::findOrFail($id);

        return view('pages.employee.edit', compact(['payroll']));
    }
    public function update(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $payroll = Employee::findOrFail($id);
        $data = $request->all();
        //      Insert comment to payroll
        $title =  'Employee Information Modified.';
        GlobalController::InsertNewComment(17, $payroll->id, $title, NULL);

        $payroll->update($data);

        return redirect()->route('EmployeeController.index', ['row#' . $payroll->id]);
    }

    public function moreDetails($id){
        $payroll = Employee::where('organization_id', org_id())
            ->where('id', $id)
            ->first();
//        show transactions
        return view('pages.employee.moreDetails', compact(['payroll']));
    }

}
