<?php

namespace Modules\NsSpecialCustomer\Services;

use App\Models\Customer;
use App\Models\CustomerAccountHistory;
use App\Services\CustomerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class WalletService
{
    public function __construct(
        private CustomerService $customerService
    ) {}

    /**
     * Process top-up with double-entry ledger and transaction safety
     */
    public function processTopup(int $customerId, float $amount, string $description, string $reference = 'ns_special_topup'): array
    {
        return DB::transaction(function () use ($customerId, $amount, $description, $reference) {
            // Validate inputs
            if ($amount == 0) {
                return [
                    'success' => false,
                    'message' => 'Amount cannot be zero',
                    'transaction_id' => null,
                ];
            }

            $customer = Customer::findOrFail($customerId);
            $previousBalance = $customer->account_amount;
            $newBalance = $previousBalance + $amount;

            // Validate balance constraints
            if ($newBalance < 0 && abs($amount) > abs($previousBalance)) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance for this operation',
                    'transaction_id' => null,
                ];
            }

            // Create ledger entry
            $transaction = CustomerAccountHistory::create([
                'customer_id' => $customerId,
                'order_id' => null,
                'previous_amount' => $previousBalance,
                'amount' => $amount,
                'next_amount' => $newBalance,
                'operation' => $amount > 0 ? 'CREDIT' : 'DEBIT',
                'author' => auth()->id() ?? 1,
                'description' => $description,
                'reference' => $reference,
            ]);

            // Update customer balance
            $customer->account_amount = $newBalance;
            $customer->save();

            // Clear customer cache
            $this->clearCustomerCache($customerId);

            return [
                'success' => true,
                'message' => 'Transaction processed successfully',
                'transaction_id' => $transaction->id,
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'amount' => $amount,
            ];
        });
    }

    /**
     * Get customer balance with caching
     */
    public function getBalance(int $customerId): float
    {
        return Cache::remember("ns_special_customer_balance_{$customerId}", 300, function () use ($customerId) {
            $customer = Customer::find($customerId);
            return $customer ? (float) $customer->account_amount : 0.0;
        });
    }

    /**
     * Get transaction history with filters
     */
    public function getTransactionHistory(int $customerId, array $filters = [], int $perPage = 50): array
    {
        $query = CustomerAccountHistory::where('customer_id', $customerId)
            ->orderBy('created_at', 'desc');

        // Apply filters
        if (!empty($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        if (!empty($filters['reference'])) {
            $query->where('reference', $filters['reference']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (!empty($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        return $query->paginate($perPage)->toArray();
    }

    /**
     * Record ledger entry with audit trail
     */
    public function recordLedgerEntry(
        int $customerId,
        float $debitAmount,
        float $creditAmount,
        string $description,
        ?int $orderId = null,
        string $reference = 'ns_special_ledger'
    ): array {
        return DB::transaction(function () use ($customerId, $debitAmount, $creditAmount, $description, $orderId, $reference) {
            if ($debitAmount > 0 && $creditAmount > 0) {
                throw new \Exception('Cannot have both debit and credit amounts in single entry');
            }

            $amount = $debitAmount > 0 ? -$debitAmount : $creditAmount;
            $operation = $debitAmount > 0 ? 'DEBIT' : 'CREDIT';

            return $this->processTopup($customerId, $amount, $description, $reference);
        });
    }

    /**
     * Validate balance before operation
     */
    public function validateBalance(int $customerId, float $requiredAmount): array
    {
        $currentBalance = $this->getBalance($customerId);
        
        return [
            'sufficient' => $currentBalance >= $requiredAmount,
            'current_balance' => $currentBalance,
            'required_amount' => $requiredAmount,
            'shortfall' => max(0, $requiredAmount - $currentBalance),
        ];
    }

    /**
     * Get balance summary for dashboard
     */
    public function getBalanceSummary(int $customerId): array
    {
        $cacheKey = "ns_special_customer_balance_summary_{$customerId}";
        
        return Cache::remember($cacheKey, 600, function () use ($customerId) {
            $customer = Customer::find($customerId);
            if (!$customer) {
                return [
                    'balance' => 0,
                    'total_credit' => 0,
                    'total_debit' => 0,
                    'transaction_count' => 0,
                    'last_transaction' => null,
                ];
            }

            $transactions = CustomerAccountHistory::where('customer_id', $customerId);
            
            $summary = [
                'balance' => (float) $customer->account_amount,
                'total_credit' => $transactions->where('operation', 'CREDIT')->sum('amount'),
                'total_debit' => abs($transactions->where('operation', 'DEBIT')->sum('amount')),
                'transaction_count' => $transactions->count(),
                'last_transaction' => $transactions->latest()->first(),
                'recent_transactions' => $transactions->latest()->limit(5)->get(),
            ];

            return $summary;
        });
    }

    /**
     * Get daily balance changes for reporting
     */
    public function getDailyBalanceChanges(int $customerId, int $days = 30): array
    {
        $cacheKey = "ns_special_customer_daily_balance_{$customerId}_{$days}";
        
        return Cache::remember($cacheKey, 1800, function () use ($customerId, $days) {
            $startDate = now()->subDays($days)->startOfDay();
            
            $changes = CustomerAccountHistory::where('customer_id', $customerId)
                ->where('created_at', '>=', $startDate)
                ->selectRaw('
                    DATE(created_at) as date,
                    SUM(CASE WHEN operation = "CREDIT" THEN amount ELSE 0 END) as credits,
                    SUM(CASE WHEN operation = "DEBIT" THEN ABS(amount) ELSE 0 END) as debits,
                    COUNT(*) as transaction_count
                ')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return $changes->toArray();
        });
    }

    /**
     * Reconcile customer balance
     */
    public function reconcileBalance(int $customerId): array
    {
        return DB::transaction(function () use ($customerId) {
            $customer = Customer::findOrFail($customerId);
            $calculatedBalance = CustomerAccountHistory::where('customer_id', $customerId)
                ->sum('amount');

            $currentBalance = $customer->account_amount;
            $discrepancy = $calculatedBalance - $currentBalance;

            if (abs($discrepancy) < 0.01) {
                return [
                    'reconciled' => true,
                    'message' => 'Balance is already reconciled',
                    'current_balance' => $currentBalance,
                    'calculated_balance' => $calculatedBalance,
                    'discrepancy' => $discrepancy,
                ];
            }

            // Create reconciliation entry
            $reconciliationDescription = "Balance reconciliation. Discrepancy: {$discrepancy}";
            
            $this->processTopup(
                $customerId,
                $discrepancy,
                $reconciliationDescription,
                'ns_special_reconciliation'
            );

            // Clear cache
            $this->clearCustomerCache($customerId);

            return [
                'reconciled' => true,
                'message' => 'Balance reconciled successfully',
                'current_balance' => $currentBalance,
                'calculated_balance' => $calculatedBalance,
                'discrepancy' => $discrepancy,
                'new_balance' => $currentBalance + $discrepancy,
            ];
        });
    }

    /**
     * Get wallet statistics for reporting
     */
    public function getWalletStatistics(?int $customerId = null): array
    {
        $cacheKey = "ns_special_wallet_stats_" . ($customerId ?? 'all');
        
        return Cache::remember($cacheKey, 3600, function () use ($customerId) {
            $query = CustomerAccountHistory::query();
            
            if ($customerId) {
                $query->where('customer_id', $customerId);
            }

            $stats = [
                'total_transactions' => $query->count(),
                'total_credits' => $query->where('operation', 'CREDIT')->sum('amount'),
                'total_debits' => abs($query->where('operation', 'DEBIT')->sum('amount')),
                'net_flow' => $query->sum('amount'),
                'average_transaction' => $query->avg('amount'),
                'largest_credit' => $query->where('operation', 'CREDIT')->max('amount'),
                'largest_debit' => $query->where('operation', 'DEBIT')->min('amount'),
            ];

            if ($customerId) {
                $customer = Customer::find($customerId);
                $stats['current_balance'] = $customer ? $customer->account_amount : 0;
            }

            return $stats;
        });
    }

    /**
     * Clear customer-specific cache
     */
    private function clearCustomerCache(int $customerId): void
    {
        Cache::forget("ns_special_customer_balance_{$customerId}");
        Cache::forget("ns_special_customer_balance_summary_{$customerId}");
        
        // Clear daily balance cache
        $keys = Cache::getRedis()->keys("*ns_special_customer_daily_balance_{$customerId}_*");
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Clear all wallet cache
     */
    public function clearAllCache(): void
    {
        $keys = Cache::getRedis()->keys("*ns_special_customer_balance*");
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        
        $keys = Cache::getRedis()->keys("*ns_special_wallet_stats*");
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
