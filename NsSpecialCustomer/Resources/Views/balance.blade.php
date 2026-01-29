@extends('layout.dashboard')

@section('layout.dashboard.body')
<div class="h-full flex-auto flex flex-col">
    <div class="px-4">
        <h3>Customer Balance</h3>
    </div>
    
    <div class="px-4 mt-6">
        <!-- Customer Info -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Customer Information</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Name:</strong> {{ $customer->name }}</p>
                        <p><strong>Email:</strong> {{ $customer->email }}</p>
                        <p><strong>Phone:</strong> {{ $customer->phone ?? 'N/A' }}</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Current Balance:</strong> <span class="text-success fw-bold">{{ number_format($customer->account_amount, 2) }}</span></p>
                        <p><strong>Customer Group:</strong> {{ $customer->group ? $customer->group->name : 'N/A' }}</p>
                        <p><strong>Special Customer:</strong> 
                            @if($customer->group && $customer->group->code === 'special')
                                <span class="badge bg-success">Yes</span>
                            @else
                                <span class="badge bg-secondary">No</span>
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Current Balance</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="current-balance">{{ number_format($customer->account_amount, 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Credited</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="total-credited">Loading...</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-left-warning shadow h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Debited</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="total-debited">Loading...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2 d-md-flex">
                    <a href="{{ route('ns.dashboard.special-customer-topup') }}?customer_id={{ $customer->id }}" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Top-up Account
                    </a>
                    <a href="{{ route('ns.dashboard.special-customer-cashback.create') }}?customer_id={{ $customer->id }}" class="btn btn-success">
                        <i class="fas fa-gift"></i> Process Cashback
                    </a>
                    <a href="{{ route('ns.dashboard.special-customer-cashback') }}?customer_id={{ $customer->id }}" class="btn btn-info">
                        <i class="fas fa-history"></i> View Cashback History
                    </a>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Recent Account History</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody id="transactions-body">
                            <tr>
                                <td colspan="5" class="text-center">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-3">
                    <button onclick="loadTransactions()" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('layout.dashboard.footer.js')
<script>
let customerId = {{ $customer->id }};

document.addEventListener('DOMContentLoaded', function() {
    loadBalanceInfo();
    loadTransactions();
});

async function loadBalanceInfo() {
    try {
        const response = await fetch(`/api/special-customer/balance/${customerId}`);
        const result = await response.json();
        
        if (result.status === 'success') {
            const data = result.data;
            
            // Update statistics
            document.getElementById('total-credited').textContent = formatCurrency(data.total_credited);
            document.getElementById('total-debited').textContent = formatCurrency(data.total_debited);
        } else {
            showError('Failed to load balance information');
        }
    } catch (error) {
        console.error('Failed to load balance information:', error);
        showError('Failed to load balance information');
    }
}

async function loadTransactions() {
    try {
        const response = await fetch(`/api/special-customer/balance/${customerId}`);
        const result = await response.json();
        
        if (result.status === 'success') {
            const transactions = result.data.account_history || [];
            renderTransactions(transactions);
        } else {
            showError('Failed to load transactions');
        }
    } catch (error) {
        console.error('Failed to load transactions:', error);
        showError('Failed to load transactions');
    }
}

function renderTransactions(transactions) {
    const tbody = document.getElementById('transactions-body');
    
    if (!transactions || transactions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center">No transactions found</td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = transactions.slice(0, 10).map(transaction => `
        <tr>
            <td>${new Date(transaction.created_at).toLocaleDateString()}</td>
            <td>
                <span class="badge bg-${transaction.operation === 'credit' ? 'success' : 'danger'}">
                    ${transaction.operation.toUpperCase()}
                </span>
            </td>
            <td class="${transaction.operation === 'credit' ? 'text-success' : 'text-danger'} fw-bold">
                ${transaction.operation === 'credit' ? '+' : '-'}${formatCurrency(transaction.amount)}
            </td>
            <td>${transaction.description || '-'}</td>
            <td>
                <small class="text-muted">${transaction.reference || '-'}</small>
            </td>
        </tr>
    `).join('');
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}

function showError(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-danger alert-dismissible fade show';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.querySelector('.px-4').prepend(alertDiv);
}
</script>
@endsection
