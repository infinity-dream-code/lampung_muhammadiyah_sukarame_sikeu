/**
 * Format No. VA: prefix + NIS (total 16 digit).
 * Contoh: 777726 + 123 => 7777260000000123
 */
(function (window) {
    const DEFAULT_PREFIX = '777726';
    const TOTAL_LENGTH = 16;

    function normalizePrefix(prefix) {
        const digits = String(prefix ?? '').replace(/\D/g, '');
        if (!digits || digits === '77726' || digits.length < 6) {
            return DEFAULT_PREFIX;
        }
        return digits;
    }

    window.formatNoVA = function (nis, prefix) {
        const vaPrefix = normalizePrefix(prefix ?? window.APP_VA_PREFIX ?? DEFAULT_PREFIX);
        const digits = String(nis ?? '').replace(/\D/g, '');
        if (!digits) {
            return '';
        }
        const padLen = Math.max(1, TOTAL_LENGTH - vaPrefix.length);
        return vaPrefix + digits.padStart(padLen, '0');
    };
})(window);
