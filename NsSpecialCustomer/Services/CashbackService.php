<?php

namespace Modules\NsSpecialCustomer\Services;

use App\Models\Customer;
use App\Models\CustomerAccountHistory;
use Modules\NsSpecialCustomer\Models\SpecialCashbackHistory;
use Modules\NsSpecialCustomer\Services\SpecialCustomerService;
use Modules\NsSpecialCustomer\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CashbackService
{
    public function __construct(
        private SpecialCustomerService $specialCustomerService,
        private WalletService $walletService
    ) {}

    /**
     * Calculate yearly cashback for a customer
     */
    public function calculateYearlyCashback(int $customerId, int $year): array
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            throw new \Exception('Customer not found');
        }

        if (!$this->specialCustomerService->isSpecialCustomer($customer)) {
            return [
                'eligible' => false,
                'reason' => 'Customer is not a special customer',
                'total_purchases' => 0,
                'cashback_amount' => 0,
                'cashback_percentage' => 0,
            ];
        }

        $config = $this->specialCustomerService->getConfig();
        $cashbackPercentage = $config['cashbackPercentage'];

        if ($cashbackPercentage <= 0) {
            return [
                'eligible' => false,
                'reason' => 'Cashback is not enabled',
                'total_purchases' => 0,
                'cashback_amount' => 0,
                'cashback_percentage' => 0,
            ];
        }

        // Calculate total purchases for the year
        $totalPurchases = CustomerAccountHistory::where('customer_id', $customerId)
            ->where('operation', 'ORDER_PAYMENT')
            ->whereYear('created_at', $year)
            ->sum('amount');

        // Subtract any refunds
        $totalRefunds = CustomerAccountHistory::where('customer_id', $customerId)
            ->where('operation', 'ORDER_REFUND')
            ->whereYear('created_at', $year)
            ->sum('amount');

        $netPurchases = $totalPurchases - abs($totalRefunds);
        $cashbackAmount = $netPurchases * ($cashbackPercentage / 100);

        return [
            'eligible' => true,
            'reason' => 'Customer is eligible for cashback',
            'total_purchases' => $netPurchases,
            'total_refunds' => abs($totalRefunds),
            'cashback_amount' => $cashbackAmount,
            'cashback_percentage' => $cashbackPercentage,
            'year' => $year,
        ];
    }

    /**
     * Process cashback for a single customer
     */
    public function processCustomerCashback(int $customerId, int $year, ?string $description = null): array
    {
        return DB::transaction(function () use ($customerId, $year, $description) {
            $calculation = $this->calculateYearlyCashback($customerId, $year);
            
            if (!$calculation['eligible']) {
                return [
                    'success' => false,
                    'message' => $calculation['reason'],
                    'cashback_amount' => 0,
                ];
            }

            if ($calculation['cashback_amount'] <= 0) {
                return [
                    'success' => false,
                    'message' => 'No cashback amount to process',
                    'cashback_amount' => 0,
                ];
            }

            // Check if cashback already processed for this year
            $existingCashback = SpecialCashbackHistory::where('customer_id', $customerId)
                ->where('year', $year)
                ->first();

            if ($existingCashback) {
                return [
                    'success' => false,
                    'message' => "Cashback for year {$year} has already been processed",
                    'cashback_amount' => $existingCashback->amount,
                ];
            }

            // Process the cashback
            $transactionDescription = $description ?? "Special Customer Cashback for {$year}";
            
            $walletResult = $this->walletService->processTopup(
                $customerId,
                $calculation['cashback_amount'],
                $transactionDescription,
                'ns_special_cashback'
            );

            if (!$walletResult['success']) {
                throw new \Exception("Failed to process wallet top-up: " . $walletResult['message']);
            }

            // Record cashback history
            $cashbackHistory = SpecialCashbackHistory::create([
                'customer_id' => $customerId,
                'year' => $year,
                'total_purchases' => $calculation['total_purchases'],
                'total_refunds' => $calculation['total_refunds'],
                'cashback_percentage' => $calculation['cashback_percentage'],
                'cashback_amount' => $calculation['cashback_amount'],
                'transaction_id' => $walletResult['transaction_id'],
                'status' => 'processed',
                'processed_at' => now(),
                'author' => auth()->id(),
            ]);

            // Clear cache
            Cache::forget("ns_special_customer_cashback_{$customerId}_{$year}");

            return [
                'success' => true,
                'message' => 'Cashback processed successfully',
                'cashback_amount' => $calculation['cashback_amount'],
                'cashback_history_id' => $cashbackHistory->id,
                'transaction_id' => $walletResult['transaction_id'],
            ];
        });
    }

    /**
     * Process cashback batch for multiple customers
     */
    public function processCashbackBatch(int $year, array $options = []): array
    {
        $specialCustomers = $this->specialCustomerService->getSpecialCustomers([], 1000);
        $results = [
            'total_customers' => count($specialCustomers['data']),
            'processed' => 0,
            'failed' => 0,
            'total_cashback' => 0,
            'errors' => [],
        ];

        foreach ($specialCustomers['data'] as $customer) {
            try {
                $result = $this->processCustomerCashback($customer['id'], $year);
                
                if ($result['success']) {
                    $results['processed']++;
                    $results['total_cashback'] += $result['cashback_amount'];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'customer_id' => $customer['id'],
                        'customer_name' => $customer['first_name'] . ' ' . $customer['last_name'],
                        'error' => $result['message'],
                    ];
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'customer_id' => $customer['id'],
                    'customer_name' => $customer['first_name'] . ' ' . $customer['last_name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get cashback report for a year
     */
    public function getCashbackReport(int $year): array
    {
        $cacheKey = "ns_special_cashback_report_{$year}";
        
        return Cache::remember($cacheKey, 3600, function () use ($year) {
            $cashbackHistory = SpecialCashbackHistory::where('year', $year)
                ->with('customer')
                ->get();

            $summary = [
                'year' => $year,
                'total_customers' => $cashbackHistory->count(),
                'total_purchases' => $cashbackHistory->sum('total_purchases'),
                'total_refunds' => $cashbackHistory->sum('total_refunds'),
                'total_cashback' => $cashbackHistory->sum('cashback_amount'),
                'average_cashback' => $cashbackHistory->avg('cashback_amount'),
                'status_breakdown' => $cashbackHistory->groupBy('status')->map->count(),
            ];

            $details = $cashbackHistory->map(function ($record) {
                return [
                    'customer_id' => $record->customer_id,
                    'customer_name' => $record->customer->first_name . ' ' . $record->customer->last_name,
                    'customer_email' => $record->customer->email,
                    'total_purchases' => $record->total_purchases,
                    'total_refunds' => $record->total_refunds,
                    'cashback_percentage' => $record->cashback_percentage,
                    'cashback_amount' => $record->cashback_amount,
                    'status' => $record->status,
                    'processed_at' => $record->processed_at,
                    'transaction_id' => $record->transaction_id,
                ];
            });

            return [
                'summary' => $summary,
                'details' => $details,
                'generated_at' => now(),
            ];
        });
    }

    /**
     * Get customer cashback history
     */
    public function getCustomerCashbackHistory(int $customerId, int $perPage = 20): array
    {
        return SpecialCashbackHistory::where('customer_id', $customerId)
            ->orderBy('year', 'desc')
            ->paginate($perPage)
            ->toArray();
    }

    /**
     * Reverse cashback (for corrections)
     */
    public function reverseCashback(int $cashbackHistoryId, string $reason): array
    {
        return DB::transaction(function () use ($cashbackHistoryId, $reason) {
            $cashbackHistory = SpecialCashbackHistory::findOrFail($cashbackHistoryId);
            
            if ($cashbackHistory->status !== 'processed') {
                throw new \Exception('Only processed cashback can be reversed');
            }

            if ($cashbackHistory->status === 'reversed') {
                throw new \Exception('Cashback has already been reversed');
            }

            // Create reversal transaction
            $reversalResult = $this->walletService->processTopup(
                $cashbackHistory->customer_id,
                -$cashbackHistory->cashback_amount,
                "Cashback Reversal: {$reason}",
                'ns_special_cashback_reversal'
            );

            if (!$reversalResult['success']) {
                throw new \Exception("Failed to process reversal: " . $reversalResult['message']);
            }

            // Update cashback history
            $cashbackHistory->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'reversal_transaction_id' => $reversalResult['transaction_id'],
                'reversal_author' => auth()->id(),
            ]);

            // Clear cache
            Cache::forget("ns_special_customer_cashback_{$cashbackHistory->customer_id}_{$cashbackHistory->year}");

            return [
                'success' => true,
                'message' => 'Cashback reversed successfully',
                'reversed_amount' => $cashbackHistory->cashback_amount,
                'reversal_transaction_id' => $reversalResult['transaction_id'],
            ];
        });
    }

    /**
     * Get cashback statistics
     */
    public function getCashbackStatistics(?int $year = null): array
    {
        $query = SpecialCashbackHistory::query();
        
        if ($year) {
            $query->where('year', $year);
        }

        $stats = [
            'total_processed' => $query->where('status', 'processed')->count(),
            'total_reversed' => $query->where('status', 'reversed')->count(),
            'total_amount_processed' => $query->where('status', 'processed')->sum('cashback_amount'),
            'total_amount_reversed' => $query->where('status', 'reversed')->sum('cashback_amount'),
            'net_amount' => $query->sum(DB::raw('CASE WHEN status = "processed" THEN cashback_amount WHEN status = "reversed" THEN -cashback_amount ELSE 0 END')),
            'average_cashback' => $query->where('status', 'processed')->avg('cashback_amount'),
        ];

        if ($year) {
            $stats['year'] = $year;
        }

        return $stats;
    }

    /**
     * Clear cashback cache
     */
    public function clearCache(?int $customerId = null, ?int $year = null): void
    {
        if ($customerId && $year) {
            Cache::forget("ns_special_customer_cashback_{$customerId}_{$year}");
        } elseif ($year) {
            Cache::forget("ns_special_cashback_report_{$year}");
        } else {
            // Clear all cashback-related caches
            $cacheKeys = Cache::getRedis()->keys("*ns_special_cashback*");
            foreach ($cacheKeys as $key) {
                Cache::forget($key);
            }
        }
    }
}
