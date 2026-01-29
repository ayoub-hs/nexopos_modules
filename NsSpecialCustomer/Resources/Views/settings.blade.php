<?php
use App\Classes\Hook;
?>
@extends('layout.dashboard')

@section('layout.dashboard.body')
    <div class="h-full flex flex-col">
        @include(Hook::filter('ns-dashboard-header-file', '../common/dashboard-header'))
        <div class="flex-auto overflow-y-auto">
            <div class="ns-container px-4 py-4">
                <div class="bg-white shadow rounded p-6">
                    <h1 class="text-2xl font-bold mb-4">Special Customer Settings</h1>
                    <p class="mb-4">Configure discount percentages, cashback rates, and other special customer preferences.</p>
                    
                    <form id="settings-form" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Discount Percentage (%)</label>
                                <div class="relative">
                                    <input type="number" name="discount_percentage" step="0.01" min="0" max="100" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                    <span class="absolute right-3 top-2 text-gray-500">%</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Default discount for special customers</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Cashback Percentage (%)</label>
                                <div class="relative">
                                    <input type="number" name="cashback_percentage" step="0.01" min="0" max="100" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                    <span class="absolute right-3 top-2 text-gray-500">%</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Yearly cashback percentage</p>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="flex items-center">
                                <input type="checkbox" name="apply_discount_stackable" id="apply_discount_stackable" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                <label for="apply_discount_stackable" class="ml-3">
                                    <span class="text-sm font-medium text-gray-700">Allow discount stacking with coupons</span>
                                    <p class="text-sm text-gray-500">If enabled, special discount can be combined with other discounts</p>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-between items-center pt-6 border-t">
                            <button type="button" onclick="resetToDefaults()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="las la-undo mr-2"></i> Reset to Defaults
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="las la-save mr-2"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('layout.dashboard.footer.js')
<script>
document.addEventListener('DOMContentLoaded', async function() {
    await loadCurrentSettings();
    
    document.getElementById('settings-form').addEventListener('submit', saveSettings);
});

async function loadCurrentSettings() {
    try {
        const response = await fetch('/api/special-customer/config');
        const result = await response.json();
        
        if (result.status === 'success') {
            const config = result.data;
            document.querySelector('input[name="discount_percentage"]').value = config.discountPercentage;
            document.querySelector('input[name="cashback_percentage"]').value = config.cashbackPercentage;
            document.querySelector('input[name="apply_discount_stackable"]').checked = config.applyDiscountStackable;
        }
    } catch (error) {
        console.error('Failed to load settings:', error);
    }
}

async function saveSettings(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = {
        discount_percentage: parseFloat(formData.get('discount_percentage')),
        cashback_percentage: parseFloat(formData.get('cashback_percentage')),
        apply_discount_stackable: formData.has('apply_discount_stackable')
    };

    try {
        const response = await fetch('/api/special-customer/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();
        
        if (result.status === 'success') {
            showNotification('Settings updated successfully', 'success');
        } else {
            showNotification(result.message || 'Failed to update settings', 'error');
        }
    } catch (error) {
        console.error('Failed to update settings:', error);
        showNotification('Failed to update settings', 'error');
    }
}

function resetToDefaults() {
    if (confirm('Are you sure you want to reset all settings to defaults?')) {
        document.querySelector('input[name="discount_percentage"]').value = 7.0;
        document.querySelector('input[name="cashback_percentage"]').value = 2.0;
        document.querySelector('input[name="apply_discount_stackable"]').checked = false;
    }
}

function showNotification(message, type) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
        type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
    }`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="las ${type === 'success' ? 'la-check-circle' : 'la-exclamation-circle'} mr-2"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.remove();
    }, 3000);
}
</script>
@endsection
