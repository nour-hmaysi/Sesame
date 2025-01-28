<?php

namespace App\Http\Controllers;

use App\AssetType;
use App\ChartOfAccounts;
use App\Asset;
use App\DepreciationRecord;
use App\DepreciationType;
use App\ExpenseDocuments;
use App\Partner;
use App\Transaction;
use App\TransactionAsset;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{

    public function store(Request $request)
    {

        $rules = [
            'asset_type' => 'required',
            'asset_account_id' => 'required',
            'dep_val_type' => 'required|in:1,2',
            'depreciation_value' => 'nullable|required_if:dep_val_type,1|numeric|min:0',
            'lifetime' => 'nullable|required_if:dep_val_type,2|numeric|min:1',
            'paid_through' => 'required|in:1,2',
            'payment_account_id' => 'nullable|required_if:paid_through,1',
            'payable_id' => 'nullable|required_if:paid_through,2',
            'depreciation_account_id' => 'required',
        ];

        // Custom error messages
        $messages = [
            'asset_type.required' => 'Asset type is required.',
            'asset_account_id.required' => 'Category is required.',
            'depreciation_value.required_if' => 'Depreciation value is required.',
            'lifetime.required_if' => 'Lifetime mis required.',
            'payment_account_id.required_if' => 'Payment account is required when paid through bank.',
            'payable_id.required_if' => 'Supplier account is required when paid through supplier.',
            'depreciation_account_id.required' => 'Depreciation account is required.',
        ];
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, $messages);
        $referenceExist = checkReferenceExists($request->input('reference_number'));

        if ($referenceExist) {
            return redirect()->back()->withErrors([
                'error' => __('messages.reference_exists')
            ])->withInput();

        }

        $assetData = $request->all();
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $fileName = uploadFile($file, 'assets');
            $assetData['receipt'] = $fileName;
        }

        if ( validateVATDate($request->input('date')) ) {
            return redirect()->back()->withInput()->with('warning', errorMsg());
        }

        $depreciationValue = $request->input('depreciation_value');
        $lifetime = $request->input('lifetime');
        $depValType = $request->input('dep_val_type');

//        if ($depValType == 1 && (empty($depreciationValue) || $depreciationValue <= 0)) {
//            $validator->errors()->add('depreciation_value', 'Depreciation value must be greater than 0.');
//        } elseif ($depValType == 2 && (empty($lifetime) || $lifetime <= 0)) {
//            $validator->errors()->add('lifetime', 'Lifetime must be greater than 0.');
//        }

        if (empty($depreciationValue) || $depreciationValue == 0) {
            if ($lifetime == 0) {
//                return redirect()->back()->with('warning', 'Lifetime/Depreciation % cannot be zero.');
//                $validator->errors()->add('lifetime', 'Lifetime/Depreciation % cannot be zero.');

            }else{
                $depreciationValue = 100 / $lifetime;
                $assetData['depreciation_value'] = $depreciationValue;
            }
        }else{
            $lifetime = 100 / $depreciationValue;
            $assetData['lifetime'] = $lifetime;
        }



        $assetAccount = $request->input('asset_account_id');

//        if ($request->input('paid_through') == 1 && !$request->has('payment_account_id')) {
//            $validator->errors()->add('payment_account_id', 'Payment account is required.');
//        }
        if($request->input('paid_through') == 1){
            $paymentAccount = $request->input('payment_account_id');

        }else{
            $paymentAccount = GetPayableAccount();
        }
        // Validate depreciation account
