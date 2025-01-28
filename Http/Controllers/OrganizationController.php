<?php

namespace App\Http\Controllers;

use App\AssetType;
use App\ChartOfAccounts;
use App\Asset;
use App\DepreciationType;
use App\Partner;
use App\Tax;
use App\Transaction;
use App\TransactionAsset;
use Carbon\Carbon;
use App\Organization;
use App\Industry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function reset()
    {

        DB::beginTransaction();

        try {
            // Step 1: Delete related records
            DB::table('transaction_adjustment')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();

            DB::table('transaction_asset')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();

            DB::table('transaction_details')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();

            DB::table('transaction_document')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();

            DB::table('transaction_expense')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();

            DB::table('transaction_invoice')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();

            DB::table('transaction_project')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();


            DB::table('transaction_vat')
                ->whereIn('transaction_id', function ($query) {
                    $query->select('id')
                        ->from('transaction')
                        ->where('organization_id', orgID());
                })
                ->delete();

            // Step 2: Delete from the main 'transaction' table
            DB::table('transaction')->where('organization_id', orgID())->delete();
//            DB::table('quotation')->where('organization_id', orgID())->delete();
//            DB::table('sales_order')->where('organization_id', orgID())->delete();
//            DB::table('purchase_order')->where('organization_id', orgID())->delete();
//            DB::table('sales_invoice')->where('organization_id', orgID())->delete();
//            DB::table('purchase_invoice')->where('organization_id', orgID())->delete();
//            DB::table('debit_note')->where('organization_id', orgID())->delete();
//            DB::table('credit_note')->where('organization_id', orgID())->delete();
            DB::table('expense_document')
                ->whereIn('expense_id', function ($query) {
                    $query->select('id')
                        ->from('expense')
                        ->where('organization_id', orgID());
                })
                ->delete();
            DB::table('expense')->where('organization_id', orgID())->delete();
            DB::table('fixed_asset')->where('organization_id', orgID())->delete();
            DB::table('adjustment_payment')->where('organization_id', orgID())->delete();
            DB::table('bank_account')->where('organization_id', orgID())->delete();
            DB::table('budget_account')->where('organization_id', orgID())->delete();
            DB::table('comments_history')->where('organization_id', orgID())->delete();
            DB::table('credit_applied')->where('organization_id', orgID())->delete();
            DB::table('debit_applied')->where('organization_id', orgID())->delete();
            DB::table('depreciation_records')->where('organization_id', orgID())->delete();
            DB::table('employee')->where('organization_id', orgID())->delete();
            DB::table('ob_accounts')->where('organization_id', orgID())->delete();
            DB::table('ob_adjustment')->where('organization_id', orgID())->delete();
            DB::table('ob_partners')->where('organization_id', orgID())->delete();
            DB::table('partners_contact_persons')
                ->whereIn('partner_id', function ($query) {
                    $query->select('id')
                        ->from('partner')
                        ->where('organization_id', orgID());
                })
                ->delete();
            DB::table('partner')->where('organization_id', orgID())->delete();
            DB::table('payment_received_documents')
                ->whereIn('payment_id', function ($query) {
                    $query->select('id')
                        ->from('payment_received')
                        ->where('organization_id', orgID());
                })
                ->delete();
            DB::table('payment_received')->where('organization_id', orgID())->delete();
            DB::table('payment_terms')->where('organization_id', orgID())->delete();
            DB::table('payroll')->where('organization_id', orgID())->delete();
            DB::table('project')->where('organization_id', orgID())->delete();
            DB::table('tax')->where('organization_id', orgID())->delete();
            DB::table('tax_audit')->where('organization_id', orgID())->delete();
            DB::table('tax_report')->where('organization_id', orgID())->delete();
            DB::table('item')->where('organization_id', orgID())->delete();
//            DB::table('users')->where('organization_id', orgID())->delete();

            DB::table('chart_of_account')->where('organization_id', orgID())->where('is_default', 0)->delete();

            $invoice_type = [
                1 => 'quotation',
                2 => 'sales_order',
                3 => 'sales_invoice',
                4 => 'purchase_invoice',
                5 => 'purchase_order',
                6 => 'credit_note',
                7 => 'debit_note',
            ];

            foreach ($invoice_type as $type_id => $class) {
                $tableName = strtolower(class_basename($class));
                DB::table('invoice_items')
                    ->where('invoice_type_id', $type_id)
                    ->whereIn('invoice_id', function ($query) use ($tableName) {
                        $query->select('id')
                            ->from($tableName)
                            ->where('organization_id', orgID());
                    })
                    ->delete();
                DB::table('invoice_files')
                    ->where('invoice_type_id', $type_id)
                    ->whereIn('invoice_id', function ($query) use ($tableName) {
                        $query->select('id')
                            ->from($tableName)
                            ->where('organization_id', orgID());
                    })
                    ->delete();
                DB::table($tableName)
                    ->where('organization_id', orgID())
                    ->delete();
            }
            $organization = Organization::findOrFail(orgID());
            $organization->vat_rate = NULL;
            $organization->invoice_prefix = NULL;
            $organization->inv_start_nb = NULL;
            $organization->currency_id = NULL;
              $organization->update();

            DB::commit();  // Commit the transaction
            return redirect()->back()->with('success', 'Reset success.');
        } catch (\Exception $e) {
            DB::rollBack();  // Rollback if something goes wrong
            throw $e;  // Optionally, rethrow the exception
        }

    }

    public function create()
    {
        $industry = Industry::all();
        return view('pages/organization.create', ['industry' => $industry]);
    }

    public function store(Request $request)
    {
             $validatedData = $request->validate([
            'logo' => 'required|string',
            'address' => 'required|string',
            'infos' => 'nullable|string',
            'industry_id' => 'nullable|int',
            'country_region' => 'nullable|string|max:255',
            'additional_number' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'fax_number' => 'nullable|string|max:255',
            'color_layout' => 'nullable|string|max:7',
            'organization_email' => 'nullable|string|email|max:255',
            'vat_rate' => 'nullable|numeric|min:0|max:15',
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $fileName = uploadFile($file, 'organization');
            $validatedData['logo']   = $fileName;
        }

        Organization::create($validatedData);

        return redirect()->route('organization.create' )->with('success', 'Organization profile created successfully.');

    }
    public function edit($encryptedId)
    {
        $industry = Industry::all();
        $id = Crypt::decryptString($encryptedId);
        $organization = Organization::findOrFail($id);
        return view('pages.organization.edit', compact(['organization','industry']));
    }

    public function update(Request $request, $encryptedId)
    {
        $id = Crypt::decryptString($encryptedId);
        $organization = Organization::findOrFail($id);

        $rules = [
            'name' => 'required|string|max:255',
            'organization_email' => 'nullable|email|max:255',
            'phone' => 'nullable|numeric',
            'additional_number' => 'nullable|numeric',
            'address' => 'nullable|string|max:500',
        ];
        if ($organization->vat_rate == NULL) {
            $rules['vat_rate'] = 'required|in:0.00,5.00,15.00';
        }
        if ($organization->invoice_prefix == NULL && $organization->inv_start_nb == NULL) {
            $rules['invoice_prefix'] = 'required|string|max:10';
            $rules['inv_start_nb'] = 'required|string|regex:/^[0-9]+$/'; // Custom validation for numeric string
        }
        if ($organization->currency_id == NULL) {
            $rules['currency_id'] = 'required|integer';
        }
        $messages = [
            'invoice_prefix.required' => 'The invoice prefix is required and must not exceed 10 characters.',
            'inv_start_nb.required' => 'Please provide a valid starting invoice number.',
            'inv_start_nb.regex' => 'The starting invoice number must be a valid number, and can include leading zeros.',
            'currency_id.required' => 'The currency is required.',
        ];

        $validatedData = $request->validate($rules, $messages);

        $onlyDigits = preg_replace('/\D/', '', (string)$request->inv_start_nb);
        $numberOfDigits = strlen($onlyDigits);

        $data = $request->all();
        $data['inv_digit'] = $numberOfDigits;

        $haveTax = Tax::where('deleted', 0)
            ->where('organization_id', $id)
            ->exists();

        if(!$haveTax){
            if(isset($data['vat_rate'])){
                $taxes = [
                    ['No VAT', 0],
                    ['VAT', $data['vat_rate']]
                ];
            }else{
                $taxes = [
                    ['No VAT', 0],
                    ['VAT', $organization->vat_rate]
                ];
            }


            foreach ($taxes as $tax) {
                Tax::create([
                    'name' => $tax[0],
                    'value' => $tax[1],
                    'organization_id' => $id
                ]);
            }
        }

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $fileName = uploadFile($file, 'organization');
            $data['logo']   = $fileName;
        }

        $organization->update($data);

        return redirect()->back()->with('success', 'Record has been updated successfully.');

     }
}
