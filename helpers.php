<?php

use App\ChartOfAccounts;
use App\CreditNote;
use App\Currency;
use App\DebitNote;
use App\Industry;
use App\Partner;
use App\Project;
use App\PurchaseInvoice;
use App\PurchaseOrder;
use App\Quotation;
use App\SalesInvoice;
use App\SalesOrder;
use App\TaxReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use NumberToWords\NumberToWords;

function convertAmountToText($amount, $currency = 'SAR')
{
    // Ensure the amount is a string to handle both integer and decimal
    $amount = (string)$amount;

    // Split the amount into integer and decimal parts (if decimals exist)
    $parts = explode('.', $amount);

    // Initialize NumberToWords
    $numberToWords = new NumberToWords();

    // Create a number transformer for English
    $numberTransformer = $numberToWords->getNumberTransformer('en');

    // Convert integer part to words
    $integerPart = (int)$parts[0];
    $integerInWords = $numberTransformer->toWords($integerPart);

    // Check if decimal part exists
    if (isset($parts[1]) && (int)$parts[1] > 0) {
        $decimalPart = (int)$parts[1]; // Convert the decimal part to an integer
        $decimalInWords = $numberTransformer->toWords($decimalPart);

        // Return the combined result (integer and decimal parts)
        return ucfirst($integerInWords) . ' and ' . $decimalInWords . ' ' . strtoupper($currency);
    }

    // If no decimal part, return just the integer part
    return ucfirst($integerInWords) . ' ' . strtoupper($currency);
}

function orgName(){
    $orgId =  orgID();
    $org = \App\Organization::find($orgId);
    return $org->name;
}
function orgNamebyId($id){
    $org = \App\Organization::find($id);
    return $org->name;
}
function orgLogo($id){
    $org = \App\Organization::find($id);
    return $org->logo;
}
function orgAddress($id){
    $org = \App\Organization::find($id);
    $address = '';
    if (!empty($org->building_number)) {
        $address .= $org->building_number . '<br>';
    }
    if (!empty($org->address)) {
        $address .= $org->address . '<br>';
    }
    if (!empty($org->district)) {
        $address .= $org->district . '<br>';
    }
    if (!empty($org->zip_code)) {
        $address .= $org->zip_code . '<br>';
    }
    if (!empty($org->city)) {
        $address .= $org->city . '<br>';
    }
    if (!empty($org->country_region)) {
        $address .= $org->country_region . '<br>';
    }
    if (!empty($org->fax_number)) {
        $address .= $org->fax_number . '<br>';
    }
    if (!empty($org->phone)) {
        $address .= $org->phone . '<br>';
    }
    if (!empty($org->vat_number)) {
        $address .= 'VAT: ' .$org->vat_number . '<br>';
    }
    return $address;
}
function orgID(){
    $user = Auth::user();
    return $user ? $user->organization_id : null;
}
function org_id() {
    return orgID();
}
function currentUser() {
    $user = Auth::id();
    return $user;
}
function userByID($id) {
    $user = \App\User::find($id);
    return $user->firstname.' '.$user->lastname;
}
function fiscalYear() {
    return '2024-01-01';
}

function InvFormat() {
    $orgDetails = \App\Organization::find(org_id());
    return [
        'prefix' => $orgDetails->invoice_prefix,
        'start_nb' =>  $orgDetails->inv_start_nb,
        'digit' =>  $orgDetails->inv_digit
    ];
}
function companyActivity() {
    return  \App\CompanyActivity::all();
}
function industries(){
    return  Industry::all();
}
function currencyID() {
    $orgId =  orgID();
    $org = \App\Organization::find($orgId);
    return $org->currency_id ?? 1;
}
function DateFormat($date){
    return   \Carbon\Carbon::parse($date)->format('d F Y');
}
function currencyName() {
    $currency = Currency::where('id', currencyID())
        ->first();
    return $currency->name;
}
function errorMsg(){
    return 'Cannot create/modify during submitted VAT';
}
function  itemUnit($value){
    $unitOptions = '';
    $options = ['dozen', 'box', 'grams', 'kilograms', 'meters', 'tablets', 'pieces', 'pairs'];
    if(!in_array(strtolower($value), $options)){
        $unitOptions .= '<option selected value="'.$value.'">'.$value.'</option>';
    }
    foreach($options as $option){
        ($value == $option) ? $selected = 'selected' :   $selected = '';
        $unitOptions .= '<option '.$selected.' value="'.$option.'">'.$option.'</option>';
    }
    return $unitOptions;
}
function  bulkUnit($value){
    $unitOptions = '';
    $options = \App\BulkUnit::pluck('name')->all();

    if($value !=NULL && !in_array(strtolower($value), $options)){
        $unitOptions .= '<option selected value="'.$value.'">'.$value.'</option>';
    }
    foreach($options as $option){
        ($value == $option) ? $selected = 'selected' :   $selected = '';
        $unitOptions .= '<option '.$selected.' value="'.$option.'">'.$option.'</option>';
    }
    return $unitOptions;
}
function  usageUnit($value){
    $unitOptions = '';
    $options = \App\UsageUnit::pluck('name')->all();
    if($value !=NULL && !in_array(strtolower($value), $options)){
        $unitOptions .= '<option selected value="'.$value.'">'.$value.'</option>';
    }
    foreach($options as $option){
        ($value == $option) ? $selected = 'selected' :   $selected = '';
        $unitOptions .= '<option '.$selected.' value="'.$option.'">'.$option.'</option>';
    }
    return $unitOptions;
}
function errorTMsg(){
    return 'Cannot delete. There are related transactions';
}
function TaxValue() {
    $orgId =  orgID();
    $org = \App\Organization::find($orgId);
    return $org->vat_rate ?? 0;
}
function getTaxValue($id) {
    $taxValue = \App\Tax::find($id);
    return $taxValue->value ?? 0;
}
function getVatDate(){
    $reportDate = TaxReport::where('organization_id', org_id())
        ->where('is_approved', 1)
        ->max('end_date');
    return $reportDate;
}
function validateVATDate($invoiceDate)
{
    $vatDate = TaxReport::where('organization_id', org_id())
        ->where('is_approved', 1)
        ->max('end_date');

    $invoiceDate = \Carbon\Carbon::parse($invoiceDate);

    return $invoiceDate <= $vatDate;
}
function checkReferenceExists($reference )
{
//    $tables = [
//        'expense' => 'reference',
//        'fixed_asset' => 'reference_number',
//        'payment_received' => 'reference_number',
//        'quotation' => 'order_number',
//        'sales_invoice' => 'order_number',
//        'purchase_invoice' => 'order_number',
//        'sales_order' => 'order_number',
//        'purchase_order' => 'order_number',
//        'credit_note' => 'order_number',
//        'debit_note' => 'order_number',
//    ];
//
//    foreach ($tables as $table => $column) {
//        $exists = DB::table($table)
//            ->where($column, $reference)
//            ->where('organization_id', orgID())
//            ->exists();
//
//        if ($exists) {
//            return $table;
//        }
//    }

    if($reference != NULL){
        $exists = DB::table('transaction')
            ->where('reference_number', $reference)
            ->where('organization_id', orgID())
            ->exists();

        return $exists;
    }
    return false;

}
function GetPaymentAccountIds() {
    //        15 for bank , 16 for cash
        $paymentAccounts = ChartOfAccounts::select('A.id')
            ->from('chart_of_account as A')
            ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
            ->where(function ($query) {
                $query->where('A.type_id', 15)
                    ->orWhere('A.type_id', 16);
            })
            ->where('A.organization_id', org_id())
            ->where('A.deleted', 0)
            ->orderBy('id', 'asc')
            ->pluck('A.id')
            ->toArray();
        return $paymentAccounts;
    }
    function GetIncomeAccountIds() {
            $Accounts = ChartOfAccounts::select('A.id')
                ->from('chart_of_account as A')
                ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
                ->where(function ($query) {
                    $query->where('B.parent_id', 4 );
                })
                ->where('A.organization_id', org_id())
                ->where('A.deleted', 0)
                ->orderBy('id', 'asc')
                ->pluck('A.id')
                ->toArray();
            return $Accounts;
        }
    function GetSalesAccountIds() {
            $Accounts = ChartOfAccounts::select('A.id', 'A.name', 'A.code')
                ->from('chart_of_account as A')
                ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
                ->where('B.cat_id', 4)
                ->where('A.organization_id', org_id())
                ->where('A.deleted', 0)
                ->get();
            return $Accounts;
        }
        
    function GetExpenseAccountIds() {
        $Accounts = ChartOfAccounts::select('A.id')
            ->from('chart_of_account as A')
            ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
            ->where(function ($query) {
                $query->where('B.parent_id', 5 );
            })
            ->where('A.organization_id', org_id())
            ->where('A.deleted', 0)
            ->orderBy('id', 'asc')
            ->pluck('A.id')
            ->toArray();
        return $Accounts;
    }