//        if (!$request->has('depreciation_account_id')) {
//            return var_dump($validator->fails());
//            $validator->errors()->add('depreciation_account_id', 'Depreciation account is required.');
//        }
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cost = $request->input('cost');//cost of single asset
        $is_vat = $request->input('is_vat');
        if($is_vat == 1){
//            inclusive
            $netCost = round($cost / (1 + (TaxValue()/100)), 2);// price without vat
            $vat_amount = round($netCost * (TaxValue() / 100), 2);
        }else if($is_vat == 0){
            $netCost = $cost;// price without vat
            $vat_amount = round($netCost * (TaxValue() / 100), 2);
        }else{
             $netCost = $cost;// price without vat
             $vat_amount = 0;
        }
        $assetData['net_cost'] = $netCost;
        $assetData['vat_amount'] = $vat_amount;
        $salvageValue = $request->input('salvage_value');
        $unit = $request->input('unit');
        $currentDate = Carbon::parse($assetData['date']);

        if($assetData['repetitive'] == 1){
//            yearly
            $assetData['dep_date'] = $currentDate->copy()->addYear();
            $assetData['end_date'] = $currentDate->copy()->addYears($lifetime);
        }else{
//            monthly
            $assetData['dep_date'] = $currentDate->copy()->addMonth();
            $assetData['end_date'] = $currentDate->copy()->addYears($lifetime);
        }



        if($assetData['depreciation_type'] == 1){
//            straight
            $depAmount = (($unit*$netCost) - ($unit*$salvageValue))/$lifetime;
        }else{
            $depAmount = ($unit*$netCost)*(2*$depreciationValue/100);
        }
        if($assetData['repetitive'] == 2){
//            monthly
            $depAmount = $depAmount/12;
        }
        $assetData['book_value'] = ($unit*($netCost - $salvageValue)) - $depAmount;



        $asset = Asset::create($assetData);

        $transaction['transaction_type_id'] = 12; //Asset
        $transaction['amount'] =  $unit*$netCost + $unit*$vat_amount;
        $vat_amount > 0 ? $transaction['taxable_amount'] =  $unit*$netCost : $transaction['non_taxable_amount'] =  $unit*$netCost ;;
        $transaction['date'] = $request->input('date');
        $transaction['reference_number'] = $request->input('reference_number');
        $transaction_id = GlobalController::InsertNewTransaction($transaction);
        //      Debit asset
        $debitAcc['transaction_id'] = $transaction_id;
        $debitAcc['amount'] = $unit*$netCost;
        $debitAcc['account_id'] = $assetAccount;
        $debitAcc['is_debit'] = 1;
        GlobalController::InsertNewTransactionDetails($debitAcc);
            //      Debit vat
            $debitVatAcc['transaction_id'] = $transaction_id;
            $debitVatAcc['amount'] = $unit*$vat_amount;
            $debitVatAcc['account_id'] = GetInputVATAccount();
            $debitVatAcc['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($debitVatAcc);

        //      Credit payable/bank
        $creditAcc['transaction_id'] = $transaction_id;
        $creditAcc['amount'] = $unit*$netCost  + $unit*$vat_amount;
        $creditAcc['account_id'] = $paymentAccount;
        $creditAcc['is_debit'] = 0;
        GlobalController::InsertNewTransactionDetails($creditAcc);

        TransactionAsset::create([
            'transaction_id' => $transaction_id,
            'asset_id' => $asset->id
        ]);

        if($assetData['depreciation_type'] == 1){
//            straight
            if($assetData['repetitive'] == 2){
//            monthly
                $this->createMonthlyStraightDepreciationRecords($asset);
            }else{
                $this->createYearlyStraightDepreciationRecords($asset);
            }
        }else{
            if($assetData['repetitive'] == 2){
//            monthly
                $this->createMonthlyDepreciationRecords($asset);
            }else{
                $this->createYearlyDepreciationRecords($asset);
            }
        }
        $comment = $asset->asset_type.' Asset Created';
        GlobalController::InsertNewComment(13, $asset->id,NULL, $comment);

        return redirect()->route('AssetController.index', ['#row-' . $asset->id]);
    }
    private function createYearlyDepreciationRecords(Asset $fixedAsset)
    {

        $purchaseDate = Carbon::parse($fixedAsset->date);
        $reference_number = $fixedAsset->reference_number;
        $startOfYear = $purchaseDate->copy()->startOfYear();
        $endOfYear = $purchaseDate->copy()->endOfYear();
        $usefulLife = $fixedAsset->lifetime;
        $salvageValue = $fixedAsset->salvage_value;
        $bookValue = $fixedAsset->unit * $fixedAsset->net_cost;
        $depreciationRate = 2 / $usefulLife; // Double Declining Balance Rate

        $startOfMonth = $purchaseDate->startOfMonth();
        $isStartOfYear = $startOfMonth->equalTo($startOfYear);
        $monthsBetween = $purchaseDate->diffInMonths($endOfYear) + 1; // Include the purchase month

        $endIndex = $isStartOfYear ? $usefulLife : $usefulLife + 1;

        for ($i = 0; $i < $endIndex; $i++) {

            $currentYearEnd = $endOfYear->copy()->addYears($i);
            $depreciationAmount = $bookValue * $depreciationRate;

            if (!$isStartOfYear && $i == 0 && $monthsBetween > 0) {
                // Adjust depreciation for the first year if not purchased at start of the year
                $depreciationAmount = ($depreciationAmount / 12) * $monthsBetween;
            }

            if ($i == $endIndex - 1) {
                $depreciationAmount = $bookValue - $salvageValue;
//                if (!$isStartOfYear ) {
//                    $monthsFromStartOfYear = $startOfYear->diffInMonths($purchaseDate);
//                    $depreciationAmount = $depreciationAmount / $monthsFromStartOfYear;
//                }

            } else {
                $depreciationAmount = min($depreciationAmount, $bookValue);
            }
            DepreciationRecord::create([
                'asset_id' => $fixedAsset->id,
                'book_value' => $bookValue,
                'depreciation_date' => $currentYearEnd,
                'depreciation_amount' => $depreciationAmount,
            ]);
            $depTransaction['transaction_type_id'] = 19; //depreciation reocrd
            $depTransaction['amount'] =  $depreciationAmount;
            $depTransaction['date'] = $currentYearEnd;
            $depTransaction['reference_number'] = $reference_number;
            $depTransaction_id = GlobalController::InsertNewTransaction($depTransaction);
            //      Debit dep
            $DepdebitAcc['transaction_id'] = $depTransaction_id;
            $DepdebitAcc['amount'] = $depreciationAmount;
            $DepdebitAcc['account_id'] = $fixedAsset->depreciation_account_id;
            $DepdebitAcc['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($DepdebitAcc);
            //      Credit accumulated
            $depCreditAcc['transaction_id'] = $depTransaction_id;
            $depCreditAcc['amount'] = $depreciationAmount;
            $depCreditAcc['account_id'] = GetAccumulatedAccount();
            $depCreditAcc['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($depCreditAcc);

            TransactionAsset::create([
                'transaction_id' => $depTransaction_id,
                'asset_id' => $fixedAsset->id
            ]);
            $bookValue -= $depreciationAmount;
            if ($bookValue <= 0) {
                break;
            }
        }
    }
    private function createMonthlyDepreciationRecords(Asset $fixedAsset)
    {
        $purchaseDate = Carbon::parse($fixedAsset->date);
        $reference_number = $fixedAsset->reference_number;
        $usefulLife = $fixedAsset->lifetime;
        $salvageValue = $fixedAsset->salvage_value;
        $bookValue = $fixedAsset->unit * $fixedAsset->net_cost;
        $depreciationRate = 2 / $usefulLife; // Double Declining Balance Rate

        for ($i = 1; $i <= $usefulLife; $i++) {
            if ($i == $usefulLife) {
                $depreciationAmount = $bookValue - $salvageValue;
            } else {
                $depreciationAmount = $bookValue * $depreciationRate;
                $depreciationAmount = min($depreciationAmount, $bookValue);
            }
            $monthlyDepreciationAmount = $depreciationAmount/12;
            for($m = 0; $m < 12; $m++) {
                $currentMonthEnd = $purchaseDate->copy()->addMonths(($i - 1) * 12 + $m)->endOfMonth();

                DepreciationRecord::create([
                    'asset_id' => $fixedAsset->id,
                    'depreciation_date' => $currentMonthEnd,
                    'book_value' => $bookValue,
                    'depreciation_amount' => $monthlyDepreciationAmount,
                ]);
                $depTransaction['transaction_type_id'] = 19; //depreciation reocrd
                $depTransaction['amount'] =  $monthlyDepreciationAmount;
                $depTransaction['date'] = $currentMonthEnd;
                $depTransaction['reference_number'] = $reference_number;
                $depTransaction_id = GlobalController::InsertNewTransaction($depTransaction);
                //      Debit dep
                $DepdebitAcc['transaction_id'] = $depTransaction_id;
                $DepdebitAcc['amount'] = $monthlyDepreciationAmount;
                $DepdebitAcc['account_id'] = $fixedAsset->depreciation_account_id;
                $DepdebitAcc['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($DepdebitAcc);
                //      Credit accumulated
                $depCreditAcc['transaction_id'] = $depTransaction_id;
                $depCreditAcc['amount'] = $monthlyDepreciationAmount;
                $depCreditAcc['account_id'] = GetAccumulatedAccount();
                $depCreditAcc['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($depCreditAcc);

                TransactionAsset::create([
                    'transaction_id' => $depTransaction_id,
                    'asset_id' => $fixedAsset->id
                ]);
            }
                $bookValue -= $depreciationAmount;
            if ($bookValue <= 0) {
                break;
            }
        }
    }
    private function createYearlyStraightDepreciationRecords(Asset $fixedAsset)
    {
        $purchaseDate = Carbon::parse($fixedAsset->date);
        $startOfYear = $purchaseDate->copy()->startOfYear();
        $endOfYear = $purchaseDate->copy()->endOfYear();
        $usefulLife = $fixedAsset->lifetime;
        $salvageValue = $fixedAsset->unit * $fixedAsset->salvage_value;
        $cost = $fixedAsset->unit * $fixedAsset->net_cost;
        $reference_number = $fixedAsset->reference_number;

        $startOfMonth = $purchaseDate->startOfMonth();
        $isStartOfYear = $startOfMonth->equalTo($startOfYear);
        $monthsBetween = $purchaseDate->diffInMonths($endOfYear) + 1;
        $fullYearDepreciation = ($cost - $salvageValue) / $usefulLife;

        $endIndex = $isStartOfYear ? $usefulLife : $usefulLife + 1;

        for ($i = 0; $i < $endIndex; $i++) {
            $currentYearEnd = $endOfYear->copy()->addYears($i);
            $depreciationAmount = $fullYearDepreciation;

            if (!$isStartOfYear && $i == 0 && $monthsBetween > 0) {
                $depreciationAmount = ($fullYearDepreciation / 12) * $monthsBetween;
            }
            // Adjustment for the last year of depreciation
            if (!$isStartOfYear && $i == $endIndex - 1 ) {
                $monthsFromStartOfYear = $startOfYear->diffInMonths($purchaseDate);
                $depreciationAmount = ($fullYearDepreciation / 12) * $monthsFromStartOfYear;
            }
            DepreciationRecord::create([
                'asset_id' => $fixedAsset->id,
                'depreciation_date' => $currentYearEnd,
                'depreciation_amount' => $depreciationAmount,
            ]);
            $depTransaction['transaction_type_id'] = 19; //depreciation reocrd
            $depTransaction['amount'] =  $depreciationAmount;
            $depTransaction['date'] = $currentYearEnd;
            $depTransaction['reference_number'] = $reference_number;
            $depTransaction_id = GlobalController::InsertNewTransaction($depTransaction);
            //      Debit dep
            $DepdebitAcc['transaction_id'] = $depTransaction_id;
            $DepdebitAcc['amount'] = $depreciationAmount;
            $DepdebitAcc['account_id'] = $fixedAsset->depreciation_account_id;
            $DepdebitAcc['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($DepdebitAcc);
            //      Credit accumulated
            $depCreditAcc['transaction_id'] = $depTransaction_id;
            $depCreditAcc['amount'] = $depreciationAmount;
            $depCreditAcc['account_id'] = GetAccumulatedAccount();
            $depCreditAcc['is_debit'] = 0;
            GlobalController::InsertNewTransactionDetails($depCreditAcc);

            TransactionAsset::create([
                'transaction_id' => $depTransaction_id,
                'asset_id' => $fixedAsset->id
            ]);
        }

    }
    private function createMonthlyStraightDepreciationRecords(Asset $fixedAsset)
    {
        $purchaseDate = Carbon::parse($fixedAsset->date);
//        $purchaseDate = $purchaseDate->endOfMonth();
        $reference_number = $fixedAsset->reference_number;
        $usefulLife = $fixedAsset->lifetime;
        $salvageValue = $fixedAsset->unit * $fixedAsset->salvage_value;
        $cost = $fixedAsset->unit * $fixedAsset->net_cost;

        $depreciationAmount = ($cost - $salvageValue)/$usefulLife;
        $monthlyDepreciationAmount = $depreciationAmount/12;

        $endOfPurchaseMonth = $purchaseDate->copy()->endOfMonth();

        for ($i = 1; $i <= $usefulLife; $i++) {
            for($m = 0; $m < 12; $m++) {
                $currentMonth = $purchaseDate->copy()->addMonths(($i - 1) * 12 + $m)->endOfMonth();

                DepreciationRecord::create([
                    'asset_id' => $fixedAsset->id,
                    'depreciation_date' => $currentMonth,
                    'depreciation_amount' => $monthlyDepreciationAmount,
                ]);
                $depTransaction['transaction_type_id'] = 19; //depreciation reocrd
                $depTransaction['amount'] =  $depreciationAmount;
                $depTransaction['date'] = $currentMonth;
                $depTransaction['reference_number'] = $reference_number;
                $depTransaction_id = GlobalController::InsertNewTransaction($depTransaction);
                //      Debit dep
                $DepdebitAcc['transaction_id'] = $depTransaction_id;
                $DepdebitAcc['amount'] = $depreciationAmount;
                $DepdebitAcc['account_id'] = $fixedAsset->depreciation_account_id;
                $DepdebitAcc['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($DepdebitAcc);
                //      Credit accumulated
                $depCreditAcc['transaction_id'] = $depTransaction_id;
                $depCreditAcc['amount'] = $depreciationAmount;
                $depCreditAcc['account_id'] = GetAccumulatedAccount();
                $depCreditAcc['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($depCreditAcc);

                TransactionAsset::create([
                    'transaction_id' => $depTransaction_id,
                    'asset_id' => $fixedAsset->id
                ]);
            }

        }
    }
    public function create()
    {
        $assetType = AssetType::get();
        $FixedAssetAccounts = FixedAssetAccounts();
        $depreciation_types = DepreciationType::get();
        $depreciationAccounts = DepreciationAccounts();
        $paymentAccounts =  PaymentAccounts();
        $suppliers = Suppliers();
        return view('pages.asset.create', compact(['assetType', 'depreciationAccounts', 'suppliers', 'depreciation_types', 'paymentAccounts', 'FixedAssetAccounts']));
    }
    public function index()
    {
        $assets = Asset::with('assetType', 'paymentAccount', 'account', 'supplier')
            ->where('deleted', 0)
            ->where('organization_id', org_id())
            ->get();
        return view('pages.asset.index', compact('assets'));
    }
    public function deleteAsset($id)
    {
        $asset = Asset::findOrFail($id);

        if ( validateVATDate($asset->date) ) {
            return response()->json(['status'=> 'warning', 'message'=> errorMsg()]);
        }

        $transactions = Transaction::with(['TransactionAsset', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->whereIn('transaction_type_id', [12, 19]) //asset and dep
            ->whereHas('TransactionAsset', function ($query) use ($id) {
                $query->where('asset_id', $id);
            })
            ->get();
        $transactions->each(function ($transaction) {
            if ($transaction->TransactionDetails) {
                $transaction->TransactionDetails->each(function ($detail) {
                    $detail->delete();
                });
            }
            if ($transaction->TransactionAsset) {
                $transaction->TransactionAsset->each(function ($asset) {
                    $asset->delete();
                });
            }
            $transaction->delete();
        });
        $asset->delete();

        return response()->json(['status' => 'success',
            'message'=>'Asset deleted']);
    }
    public function edit($encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $asset = Asset::findOrFail($id);
        $assetType = AssetType::get();

        $FixedAssetAccounts = FixedAssetAccounts();
        $depreciation_types = DepreciationType::get();
        $depreciationAccounts = DepreciationAccounts();
        $paymentAccounts =  PaymentAccounts();
        $suppliers = Suppliers();
        return view('pages.asset.edit', compact(['asset','assetType', 'depreciationAccounts','FixedAssetAccounts', 'suppliers', 'depreciation_types', 'paymentAccounts']));

    }
    public function update(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $asset = Asset::findOrFail($id);
        $assetData = $request->all();

        if ( validateVATDate($asset->date) || validateVATDate($request->date) ) {
            return redirect()->back()->withInput()->with('warning', errorMsg());
        }

        $currentRecord = Asset::findOrFail($id);
        $referenceNumber = $request->input('reference_number');
        if ($referenceNumber !== $currentRecord->reference_number) {
            // Check if the new reference exists in other records
            $referenceExist = checkReferenceExists($referenceNumber);

            if ($referenceExist) {
                // Redirect back with error if reference exists in other records
                return redirect()->back()->withErrors([
                    'reference_number' => __('messages.reference_exists'),
                ])->withInput();
            }
        }

        $lifetime = $request->input('lifetime');
        if ($lifetime == 0) {
            return redirect()->back()->with('warning', 'Lifetime cannot be zero.');
        }
        $dep_value = 100 / $lifetime;
        $assetData['depreciation_value'] = $dep_value;

        // Handle the new receipt file upload
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $fileName = uploadFile($file, 'assets');
            $assetData['receipt'] = $fileName;
        }
//        delete old transaction
        $transactions = Transaction::with(['TransactionAsset', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->whereIn('transaction_type_id', [12, 19]) //asset and dep record
            ->whereHas('TransactionAsset', function ($query) use ($id) {
                $query->where('asset_id', $id);
            })
            ->get();
        DepreciationRecord::where('organization_id', org_id())
            ->where('asset_id', $id)
            ->delete();
        $transactions->each(function ($transaction) {
            if ($transaction->TransactionDetails) {
                $transaction->TransactionDetails->each(function ($detail) {
                    $detail->delete();
                });
            }
            if ($transaction->TransactionAsset) {
                $transaction->TransactionAsset->each(function ($assets) {
                    $assets->delete();
                });
            }
            $transaction->delete();
        });

        $cost = $request->input('cost');//cost of single asset
        $salvageValue = $request->input('salvage_value');
        $unit = $request->input('unit');
        $is_vat = $request->input('is_vat');
        if($is_vat == 1){
//            inclusive
            $netCost = round($cost / (1 + (TaxValue()/100)), 2);// price without vat
            $vat_amount = round($netCost * (TaxValue() / 100), 2);
        }else if($is_vat == 0){
            $netCost = $cost;// price without vat
            $vat_amount = round($netCost * (TaxValue() / 100), 2);
        }else{
            $netCost = $cost;// price without vat
            $vat_amount = 0;
        }
        $assetData['net_cost'] = $netCost;
        $assetData['vat_amount'] = $vat_amount;
        $currentDate = Carbon::parse($assetData['date']);

        if($asset->repetitive == 1){
//            yearly
            $assetData['dep_date'] = $currentDate->copy()->addYear();
            $assetData['end_date'] = $currentDate->copy()->addYears($lifetime);
        }else{
//            monthly
            $assetData['dep_date'] = $currentDate->copy()->addMonth();
            $assetData['end_date'] = $currentDate->copy()->addYears($lifetime);
        }
        $assetData['book_value'] = $unit*$netCost;

        $asset->update($assetData);

        if($request->input('paid_through') == 1){
            $paymentAccount = $request->input('payment_account_id');
            if($paymentAccount == NULL){
                return redirect()->back()->with('warning', 'Payment account is required.');
            }
        }else{
            $paymentAccount = GetPayableAccount();
        }
        $assetAccount = $request->input('asset_account_id');

        $transaction['transaction_type_id'] = 12; //Asset
        $transaction['amount'] =  $unit*$netCost + $unit*$vat_amount;
        $vat_amount > 0 ? $transaction['taxable_amount'] =  $unit*$netCost : $transaction['non_taxable_amount'] =  $unit*$netCost ;;
        $transaction['date'] = $request->input('date');
        $transaction['reference_number'] = $referenceNumber;
        $transaction_id = GlobalController::InsertNewTransaction($transaction);
        //      Debit asset
        $debitAcc['transaction_id'] = $transaction_id;
        $debitAcc['amount'] = $unit*$netCost;
        $debitAcc['account_id'] = $assetAccount;
        $debitAcc['is_debit'] = 1;
        GlobalController::InsertNewTransactionDetails($debitAcc);
            //      Debit vat
            $debitVatAcc['transaction_id'] = $transaction_id;
            $debitVatAcc['amount'] = $unit*$vat_amount;
            $debitVatAcc['account_id'] = GetInputVATAccount();
            $debitVatAcc['is_debit'] = 1;
            GlobalController::InsertNewTransactionDetails($debitVatAcc);

        //      Credit payable/bank
        $creditAcc['transaction_id'] = $transaction_id;
        $creditAcc['amount'] = $unit*$netCost + $unit*$vat_amount;
        $creditAcc['account_id'] = $paymentAccount;
        $creditAcc['is_debit'] = 0;
        GlobalController::InsertNewTransactionDetails($creditAcc);

        TransactionAsset::create([
            'transaction_id' => $transaction_id,
            'asset_id' => $asset->id
        ]);

        if($assetData['depreciation_type'] == 1){
//            straight
            if($asset->repetitive == 2){
//            monthly
                $this->createMonthlyStraightDepreciationRecords($asset);
            }else{
                $this->createYearlyStraightDepreciationRecords($asset);
            }
        }else{
            if($asset->repetitive == 2){
//            monthly
                $this->createMonthlyDepreciationRecords($asset);
            }else{
                $this->createYearlyDepreciationRecords($asset);
            }
        }
//        return redirect()->back()->with('success', 'Asset updated successfully.');

        $comment = $asset->asset_type.' Edited';
        GlobalController::InsertNewComment(13, $asset->id,NULL, $comment);

        return redirect()->route('AssetController.index', ['#row-' . $asset->id]);


    }
    public function viewDetails($id){
        $asset = Asset::find($id);
        $transactions = Transaction::with(['TransactionAsset', 'TransactionDetails'])
            ->where('organization_id', org_id())
            ->where('date', '<=' ,today())
            ->whereIn('transaction_type_id', [12, 19]) //asset and dep records
            ->whereHas('TransactionAsset', function ($query) use ($id) {
                $query->where('asset_id', $id);
            })
            ->orderBy('created_at')
            ->get();
        $depreciationRecords = DepreciationRecord::where('organization_id', org_id())
            ->where('asset_id', $id)
            ->get();
        return view('pages.asset.moreDetails', compact(['asset', 'transactions', 'depreciationRecords']));

    }

}
