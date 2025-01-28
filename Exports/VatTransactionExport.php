<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromView;
use Illuminate\Contracts\View\View;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VatTransactionExport implements FromView
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        //
    }
    protected $startDate;
    protected $endDate;
    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function view(): View
    {
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
            ->whereNotIn('transaction.transaction_type_id', [22, 23, 24, 25])
            ->whereIn('transaction_details.account_id', [$purchaseVat, $salesVat])
            ->whereBetween('transaction.date', [$this->startDate, $this->endDate])
            ->orderBy('transaction.date', 'ASC')
            ->select(
                'transaction.date',
                'transaction_details.amount as vat_amount',
                'transaction.taxable_amount',
                'transaction.non_taxable_amount',
                'transaction_details.is_debit',
                'transaction.description',
                'transaction.reference_number',
                'transaction.internal_note',
                'transaction.transaction_type_id',
                'chart_of_account.name as accountName',
                'project.id as project_id',
                'expense.id as expense_id',
                'fixed_asset.id as asset_id',
                'transaction_invoice.invoice_type_id as invoice_type',
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
                    sales_invoice.id,
                    credit_note.id,
                    purchase_invoice.id,
                    debit_note.id
                ) AS invoice_id'
                ),
                DB::raw('
                COALESCE(
                    sales_invoice.partner_id,
                    credit_note.partner_id,
                    purchase_invoice.partner_id,
                    debit_note.partner_id,
                    project.receivable_id,
                    expense.customer_id
                ) AS partner_id'
                )
            )
            ->get();

        return view('pages.exports.vat_audit', ['vatTransactions' => $vatTransaction]);
    }
    public function styles(Worksheet $sheet)
    {
        // Define the styles
        return [
            // Bold font and larger width for the first row (header row)
            1 => ['font' => ['bold' => true],
                'width' => 20,
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'ccbfbfd9']]],
        ];
    }
}