function PaymentAccounts() {
//        15 for bank , 16 for cash
    $paymentAccounts = ChartOfAccounts::select('A.id', 'A.name', 'A.code')
        ->from('chart_of_account as A')
        ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
        ->where(function ($query) {
            $query->where('A.type_id', 15)
                ->orWhere('A.type_id', 16);
        })
        ->where('A.organization_id', org_id())
        ->where('A.deleted', 0)
        ->orderBy('id', 'asc')
        ->get();
    return $paymentAccounts;
}
function EquityAccounts() {
//        3 for equity and 10
    $account = ChartOfAccounts::select('A.id', 'A.name', 'A.code', 'A.type_id', 'A.parent_account_id')
        ->from('chart_of_account as A')
        ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
        ->where(function ($query) {
            $query->where('B.parent_id', 3);
        })
        ->where('A.organization_id', org_id())
        ->where('A.deleted', 0)
        ->orderBy('id', 'asc')
        ->get();
    return $account;
}
function LoanAccounts() {
//    $account = ChartOfAccounts::select('A.id', 'A.name', 'A.code', 'A.type_id', 'A.parent_account_id')
//        ->from('chart_of_account as A')
//        ->where('A.type_id', 41)
//        ->where('A.organization_id', org_id())
//        ->where('A.deleted', 0)
//        ->orderBy('id', 'asc')
//        ->get();
    $account = ChartOfAccounts::select('A.id', 'A.name', 'A.code', 'A.type_id', 'A.parent_account_id')
        ->from('chart_of_account as A')
        ->where(function ($query) {
            $query->where('A.type_id', 41)
                ->orWhereIn('A.parent_account_id', function ($subQuery) {
                    $subQuery->select('B.id')
                        ->from('chart_of_account as B')
                        ->where('B.type_id', 41)
                        ->where('B.deleted', 0)
                        ->where('B.organization_id', org_id());
                });
        })
        ->where('A.organization_id', org_id())
        ->where('A.deleted', 0)
        ->orderBy('id', 'asc')
        ->get();
    return $account;
}
function FixedAssetAccounts() {
    $fixedAssets = ChartOfAccounts::select('A.id', 'A.name', 'A.code')
        ->from('chart_of_account as A')
        ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
        ->where(function ($query) {
            $query->where('A.type_id', 6);
        })
        ->where('A.organization_id', org_id())
        ->where('A.deleted', 0)
        ->get();
    return $fixedAssets;
}
function ParentAccounts() {
    $ParentAccounts = ChartOfAccounts::select('A.id', 'A.name', 'A.code',  'B.sub_parent_id as type_id')
        ->from('chart_of_account as A')
        ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
        ->where(function ($query) {
            $query->where('B.hidden', 0);
        })
        ->where('A.organization_id', org_id())
        ->where('A.parent_account_id', NULL)
        ->where('A.deleted', 0)
        ->orderBy('id', 'asc')
        ->get();
    return $ParentAccounts;
}
function ExpenseAccounts() {
//        5 for expense
    $expenseAccounts =  ChartOfAccounts::select('A.id', 'A.name', 'A.code')
        ->from('chart_of_account as A')
        ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
        ->where('B.cat_id', 5)
        ->where('A.organization_id', org_id())
        ->where('A.deleted', 0)
        ->orderBy('id', 'asc')
        ->get();
    return $expenseAccounts;
}
function DepreciationAccounts() {
    $depreciationAccounts =  ChartOfAccounts::select('A.id', 'A.name', 'A.code')
        ->from('chart_of_account as A')
        ->leftJoin('account_type as B', 'B.id', '=', 'A.type_id')
        ->where('B.id', 38)
        ->where('A.organization_id', org_id())
        ->where('A.deleted', 0)
        ->orderBy('id', 'asc')
        ->get();
    return $depreciationAccounts;
}
function Customers() {
    $customers = Partner::where('deleted', 0)
        ->where('partner_type', 2)
        ->where('organization_id', org_id())
        ->get();
    return $customers;
}
function Suppliers() {
    $suppliers = Partner::where('deleted', 0)
        ->where('partner_type', 1)
        ->where('organization_id', org_id())
        ->get();
    return $suppliers;
}
function PartnerInfo($id){
    $partner = Partner::find($id);
    $trn = $partner->tax_number;
    $name = $partner->display_name;
    return [
        "trn" =>$trn,
        "name"=>$name
    ];
}
function PartnerAddress($id){
    $partner = Partner::find($id);
    $address = '';
    if (!empty($partner->building_number)) {
        $address .= $partner->building_number . ', ';
    }
    if (!empty($partner->address)) {
        $address .= $partner->address . ', ';
    }
    if (!empty($partner->district)) {
        $address .= $partner->district . ', ';
    }
    if (!empty($partner->zip_code)) {
        $address .= $partner->zip_code . ', ';
    }
    if (!empty($partner->city)) {
        $address .= $partner->city . ', ';
    }
    if (!empty($partner->country)) {
        $address .= $partner->country;
    }

    $ar_address = '';

    if (!empty($partner->ar_address)) {
        $ar_address .= $partner->ar_address . ', ';
    }
    if (!empty($partner->ar_district)) {
        $ar_address .= $partner->ar_district . ', ';
    }
    if (!empty($partner->ar_city)) {
        $ar_address .= $partner->ar_city . ', ';
    }
    if (!empty($partner->ar_state)) {
        $ar_address .= $partner->ar_state . ', ';
    }
    if (!empty($partner->ar_zip_code)) {
        $ar_address .= $partner->ar_zip_code . ', ';
    }
    if (!empty($partner->ar_country)) {
        $ar_address .= $partner->ar_country;
    }


    return [
        "address" =>rtrim($address, ','),
        "ar_address" =>rtrim($ar_address, ',')
    ];
}
function PartnerFullInfo($id){
    $partner = Partner::find($id);
    $address = '';
    if (!empty($partner->building_number)) {
        $address .= $partner->building_number . '<br>';
    }
    if (!empty($partner->address)) {
        $address .= $partner->address . '<br>';
    }
    if (!empty($partner->district)) {
        $address .= $partner->district . '<br>';
    }
    if (!empty($partner->zip_code)) {
        $address .= $partner->zip_code . '<br>';
    }
    if (!empty($partner->city)) {
        $address .= $partner->city . '<br>';
    }
    if (!empty($partner->country)) {
        $address .= $partner->country . '<br>';
    }
    if (!empty($partner->tax_number)) {
        $address .= $partner->tax_number . '<br>';
    }
    if (!empty($partner->cr_number)) {
        $address .= $partner->cr_number . '<br>';
    }
    $trn = $partner->tax_number;
    $crn = $partner->cr_number;
    $name = $partner->display_name;
    return [
        "address" =>$address,
        "trn" => $trn,
        "crn" => $crn,
        "name"=> $name
    ];
}
function Projects() {
    $projects = Project::where('deleted', 0)
        ->where('organization_id', org_id())
        ->get();
    return $projects;
}
function Employee() {
    $employee = \App\Employee::where('deleted', 0)
        ->where('organization_id', org_id())
        ->get();
    return $employee;
}
 function GetDiscountAccount()
{
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 24)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetCostDiscountAccount()
{
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 39)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetReceivableAccount()
{
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 19)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetPayableAccount()
{
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 12)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetOutputVATAccount()
{
//    sales vat
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 13)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetInputVATAccount()
{
//    purchase vat
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 17)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetDefaultSalesAccount()
{
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 8)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetDefaultPurchaseAccount()
{
//    cost of goods
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 7)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetAdjustmentAccount()
{
//    Opening Balance Adjustments
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 33)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetEmployeeAccount()
{
//    Employee
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 34)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetEmployeeSalariesAccount()
{
//    Employee Salaries
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 35)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetEmployeeAbsenceAccount()
{
//    Employee Absence
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 36)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetPayableVATAccount()
{
//    VAT Payable
    $account = ChartOfAccounts::where('organization_id', org_id())
        ->where('type_id', 32)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetAdvPaymentAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 20)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetPrepaidPaymentAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 30)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetAllAccounts()
{
    $accounts = \App\AccountType::whereNull('parent_id')
        ->with(['children', 'children.accounts' => function($query) {
            $query->where('organization_id', org_id());
        }])
        ->get();
//    $account = ChartOfAccounts::where('organization_id',  org_id())
//        ->get();
    return $accounts;
}
 function GetRetentionAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 25)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
function GetDepreciationAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 22)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
function GetAccumulatedAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 31)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetBankChargeAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 23)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetBankAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 15)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
 function GetCashAccount()
{
    $account = ChartOfAccounts::where('organization_id',  org_id())
        ->where('type_id', 16)
        ->where('is_default', 1)
        ->first();
    return $account->id;
}
function GetDateRange($type = 1){
    if ($type == 1) {
        $startOfWeek = now()->startOfWeek()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();
        $startDate = $startOfWeek;
        $endDate = $endOfWeek;
    } elseif ($type == 2) {
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();
        $startDate = $startOfMonth;
        $endDate = $endOfMonth;
    } elseif ($type == 3) {
        $startOfQuarter = now()->firstOfQuarter()->toDateString();
        $endOfQuarter = now()->lastOfQuarter()->toDateString();
        $startDate = $startOfQuarter;
        $endDate = $endOfQuarter;
    } elseif ($type == 4) {
        $startOfYear = now()->startOfYear()->toDateString();
        $endOfYear = now()->endOfYear()->toDateString();
        $startDate = $startOfYear;
        $endDate = $endOfYear;
    } elseif ($type == 5) {
        $previousYear = now()->subYear();
        $startOfPreviousYear = $previousYear->startOfYear()->toDateString();
        $endOfPreviousYear = $previousYear->endOfYear()->toDateString();
        $startDate = $startOfPreviousYear;
        $endDate = $endOfPreviousYear;
    } else {
        // Default to current month
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();
        $startDate = $startOfMonth;
        $endDate = $endOfMonth;
    }
    return [$startDate, $endDate];
}
//  function GetTotalExpense($type = 4)
// {
//     list($startDate, $endDate) = GetDateRange($type);
//     $purchaseVat = GetInputVATAccount();
//      $totalAssetCost = \App\Asset::where('organization_id', org_id())
//          ->where(function ($query) use ($startDate, $endDate) {
//              $query->where('date', '>=', $startDate)
//                  ->Where('date', '<=', $endDate);
//          })
//          ->select(DB::raw('SUM(unit * net_cost ) as total_cost'))
//          ->pluck('total_cost')
//          ->first();
// //      expenses without vat
//      $totalExpenseCost = DB::table('transaction')
//          ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
//          ->join('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
//          ->join('expense', 'transaction_expense.expense_id', '=', 'expense.id')
//          ->where('transaction.transaction_type_id', 11)
//          ->where('transaction_details.is_debit', 1)
//          ->whereNot('transaction_details.account_id', $purchaseVat)
//          ->where('expense.organization_id', org_id())
//          ->whereBetween('transaction.date', [$startDate, $endDate])
//          ->sum('transaction_details.amount');
// //      refund expenses without vat
//      $totalRefundExpenseCost = DB::table('transaction')
//          ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
//          ->join('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
//          ->join('expense', 'transaction_expense.expense_id', '=', 'expense.id')
//          ->where('transaction.transaction_type_id', 7)
//          ->where('transaction_details.is_debit', 0)
//          ->whereNot('transaction_details.account_id', $purchaseVat)
//          ->where('expense.organization_id', org_id())
//          ->whereBetween('transaction.date', [$startDate, $endDate])
//          ->sum('transaction_details.amount');
// //     purchase invoice
//      $totalPurchases = \App\PurchaseInvoice::where('organization_id', org_id())
//          ->whereIn('status', [4,7])
//          ->whereBetween('invoice_date', [$startDate, $endDate])
//          ->select(DB::raw('SUM(total - vat_amount ) as total_cost'))
//          ->pluck('total_cost')
//          ->first();
// //     Debit Note
//      $totalRefundPurchase = \App\DebitNote::where('organization_id', org_id())
//          ->whereIn('status', [4,8,9])
//          ->whereBetween('invoice_date', [$startDate, $endDate])
//          ->select(DB::raw('SUM(total - vat_amount ) as total_cost'))
//          ->pluck('total_cost')
//          ->first();
//      $totalExpense = $totalExpenseCost + $totalAssetCost + $totalPurchases - $totalRefundExpenseCost - $totalRefundPurchase ;
//     return number_format($totalExpense, 2, '.', ',');
// }
 function GetTotalIncome($type = 4)
{
    list($startDate, $endDate) = GetDateRange($type);


     $IncomeAccountsIds = GetIncomeAccountIds();
     $totalDebitIncome = DB::table('transaction')
     ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
     ->where('transaction_details.is_debit', 1)
     ->whereIn('transaction_details.account_id', $IncomeAccountsIds)
     ->where('transaction.organization_id', org_id())
     ->whereBetween('transaction.date', [$startDate, $endDate])
     ->sum('transaction_details.amount');

     $totalCreditIncome = DB::table('transaction')
     ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
     ->where('transaction_details.is_debit', 0)
     ->whereIn('transaction_details.account_id', $IncomeAccountsIds)
     ->where('transaction.organization_id', org_id())
     ->whereBetween('transaction.date', [$startDate, $endDate])
     ->sum('transaction_details.amount');

    //  for income credit - debit
     $totalIncome = $totalCreditIncome - $totalDebitIncome ;


    return number_format($totalIncome, 2, '.', ',');
}
function GetTotalExpense($type = 4)
{
    list($startDate, $endDate) = GetDateRange($type);


     $ExpenseAccountsIds = GetExpenseAccountIds();
     $totalDebitExpense = DB::table('transaction')
     ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
     ->where('transaction_details.is_debit', 1)
     ->whereIn('transaction_details.account_id', $ExpenseAccountsIds)
     ->where('transaction.organization_id', org_id())
     ->whereBetween('transaction.date', [$startDate, $endDate])
     ->sum('transaction_details.amount');

     $totalCreditExpense = DB::table('transaction')
     ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
     ->where('transaction_details.is_debit', 0)
     ->whereIn('transaction_details.account_id', $ExpenseAccountsIds)
     ->where('transaction.organization_id', org_id())
     ->whereBetween('transaction.date', [$startDate, $endDate])
     ->sum('transaction_details.amount');

     $totalExpense = $totalDebitExpense - $totalCreditExpense ;


    return number_format($totalExpense, 2, '.', ',');
}
function getTotalIncomeExpenseByMonth($type = 4)
{
    list($startDate, $endDate) = GetDateRange($type);

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

        // Group income transactions by month and calculate total income for each month
        $incomeByMonth = $incomeTransactions->groupBy(function ($transaction) {
            return \Carbon\Carbon::parse($transaction->date)->format('Y-m'); // grouping by year and month
        })->map(function ($group) {
            return $group->reduce(function ($carry, $transaction) {
                return $carry + ($transaction->is_debit ? -$transaction->amount : $transaction->amount);
            }, 0);
        });

        // Group expense transactions by month and calculate total expenses for each month
        $expensesByMonth = $expenseTransactions->groupBy(function ($transaction) {
            return \Carbon\Carbon::parse($transaction->date)->format('Y-m'); // grouping by year and month
        })->map(function ($group) {
            return $group->reduce(function ($carry, $transaction) {
                return $carry + ($transaction->is_debit ? $transaction->amount : -$transaction->amount);
            }, 0);
        });
     // Prepare data for chart
     $months = $incomeByMonth->keys()->union($expensesByMonth->keys())->sort();
     $incomeData = $months->map(function ($month) use ($incomeByMonth) {
         return $incomeByMonth->get($month, 0);
     });
     $expensesData = $months->map(function ($month) use ($expensesByMonth) {
         return $expensesByMonth->get($month, 0);
     });
        return [
            'months' => $months->values()->toArray(),
        'incomeData' => $incomeData->values()->toArray(),
        'expensesData' => $expensesData->values()->toArray(),
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];

}
function GetTotalReceivableAndPayable()
{
//     sales invoice
     $totalInvoices = \App\SalesInvoice::where('organization_id', org_id())
         ->whereIn('status', [4,7])
//         ->whereBetween('invoice_date', [$startDate, $endDate])
         ->where('invoice_date', '<=', dateOfToday())
         ->select(DB::raw('SUM(amount_due ) as total_cost'))
         ->pluck('total_cost')
         ->first();
//     purchase invoice
     $totalBills = \App\PurchaseInvoice::where('organization_id', org_id())
         ->whereIn('status', [4,7])
//         ->whereBetween('invoice_date', [$startDate, $endDate])
         ->where('invoice_date', '<=', dateOfToday())
         ->select(DB::raw('SUM(amount_due ) as total_cost'))
         ->pluck('total_cost')
         ->first();



     return [
         'totalUnpaidInvoice' => number_format($totalInvoices, 2, '.', ','),
         'totalUnpaidBill' => number_format($totalBills, 2, '.', ',')
     ];
}
function GetNetValue($account_id){
    $today = dateOfToday();
    $transactions = \App\TransactionDetails::with('transaction')
        ->where('account_id', $account_id)
        ->whereHas('transaction', function ($query) use ($today) {
            $query->where('date','<=', $today);
            $query->where('organization_id', org_id());
        })
        ->select(
            DB::raw('SUM(CASE WHEN transaction_details.is_debit = 1 THEN transaction_details.amount ELSE 0 END) as total_debit'),
            DB::raw('SUM(CASE WHEN transaction_details.is_debit = 0 THEN transaction_details.amount ELSE 0 END) as total_credit')
        )
        ->first();

    $total_debit = $transactions->total_debit ?? 0;
    $total_credit = $transactions->total_credit ?? 0;

    $net_value = $total_debit - $total_credit;

    return $net_value;
}
 function GetTotalCash()
{
//    list($startDate, $endDate) = GetDateRange($type);

//    $IncomeOpeningBalance =  DB::table('transaction')
//         ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
//         ->where('transaction_details.is_debit', 1)
//         ->whereIn('transaction_details.account_id', GetPaymentAccountIds())
//         ->where('transaction.organization_id', org_id())
//        ->where('transaction.date', '<' ,$startDate)
//         ->sum('transaction_details.amount');
//
//     $totalIncomeAccount =  DB::table('transaction')
//         ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
//         ->where('transaction_details.is_debit', 1)
//         ->whereIn('transaction_details.account_id', GetPaymentAccountIds())
//         ->where('transaction.organization_id', org_id())
//         ->whereBetween('transaction.date', [$startDate, $endDate])
//         ->sum('transaction_details.amount');
//
//    $OutgoingOpeningBalance = DB::table('transaction')
//        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
//        ->where('transaction_details.is_debit', 0)
//        ->whereIn('transaction_details.account_id', GetPaymentAccountIds())
//        ->where('transaction.organization_id', org_id())
//        ->where('transaction.date', '<' ,$startDate)
//        ->sum('transaction_details.amount');

//     $totalOutgoingAccount =  DB::table('transaction')
//         ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
//         ->where('transaction_details.is_debit', 0)
//         ->whereIn('transaction_details.account_id', GetPaymentAccountIds())
//         ->where('transaction.organization_id', org_id())
//         ->whereBetween('transaction.date', [$startDate, $endDate])
//         ->sum('transaction_details.amount');

//     $openingBalance = $IncomeOpeningBalance - $OutgoingOpeningBalance;

     $today = dateOfToday();

     $totalDebit =  DB::table('transaction')
         ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
         ->where('transaction_details.is_debit', 1)
         ->whereIn('transaction_details.account_id', GetPaymentAccountIds())
         ->where('transaction.organization_id', org_id())
         ->where('transaction.date', '<=', $today)
         ->sum('transaction_details.amount');

     $totalCredit =  DB::table('transaction')
         ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
         ->where('transaction_details.is_debit', 0)
         ->whereIn('transaction_details.account_id', GetPaymentAccountIds())
         ->where('transaction.organization_id', org_id())
         ->where('transaction.date', '<=', $today)
         ->sum('transaction_details.amount');

     $total_debit = $totalDebit ?? 0;
     $total_credit = $totalCredit ?? 0;

     $net_value = $total_debit - $total_credit;

    return $net_value;

}

