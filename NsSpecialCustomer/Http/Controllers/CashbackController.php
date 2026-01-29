<?php

namespace Modules\NsSpecialCustomer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\NsSpecialCustomer\Services\SpecialCustomerService;
use Modules\NsSpecialCustomer\Models\SpecialCashbackHistory;
use App\Services\CustomerService;
use Carbon\Carbon;
use DB;

class CashbackController extends Controller
{
    private SpecialCustomerService $specialCustomerService;
    private CustomerService $customerService;

    public function __construct(
        SpecialCustomerService $specialCustomerService,
        CustomerService $customerService
    ) {
        $this->specialCustomerService = $specialCustomerService;
        $this->customerService = $customerService;
    }

    /**
     * Get cashback history
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'nullable|integer|exists:ns_customers,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = SpecialCashbackHistory::with(['customer', 'transaction']);

        if ($request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->start_date) {
            $query->whereDate('period_start', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->whereDate('period_end', '<=', $request->end_date);
        }

        $perPage = $request->per_page ?? 25;
        $cashbackHistory = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $cashbackHistory
        ]);
    }

    /**
     * Process cashback for a customer
     */
    public function processCashback(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:ns_customers,id',
            'amount' => 'required|numeric|min:0.01',
            'percentage' => 'required|numeric|min:0|max:100',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'description' => 'nullable|string|max:255',
            'initiator' => 'nullable|in:admin,system,cashback'
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $customer = $this->customerService->get($request->customer_id);

                // Check if customer is special customer
                if (!$this->specialCustomerService->isSpecialCustomer($customer)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Customer is not a special customer'
                    ], 400);
                }

                // Check for overlapping periods
                if (SpecialCashbackHistory::hasOverlappingPeriod(
                    $customer->id,
                    $request->period_start,
                    $request->period_end
                )) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cashback period overlaps with existing period for this customer'
                    ], 400);
                }

                // Create cashback history record
                $cashbackHistory = SpecialCashbackHistory::create([
                    'customer_id' => $customer->id,
                    'amount' => $request->amount,
                    'percentage' => $request->percentage,
                    'period_start' => $request->period_start,
                    'period_end' => $request->period_end,
                    'initiator' => $request->initiator ?? 'admin',
                    'description' => $request->description ?? "Cashback for period {$request->period_start} to {$request->period_end}"
                ]);

                // Process the actual account credit
                $transaction = $this->customerService->saveTransaction([
                    'customer_id' => $customer->id,
                    'amount' => $request->amount,
                    'description' => $request->description ?? "Cashback for period {$request->period_start} to {$request->period_end}",
                    'operation' => 'credit',
                    'author' => auth()->id(),
                    'reference' => 'ns_special_cashback',
                    'initiator' => $request->initiator ?? 'admin'
                ]);

                // Link transaction to cashback history
                $cashbackHistory->transaction_id = $transaction->id;
                $cashbackHistory->save();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Cashback processed successfully',
                    'data' => [
                        'cashback_history' => $cashbackHistory,
                        'transaction' => $transaction,
                        'new_balance' => $customer->fresh()->account_amount
                    ]
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get cashback summary for a customer
     */
    public function getCustomerCashbackSummary(int $customerId): JsonResponse
    {
        try {
            $customer = $this->customerService->get($customerId);

            $totalCashback = SpecialCashbackHistory::where('customer_id', $customerId)
                ->sum('amount');

            $recentCashbacks = SpecialCashbackHistory::where('customer_id', $customerId)
                ->with(['transaction'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'customer' => $customer,
                    'total_cashback' => $totalCashback,
                    'recent_cashbacks' => $recentCashbacks,
                    'is_special_customer' => $this->specialCustomerService->isSpecialCustomer($customer)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Delete cashback history record
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $cashbackHistory = SpecialCashbackHistory::findOrFail($id);

            // Check if there's a linked transaction and reverse it
            if ($cashbackHistory->transaction_id) {
                $transaction = $cashbackHistory->transaction;
                
                // Create a reversal transaction
                $this->customerService->saveTransaction([
                    'customer_id' => $cashbackHistory->customer_id,
                    'amount' => $cashbackHistory->amount,
                    'description' => "Reversal of cashback: {$cashbackHistory->description}",
                    'operation' => 'debit',
                    'author' => auth()->id(),
                    'reference' => 'ns_special_cashback_reversal',
                    'initiator' => 'admin'
                ]);
            }

            $cashbackHistory->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Cashback record deleted and reversed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get cashback statistics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date'
        ]);

        $query = SpecialCashbackHistory::query();

        if ($request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $totalAmount = $query->sum('amount');
        $totalRecords = $query->count();
        $uniqueCustomers = $query->distinct('customer_id')->count('customer_id');

        // Get top customers by cashback amount
        $topCustomers = $query->selectRaw('customer_id, SUM(amount) as total_cashback')
            ->groupBy('customer_id')
            ->orderBy('total_cashback', 'desc')
            ->limit(10)
            ->with('customer:id,name,email')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_amount' => $totalAmount,
                'total_records' => $totalRecords,
                'unique_customers' => $uniqueCustomers,
                'top_customers' => $topCustomers
            ]
        ]);
    }

    /**
     * Show cashback index page
     */
    public function indexPage()
    {
        if (!ns()->allowedTo('special.customer.cashback')) {
            return redirect()->route('ns.dashboard.home')->with('error', __('You don\'t have permission to access this page.'));
        }
        
        $customers = $this->customerService->get();
        return view('NsSpecialCustomer::cashback.index', compact('customers'));
    }

    /**
     * Show cashback create page
     */
    public function createPage()
    {
        if (!ns()->allowedTo('special.customer.cashback')) {
            return redirect()->route('ns.dashboard.home')->with('error', __('You don\'t have permission to access this page.'));
        }
        
        $customers = $this->customerService->get();
        return view('NsSpecialCustomer::cashback.create', compact('customers'));
    }

    /**
     * Show cashback statistics page
     */
    public function statisticsPage()
    {
        if (!ns()->allowedTo('special.customer.cashback')) {
            return redirect()->route('ns.dashboard.home')->with('error', __('You don\'t have permission to access this page.'));
        }
        
        return view('NsSpecialCustomer::cashback.statistics');
    }
}
