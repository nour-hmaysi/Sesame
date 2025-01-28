<?php

namespace App\Console\Commands;

use App\Asset;
use App\DepreciationRecord;
use App\Http\Controllers\GlobalController;
use App\Transaction;
use App\TransactionAsset;
use App\TransactionExpense;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessDepreciationAssets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset:process';
    protected $description = 'Process depreciation';


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
//        return Command::SUCCESS;
        $today = Carbon::today();

//        get the expenses
        $assets = Asset::whereDate('dep_date', '<=', $today)
            ->where('organization_id', org_id())
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $today);
            })
            ->get();

        foreach ($assets as $asset) {
//            $depDate = Carbon::parse($asset->recurring_date)->addMonths($asset->repetitive);
            $depDate = Carbon::parse($asset->dep_date);

//            when the function should proceed, if the recurring date is less than today or equal
            while ($depDate->lte($today)) {

                $salvageValue = $asset->salvage_value;
                $unit = $asset->unit;
                $cost = $asset->cost;//cost of single asset
                $netCost = $asset->net_cost;
                $bookValue = $asset->book_value;
                $lifetime = $asset->lifetime;
                $depreciationValue = $asset->depreciation_value;

                if($asset->depreciation_type == 1){
//            straight
                    $depAmount = (($unit*$netCost) - ($unit*$salvageValue))/$lifetime;
                }else{
                    $depAmount = ($bookValue)*(2*$depreciationValue/100);
                }
                if($asset->repetitive == 2){
//            monthly
                    $depAmount = $depAmount/12;
                }else{
//                    yearly
                    $asset->book_value = $bookValue - $depAmount;
                }
                $request1 = [
                    'transaction_type_id' => 19, // Depreciation
                    'amount' => $depAmount,
                    'date' => $depDate->toDateString(),
                ];
                $transaction = GlobalController::InsertNewTransaction($request1);

                $assetAccount['transaction_id'] = $transaction;
                $assetAccount['amount'] =  $depAmount;
                $assetAccount['account_id'] = GetDepreciationAccount();
                $assetAccount['is_debit'] = 1;
                GlobalController::InsertNewTransactionDetails($assetAccount);

                //      Credit accumulated
                $depCreditAcc['transaction_id'] = $transaction;
                $depCreditAcc['amount'] = $depAmount;
                $depCreditAcc['account_id'] = GetAccumulatedAccount();
                $depCreditAcc['is_debit'] = 0;
                GlobalController::InsertNewTransactionDetails($depCreditAcc);

                TransactionAsset::create([
                    'transaction_id' => $transaction,
                    'asset_id' => $asset->id
                ]);
                $asset->dep_date = $depDate->copy()->addMonths($asset->repetitive);
                if($asset->repetitive == 1){
//            yearly
                    $asset->dep_date = $depDate->copy()->addYear();
                }else{
//            monthly
                    $asset->dep_date = $depDate->copy()->addMonth();
                }
                $asset->save();
                DepreciationRecord::create([
                    'asset_id' => $asset->id,
                    'depreciation_date' => $depDate,
                    'depreciation_amount' => $depAmount,
                    'next_date' => $asset->dep_date,
                ]);
                $depDate = $depDate->addMonths($asset->repetitive);
            }

        }
        return Command::SUCCESS;

    }
}