function SalesInvoice(){
    $invoices = \App\SalesInvoice::where('organization_id', org_id())
        ->whereIn('status', [4,7])
        ->get();
    return $invoices;
}

function PurchaseInvoice(){
    $invoices = \App\PurchaseInvoice::where('organization_id', org_id())
        ->whereIn('status', [4,7])
        ->get();
    return $invoices;
}

function AccountReport($id, $startDate, $endDate){
    $childAccountIds = DB::table('chart_of_account')
        ->where('parent_account_id', $id)
        ->pluck('id')
        ->toArray();

    $accountIds = array_merge([$id], $childAccountIds);

    $record = ChartOfAccounts::where('organization_id', org_id())
        ->where('id', $id)
        ->where('created_at', '<', $startDate)
        ->first();

    if ($record) {
        $created_at = $record->created_at;
        // Now you can format or use $created_at as needed
    } else {
        // Handle the case where no record is found
        $created_at = null;
    }

    $debitAmount = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereIn('transaction_details.account_id', $accountIds)
        ->where('transaction.date', '<', $startDate)
        ->where('transaction_details.is_debit', 1)
        ->sum('transaction_details.amount');

    $creditAmount = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereIn('transaction_details.account_id', $accountIds)
        ->where('transaction.date', '<', $startDate)
        ->where('transaction_details.is_debit', 0)
        ->sum('transaction_details.amount');

    $accountTransactions = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->leftJoin('payment_received', 'transaction.payment_id', '=', 'payment_received.id')
        ->leftJoin('transaction_invoice', 'transaction.id', '=', 'transaction_invoice.transaction_id')
        ->leftJoin('transaction_project', 'transaction.id', '=', 'transaction_project.transaction_id')
        ->leftJoin('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
        ->leftJoin('transaction_asset', 'transaction.id', '=', 'transaction_asset.transaction_id')
        ->leftJoin('transaction_vat', 'transaction.id', '=', 'transaction_vat.transaction_id')
        ->leftJoin('project', 'transaction_project.project_id', '=', 'project.id')
        ->leftJoin('fixed_asset', 'transaction_asset.asset_id', '=', 'fixed_asset.id')
        ->leftJoin('expense', 'transaction_expense.expense_id', '=', 'expense.id')
        ->leftJoin('sales_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'sales_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 3);
        })
        ->leftJoin('credit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'credit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 6);
        })
        ->leftJoin('purchase_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'purchase_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 4);
        })
        ->leftJoin('debit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'debit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 7);
        })
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereIn('transaction_details.account_id', $accountIds)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            'transaction.date',
            'transaction.id as transaction_real_id',
            'transaction_details.amount',
            'transaction_details.is_debit',
            'transaction.reference_number',
            'transaction.payment_id',
            'transaction.internal_note',
            'transaction.transaction_type_id',
            'chart_of_account.name as accountName',
            'chart_of_account.type_id as accountTypeId',
            'project.id as project_id',
            'expense.id as expense_id',
            'fixed_asset.id as asset_id',
            'payment_received.paid_by_id as partner_id',
            'payment_received.id as payment_id',
            'transaction_invoice.invoice_type_id as invoice_type',
            DB::raw(
                'COALESCE(transaction_details.description, transaction.description) AS description '),
            DB::raw('CASE 
            WHEN transaction_invoice.transaction_id IS NOT NULL THEN "invoice" 
            WHEN transaction_project.transaction_id IS NOT NULL THEN "project"
            WHEN transaction_expense.transaction_id IS NOT NULL THEN "expense"
            WHEN transaction_asset.transaction_id IS NOT NULL THEN "asset"
            WHEN transaction_vat.transaction_id IS NOT NULL THEN "vat"
            ELSE "Unknown"
        END AS transaction_type'),
            DB::raw('
            COALESCE(
                sales_invoice.invoice_number,
                credit_note.invoice_number,
                purchase_invoice.invoice_number,
                debit_note.invoice_number,
                expense.reference,
                fixed_asset.reference_number,
                project.code,
                payment_received.reference_number
            ) AS t_number'
            ),
            DB::raw('
            COALESCE(
                sales_invoice.id ,
                purchase_invoice.id ,
                credit_note.id ,
                debit_note.id   
            ) AS invoice_id'
            ),
            DB::raw('
            COALESCE(
                sales_invoice.partner_id ,
                purchase_invoice.partner_id ,
                credit_note.partner_id ,
                debit_note.partner_id   
            ) AS inv_partner_id'
            )
        )
        ->get();
    return [
        'created_at' => $created_at,
        'creditAmount' => $creditAmount,
        'debitAmount' => $debitAmount,
        'accountTransactions' => $accountTransactions
    ];
}
function SaleVatReport( $startDate, $endDate){
    $accountId = GetOutputVATAccount();
//transaction that have at 0
    $nonVatCredit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.is_debit', 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->select(
            DB::raw('SUM(transaction.non_taxable_amount) as total_non_taxable_amount')
        )
        ->first();
    $nonVatCreditTaxableAmount = $nonVatCredit->total_non_taxable_amount;

    $nonVatDebit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.is_debit', 1)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->select(
            DB::raw('SUM(transaction.non_taxable_amount) as total_non_taxable_amount')
        )
        ->first();
    $nonVatDebitTaxableAmount = $nonVatDebit->total_non_taxable_amount;


    $vatCredit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.amount', '>' , 0)
        ->where('transaction_details.is_debit', 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            DB::raw('SUM(transaction_details.amount) as total_amount'),
            DB::raw('SUM(transaction.taxable_amount) as total_taxable_amount')
        )
        ->first();


    $vatCreditAmount = $vatCredit->total_amount;
    $vatCreditTaxableAmount = $vatCredit->total_taxable_amount;

    $vatDebit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.amount', '>' , 0)
        ->where('transaction_details.is_debit', 1)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            DB::raw('SUM(transaction_details.amount) as total_amount'),
            DB::raw('SUM(transaction.taxable_amount) as total_taxable_amount')
        )
        ->first();

    $vatDebitAmount = $vatDebit->total_amount;
    $vatDebitTaxableAmount = $vatDebit->total_taxable_amount;


    return [
        'vatAmount' =>  $vatCreditAmount - $vatDebitAmount  ,
        'nonVatAmount' => 0  ,
        'nonVatTaxableAmount' => $nonVatCreditTaxableAmount - $nonVatDebitTaxableAmount ,
        'VatTaxableAmount' => $vatCreditTaxableAmount - $vatDebitTaxableAmount
    ];
}
function SaleVatTransaction( $startDate, $endDate){
    $accountId = GetOutputVATAccount();
    $vatTransaction = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->leftJoin('transaction_invoice', 'transaction.id', '=', 'transaction_invoice.transaction_id')
        ->leftJoin('transaction_project', 'transaction.id', '=', 'transaction_project.transaction_id')
        ->leftJoin('project', 'transaction_project.project_id', '=', 'project.id')
        ->leftJoin('sales_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'sales_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 3);
        })
        ->leftJoin('credit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'credit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 6);
        })
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.amount', '>' , 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            'transaction.date',
            'transaction_details.amount as vat_amount',
            'transaction_details.is_debit',
            'transaction.reference_number',
            'transaction.internal_note',
            'transaction.transaction_type_id',
            'transaction.taxable_amount',
            'transaction.non_taxable_amount',
            'chart_of_account.name as accountName',
            'project.id as project_id',
            'transaction_invoice.invoice_type_id as invoice_type',
            DB::raw(
                'COALESCE(transaction_details.description, transaction.description) AS description '),
            DB::raw('CASE 
            WHEN transaction_invoice.transaction_id IS NOT NULL THEN "invoice" 
            WHEN transaction_project.transaction_id IS NOT NULL THEN "project"
            ELSE "Unknown"
        END AS transaction_type'),
            DB::raw('
            COALESCE(
                sales_invoice.invoice_number,
                credit_note.invoice_number,
                project.code
            ) AS t_number'
            ),
            DB::raw('
            COALESCE(
                sales_invoice.id ,
                credit_note.id 
            ) AS invoice_id'
            )
        )
        ->get();

    $nonVatTransaction = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->leftJoin('transaction_invoice', 'transaction.id', '=', 'transaction_invoice.transaction_id')
        ->leftJoin('transaction_project', 'transaction.id', '=', 'transaction_project.transaction_id')
        ->leftJoin('project', 'transaction_project.project_id', '=', 'project.id')
        ->leftJoin('sales_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'sales_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 3);
        })
        ->leftJoin('credit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'credit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 6);
        })
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction.non_taxable_amount','>', 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            'transaction.date',
            'transaction_details.amount as vat_amount',
            'transaction_details.is_debit',
            'transaction.reference_number',
            'transaction.internal_note',
            'transaction.transaction_type_id',
            'transaction.taxable_amount',
            'transaction.non_taxable_amount',
            'chart_of_account.name as accountName',
            'project.id as project_id',
            'transaction_invoice.invoice_type_id as invoice_type',
            DB::raw(
                'COALESCE(transaction_details.description, transaction.description) AS description '),
            DB::raw('CASE 
            WHEN transaction_invoice.transaction_id IS NOT NULL THEN "invoice" 
            WHEN transaction_project.transaction_id IS NOT NULL THEN "project"
            ELSE "Unknown"
        END AS transaction_type'),
            DB::raw('
            COALESCE(
                sales_invoice.invoice_number,
                credit_note.invoice_number,
                project.code
            ) AS t_number'
            ),
            DB::raw('
            COALESCE(
                sales_invoice.id ,
                credit_note.id 
            ) AS invoice_id'
            )
        )
        ->get();
    return [
        'vatTransactions' => $vatTransaction,
        'nonVatTransactions' => $nonVatTransaction
    ];
}
function PurchaseVatReport( $startDate, $endDate){
    $accountId = GetInputVATAccount();

    $nonVatCredit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.is_debit', 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->select(
            DB::raw('SUM(transaction.non_taxable_amount) as total_non_taxable_amount')
        )
        ->first();
    $nonVatCreditTaxableAmount = $nonVatCredit->total_non_taxable_amount;

    $nonVatDebit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.is_debit', 1)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->select(
            DB::raw('SUM(transaction.non_taxable_amount) as total_non_taxable_amount')
        )
        ->first();

    $nonVatDebitTaxableAmount = $nonVatDebit->total_non_taxable_amount;

    $vatCredit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.amount', '>' , 0)
        ->where('transaction_details.is_debit', 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            DB::raw('SUM(transaction_details.amount) as total_amount'),
            DB::raw('SUM(transaction.taxable_amount) as total_taxable_amount')
        )
        ->first();

    $vatCreditAmount = $vatCredit->total_amount;
    $vatCreditTaxableAmount = $vatCredit->total_taxable_amount;

    $vatDebit = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.amount', '>' , 0)
        ->where('transaction_details.is_debit', 1)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            DB::raw('SUM(transaction_details.amount) as total_amount'),
            DB::raw('SUM(transaction.taxable_amount) as total_taxable_amount')
        )
        ->first();

    $vatDebitAmount = $vatDebit->total_amount;
    $vatDebitTaxableAmount = $vatDebit->total_taxable_amount;

    return [
        'vatAmount' => $vatDebitAmount - $vatCreditAmount,
        'nonVatAmount' => 0,
        'nonVatTaxableAmount' => $nonVatDebitTaxableAmount - $nonVatCreditTaxableAmount,
        'VatTaxableAmount' => $vatDebitTaxableAmount - $vatCreditTaxableAmount
    ];
}
function PurchaseVatTransaction( $startDate, $endDate){
    $accountId = GetInputVATAccount();
    $vatTransaction = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->leftJoin('transaction_invoice', 'transaction.id', '=', 'transaction_invoice.transaction_id')
        ->leftJoin('transaction_project', 'transaction.id', '=', 'transaction_project.transaction_id')
        ->leftJoin('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
        ->leftJoin('transaction_asset', 'transaction.id', '=', 'transaction_asset.transaction_id')
        ->leftJoin('project', 'transaction_project.project_id', '=', 'project.id')
        ->leftJoin('fixed_asset', 'transaction_asset.asset_id', '=', 'fixed_asset.id')
        ->leftJoin('expense', 'transaction_expense.expense_id', '=', 'expense.id')
        ->leftJoin('purchase_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'purchase_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 4);
        })
        ->leftJoin('debit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'debit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 7);
        })
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction_details.amount', '>' , 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            'transaction.date',
            'transaction_details.amount as vat_amount',
            'transaction.taxable_amount',
            'transaction.non_taxable_amount',
            'transaction_details.is_debit',
            'transaction.reference_number',
            'transaction.internal_note',
            'transaction.transaction_type_id',
            'chart_of_account.name as accountName',
            'project.id as project_id',
            'expense.id as expense_id',
            'fixed_asset.id as asset_id',
            'transaction_invoice.invoice_type_id as invoice_type',
            DB::raw(
                'COALESCE(transaction_details.description, transaction.description) AS description '),
            DB::raw('CASE 
            WHEN transaction_invoice.transaction_id IS NOT NULL THEN "invoice" 
            WHEN transaction_project.transaction_id IS NOT NULL THEN "project"
            WHEN transaction_expense.transaction_id IS NOT NULL THEN "expense"
            WHEN transaction_asset.transaction_id IS NOT NULL THEN "asset"
            ELSE "Unknown"
        END AS transaction_type'),
            DB::raw('
            COALESCE(
                purchase_invoice.invoice_number,
                debit_note.invoice_number,
                expense.reference,
                fixed_asset.reference_number,
                project.code
            ) AS t_number'
            ),
            DB::raw('
            COALESCE(
                 purchase_invoice.id ,
                debit_note.id   
            ) AS invoice_id'
            )
        )
        ->get();

    $nonVatTransaction = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->leftJoin('transaction_invoice', 'transaction.id', '=', 'transaction_invoice.transaction_id')
        ->leftJoin('transaction_project', 'transaction.id', '=', 'transaction_project.transaction_id')
        ->leftJoin('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
        ->leftJoin('transaction_asset', 'transaction.id', '=', 'transaction_asset.transaction_id')
        ->leftJoin('project', 'transaction_project.project_id', '=', 'project.id')
        ->leftJoin('fixed_asset', 'transaction_asset.asset_id', '=', 'fixed_asset.id')
        ->leftJoin('expense', 'transaction_expense.expense_id', '=', 'expense.id')
        ->leftJoin('purchase_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'purchase_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 4);
        })
        ->leftJoin('debit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'debit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 7);
        })
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->where('transaction_details.account_id', $accountId)
        ->where('transaction.non_taxable_amount','>', 0)
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            'transaction.date',
            'transaction.taxable_amount',
            'transaction.non_taxable_amount',
            'transaction_details.amount as vat_amount',
            'transaction_details.is_debit',
            'transaction.reference_number',
            'transaction.internal_note',
            'transaction.transaction_type_id',
            'chart_of_account.name as accountName',
            'project.id as project_id',
            'expense.id as expense_id',
            'fixed_asset.id as asset_id',
            'transaction_invoice.invoice_type_id as invoice_type',
            DB::raw(
                'COALESCE(transaction_details.description, transaction.description) AS description '),
            DB::raw('CASE 
            WHEN transaction_invoice.transaction_id IS NOT NULL THEN "invoice" 
            WHEN transaction_project.transaction_id IS NOT NULL THEN "project"
            WHEN transaction_expense.transaction_id IS NOT NULL THEN "expense"
            WHEN transaction_asset.transaction_id IS NOT NULL THEN "asset"
            ELSE "Unknown"
        END AS transaction_type'),
            DB::raw('
            COALESCE(
                purchase_invoice.invoice_number,
                debit_note.invoice_number,
                expense.reference,
                fixed_asset.reference_number,
                project.code
            ) AS t_number'
            ),
            DB::raw('
            COALESCE(
                purchase_invoice.id ,
                debit_note.id   
            ) AS invoice_id'
            )
        )
        ->get();


    return [
        'vatTransactions' => $vatTransaction,
        'nonVatTransactions' => $nonVatTransaction
    ];
}
function VatAudit( $startDate, $endDate){
    $purchaseVat = GetInputVATAccount();
    $salesVat = GetOutputVATAccount();
    $vatTransaction = DB::table('transaction')
        ->join('transaction_details', 'transaction.id', '=', 'transaction_details.transaction_id')
        ->join('chart_of_account', 'chart_of_account.id', '=', 'transaction_details.account_id')
        ->leftJoin('transaction_invoice', 'transaction.id', '=', 'transaction_invoice.transaction_id')
        ->leftJoin('transaction_project', 'transaction.id', '=', 'transaction_project.transaction_id')
        ->leftJoin('transaction_expense', 'transaction.id', '=', 'transaction_expense.transaction_id')
        ->leftJoin('transaction_asset', 'transaction.id', '=', 'transaction_asset.transaction_id')
        ->leftJoin('project', 'transaction_project.project_id', '=', 'project.id')
        ->leftJoin('fixed_asset', 'transaction_asset.asset_id', '=', 'fixed_asset.id')
        ->leftJoin('expense', 'transaction_expense.expense_id', '=', 'expense.id')
        ->leftJoin('sales_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'sales_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 3);
        })
        ->leftJoin('credit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'credit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 6);
        })
        ->leftJoin('purchase_invoice', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'purchase_invoice.id')
                ->where('transaction_invoice.invoice_type_id', '=', 4);
        })
        ->leftJoin('debit_note', function ($join) {
            $join->on('transaction_invoice.invoice_id', '=', 'debit_note.id')
                ->where('transaction_invoice.invoice_type_id', '=', 7);
        })
        ->where('chart_of_account.organization_id', org_id())
        ->where('transaction.organization_id', org_id())
        ->whereNotIn('transaction.transaction_type_id', [22,23,24,25])
        ->whereIn('transaction_details.account_id', [$purchaseVat, $salesVat])
        ->whereBetween('transaction.date', [$startDate, $endDate])
        ->orderBy('transaction.date', 'ASC')
        ->select(
            'transaction.date',
            'transaction_details.amount as vat_amount',
            'transaction.taxable_amount',
            'transaction.non_taxable_amount',
            'transaction_details.is_debit',
            'transaction.reference_number',
            'transaction.internal_note',
            'transaction.transaction_type_id',
            'chart_of_account.name as accountName',
            'project.id as project_id',
            'expense.id as expense_id',
            'fixed_asset.id as asset_id',
            'transaction_invoice.invoice_type_id as invoice_type',
            DB::raw(
                'COALESCE(transaction_details.description, transaction.description) AS description '),
            DB::raw('CASE 
            WHEN transaction_invoice.transaction_id IS NOT NULL THEN "invoice" 
            WHEN transaction_project.transaction_id IS NOT NULL THEN "project"
            WHEN transaction_expense.transaction_id IS NOT NULL THEN "expense"
            WHEN transaction_asset.transaction_id IS NOT NULL THEN "asset"
            ELSE "Unknown"
        END AS transaction_type'),
            DB::raw('
            COALESCE(
                sales_invoice.invoice_number,
                credit_note.invoice_number,
                purchase_invoice.invoice_number,
                debit_note.invoice_number,
                expense.reference,
                fixed_asset.reference_number,
                project.code
            ) AS t_number'
            ),
            DB::raw('
            COALESCE(
                sales_invoice.id ,
                credit_note.id ,
                 purchase_invoice.id ,
                debit_note.id   
            ) AS invoice_id'
            ),
            DB::raw('
            COALESCE(
                sales_invoice.partner_id ,
                credit_note.partner_id ,
                 purchase_invoice.partner_id ,
                debit_note.partner_id,
                project.receivable_id,   
                expense.customer_id   
            ) AS partner_id'
            )
        )
        ->get();

    return [
        'vatTransactions' => $vatTransaction,
    ];
}
function getInvoiceModel($id, $invoice_type_id)
{
    $models = [
        1 => Quotation::class,
        2 => SalesOrder::class,
        3 => SalesInvoice::class,
        4 => PurchaseInvoice::class,
        5 => PurchaseOrder::class,
        6 => CreditNote::class,
        7 => DebitNote::class,
    ];

    if (!isset($models[$invoice_type_id])) {
        throw new InvalidArgumentException("Invalid invoice type ID: $invoice_type_id");
    }

    return $models[$invoice_type_id]::findOrFail($id);
}

function getStatusOfInv($id, $invoice_type_id)
{
    $invoice = getInvoiceModel($id, $invoice_type_id);
    return $invoice->status;
}

function updateStatusOfInv($id, $invoice_type_id)
{
    $invoice = getInvoiceModel($id, $invoice_type_id);
    $invoice->status = 11;
    $invoice->update();
}


function uploadFile($file, $folderName){
    $fileName = time() . '_' . $file->getClientOriginalName();
    $destinationPath = public_path('system/'.$folderName);
    $file->move($destinationPath, $fileName);
    $filePathName = '/'.$folderName.'/' . $fileName;
    return $filePathName;
}

function dateOfToday(){
    return \Carbon\Carbon::now()->toDateString();
}

function active_class($path, $active = 'active') {
  return call_user_func_array('Request::is', (array)$path) ? $active : '';
}

function is_active_route($path) {
  return call_user_func_array('Request::is', (array)$path) ? 'true' : 'false';
}

function show_class($path) {
  return call_user_func_array('Request::is', (array)$path) ? 'show' : '';
}
