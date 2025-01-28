<?php

namespace App;

use Faker\Provider\Payment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Transaction extends Model
{
    use HasFactory;
    protected $table = 'transaction';
    protected $fillable = [
        'id',
        'transaction_type_id',
        'journal_number',
        'amount',
        'taxable_amount',
        'non_taxable_amount',
        'currency',
        'reference_number',
        'payment_id',
        'payment_number',
        'description',
        'internal_note',
        'paid_by_id',
        'organization_id',
        'date',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'
    ];
    public function PaymentReceived()
    {
        return $this->belongsTo(PaymentReceived::class, 'payment_id');
    }
    public function TransactionInvoice()
    {
        return $this->hasMany(TransactionInvoice::class);
    }

    public function TransactionExpense()
    {
        return $this->hasMany(TransactionExpense::class);
    }
    public function TransactionAsset()
    {
        return $this->hasMany(TransactionAsset::class);
    }
    public function TransactionProject()
    {
        return $this->hasMany(TransactionProject::class);
    }
    public function TransactionDetails()
    {
        return $this->hasMany(TransactionDetails::class);
    }

    public function TransactionAdjustment()
    {
        return $this->hasMany(TransactionAdjustment::class);
    }

    public function TransactionType()
    {
        return $this->belongsTo(TransactionType::class, 'transaction_type_id');
    }
    public function Partner()
    {
        return $this->belongsTo(Partner::class, 'paid_by_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            $transaction->organization_id = org_id();
            $transaction->created_by = Auth::id();

            $autoGenerateTypes = [9, 10, 11, 12, 22, 23, 24, 25, 27, 28, 8, 29];

            if (in_array($transaction->transaction_type_id, $autoGenerateTypes)) {
                $transaction->journal_number = self::generateJournalNumber();
            }
        });

        static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
    /**
     * Generate the next sequential journal number
     *
     * @return int
     */
    public static function generateJournalNumber()
    {
        $lastTransaction = self::whereNotNull('journal_number')
            ->orderBy('journal_number', 'desc')
            ->first();

        return $lastTransaction ? $lastTransaction->journal_number + 1 : 1;
    }

}
