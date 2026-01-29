<?php

namespace Modules\NsSpecialCustomer\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use TorMorten\Eventy\Facades\Events as Hook;
use TorMorten\Eventy\Facades\Events as Event;
use Modules\NsSpecialCustomer\Services\SpecialCustomerService;
use Modules\NsSpecialCustomer\Services\CashbackService;
use Modules\NsSpecialCustomer\Services\WalletService;
use Modules\NsSpecialCustomer\Crud\SpecialCashbackCrud;
use Modules\NsSpecialCustomer\Crud\CustomerTopupCrud;
use Modules\NsSpecialCustomer\Crud\SpecialCustomerCrud;
use App\Events\OrderAfterCreatedEvent;
use App\Events\RenderFooterEvent;

class NsSpecialCustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('ns.special-customer.permission', \Modules\NsSpecialCustomer\Http\Middleware\CheckSpecialCustomerPermission::class);

        // Register services as singletons for performance
        $this->app->singleton(SpecialCustomerService::class, function ($app) {
            return new SpecialCustomerService($app->make(\App\Services\Options::class));
        });

        $this->app->singleton(CashbackService::class, function ($app) {
            return new CashbackService(
                $app->make(SpecialCustomerService::class),
                $app->make(WalletService::class)
            );
        });

        $this->app->singleton(WalletService::class, function ($app) {
            return new WalletService($app->make(\App\Services\CustomerService::class));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(__DIR__ . '/../Resources/Views', 'NsSpecialCustomer');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');
        
        // Load module permissions (legacy format)
        if (defined('NEXO_CREATE_PERMISSIONS')) {
            include_once dirname(__FILE__) . '/../Database/Permissions/special-customer.php';
        }

        // Load new permission migration
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // View composer for all module views
        View::composer('NsSpecialCustomer::*', function ($view) {
            $view->with('specialCustomerConfig', app(SpecialCustomerService::class)->getConfig());
        });

        // Register CRUD resources
        Hook::addFilter('ns-crud-resource', function ($identifier) {
            switch ($identifier) {
                case 'ns.special-customers':
                    return SpecialCustomerCrud::class;
                case 'ns.special-customer-cashback':
                    return SpecialCashbackCrud::class;
                case 'ns.special-customer-topup':
                    return CustomerTopupCrud::class;
            }
            return $identifier;
        });

        // Dashboard Menu with proper structure
        Hook::addFilter('ns-dashboard-menus', function ($menus) {
            $menus[] = [
                'label' => 'Special Customer',
                'icon' => 'la-star',
                'childrens' => [
                    ['label' => 'Customer List', 'href' => url('/dashboard/special-customer/customers')],
                    ['label' => 'Top-up Account', 'href' => url('/dashboard/special-customer/topup')],
                    ['label' => 'Cashback History', 'href' => url('/dashboard/special-customer/cashback')],
                    ['label' => 'Statistics', 'href' => url('/dashboard/special-customer/statistics')],
                    ['label' => 'Settings', 'href' => url('/dashboard/special-customer/settings')],
                ]
            ];
            return $menus;
        });

        // Comprehensive POS Integration Hooks
        $this->registerPOSHooks();

        // Register UI components
        $this->registerUIComponents();

        // Register Vue components for dashboard
        $this->registerVueComponents();
        
        // Register event listeners for special customer functionality
        $this->registerEventListeners();
    }

    /**
     * Register event listeners for special customer functionality
     */
    private function registerEventListeners(): void
    {
        // Listen to order creation events to apply special customer discount and wholesale pricing
        Hook::addAction('ns-order-after-check-performed', function ($fields, $order) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Check if customer is provided
            $customerId = $fields['customer_id'] ?? null;
            if (!$customerId) {
                return $fields;
            }
            
            // Get customer
            $customer = \App\Models\Customer::find($customerId);
            if (!$customer || !$specialCustomerService->isSpecialCustomer($customer)) {
                return $fields;
            }
            
            // Step 1: Update order line prices to wholesale
            if (isset($fields['products']) && is_array($fields['products'])) {
                $updatedProducts = collect($fields['products'])->map(function ($product) use ($specialCustomerService, $customer) {
                    $pricing = $specialCustomerService->applyWholesalePricing($product, $customer);
                    
                    if ($pricing['wholesale_applied']) {
                        $oldPrice = $product['unit_price'] ?? 0;
                        $newPrice = $pricing['special_price'];
                        $quantity = $product['quantity'] ?? 1;
                        
                        // Update product unit price to wholesale price
                        $product['unit_price'] = $newPrice;
                        $product['original_unit_price'] = $oldPrice;
                        $product['wholesale_applied'] = true;
                        $product['total_price'] = $newPrice * $quantity;
                        $product['price_mode'] = 'wholesale';
                        
                        \Log::info('Order line price updated to wholesale', [
                            'product_id' => $product['id'] ?? 0,
                            'product_name' => $product['name'] ?? 'Unknown',
                            'old_price' => $oldPrice,
                            'new_price' => $newPrice,
                            'quantity' => $quantity,
                        ]);
                    } else {
                        $product['price_mode'] = 'regular';
                    }
                    
                    return $product;
                });
                
                $fields['products'] = $updatedProducts->toArray();
                
                // Recalculate subtotal based on updated product prices
                $newSubtotal = collect($fields['products'])->sum('total_price');
                $fields['subtotal'] = $newSubtotal;
                
                \Log::info('Order subtotal recalculated after wholesale pricing', [
                    'customer_id' => $customerId,
                    'new_subtotal' => $newSubtotal,
                    'product_count' => count($fields['products']),
                ]);
            }
            
            // Step 2: Apply special customer discount
            // Check if discount is already applied
            if (isset($fields['discount']) && $fields['discount'] > 0) {
                return $fields;
            }
            
            // Calculate special customer discount
            $config = $specialCustomerService->getConfig();
            $discountPercentage = $config['discountPercentage'];
            
            if ($discountPercentage > 0) {
                $subtotal = $fields['subtotal'] ?? 0;
                $discountAmount = $subtotal * ($discountPercentage / 100);
                
                // Apply the discount to fields
                $fields['discount'] = $discountAmount;
                $fields['discount_type'] = 'percentage';
                $fields['discount_percentage'] = $discountPercentage;
                
                // Update the total after discount
                $tax = $fields['tax_value'] ?? 0;
                $shipping = $fields['shipping'] ?? 0;
                $fields['total'] = $subtotal - $discountAmount + $tax + $shipping;
                
                \Log::info('Special customer discount applied via event listener', [
                    'customer_id' => $customerId,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'discount_percentage' => $discountPercentage,
                    'total' => $fields['total'],
                ]);
            }
            
            return $fields;
        });

        // Listen to order after creation for cashback tracking
        Hook::addAction('ns-pos-after-order', function ($order) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Handle both array and object cases
            $customer = is_array($order) ? ($order['customer'] ?? null) : ($order->customer ?? null);
            
            if ($customer && $specialCustomerService->isSpecialCustomer($customer)) {
                // Add order metadata for cashback tracking
                $orderData = [
                    'is_special' => true,
                    'order_year' => now()->year,
                    'eligible_for_cashback' => true,
                ];
                
                if (is_array($order)) {
                    $order['special_customer_data'] = $orderData;
                } else {
                    $order->special_customer_data = $orderData;
                }
                
                // Log for cashback calculation
                \Log::info('Special customer order created', [
                    'order_id' => is_array($order) ? ($order['id'] ?? null) : ($order->id ?? null),
                    'customer_id' => is_array($order) ? ($order['customer_id'] ?? null) : ($order->customer_id ?? null),
                    'total' => is_array($order) ? ($order['total'] ?? null) : ($order->total ?? null),
                    'year' => now()->year,
                ]);
            }
        }, 10, 1);
    }

    /**
     * Register Vue components using nsExtraComponents
     */
    private function registerVueComponents(): void
    {
        Hook::addAction('ns-dashboard-footer', function () {
            ?>
            <script>
            // Register Vue components for Special Customer module
            if (typeof nsExtraComponents !== "undefined") {
                // Special Customer Balance Widget
                nsExtraComponents["ns-special-customer-balance"] = {
                    template: `<div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-wallet"></i> Customer Balance</h5>
                            <button class="btn btn-sm btn-primary" @click="refreshBalance()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                        <div class="card-body" v-if="customer">
                            <div class="row">
                                <div class="col-md-6">
                                    <small class="text-muted">Current Balance</small>
                                    <h4 class="text-primary">{{ formatCurrency(balance) }}</h4>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted">Total Credited</small>
                                    <h5 class="text-success">{{ formatCurrency(totalCredited) }}</h5>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <small class="text-muted">Total Debited</small>
                                    <h5 class="text-danger">{{ formatCurrency(totalDebited) }}</h5>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted">Recent Transactions</small>
                                    <div class="transaction-list" style="max-height: 150px; overflow-y: auto;">
                                        <div v-for="transaction in recentTransactions.slice(0, 5)" :key="transaction.id" 
                                             class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span class="small">{{ transaction.description }}</span>
                                            <span class="badge" :class="transaction.operation === 'credit' ? 'bg-success' : 'bg-danger'">
                                                {{ formatCurrency(transaction.amount) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body text-center" v-else>
                            <p class="text-muted">Select a customer to view balance information</p>
                        </div>
                    </div>`,
                    props: ["customerId"],
                    data() { 
                        return {
                            customer: null,
                            balance: 0,
                            totalCredited: 0,
                            totalDebited: 0,
                            recentTransactions: [],
                            loading: false
                        }; 
                    },
                    mounted() {
                        if (this.customerId) {
                            this.loadCustomerBalance();
                        }
                    },
                    watch: {
                        customerId(newVal) {
                            if (newVal) {
                                this.loadCustomerBalance();
                            }
                        }
                    },
                    methods: {
                        async loadCustomerBalance() {
                            this.loading = true;
                            try {
                                const response = await nsHttpClient.get(`/api/special-customer/balance/${this.customerId}`);
                                const data = response.data.data;
                                this.customer = data.customer;
                                this.balance = data.current_balance;
                                this.totalCredited = data.total_credited;
                                this.totalDebited = data.total_debited;
                                this.recentTransactions = data.account_history || [];
                            } catch (error) {
                                console.error("Error loading customer balance:", error);
                                if (typeof nsSnackBar !== "undefined") {
                                    nsSnackBar.error("Failed to load customer balance").show();
                                }
                            } finally {
                                this.loading = false;
                            }
                        },
                        refreshBalance() {
                            this.loadCustomerBalance();
                        },
                        formatCurrency(amount) {
                            return typeof nsCurrency !== "undefined" ? nsCurrency.define(amount) : "$" + parseFloat(amount).toFixed(2);
                        }
                    }
                };

                // Special Customer Quick Actions Widget
                nsExtraComponents["ns-special-customer-actions"] = {
                    template: `<div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-star"></i> Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" @click="openTopupModal()" 
                                        :disabled="!customer || loading">
                                    <i class="fas fa-plus-circle"></i> Top-up Account
                                </button>
                                <button class="btn btn-success" @click="processCashback()" 
                                        :disabled="!customer || loading || !isSpecial">
                                    <i class="fas fa-percentage"></i> Process Cashback
                                </button>
                                <button class="btn btn-info" @click="viewStatistics()" 
                                        :disabled="!customer">
                                    <i class="fas fa-chart-bar"></i> View Statistics
                                </button>
                            </div>
                            <div v-if="isSpecial" class="mt-3">
                                <div class="alert alert-warning">
                                    <i class="fas fa-star"></i> <strong>Special Customer</strong>
                                    <div class="small mt-1">
                                        Discount: {{ config.discountPercentage }}%<br>
                                        Cashback: {{ config.cashbackPercentage }}%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`,
                    props: ["customerId"],
                    data() { 
                        return {
                            customer: null,
                            isSpecial: false,
                            config: {},
                            loading: false
                        }; 
                    },
                    mounted() {
                        this.loadCustomerData();
                    },
                    watch: {
                        customerId() {
                            this.loadCustomerData();
                        }
                    },
                    methods: {
                        async loadCustomerData() {
                            if (!this.customerId) return;
                            this.loading = true;
                            try {
                                const [statusResponse, configResponse] = await Promise.all([
                                    nsHttpClient.get(`/api/special-customer/check/${this.customerId}`),
                                    nsHttpClient.get("/api/special-customer/config")
                                ]);
                                this.isSpecial = statusResponse.data.data.isSpecial;
                                this.config = configResponse.data.data;
                            } catch (error) {
                                console.error("Error loading customer data:", error);
                            } finally {
                                this.loading = false;
                            }
                        },
                        openTopupModal() {
                            if (typeof Popup !== "undefined") {
                                Popup.show(nsExtraComponents["ns-special-customer-topup-modal"], {
                                    resolve: (result) => {
                                        if (result.success) {
                                            nsSnackBar.success("Top-up processed successfully").show();
                                            this.$emit("topup-completed", result);
                                        }
                                    },
                                    customerId: this.customerId
                                });
                            }
                        },
                        processCashback() {
                            window.location.href = `/dashboard/special-customer/cashback/create?customer_id=${this.customerId}`;
                        },
                        viewStatistics() {
                            window.location.href = `/dashboard/special-customer/statistics?customer_id=${this.customerId}`;
                        }
                    }
                };

                // Special Customer Top-up Modal
                nsExtraComponents["ns-special-customer-topup-modal"] = {
                    template: `<div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Top-up Account</h5>
                            <button type="button" class="btn-close" @click="$emit('close')"></button>
                        </div>
                        <div class="modal-body">
                            <form @submit.prevent="processTopup">
                                <div class="mb-3">
                                    <label class="form-label">Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" v-model="amount" 
                                               step="0.01" min="1" max="10000" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" v-model="description" 
                                              placeholder="Optional description for this top-up" rows="3"></textarea>
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-secondary" @click="$emit('close')">Cancel</button>
                                    <button type="submit" class="btn btn-primary" :disabled="loading">
                                        <i class="fas fa-spinner fa-spin" v-if="loading"></i>
                                        <i class="fas fa-check" v-else></i> Process Top-up
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>`,
                    props: ["customerId"],
                    data() { 
                        return {
                            amount: "",
                            description: "",
                            loading: false
                        }; 
                    },
                    methods: {
                        async processTopup() {
                            this.loading = true;
                            try {
                                const response = await nsHttpClient.post("/api/special-customer/topup", {
                                    customer_id: this.customerId,
                                    amount: parseFloat(this.amount),
                                    description: this.description || "Account top-up"
                                });
                                if (response.data.status === "success") {
                                    this.$resolve({ success: true, data: response.data.data });
                                    this.$emit("close");
                                } else {
                                    nsSnackBar.error(response.data.message || "Top-up failed").show();
                                }
                            } catch (error) {
                                console.error("Top-up error:", error);
                                nsSnackBar.error("Failed to process top-up").show();
                            } finally {
                                this.loading = false;
                            }
                        }
                    }
                };
            }
            </script>
            <?php
        });
    }
    
    /**
     * Register POS integration hooks
     */
    private function registerPOSHooks(): void
    {
        // POS Options Hook - inject special customer configuration
        Hook::addFilter('ns-pos-options', function ($options) {
            $specialCustomerService = app(SpecialCustomerService::class);
            $config = $specialCustomerService->getConfig();
            
            $options['specialCustomer'] = [
                'enabled' => !is_null($config['groupId']),
                'groupId' => $config['groupId'],
                'discountPercentage' => $config['discountPercentage'],
                'cashbackPercentage' => $config['cashbackPercentage'],
                'applyDiscountStackable' => $config['applyDiscountStackable'],
            ];

            return $options;
        });

        // Customer Selected Hook - mark special customer and update UI
        Hook::addFilter('ns-pos-customer-selected', function ($customerData, $customer) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Handle both array and object cases
            $customerObj = is_array($customer) ? (object) $customer : $customer;
            
            if ($customerObj && $specialCustomerService->isSpecialCustomer($customerObj)) {
                $customerData['is_special'] = true;
                $customerData['special_badge'] = 'Special Customer';
                $customerData['wallet_balance'] = is_array($customer) ? ($customer['account_amount'] ?? 0) : ($customerObj->account_amount ?? 0);
                
                // Get customer status
                $customerId = is_array($customer) ? ($customer['id'] ?? 0) : ($customerObj->id ?? 0);
                $status = $specialCustomerService->getCustomerStatus($customerId);
                $customerData['discount_eligible'] = $status['discount_eligible'];
                $customerData['cashback_eligible'] = $status['cashback_eligible'];
                
                // Trigger frontend event for UI updates
                if (function_exists('event')) {
                    event('special-customer-selected', $customerData);
                }
            } else {
                $customerData['is_special'] = false;
            }

            return $customerData;
        }, 10, 2);

        // Product Price Hook - apply wholesale pricing for special customers
        Hook::addFilter('ns-pos-product-price', function ($price, $product, $customer) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Handle both array and object cases
            $customerObj = is_array($customer) ? (object) $customer : $customer;
            
            if ($customerObj && $specialCustomerService->isSpecialCustomer($customerObj)) {
                $pricing = $specialCustomerService->applyWholesalePricing($product, $customerObj);
                
                if ($pricing['wholesale_applied']) {
                    // Log wholesale pricing application
                    if (function_exists('Log')) {
                        Log::info('Wholesale pricing applied for special customer', [
                            'customer_id' => is_array($customer) ? ($customer['id'] ?? 0) : ($customerObj->id ?? 0),
                            'product_id' => is_array($product) ? ($product['id'] ?? 0) : ($product->id ?? 0),
                            'original_price' => $pricing['original_price'],
                            'special_price' => $pricing['special_price'],
                            'savings' => $pricing['savings']
                        ]);
                    }
                    
                    return [
                        'price' => $pricing['special_price'],
                        'original_price' => $pricing['original_price'],
                        'wholesale_applied' => true,
                        'savings' => $pricing['savings'],
                    ];
                }
            }
            
            return $price;
        }, 10, 3);

        // Order Discounts Hook - apply special discount
        Hook::addFilter('ns-pos-order-discounts', function ($discounts, $order, $customer) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Handle both array and object cases
            $customerObj = is_array($customer) ? (object) $customer : $customer;
            
            if ($customerObj && $specialCustomerService->isSpecialCustomer($customerObj)) {
                $discountResult = $specialCustomerService->applySpecialDiscount($order, $customerObj);
                
                if ($discountResult['discount_applied']) {
                    $discounts[] = [
                        'type' => 'special_customer',
                        'label' => 'Special Customer Discount',
                        'amount' => $discountResult['discount_amount'],
                        'percentage' => $discountResult['discount_percentage'],
                        'stackable' => $discountResult['stackable'],
                    ];
                }
            }
            
            return $discounts;
        }, 10, 3);

        // After Order Hook - record order for cashback calculation
        Hook::addAction('ns-pos-after-order', function ($order) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Handle both array and object cases
            $customer = is_array($order) ? ($order['customer'] ?? null) : ($order->customer ?? null);
            
            if ($customer && $specialCustomerService->isSpecialCustomer($customer)) {
                // Add order metadata for cashback tracking
                $orderData = [
                    'is_special' => true,
                    'order_year' => now()->year,
                    'eligible_for_cashback' => true,
                ];
                
                if (is_array($order)) {
                    $order['special_customer_data'] = $orderData;
                } else {
                    $order->special_customer_data = $orderData;
                }
                
                // Log for cashback calculation
                \Log::info('Special customer order created', [
                    'order_id' => is_array($order) ? ($order['id'] ?? null) : ($order->id ?? null),
                    'customer_id' => is_array($order) ? ($order['customer_id'] ?? null) : ($order->customer_id ?? null),
                    'total' => is_array($order) ? ($order['total'] ?? null) : ($order->total ?? null),
                    'year' => now()->year,
                ]);
            }
        }, 10, 1);

        // Order Products Before Save Hook - update product prices to wholesale for special customers
        Hook::addFilter('ns-pos-order-products-before-save', function ($products, $customer) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Check if customer is special
            if (!$customer || !$specialCustomerService->isSpecialCustomer($customer)) {
                return $products;
            }
            
            // Process each product to apply wholesale pricing
            $updatedProducts = collect($products)->map(function ($product) use ($specialCustomerService, $customer) {
                $pricing = $specialCustomerService->applyWholesalePricing($product, $customer);
                
                if ($pricing['wholesale_applied']) {
                    // Update the unit price to wholesale price
                    $oldPrice = is_array($product) ? ($product['unit_price'] ?? 0) : ($product->unit_price ?? 0);
                    $newPrice = $pricing['special_price'];
                    $quantity = is_array($product) ? ($product['quantity'] ?? 1) : ($product->quantity ?? 1);
                    
                    // Update product array/object
                    if (is_array($product)) {
                        $product['unit_price'] = $newPrice;
                        $product['original_unit_price'] = $oldPrice; // Keep track of original price
                        $product['wholesale_applied'] = true;
                        $product['total_price'] = $newPrice * $quantity;
                        $product['price_mode'] = 'wholesale';
                    } else {
                        $product->unit_price = $newPrice;
                        $product->original_unit_price = $oldPrice;
                        $product->wholesale_applied = true;
                        $product->total_price = $newPrice * $quantity;
                        $product->price_mode = 'wholesale';
                    }
                    
                    \Log::info('Order line price updated to wholesale', [
                        'product_id' => is_array($product) ? ($product['id'] ?? 0) : ($product->id ?? 0),
                        'old_price' => $oldPrice,
                        'new_price' => $newPrice,
                        'quantity' => $quantity,
                    ]);
                } else {
                    // Mark as regular price
                    if (is_array($product)) {
                        $product['price_mode'] = 'regular';
                    } else {
                        $product->price_mode = 'regular';
                    }
                }
                
                return $product;
            });
            
            return $updatedProducts->toArray();
        }, 10, 2);

        // UI Components Hook - add wallet balance widget and indicators
        Hook::addFilter('ns-pos-ui-components', function ($components) {
            $specialCustomerService = app(SpecialCustomerService::class);
            $config = $specialCustomerService->getConfig();
            
            if ($config['groupId']) {
                $components[] = [
                    'type' => 'special_customer_indicator',
                    'template' => 'NsSpecialCustomer::pos.special-customer-indicator',
                    'position' => 'customer_info',
                ];
                
                $components[] = [
                    'type' => 'wallet_balance_widget',
                    'template' => 'NsSpecialCustomer::pos.wallet-balance-widget',
                    'position' => 'sidebar',
                ];
            }
            
            return $components;
        });
    }

    /**
     * Register UI components and JavaScript
     */
    private function registerUIComponents(): void
    {
        // Register customer account hooks for transaction labeling
        Hook::addFilter('ns-customer-account-history-label', function ($label, $transaction) {
            return match ($transaction->reference) {
                'ns_special_topup' => __('Special Customer Top-up'),
                'ns_special_cashback' => __('Special Customer Cashback'),
                'ns_special_cashback_reversal' => __('Special Customer Cashback Reversal'),
                'ns_special_reconciliation' => __('Balance Reconciliation'),
                default => $label,
            };
        }, 10, 2);

        // Register order attributes hook
        Hook::addFilter('ns-order-attributes', function ($order) {
            $specialCustomerService = app(SpecialCustomerService::class);
            
            // Handle both array and object cases
            $customer = is_array($order) ? ($order['customer'] ?? null) : ($order->customer ?? null);

            if ($customer && $specialCustomerService->isSpecialCustomer($customer)) {
                $config = $specialCustomerService->getConfig();
                $orderYear = is_array($order) ? ($order['created_at'] ?? now()) : ($order->created_at ?? now());
                
                // Set discount fields for special customer (these will be used in __initOrder)
                if (is_array($order)) {
                    // These fields will be used by __initOrder when creating the order
                    if (!isset($order['discount_type']) || $order['discount_type'] === null) {
                        $order['discount_type'] = 'percentage';
                    }
                    if (!isset($order['discount_percentage']) || $order['discount_percentage'] === null) {
                        $order['discount_percentage'] = $config['discountPercentage'];
                    }
                    
                    $orderData = [
                        'is_special' => true,
                        'group_id' => $config['groupId'],
                        'discount_percentage' => $config['discountPercentage'],
                        'cashback_percentage' => $config['cashbackPercentage'],
                        'discount_stackable' => $config['applyDiscountStackable'],
                        'order_year' => is_object($orderYear) ? $orderYear->year : (is_array($orderYear) ? \Carbon\Carbon::parse($orderYear)->year : \Carbon\Carbon::parse($orderYear)->year),
                    ];
                    $order['special_customer_data'] = $orderData;
                } else {
                    // For objects, set the discount fields directly
                    if (!isset($order->discount_type) || $order->discount_type === null) {
                        $order->discount_type = 'percentage';
                    }
                    if (!isset($order->discount_percentage) || $order->discount_percentage === null) {
                        $order->discount_percentage = $config['discountPercentage'];
                    }
                    
                    $order->special_customer_data = [
                        'is_special' => true,
                        'group_id' => $config['groupId'],
                        'discount_percentage' => $config['discountPercentage'],
                        'cashback_percentage' => $config['cashbackPercentage'],
                        'discount_stackable' => $config['applyDiscountStackable'],
                        'order_year' => is_object($orderYear) ? $orderYear->year : (is_array($orderYear) ? \Carbon\Carbon::parse($orderYear)->year : \Carbon\Carbon::parse($orderYear)->year),
                    ];
                }
                
                \Log::info('Special customer discount attributes set', [
                    'discount_type' => is_array($order) ? ($order['discount_type'] ?? null) : ($order->discount_type ?? null),
                    'discount_percentage' => is_array($order) ? ($order['discount_percentage'] ?? null) : ($order->discount_percentage ?? null),
                ]);
            }

            return $order;
        });

        // Register footer event for Vue injections
        Hook::addAction('ns-dashboard-footer', function () {
            $specialCustomerService = app(SpecialCustomerService::class);
            $config = $specialCustomerService->getConfig();
            
            echo '<script>';
            echo 'window.nsSpecialCustomerConfig = ' . json_encode($config) . ';';
            echo 'window.nsSpecialCustomer = {';
            echo '    isSpecialCustomer: function(customer) {';
            echo '        if (!customer || !window.nsSpecialCustomerConfig.groupId) return false;';
            echo '        return customer.group_id === window.nsSpecialCustomerConfig.groupId;';
            echo '    },';
            echo '    checkCustomerSpecialStatus: async function(customerId) {';
            echo '        try {';
            echo '            const response = await nsHttpClient.get(`/api/special-customer/check/${customerId}`);';
            echo '            return response.data;';
            echo '        } catch (error) {';
            echo '            console.error("Error checking special customer status:", error);';
            echo '            return { isSpecial: false };';
            echo '        }';
            echo '    },';
            echo '    applySpecialPricing: function(product, customer) {';
            echo '        if (!this.isSpecialCustomer(customer)) return product;';
            echo '        var updatedProduct = Object.assign({}, product);';
            echo '        var wholesalePrice = this.getWholesalePrice(product);';
            echo '        var originalPrice = this.getOriginalPrice(product);';
            echo '        if (wholesalePrice > 0 && wholesalePrice < originalPrice) {';
            echo '            updatedProduct.special_price = wholesalePrice;';
            echo '            updatedProduct.original_price = originalPrice;';
            echo '            updatedProduct.wholesale_applied = true;';
            echo '            updatedProduct.savings = originalPrice - wholesalePrice;';
            echo '            updatedProduct.mode = "wholesale";';
            echo '        }';
            echo '        return updatedProduct;';
            echo '    },';
            echo '    getWholesalePrice: function(product) {';
            echo '        var posOptions = window.POS ? window.POS.getOptions() : {};';
            echo '        var priceWithTax = posOptions.ns_pos_price_with_tax === "yes";';
            echo '        if (priceWithTax) {';
            echo '            return parseFloat(product.wholesale_price_with_tax || product.wholesale_price || 0);';
            echo '        } else {';
            echo '            return parseFloat(product.wholesale_price_without_tax || product.wholesale_price || 0);';
            echo '        }';
            echo '    },';
            echo '    getOriginalPrice: function(product) {';
            echo '        var posOptions = window.POS ? window.POS.getOptions() : {};';
            echo '        var priceWithTax = posOptions.ns_pos_price_with_tax === "yes";';
            echo '        if (priceWithTax) {';
            echo '            return parseFloat(product.sale_price_with_tax || product.sale_price || product.unit_price || 0);';
            echo '        } else {';
            echo '            return parseFloat(product.sale_price_without_tax || product.sale_price || product.unit_price || 0);';
            echo '        }';
            echo '    },';
            echo '    calculateSpecialDiscount: function(orderTotal, customer) {';
            echo '        if (!this.isSpecialCustomer(customer)) return 0;';
            echo '        return orderTotal * (window.nsSpecialCustomerConfig.discountPercentage / 100);';
            echo '    },';
            echo '    updatePOSUI: function(customerData) {';
            echo '        if (customerData.is_special) {';
            echo '            this.showSpecialCustomerBadge(customerData);';
            echo '            this.updateWalletDisplay(customerData.wallet_balance);';
            echo '            this.highlightDiscountEligibility(customerData.discount_eligible);';
            echo '        } else {';
            echo '            this.hideSpecialCustomerFeatures();';
            echo '        }';
            echo '    },';
            echo '    showSpecialCustomerBadge: function(customerData) {';
            echo '        const customerInfo = document.querySelector(".customer-info");';
            echo '        if (customerInfo && !customerInfo.querySelector(".special-customer-badge")) {';
            echo '            const badge = document.createElement("span");';
            echo '            badge.className = "special-customer-badge badge bg-warning text-dark ms-2";';
            echo '            badge.innerHTML = \'<i class="fas fa-star"></i> \' + (customerData.special_badge || "Special");';
            echo '            customerInfo.appendChild(badge);';
            echo '        }';
            echo '    },';
            echo '    updateWalletDisplay: function(balance) {';
            echo '        let walletDisplay = document.querySelector(".wallet-balance-display");';
            echo '        if (!walletDisplay) {';
            echo '            walletDisplay = document.createElement("div");';
            echo '            walletDisplay.className = "wallet-balance-display alert alert-info mt-2";';
            echo '            const customerSection = document.querySelector(".customer-section");';
            echo '            if (customerSection) customerSection.appendChild(walletDisplay);';
            echo '        }';
            echo '        walletDisplay.innerHTML = \'<i class="fas fa-wallet"></i> Wallet Balance: \' + nsCurrency.define(balance);';
            echo '    },';
            echo '    highlightDiscountEligibility: function(eligible) {';
            echo '        const orderSummary = document.querySelector(".order-summary");';
            echo '        if (orderSummary) {';
            echo '            let discountInfo = orderSummary.querySelector(".special-discount-info");';
            echo '            if (eligible && !discountInfo) {';
            echo '                discountInfo = document.createElement("div");';
            echo '                discountInfo.className = "special-discount-info alert alert-success mt-2";';
            echo '                discountInfo.innerHTML = \'<i class="fas fa-percentage"></i> Special customer discount will be applied at checkout\';';
            echo '                orderSummary.appendChild(discountInfo);';
            echo '            } else if (!eligible && discountInfo) {';
            echo '                discountInfo.remove();';
            echo '            }';
            echo '        }';
            echo '    },';
            echo '    hideSpecialCustomerFeatures: function() {';
            echo '        const badge = document.querySelector(".special-customer-badge");';
            echo '        const walletDisplay = document.querySelector(".wallet-balance-display");';
            echo '        const discountInfo = document.querySelector(".special-discount-info");';
            echo '        if (badge) badge.remove();';
            echo '        if (walletDisplay) walletDisplay.remove();';
            echo '        if (discountInfo) discountInfo.remove();';
            echo '    },';
            echo '    processOrderWithSpecialPricing: function(orderData) {';
            echo '        if (orderData.customer && this.isSpecialCustomer(orderData.customer)) {';
            echo '            let totalDiscount = 0;';
            echo '            orderData.products.forEach(product => {';
            echo '                const pricedProduct = this.applySpecialPricing(product, orderData.customer);';
            echo '                if (pricedProduct.wholesale_applied) {';
            echo '                    product.special_price = pricedProduct.special_price;';
            echo '                    product.original_price = pricedProduct.original_price;';
            echo '                    product.savings = pricedProduct.savings;';
            echo '                    totalDiscount += pricedProduct.savings;';
            echo '                }';
            echo '            });';
            echo '            const specialDiscount = this.calculateSpecialDiscount(orderData.subtotal, orderData.customer);';
            echo '            if (specialDiscount > 0) {';
            echo '                orderData.discounts = orderData.discounts || [];';
            echo '                orderData.discounts.push({';
            echo '                    type: "special_customer",';
            echo '                    label: "Special Customer Discount",';
            echo '                    amount: specialDiscount,';
            echo '                    percentage: window.nsSpecialCustomerConfig.discountPercentage';
            echo '                });';
            echo '                totalDiscount += specialDiscount;';
            echo '            }';
            echo '            orderData.special_discount_total = totalDiscount;';
            echo '        }';
            echo '        return orderData;';
            echo '    },';
            echo '    getConfig: function() {';
            echo '        return window.nsSpecialCustomerConfig;';
            echo '    },';
            echo '    initPOSIntegration: function() {';
            echo '        // Listen for customer selection events';
            echo '        document.addEventListener("customer-selected", (event) => {';
            echo '            const customerData = event.detail;';
            echo '            this.updatePOSUI(customerData);';
            echo '        });';
            echo '        // Listen for product updates';
            echo '        document.addEventListener("product-added", (event) => {';
            echo '            const product = event.detail.product;';
            echo '            const customer = window.posState?.customer;';
            echo '            if (customer && this.isSpecialCustomer(customer)) {';
            echo '                const pricedProduct = this.applySpecialPricing(product, customer);';
            echo '                if (pricedProduct.wholesale_applied) {';
            echo '                    this.updateProductDisplay(product.id, pricedProduct);';
            echo '                }';
            echo '            }';
            echo '        });';
            echo '    },';
            echo '    updateProductDisplay: function(productId, pricedProduct) {';
            echo '        const productRow = document.querySelector(`[data-product-id="${productId}"]`);';
            echo '        if (productRow) {';
            echo '            const priceCell = productRow.querySelector(".product-price");';
            echo '            if (priceCell && pricedProduct.wholesale_applied) {';
            echo '                priceCell.innerHTML = `<del>${nsCurrency.define(pricedProduct.original_price)}</del> ${nsCurrency.define(pricedProduct.special_price)} <span class="badge bg-success">Wholesale</span>`;';
            echo '            }';
            echo '        }';
            echo '    }';
            echo '};';
            echo '// Initialize POS integration when DOM is ready';
            echo 'if (document.readyState === "loading") {';
            echo '    document.addEventListener("DOMContentLoaded", () => {';
            echo '        window.nsSpecialCustomer.initPOSIntegration();';
            echo '    });';
            echo '} else {';
            echo '    window.nsSpecialCustomer.initPOSIntegration();';
            echo '}';
            echo '</script>';
        });
    }
}

