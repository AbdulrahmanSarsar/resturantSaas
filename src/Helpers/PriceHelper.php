<?php
/**
 * MenuPro — Price & Currency Helper
 * 
 * Single source of truth for all currency formatting.
 * Replaces the scattered fmt_price() / fmtPrice() definitions
 * that were duplicated across cart.php, invoice.php, reports.php, etc.
 * 
 * Usage:
 *   $price = new PriceHelper('ل.س', 'S.P', 0);
 *   echo $price->format(15000);       // "ل.س 15,000"
 *   echo $price->format(15000, 'en'); // "S.P 15,000"
 *   echo $price->jsFormatter();       // JS function for templates
 */

namespace MenuPro\Helpers;

class PriceHelper
{
    private string $symbolAr;
    private string $symbolEn;
    private int    $decimals;
    
    // Symbols that go before the number (prefix currencies)
    private const PREFIX_SYMBOLS = ['$', '€', '₺', '£', '¥'];

    public function __construct(
        string $symbolAr = '$',
        string $symbolEn = '$',
        int    $decimals = 2
    ) {
        $this->symbolAr = $symbolAr;
        $this->symbolEn = $symbolEn;
        $this->decimals  = max(0, min(4, $decimals)); // Clamp 0-4
    }

    /**
     * Create from branch settings array.
     */
    public static function fromBranch(array $settings): self
    {
        return new self(
            $settings['currency_symbol']    ?? '$',
            $settings['currency_symbol_en'] ?? '$',
            intval($settings['currency_decimals'] ?? 2)
        );
    }

    /**
     * Format a price amount with the appropriate currency symbol.
     * 
     * @param float|string $amount The price amount
     * @param string       $lang   'ar' or 'en'
     * @return string Formatted price string
     */
    public function format($amount, string $lang = 'ar'): string
    {
        $amount = floatval($amount);
        $symbol = ($lang === 'en') ? $this->symbolEn : $this->symbolAr;
        $formatted = number_format($amount, $this->decimals);
        
        if ($this->isPrefix($symbol)) {
            return $symbol . $formatted;
        }
        
        return $formatted . ' ' . $symbol;
    }

    /**
     * Format as integer (no decimals) — useful for currencies like SYP.
     */
    public function formatRound($amount, string $lang = 'ar'): string
    {
        $amount = round(floatval($amount));
        $symbol = ($lang === 'en') ? $this->symbolEn : $this->symbolAr;
        $formatted = number_format($amount, 0);
        
        if ($this->isPrefix($symbol)) {
            return $symbol . $formatted;
        }
        
        return $formatted . ' ' . $symbol;
    }

    /**
     * Get the raw numeric format (no symbol).
     */
    public function formatNumber($amount): string
    {
        return number_format(floatval($amount), $this->decimals);
    }

    /**
     * Check if a symbol should be placed before the number.
     */
    private function isPrefix(string $symbol): bool
    {
        return in_array($symbol, self::PREFIX_SYMBOLS, true);
    }

    /**
     * Generate a JavaScript function for client-side price formatting.
     * Outputs a self-contained function that matches the PHP behavior.
     */
    public function jsFormatter(): string
    {
        $arSymbol = json_encode($this->symbolAr);
        $enSymbol = json_encode($this->symbolEn);
        $decimals = $this->decimals;
        $prefixes = json_encode(self::PREFIX_SYMBOLS);
        
        return <<<JS
        function fmtPrice(amount, lang) {
            lang = lang || document.documentElement.lang || 'ar';
            var symbol = (lang === 'en') ? {$enSymbol} : {$arSymbol};
            var decimals = {$decimals};
            var prefixes = {$prefixes};
            var formatted = Number(amount).toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
            if (prefixes.indexOf(symbol) !== -1) {
                return symbol + formatted;
            }
            return formatted + ' ' + symbol;
        }
        JS;
    }

    /**
     * Get symbol for a specific language.
     */
    public function symbol(string $lang = 'ar'): string
    {
        return ($lang === 'en') ? $this->symbolEn : $this->symbolAr;
    }

    /**
     * Get decimal count.
     */
    public function decimals(): int
    {
        return $this->decimals;
    }
}

/**
 * Global backward-compatible function.
 * Maps to the old fmt_price() signature used across the codebase.
 * 
 * @deprecated Use PriceHelper::format() instead
 */
if (!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol = '$', $decimals = 2, $is_prefix = true): string
    {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $formatted . ' ' . $symbol;
    }
}