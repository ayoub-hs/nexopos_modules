<?php

namespace Modules\NsSpecialCustomer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Modules\NsSpecialCustomer\Services\SpecialCustomerService;

/**
 * Process Topup Request Validation
 * 
 * Validates customer top-up requests with proper security checks,
 * amount validation, and business rule enforcement.
 */
class ProcessTopupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->can('special.customer.manage');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $specialCustomerService = app(SpecialCustomerService::class);
        $config = $specialCustomerService->getConfig();

        return [
            'customer_id' => [
                'required',
                'integer',
                'exists:nexopos_customers,id',
                function ($attribute, $value, $fail) use ($specialCustomerService) {
                    $customer = \App\Models\Customer::find($value);
                    if (!$customer || !$specialCustomerService->isSpecialCustomer($customer)) {
                        $fail('The selected customer is not a special customer.');
                    }
                },
            ],
            'amount' => [
                'required',
                'numeric',
                'min:' . $config['min_topup_amount'] ?? 1,
                'max:' . $config['max_topup_amount'] ?? 10000,
                'regex:/^\d+(\.\d{1,2})?$/',
            ],
            'description' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\s\-_.(),&@#]+$/', // Allow safe characters
            ],
            'reference' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\-_]+$/', // Alphanumeric with hyphens and underscores
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Please select a customer.',
            'customer_id.exists' => 'The selected customer does not exist.',
            'customer_id.integer' => 'Invalid customer ID format.',
            'amount.required' => 'Please enter an amount.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The minimum top-up amount is :min.',
            'amount.max' => 'The maximum top-up amount is :max.',
            'amount.regex' => 'The amount format is invalid. Use up to 2 decimal places.',
            'description.max' => 'The description must not exceed 255 characters.',
            'description.regex' => 'The description contains invalid characters.',
            'reference.max' => 'The reference must not exceed 100 characters.',
            'reference.regex' => 'The reference can only contain letters, numbers, hyphens, and underscores.',
        ];
    }

    /**
     * Get custom attributes for validation errors.
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'customer',
            'amount' => 'top-up amount',
            'description' => 'description',
            'reference' => 'reference',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isEmpty()) {
                $this->validateBusinessRules($validator);
            }
        });
    }

    /**
     * Validate business-specific rules.
     */
    protected function validateBusinessRules($validator): void
    {
        $customer = \App\Models\Customer::find($this->customer_id);
        $amount = (float) $this->amount;

        // Check if customer account is in good standing
        if ($customer && $customer->account_amount < 0 && abs($amount) > abs($customer->account_amount)) {
            $validator->errors()->add('amount', 'Insufficient balance for this operation.');
        }

        // Check for suspicious activity (multiple large top-ups in short time)
        if ($amount >= 1000) {
            $recentTopups = \App\Models\CustomerAccountHistory::where('customer_id', $this->customer_id)
                ->where('reference', 'ns_special_topup')
                ->where('amount', '>=', 1000)
                ->where('created_at', '>=', now()->subHours(24))
                ->count();

            if ($recentTopups >= 3) {
                $validator->errors()->add('amount', 'Multiple large top-ups detected. Please contact support.');
            }
        }

        // Validate daily top-up limits
        $dailyTotal = \App\Models\CustomerAccountHistory::where('customer_id', $this->customer_id)
            ->where('reference', 'ns_special_topup')
            ->whereDate('created_at', now())
            ->sum('amount');

        $dailyLimit = config('ns-special-customer.daily_topup_limit', 5000);
        if (($dailyTotal + $amount) > $dailyLimit) {
            $validator->errors()->add('amount', "Daily top-up limit of {$dailyLimit} exceeded.");
        }
    }

    /**
     * Get validated data with proper type casting.
     */
    public function getValidatedData(): array
    {
        $data = parent::validated();
        
        // Type casting
        $data['customer_id'] = (int) $data['customer_id'];
        $data['amount'] = (float) $data['amount'];
        
        // Set defaults
        $data['description'] = $data['description'] ?? 'Special customer top-up';
        $data['reference'] = $data['reference'] ?? 'ns_special_topup';
        
        return $data;
    }
}
