import { ref, computed, watch, toValue } from 'vue';

const LOCALE_KEY = 'checkout_locale';
const CURRENCY_KEY = 'checkout_currency';
const SUPPORTED_LOCALES = ['pt_BR', 'en', 'es'];

/**
 * @param {Object} options
 * @param {Record<string, Record<string, string>>} options.translations - checkout_translations
 * @param {Array<{ code: string, symbol: string, label: string, rate_to_brl: number }>} options.currencies
 * @param {string} [options.suggestedLocale] - suggested_locale from backend
 * @param {string} [options.suggestedCurrency] - suggested_currency from backend
 * @param {string} [options.storageKey] - e.g. checkout_slug for localStorage keys
 * @param {import('vue').MaybeRefOrGetter<{ enabled?: boolean, locale?: string|null, currency?: string|null }|null|undefined>} [options.checkoutForce] - forçar idioma/moeda do produto (quando sem escolha manual)
 * @param {import('vue').MaybeRefOrGetter<Record<string, number>|null|undefined>} [options.customDisplayPricesByCurrency] - preços fixos por moeda (exibição)
 * @param {import('vue').MaybeRefOrGetter<boolean>} [options.skipCustomDisplayPrices] - ex.: cupom ativo — usa só conversão por taxa
 */
export function useCheckoutLocale(options = {}) {
    const {
        translations = {},
        currencies = [],
        suggestedLocale = 'pt_BR',
        suggestedCurrency = 'BRL',
        storageKey = 'default',
        checkoutForce = null,
        customDisplayPricesByCurrency = null,
        skipCustomDisplayPrices = false,
    } = options;

    const localeStorageKey = `${LOCALE_KEY}_${storageKey}`;
    const currencyStorageKey = `${CURRENCY_KEY}_${storageKey}`;

    function getStoredLocale() {
        try {
            const v = localStorage.getItem(localeStorageKey);
            return SUPPORTED_LOCALES.includes(v) ? v : null;
        } catch {
            return null;
        }
    }

    function getStoredCurrency() {
        try {
            const v = localStorage.getItem(currencyStorageKey);
            const codes = currencies.map((c) => c.code);
            return v && codes.includes(v) ? v : null;
        } catch {
            return null;
        }
    }

    function readManualChoiceFlag() {
        try {
            if (typeof window === 'undefined') return false;
            return !!localStorage.getItem(`checkout_locale_manual_${storageKey}`);
        } catch {
            return false;
        }
    }

    const codes = (Array.isArray(currencies) ? currencies : []).map((c) => c?.code).filter(Boolean);
    const manual = readManualChoiceFlag();
    const force = checkoutForce != null ? toValue(checkoutForce) : null;
    let initialLocale = suggestedLocale || 'pt_BR';
    let initialCurrency = suggestedCurrency || 'BRL';
    if (manual) {
        initialLocale = getStoredLocale() || initialLocale;
        initialCurrency = getStoredCurrency() || initialCurrency;
    } else if (force && force.enabled) {
        if (force.locale && SUPPORTED_LOCALES.includes(force.locale)) {
            initialLocale = force.locale;
        }
        if (force.currency && codes.includes(force.currency)) {
            initialCurrency = force.currency;
        }
    } else if (typeof window !== 'undefined') {
        initialLocale = getStoredLocale() || initialLocale;
        initialCurrency = getStoredCurrency() || initialCurrency;
    }

    const locale = ref(initialLocale);
    const currency = ref(initialCurrency);

    watch(
        locale,
        (v) => {
            try {
                if (v) localStorage.setItem(localeStorageKey, v);
            } catch (_) {}
        },
        { immediate: true }
    );
    watch(
        currency,
        (v) => {
            try {
                if (v) localStorage.setItem(currencyStorageKey, v);
            } catch (_) {}
        },
        { immediate: true }
    );

    function setLocale(v) {
        if (SUPPORTED_LOCALES.includes(v)) locale.value = v;
    }

    function setCurrency(v) {
        const codes = currencies.map((c) => c.code);
        if (codes.includes(v)) currency.value = v;
    }

    function t(key) {
        const loc = locale.value || 'pt_BR';
        const byLocale = translations[loc] || translations.pt_BR || {};
        return byLocale[key] != null ? byLocale[key] : key;
    }

    const currencyList = computed(() => (Array.isArray(currencies) ? currencies : []));

    const currentCurrencyObj = computed(
        () => currencyList.value.find((c) => c.code === currency.value) || currencyList.value[0] || { code: 'BRL', symbol: 'R$', label: 'Real', rate_to_brl: 1 }
    );

    /** Converte preço em BRL para a moeda selecionada (price_brl * rate_to_brl), ou valor fixo do produto quando configurado. */
    function priceInCurrency(priceBrl) {
        const n = Number(priceBrl);
        if (Number.isNaN(n)) return 0;
        const code = currency.value;
        if (!toValue(skipCustomDisplayPrices) && code && code !== 'BRL') {
            const map = toValue(customDisplayPricesByCurrency);
            if (map && typeof map === 'object') {
                const custom = map[code] ?? map[String(code).toUpperCase()];
                const cnum = Number(custom);
                if (!Number.isNaN(cnum) && cnum > 0) {
                    return Math.round(cnum * 100) / 100;
                }
            }
        }
        const obj = currentCurrencyObj.value;
        const rate = Number(obj.rate_to_brl) || 1;
        return Math.round(n * rate * 100) / 100;
    }

    function formatPrice(value, currencyCode) {
        const code = currencyCode || currency.value || 'BRL';
        const localeForFormat = code === 'BRL' ? 'pt-BR' : code === 'EUR' ? 'de-DE' : 'en-US';
        return new Intl.NumberFormat(localeForFormat, {
            style: 'currency',
            currency: code,
        }).format(value);
    }

    return {
        locale,
        setLocale,
        currency,
        setCurrency,
        t,
        currencies: currencyList,
        currentCurrencyObj,
        priceInCurrency,
        formatPrice,
        supportedLocales: SUPPORTED_LOCALES,
    };
}
