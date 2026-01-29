<?php

namespace Modules\NsSpecialCustomer\Listeners;

use App\Events\RenderFooterEvent;
use Modules\NsSpecialCustomer\Services\SpecialCustomerService;

class RenderFooterListener
{
    private SpecialCustomerService $specialCustomerService;

    public function __construct(SpecialCustomerService $specialCustomerService)
    {
        $this->specialCustomerService = $specialCustomerService;
    }

    public function handle(RenderFooterEvent $event): void
    {
        if ($event->routeName === 'ns.dashboard.home') {
            // Add special customer dashboard widget
            $event->output->addView('NsSpecialCustomer::widgets.dashboard');
        }

        // Inject Vue component for POS integration
        if (str_starts_with($event->routeName, 'ns.pos')) {
            $config = $this->specialCustomerService->getConfig();
            
            $event->output->addInlineScript("
                <script>
                window.nsSpecialCustomerConfig = " . json_encode($config) . ";
                
                // Special Customer POS Integration
                window.nsSpecialCustomer = {
                    isSpecialCustomer: function(groupId) {
                        return groupId === window.nsSpecialCustomerConfig.groupId;
                    },
                    
                    applySpecialPricing: function(product, customerData) {
                        if (!customerData || !customerData.isSpecial) {
                            return product;
                        }
                        
                        var updatedProduct = Object.assign({}, product);
                        
                        // Apply wholesale pricing if available
                        if (product.wholesale_price && product.wholesale_price > 0) {
                            updatedProduct.special_price = product.wholesale_price;
                        }
                        
                        return updatedProduct;
                    },
                    
                    getConfig: function() {
                        return window.nsSpecialCustomerConfig;
                    }
                };
                </script>
            ");
        }
    }
}
