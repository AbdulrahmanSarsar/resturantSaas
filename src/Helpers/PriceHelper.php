<?php
/**
 * MenuPro — Price & Currency Helper
 *
 * Single source of truth for all currency formatting.
 * Uses bracket-style namespaces so the PriceHelper CLASS stays in
 * MenuPro\Helpers (for the autoloader), while fmt_price() and
 * load_branch_currency() land in the GLOBAL namespace so any page
 * can call them without a namespace prefix.
 */

// ============================================================
// 1. Namespaced class (MenuPro\Helpers\PriceHelper)
// ============================================================
namespace MenuPro\Helpers {

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

} // end namespace MenuPro\Helpers


// ============================================================
// 2. Global functions (namespace \ — accessible from anywhere)
// ============================================================
namespace {

/**
 * Global backward-compatible price formatter.
 * Maps to the old fmt_price() signature used across the codebase.
 *
 *   echo fmt_price(1500, 'ل.س', 0, false);  // "1,500 ل.س"
 *   echo fmt_price(9.99, '$', 2, true);      // "$9.99"
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

} // end namespace (global)
