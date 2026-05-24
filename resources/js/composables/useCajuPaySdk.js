/**
 * useCajuPaySdk
 *
 * Helpers para carregar o SDK do checkout CajuPay (CDN) e montar o widget
 * no modo `embeddedOnly`. O backend cria a sessão (`/checkout/cajupay/session`)
 * e devolve o `token` público; o SDK roda no navegador apenas com esse token.
 */

const SDK_URL = 'https://cdn.cajupay.com.br/sdk/v1/cajupay-sdk.min.js';
const SDK_BASE_URL = 'https://api.cajupay.com.br';

let sdkPromise = null;

/**
 * Carrega o script do SDK CajuPay (idempotente).
 *
 * @returns {Promise<typeof window.CajuPaySDK>}
 */
export function loadCajuPaySdk() {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('CajuPay SDK só pode ser carregado no navegador.'));
    }
    if (window.CajuPaySDK) {
        return Promise.resolve(window.CajuPaySDK);
    }
    if (sdkPromise) {
        return sdkPromise;
    }

    sdkPromise = new Promise((resolve, reject) => {
        const existing = document.querySelector(`script[src="${SDK_URL}"]`);
        const handle = (script) => {
            script.addEventListener('load', () => {
                if (window.CajuPaySDK) {
                    resolve(window.CajuPaySDK);
                } else {
                    sdkPromise = null;
                    reject(new Error('CajuPay SDK carregado, mas window.CajuPaySDK não existe.'));
                }
            });
            script.addEventListener('error', () => {
                sdkPromise = null;
                reject(new Error('Falha ao carregar o SDK da CajuPay.'));
            });
        };

        if (existing) {
            handle(existing);
            return;
        }

        const script = document.createElement('script');
        script.src = SDK_URL;
        script.async = true;
        handle(script);
        document.head.appendChild(script);
    });

    return sdkPromise;
}

/**
 * Monta o checkout SDK em modo `embeddedOnly` no container indicado e
 * retorna o controller (com `.confirm()`, `.setPayer()`).
 *
 * @param {string} containerSelector  Seletor CSS do container (ex.: '#cajupay-method').
 * @param {{ token: string, defaultMethod?: string, initialPayer?: object, baseUrl?: string, onStatus?: (event: any) => void }} opts
 * @returns {Promise<{ confirm: () => Promise<any>, setPayer?: (p: object) => any, [k: string]: any }>}
 */
export async function mountCajuPayCheckout(containerSelector, opts) {
    if (!opts || !opts.token) {
        throw new Error('CajuPay: token público da sessão é obrigatório.');
    }
    const sdk = await loadCajuPaySdk();
    if (!sdk?.init) {
        throw new Error('CajuPay SDK não expõe init().');
    }
    const instance = sdk.init({ baseUrl: opts.baseUrl || SDK_BASE_URL });
    if (!instance?.mountCheckout) {
        throw new Error('CajuPay SDK não expõe mountCheckout().');
    }
    return await instance.mountCheckout(containerSelector, {
        token: opts.token,
        defaultMethod: opts.defaultMethod || 'card',
        embeddedOnly: true,
        initialPayer: opts.initialPayer || undefined,
        onStatus: typeof opts.onStatus === 'function' ? opts.onStatus : undefined,
    });
}

/**
 * Wrapper de `controller.confirm()` que normaliza erros para uma mensagem
 * amigável.
 *
 * @param {{ confirm: () => Promise<any> }} controller
 * @returns {Promise<any>}
 */
export async function confirmCajuPayController(controller) {
    if (!controller || typeof controller.confirm !== 'function') {
        throw new Error('CajuPay: widget não está pronto. Recarregue a página.');
    }
    try {
        return await controller.confirm();
    } catch (err) {
        const msg = err?.message || err?.error || err?.toString?.() || 'Falha ao confirmar pagamento na CajuPay.';
        const e = new Error(msg);
        e.cause = err;
        throw e;
    }
}

/**
 * Atualiza o payer (name/email/document) no controller atual do SDK SEM remontar.
 * Indicação oficial CajuPay para fluxo embeddedOnly: chame setPayer() antes do
 * controller.confirm() — assim o SDK envia payer_name / payer_email / payer_document
 * no POST /api/sdk/public/checkout/sessions/{token}/confirm com os dados que o
 * cliente preencheu no SEU formulário. Funciona pra TODOS os métodos (card inclusive)
 * e evita destruir os inputs do cartão que o cliente já digitou.
 *
 * O SDK ignora silenciosamente campos não suportados (ex.: phone), então mandamos
 * só os 3 que o /confirm valida (name + email + document).
 *
 * @param {{ setPayer: (payer: { name?: string, email?: string, document?: string }) => any }} controller
 * @param {{ name?: string, email?: string, document?: string }} payer
 */
export function setCajuPayPayer(controller, payer) {
    if (!controller || typeof controller.setPayer !== 'function') {
        // SDK antigo (anterior à atualização que adicionou setPayer). Cai pro fallback
        // de re-mount feito pelo caller. Não joga erro pra não quebrar quem usa CDN
        // sem cache-busting.
        return false;
    }
    const cleaned = {};
    if (payer && typeof payer === 'object') {
        if (typeof payer.name === 'string' && payer.name.trim() !== '') cleaned.name = payer.name.trim();
        if (typeof payer.email === 'string' && payer.email.trim() !== '') cleaned.email = payer.email.trim();
        if (typeof payer.document === 'string' && payer.document.trim() !== '') {
            cleaned.document = payer.document.replace(/\D/g, '');
        }
    }
    try {
        controller.setPayer(cleaned);
        return true;
    } catch (_) {
        return false;
    }
}

/**
 * Mapeia o método do Getfy para o nome aceito pelo SDK em `defaultMethod`.
 *
 * @param {string} method  pix|card|apple_pay|google_pay
 * @returns {string}
 */
// IMPORTANTE: deve retornar EXATAMENTE os mesmos slugs que aparecem em
// session.methods_available da CajuPay. O SDK em embeddedOnly: true não mostra seletor
// de método próprio e usa defaultMethod pra escolher o método inicial — se o valor não
// bater com algum item de methods_available, o SDK cai no PRIMEIRO da lista (geralmente
// 'card' porque a regra "Wallets implicam cartão" promove card pra lista). Aí o cliente
// clica em "Google Pay" no nosso UI, mas o SDK monta o formulário de cartão. Doc CajuPay:
// "defaultMethod é obrigatório quando embeddedOnly: true e o pagador escolheu wallet".
export function cajupayDefaultMethodFor(method) {
    switch (method) {
        case 'apple_pay':
            return 'apple_pay';
        case 'google_pay':
            return 'google_pay';
        case 'pix':
            return 'pix';
        default:
            return 'card';
    }
}
