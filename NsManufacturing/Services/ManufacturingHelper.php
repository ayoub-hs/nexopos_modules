<?php

namespace Modules\NsManufacturing\Services;

trait ManufacturingHelper
{
    /**
     * Format a number using NexoPOS standard settings.
     * 
     * @param float|int|string $value
     * @param int|null $precision
     * @return string
     */
    public function formatNumber($value, $precision = null)
    {
        $precision = $precision !== null ? $precision : ns()->option->get('ns_currency_precision', 2);
        $decimalSeparator = ns()->option->get('ns_currency_decimal_separator', '.');
        $thousandSeparator = ns()->option->get('ns_currency_thousand_separator', ',');

        return number_format(
            (float) $value,
            $precision,
            $decimalSeparator,
            $thousandSeparator
        );
    }

    /**
     * Format a value as currency using NexoPOS standard settings.
     * 
     * @param float|int|string $value
     * @return string
     */
    public function formatCurrency($value)
    {
        return (string) ns()->currency->define($value);
    }
}
