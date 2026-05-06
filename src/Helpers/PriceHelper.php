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
 */
if (!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol = '$', $decimals = 2, $is_prefix = true): string
    {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $formatted . ' ' . $symbol;
    }
}

/**
 * جلب إعدادات العملة من branch_settings حسب branch_id.
 * يرجع مصفوفة جاهزة للاستخدام مع fmt_price().
 *
 * استخدام:
 *   $cur = load_branch_currency($pdo, $branch_id);
 *   echo fmt_price(1500, $cur['symbol'], $cur['decimals'], $cur['prefix']);
 */
if (!function_exists('load_branch_currency')) {
    function load_branch_currency(PDO $pdo, ?int $branch_id): array
    {
        static $cache = [];
        $key = (string)$branch_id;

        if (isset($cache[$key])) return $cache[$key];

        $row = null;
        if ($branch_id) {
            $stmt = $pdo->prepare(
                "SELECT currency_symbol, currency_symbol_en, currency_decimals
                 FROM branch_settings WHERE branch_id = ? LIMIT 1"
            );
            $stmt->execute([$branch_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $prefixes  = ['$', '€', '₺', '£', '¥'];
        $symbol    = ($row['currency_symbol']    ?? null) ?: '$';
        $symbol_en = ($row['currency_symbol_en'] ?? null) ?: $symbol;
        $decimals  = intval($row['currency_decimals'] ?? 2);

        $result = [
            'symbol'     => $symbol,
            'symbol_en'  => $symbol_en,
            'decimals'   => $decimals,
            'prefix'     => in_array($symbol,    $prefixes, true),
            'prefix_en'  => in_array($symbol_en, $prefixes, true),
        ];

        $cache[$key] = $result;
        return $result;
    }
}