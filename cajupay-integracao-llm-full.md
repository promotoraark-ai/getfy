# CajuPay — Documentação completa para LLMs
> Gerado em 2026-05-22T00:41:05.548Z. Não edite à mão — rode `npm run build:llm-docs` no frontend.
> Pacote modular: https://cajupay.com.br/docs/llm/
---

---

<!-- module: 00-instructions-for-llm -->


# Instruções para o modelo de IA

## INSTRUÇÕES PARA O MODELO (obrigatório)

1. **Não invente rotas.** Use apenas endpoints documentados em `https://api.cajupay.com.br` (base deste pacote).
2. **Segredos só no servidor.** `X-API-Secret` nunca vai para o browser, bundle frontend ou repositório público.
3. **Valores em centavos.** `amount_cents: 2590` = R$ 25,90. Moeda padrão: `BRL`.
4. **Cartão e wallets exigem HTTPS** na página do checkout em produção; HTTP local costuma falhar (PSP / formulário embed).
5. **PCI:** PAN/CVV não passam pelo backend do integrador — use o SDK CajuPay (`embeddedOnly`) ou API server-side PIX.
6. **Rotas tipo `/checkout/cajupay/*` não existem na CajuPay** — o integrador implementa wrappers no próprio backend; a API nativa é `/api/sdk/v1/...` e `/api/payments/pix`.
7. **Wallets:** filtrar Apple Pay (iOS) vs Google Pay (Android/desktop); passar `defaultMethod` igual ao botão clicado; ocultar botão "Pagar" do host quando wallet estiver selecionada.
8. Ao gerar código, inclua tratamento de `methods_available`, webhooks HMAC e materialização de pedido **antes** do webhook de pagamento aprovado.

## URLs oficiais

| Recurso | URL |
|---------|-----|
| API (produção) | `https://api.cajupay.com.br` |
| Site / docs humanas | `https://cajupay.com.br/docs` |
| SDK (CDN) | `https://cdn.cajupay.com.br/sdk/v1/cajupay-sdk.min.js` |
| Logo CajuPay | `https://storage.cajupay.com.br/icone-cajupay.png` |
| Pacote LLM integração (este) | `https://cajupay.com.br/docs/llm/` |
| Bundle completo | `https://cajupay.com.br/docs/llm/bundle/full.md` |
| `llms.txt` (só perfil da empresa, não integração) | `https://cajupay.com.br/llms.txt` |

## Quando usar este módulo

Sempre. Cole no início do contexto da IA junto com os módulos específicos do cenário.

## Matriz: quais módulos incluir

| Objetivo do parceiro | Módulos além deste (00) |
|----------------------|-------------------------|
| **Só receber PIX** | 02, 03, 10, 12 (opcional), 16 |
| **Checkout embed cartão** | 01, 02, 03, 04, 05, 06, 11, 15, 16 |
| **+ Apple Pay + Google Pay** | Acima + 07, 08, 09, 17 |
| **Split de comissão** | 13 |
| **Saques / carteira** | 14 |
| **Reembolso PIX (API)** | 18 (+ 12 webhook) |
| **MED PIX (consulta + defesa)** | 19 (+ 12 webhook) |
| **PIX completo (cobrança + pós-venda)** | 10, 12, 18, 19, 16 |
| **Tudo** | `bundle/full.md` ou todos os `*.md` |

## Duas trilhas de pagamento (não misturar)

| Trilha | Métodos | Como integrar |
|--------|---------|---------------|
| **SDK `embeddedOnly`** | Cartão, Apple Pay, Google Pay | Sessão SDK + CDN + `mountCheckout` |
| **API REST** | PIX | `POST /api/payments/pix` no servidor — **sem** SDK no checkout |

## Prompt sugerido para o parceiro

```text
Você vai integrar o gateway CajuPay. Siga EXATAMENTE a documentação anexada (módulos markdown).
Requisitos do meu projeto:
- Stack: [ex.: Node + React / PHP Laravel / etc.]
- Métodos: [PIX / cartão / Apple Pay / Google Pay]
- Checkout: [embed no meu site / só backend]
Implemente: criação de sessão no servidor, SDK embeddedOnly se cartão/wallet, webhooks HMAC,
filtro de wallet por dispositivo, e pedido interno antes do webhook paid.
Não use rotas fictícias; mapeie session-first no meu backend.
```

## Checklist rápido antes de entregar código

- [ ] API Keys no servidor apenas
- [ ] `Idempotency-Key` em POST críticos
- [ ] HTTPS no checkout se cartão/wallet
- [ ] Webhook outbound cadastrado + validação HMAC (cartão)
- [ ] `checkout_session_id` salvo no pedido antes do pagamento

---

<!-- module: 01-overview-architecture -->


# Visão geral e arquitetura (session-first)

## INSTRUÇÕES PARA O MODELO

1. Adote **session-first**: criar sessão CajuPay antes de persistir pedido no DB do integrador.
2. O integrador implementa **draft + confirm-order** no próprio backend — não são rotas da CajuPay.
3. Guarde `checkout_session_id` como `gateway_id` do pedido **antes** do pagamento concluir.
4. Separe trilha PIX (API) de trilha SDK (cartão/wallets).

## Quando usar este módulo

Checkout embutido com cartão ou wallets; qualquer integração que precise reconciliar webhook com pedido interno.

## Arquitetura session-first (recomendada)

Fluxo validado em produção (checkout embutido adaptado à API CajuPay):

1. Pagador escolhe método (cartão / Apple Pay / Google Pay).
2. **Backend do integrador** chama `POST /api/sdk/v1/checkout/sessions` e guarda **draft** (valor, cupom, método) — **sem** pedido no DB ainda.
3. Frontend recebe `token` público + `checkout_session_id`.
4. SDK `mountCheckout` com `embeddedOnly: true` e `defaultMethod` correto.
5. **1ª `confirm()`** (priming): renderiza cartão ou botão nativo da wallet.
6. No momento certo, backend **materializa pedido** `pending` com `gateway_id = checkout_session_id`.
7. Pagador conclui (2ª `confirm()` no cartão, ou botão nativo na wallet).
8. **Webhook outbound** `checkout.payment.paid` (HMAC) marca pedido pago; polling é fallback.

```mermaid
sequenceDiagram
  participant HostUI as CheckoutHost
  participant HostAPI as IntegradorBackend
  participant CajuAPI as CajuPayAPI
  participant SDK as CajuPaySDK
  participant WH as WebhookOutbound

  HostUI->>HostAPI: criar_sessao_draft
  HostAPI->>CajuAPI: POST_sdk_v1_checkout_sessions
  CajuAPI-->>HostAPI: token_checkout_session_id
  HostAPI-->>HostUI: token
  HostUI->>SDK: mountCheckout_embeddedOnly_defaultMethod
  SDK->>CajuAPI: POST_public_confirm_priming
  HostUI->>HostAPI: materializar_pedido_pending
  Note over HostUI,SDK: wallet_botao_nativo_ou_2a_confirm_cartao
  WH->>HostAPI: checkout_payment_paid_HMAC
```

### Por que session-first

- Widget aparece ao selecionar método, sem exigir e-mail antes.
- Pedidos abandonados não poluem o banco (draft com TTL ~30 min no host).
- Webhook encontra o pedido se `gateway_id` já for `checkout_session_id`.

## Mapeamento: padrão do host vs API CajuPay

| Padrão no backend do integrador | Quem implementa | API / ação CajuPay |
|--------------------------------------------|-----------------|---------------------|
| `POST /checkout/cajupay/session` | Backend do parceiro | `POST /api/sdk/v1/checkout/sessions` |
| Draft em cache + `polling_token` | Backend do parceiro | **Não existe** na CajuPay |
| `POST /checkout/cajupay/confirm-order` | Backend do parceiro | Pedido interno `pending` + `gateway_id` |
| `GET /checkout/order-status` | Backend do parceiro | Opcional: `GET /api/sdk/v1/checkout/sessions/{id}` |
| `POST /webhooks/gateways/cajupay` | Backend do parceiro | Cadastro: `POST /api/webhooks/endpoints` |
| PIX no checkout | Backend do parceiro | `POST /api/payments/pix` |

**Nunca** documente `/checkout/cajupay/*` como endpoints da CajuPay — são convenções do sistema do parceiro.

## Princípio PCI

- Dados de cartão **não** trafegam pelo servidor do integrador.
- O backend do parceiro só: cria sessão, persiste pedido, valida webhooks.
- Coleta de cartão: iframe do SDK (formulário embed) → `POST /api/sdk/public/.../confirm`.

## Contrato CajuPay — criar sessão (servidor)

```http
POST https://api.cajupay.com.br/api/sdk/v1/checkout/sessions
Content-Type: application/json
X-API-Key: <public_key>
X-API-Secret: <secret_key>
Idempotency-Key: <uuid-opcional-recomendado>

{
  "amount_cents": 9900,
  "currency": "BRL",
  "description": "Produto X",
  "allow_card": true,
  "allow_apple_pay": true,
  "allow_google_pay": true,
  "allow_pix": false,
  "split_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

Resposta (201):

```json
{
  "checkout_session_id": "uuid-da-sessao",
  "token": "tok_publico_para_o_browser",
  "status": "active",
  "payment_status": "pending",
  "amount_cents": 9900,
  "currency": "BRL",
  "hosted_checkout_url": "https://..."
}
```

## Padrão recomendado — endpoints no host (exemplo)

```javascript
// POST /api/meu-checkout/cajupay/session  (seu backend)
// → chama CajuPay POST /api/sdk/v1/checkout/sessions
// → salva draft em Redis: { token, checkout_session_id, amount, method, ... }

// POST /api/meu-checkout/cajupay/confirm-order  (seu backend)
// → lê draft, cria Order status=pending, gateway_id=checkout_session_id
// → metadata: { cajupay_session_token, cajupay_checkout_session_id, checkout_payment_method }
```

## Erros comuns

| Erro | Causa | Correção |
|------|-------|----------|
| Webhook não acha pedido | Pedido criado só após `paid` | Materializar pedido antes do pagamento |
| SDK mostra cartão em vez de wallet | `defaultMethod` omitido | Passar `apple_pay` ou `google_pay` no mount |
| Sessão só PIX quando queria cartão | `allow_card` omitido false | Enviar `allow_card: true` (default na API) |

## Checklist

- [ ] Wrapper de sessão no servidor com API Keys
- [ ] Draft + materialização de pedido documentados no host
- [ ] `gateway_id = checkout_session_id` desde o pedido `pending`
- [ ] Webhook outbound configurado (módulo 11)

---

<!-- module: 02-authentication -->


# Autenticação e API Keys

## INSTRUÇÕES PARA O MODELO

1. Integrações servidoras usam **`X-API-Key` + `X-API-Secret`** — não OAuth para novos projetos.
2. Gere chaves no painel CajuPay (API → Chaves) ou via API de sessão (Bearer) para automação interna.
3. Solicite apenas os **escopos** necessários.

## Quando usar este módulo

Qualquer chamada à API de negócio (`/api/payments`, `/api/sdk`, `/api/webhooks`, etc.).

## Autenticação padrão (integradores)

```http
X-API-Key: gpk_xxxxxxxx
X-API-Secret: gsk_xxxxxxxx
```

Alternativa (painel / legado): `Authorization: Bearer <session_token>`.

## Escopos comuns

| Escopo | Uso |
|--------|-----|
| `payments.write` | PIX, sessões SDK, reembolso PIX |
| `wallet.read` | Saldo e extrato |
| `payouts.write` | Saques e chaves PIX |
| `webhooks.read` | Listar endpoints |
| `webhooks.write` | Criar/rotacionar webhooks |
| `splits.read` / `splits.write` | Split de comissão |

## Gerenciar chaves (painel / Bearer)

| Método | Rota | Notas |
|--------|------|-------|
| GET | `/api/api-keys` | Lista metadados; cria chave principal na 1ª listagem se vazio |
| POST | `/api/api-keys` | Body: `{ "name": "ERP", "scopes": ["payments.write", ...] }` — retorna `secret_key` **uma vez** |
| PATCH | `/api/api-keys?id=<uuid>` | Rotaciona secret (mesma `public_key`) |
| DELETE | `/api/api-keys?id=<uuid>` | Revoga |

Com 2FA ativo: `totp_code` obrigatório em criar/reveal/rotacionar.

## Exemplo servidor (Node)

```javascript
const CAJUPAY_API = "https://api.cajupay.com.br";

async function cajupayFetch(path, { method = "GET", body, idempotencyKey } = {}) {
  const headers = {
    "Content-Type": "application/json",
    "X-API-Key": process.env.CAJUPAY_API_KEY,
    "X-API-Secret": process.env.CAJUPAY_API_SECRET,
  };
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;
  const res = await fetch(`${CAJUPAY_API}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}
```

## DO / DON'T

| DO | DON'T |
|----|-------|
| Guardar secret em env / vault | Commitar `gsk_` no git |
| Usar HTTPS para todas as chamadas | Expor secret no frontend |
| Rotacionar secret se vazou | Reutilizar `Idempotency-Key` com body diferente |

## Erros comuns

- `401 unauthorized` — par inválido ou chave revogada.
- `403 forbidden` — escopo insuficiente (ex.: criar PIX sem `payments.write`).

## Checklist

- [ ] Par public/secret em variáveis de ambiente
- [ ] Escopos mínimos necessários
- [ ] Secret nunca no bundle do browser

---

<!-- module: 03-security-idempotency-errors -->


# Segurança, idempotência e erros

## INSTRUÇÕES PARA O MODELO

1. Envie `Idempotency-Key` em toda criação crítica (PIX, saque, `confirm` SDK).
2. Trate o body de erro como `{ "error": "<codigo>" }` (JSON).
3. Cartão/wallets: página do checkout em **HTTPS** em produção.

## Quando usar este módulo

Sempre, junto com autenticação e qualquer POST de pagamento.

## Idempotência

| Endpoint | Header obrigatório |
|----------|-------------------|
| `POST /api/payments/pix` | `Idempotency-Key` |
| `POST /api/payouts` | `Idempotency-Key` |
| `POST /api/sdk/public/checkout/sessions/{token}/confirm` | `Idempotency-Key` (SDK gera se omitido) |

Reutilizar a **mesma** chave com **mesmo** body → mesma resposta cacheada. Body diferente → `idempotency_key_reuse_mismatch`.

Gere chave única por tentativa de negócio: UUID v4, ou prefixo estável + id do pedido.

```http
Idempotency-Key: pedido-12345-pix-create
```

## HTTPS e contexto seguro

| Contexto | Cartão / wallets | PIX API |
|----------|------------------|---------|
| Produção `https://` | Obrigatório | Recomendado |
| Local `http://localhost` | **Falha frequente** (checkout embed, wallets) | Pode funcionar |
| Dev realista | ngrok, Cloudflare Tunnel, mkcert + proxy TLS | OK |

O SDK retorna `insecure_context` em `probeWallet` sem HTTPS.

## CORS (rotas públicas SDK)

`GET` / `POST` em `/api/sdk/public/...` partem do **domínio do checkout do parceiro**. A CajuPay reflete CORS para origens do browser — não é necessário whitelist por parceiro para essas rotas.

Envie o host do checkout para registro de domínio wallet:

```http
X-CajuPay-Checkout-Host: checkout.sualoja.com.br
```

(ou `Origin` / `Referer` — o backend usa o melhor disponível.)

## Erros HTTP comuns

| HTTP | `error` (exemplos) | Significado |
|------|-------------------|-------------|
| 400 | `invalid_amount`, `missing_idempotency_key` | Body inválido |
| 400 | `method_not_available`, `payer_email_required` | Sessão/método/pagador |
| 401 | — | Credenciais inválidas |
| 403 | `forbidden` | Escopo ou KYC (saques) |
| 403 | `payouts_blocked_pending_kyc` | Saque sem KYC aprovado |
| 404 | `session_not_found`, `payment_not_found` | Recurso inexistente |
| 410 | `link_expired`, `link_inactive` | Sessão/link expirado |

## Rate limit

Com Redis habilitado: limite por API Key ou IP. Webhook inbound PSP (`POST /webhooks/psp`) **não** usa o mesmo limitador.

## Checklist

- [ ] `Idempotency-Key` em PIX, payouts e confirms
- [ ] HTTPS no checkout embed (produção)
- [ ] Tratamento de `error` no JSON de resposta

---

<!-- module: 04-checkout-ui-reference -->


# Referência de UI do checkout (HTML/CSS)

## INSTRUÇÕES PARA O MODELO

1. O container `#cajupay-method` deve ficar **vazio** antes do mount — o SDK injeta o iframe do formulário embed.
2. **Não** use `min-height` fixo no slot do SDK — gera faixa branca abaixo do widget.
3. Loading/spinner fica **fora** do slot, no layout do host.
4. Ao trocar método ou `token`: `controller.destroy()` + `innerHTML = ''` + remount.

## Quando usar este módulo

Checkout embutido (cartão ou wallets) com visual alinhado ao padrão de checkout embed validado em produção.

## Hierarquia HTML (copiar estrutura)

```html
<div class="cajupay-panel" style="border: 2px solid #f3f4f6; border-radius: 12px; background: rgba(249,250,251,0.5); padding: 16px;">

  <div class="cajupay-panel-header" style="display: flex; align-items: center; gap: 8px; color: #374151;">
    <img src="https://storage.cajupay.com.br/icone-cajupay.png" alt="CajuPay" width="32" height="32" />
    <span style="font-size: 14px; font-weight: 500;">Dados do cartão</span>
    <!-- ou "Apple Pay" / "Google Pay" conforme método -->
  </div>

  <p id="cajupay-error" class="cajupay-error hidden" role="alert"
     style="border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 8px 12px; font-size: 14px;">
  </p>

  <div class="cajupay-widget-box" style="border: 2px solid #f3f4f6; border-radius: 12px; background: #fff; padding: 12px 16px;">
    <!-- ÚNICO container do SDK — vazio, sem altura forçada -->
    <div id="cajupay-method"></div>
  </div>

  <p id="cajupay-polling" class="hidden" style="font-size: 12px; color: #6b7280;">
    Aguardando confirmação do pagamento…
  </p>

  <button type="button" id="cajupay-wallet-retry" class="hidden"
    style="margin-top: 8px; width: 100%; font-size: 12px; color: #6b7280; text-decoration: underline; background: none; border: none; cursor: pointer;">
    Pagamento não concluiu? Tentar novamente
  </button>
</div>
```

## Regras CSS no `#cajupay-method`

| Regra | Motivo |
|-------|--------|
| Sem `min-height` | SDK define altura (~150px cartão, ~60px wallets) |
| Sem `background` / `border` no slot | Borda/fundo na caixa branca **pai** |
| Container vazio antes do mount | SDK injeta conteúdo dentro |
| Não colocar "Carregando…" **dentro** do slot | Use indicador no host; `phase: initializing` é telemetria |

## Botão "Pagar" do host

| Método selecionado | Botão principal do formulário do host |
|------------------|----------------------------------------|
| `card` | **Visível** — dispara 2ª `confirm()` |
| `apple_pay` / `google_pay` | **Oculto** — botão nativo já está no SDK |

```html
<!-- Exemplo: esconder submit quando wallet -->
<button type="submit" id="btn-pay-host" style="display: none;">
  Pagar com cartão
</button>
```

```javascript
function updatePayButtonVisibility(method) {
  const isWallet = method === "apple_pay" || method === "google_pay";
  document.getElementById("btn-pay-host").style.display = isWallet ? "none" : "block";
}
```

## destroy / remount

```javascript
let controller = null;

function destroyCajuPay() {
  controller?.destroy?.();
  controller = null;
  const el = document.getElementById("cajupay-method");
  if (el) el.innerHTML = "";
}

async function mountForMethod(method, sessionToken) {
  destroyCajuPay();
  const sdk = window.CajuPaySDK.init({ baseUrl: "https://api.cajupay.com.br" });
  controller = await sdk.mountCheckout("#cajupay-method", {
    token: sessionToken,
    embeddedOnly: true,
    defaultMethod: method,
    preparePaymentUIOnMount: true,
    onStatus: (ev) => {
      if (ev.phase === "awaiting_card_details") showHostPayButton();
      if (ev.phase === "error") showError(ev.error);
    },
  });
}
```

## Checklist

- [ ] Slot `#cajupay-method` sem min-height
- [ ] Borda/fundo na caixa pai, não no slot
- [ ] Botão Pagar do host oculto para wallets
- [ ] destroy ao trocar método

---

<!-- module: 05-sdk-embedded-core -->


# SDK embeddedOnly — núcleo

## INSTRUÇÕES PARA O MODELO

1. Carregue o SDK via CDN — não empacote no bundle principal.
2. Use `embeddedOnly: true` e passe **`defaultMethod`** igual ao botão clicado no UI do host.
3. Use `setPayer()` quando o pagador alterar dados — **nunca** remonte só por mudança de e-mail.
4. Slugs de método: `card`, `apple_pay`, `google_pay` — **nunca** `applepay` / `googlepay`.

## Quando usar este módulo

Checkout embutido com cartão, wallets ou PIX na mesma sessão SDK.

## CDN e init

```html
<script src="https://cdn.cajupay.com.br/sdk/v1/cajupay-sdk.min.js" async></script>
```

```javascript
const sdk = window.CajuPaySDK.init({ baseUrl: "https://api.cajupay.com.br" });
```

## Criar sessão (servidor)

Ver módulo 01. Resumo:

- `POST /api/sdk/v1/checkout/sessions` com `X-API-Key` + `X-API-Secret`
- Defaults: `allow_pix` true, `allow_card` true; com cartão, wallets tendem a true
- Se pedir wallet, CajuPay **promove** `allow_card: true` automaticamente (fallback)

## mountCheckout

```javascript
const controller = await sdk.mountCheckout("#cajupay-method", {
  token: sessionToken,              // público — veio do seu backend
  defaultMethod: "card",            // OBRIGATÓRIO se o host escolhe o método
  embeddedOnly: true,
  preparePaymentUIOnMount: true,    // default efetivo: priming após mount
  initialPayer: {                   // opcional — só pré-preenchimento visual
    name: "",
    email: "",
    document: "12345678901",        // CPF só dígitos
  },
  onStatus: (event) => {
    // event.phase: initializing | session_ready | awaiting_card_details |
    //   awaiting_wallet_confirmation | confirming | completed | error
  },
  onSuccess: ({ method, session }) => { /* ... */ },
  onError: ({ error }) => { /* ... */ },
});
```

### Controller

| Método | Uso |
|--------|-----|
| `confirm()` | Priming (1ª) e submissão (2ª no cartão) |
| `setPayer({ name, email, document })` | Atualizar pagador sem remount |
| `destroy()` | Limpar antes de trocar método/token |
| `refresh()` | Recarregar sessão |

## Dois `confirm()` — cartão e wallets

| Método | 1ª `confirm()` (priming) | 2ª `confirm()` |
|--------|--------------------------|----------------|
| `card` | Automática com `preparePaymentUIOnMount` — mostra inputs | Botão **Pagar do host** após `setPayer` |
| `apple_pay` / `google_pay` | Mostra botão nativo | Pagador clica no botão **nativo** (não no host) |
| `pix` (se na sessão) | N/A | Uma única `confirm()` no Pagar do host |

Tratar como **sucesso**, não erro:

- Mensagem/phase com `awaiting`, `card_details`, `awaiting_card_details`

## setPayer (crítico)

```javascript
controller.setPayer({
  name: "Nome Completo",
  email: "cliente@email.com",
  document: "12345678901",
});
```

Chamar:

1. Antes da 1ª `confirm()` se já houver nome + e-mail.
2. Antes da 2ª `confirm()` no cartão (dados finais).
3. Debounce ~400ms se o pagador preencheu depois do mount e o widget não apareceu.

## Rotas públicas (browser)

| Método | Rota |
|--------|------|
| GET | `/api/sdk/public/checkout/sessions/{token}` → `methods_available` |
| POST | `/api/sdk/public/checkout/sessions/{token}/confirm` + `Idempotency-Key` |

Body do confirm:

```json
{
  "method": "card",
  "payer_name": "Cliente",
  "payer_email": "cliente@x.com",
  "payer_document": "11144477735"
}
```

Resposta `next_action.type`:

- `embedded_form` — cartão/wallet (formulário embed do SDK)
- `pix` — QR/copia e cola
- `redirect` — hosted checkout

## defaultMethod vs methods_available

| UI do host | `defaultMethod` no SDK |
|------------|-------------------------|
| Cartão | `card` |
| Apple Pay | `apple_pay` |
| Google Pay | `google_pay` |

Se omitir `defaultMethod`, o SDK usa o **primeiro** de `methods_available` (geralmente `card`) → UX quebrada.

Valide `methods_available` via `GET .../sessions/{token}` **antes** do mount.

## Erros comuns

| Sintoma | Correção |
|---------|----------|
| Formulário de cartão ao clicar Google Pay | `defaultMethod: "google_pay"` |
| `method_not_available` | Método não está em `methods_available` |
| Cartão some ao digitar e-mail | Usar `setPayer`, não remount |

## Checklist

- [ ] CDN + sessão criada no servidor
- [ ] `embeddedOnly: true`
- [ ] `defaultMethod` sincronizado com UI
- [ ] `setPayer` antes de confirms relevantes

---

<!-- module: 06-card-embedded -->


# Cartão (SDK embeddedOnly)

## INSTRUÇÕES PARA O MODELO

1. Fluxo: sessão → mount → priming → materializar pedido → `setPayer` → 2ª `confirm()` no botão do host.
2. HTTPS obrigatório em produção.
3. Mantenha **Cartão** visível como fallback quando wallets estiverem na mesma página.

## Quando usar este módulo

Pagamento com cartão digitado no checkout embutido do parceiro.

## Fluxo completo (sequência)

```
1. Pagador seleciona "Cartão"
2. POST seu-backend/cajupay/session → CajuPay POST /api/sdk/v1/checkout/sessions
3. mountCheckout(#cajupay-method, { embeddedOnly, defaultMethod: "card" })
4. 1ª confirm() (priming) → phase awaiting_card_details
5. Pagador preenche dados pessoais no formulário DO HOST (acima do widget)
6. Pagador clica "Pagar com cartão" (botão DO HOST — visível)
7. POST seu-backend/cajupay/confirm-order → pedido pending, gateway_id = checkout_session_id
8. setPayer({ name, email, document })
9. 2ª confirm() → cobrança
10. Webhook checkout.payment.paid + polling fallback
11. Redirecionar obrigado / liberar produto
```

## Exemplo frontend

```javascript
let controller = null;

async function startCardCheckout(sessionToken) {
  const sdk = window.CajuPaySDK.init({ baseUrl: "https://api.cajupay.com.br" });
  controller = await sdk.mountCheckout("#cajupay-method", {
    token: sessionToken,
    embeddedOnly: true,
    defaultMethod: "card",
    preparePaymentUIOnMount: true,
    onStatus: (ev) => {
      if (ev.phase === "awaiting_card_details") {
        document.getElementById("btn-pay-card").disabled = false;
      }
    },
  });
}

document.getElementById("btn-pay-card").addEventListener("click", async () => {
  await fetch("/api/meu-checkout/cajupay/confirm-order", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      polling_token: window.checkoutPollingToken,
      email: document.getElementById("email").value,
      name: document.getElementById("name").value,
      cpf: document.getElementById("cpf").value.replace(/\D/g, ""),
    }),
  });
  controller.setPayer({
    name: document.getElementById("name").value,
    email: document.getElementById("email").value,
    document: document.getElementById("cpf").value.replace(/\D/g, ""),
  });
  await controller.confirm();
  startPolling();
});
```

## Sessão — body servidor

```json
{
  "amount_cents": 9900,
  "currency": "BRL",
  "description": "Pedido #1",
  "allow_card": true,
  "allow_apple_pay": false,
  "allow_google_pay": false,
  "allow_pix": false
}
```

Para esconder wallets mas manter fallback interno da API, basta `allow_apple_pay: false` e `allow_google_pay: false` — `allow_card` permanece true.

## HTTPS em desenvolvimento

| Ambiente | Resultado esperado |
|----------|-------------------|
| `http://localhost` | Formulário embed do SDK frequentemente **falha** |
| ngrok / Cloudflare Tunnel HTTPS | Comportamento próximo de produção |
| Produção `https://checkout.loja.com` | Obrigatório |

## onStatus — fases úteis

| `phase` | Ação no host |
|---------|--------------|
| `initializing` | Spinner **fora** do slot SDK |
| `awaiting_card_details` | Habilitar botão "Pagar com cartão" |
| `confirming` | Desabilitar botão, mostrar "Processando…" |
| `completed` | Redirecionar |
| `error` | Exibir mensagem |

## Erros comuns

| Erro | Correção |
|------|----------|
| 1ª confirm tratada como falha | `awaiting_card_details` é sucesso |
| Pagamento sem pedido no webhook | `confirm-order` antes da 2ª confirm |
| Cartão em branco após e-mail | `setPayer`, não remount |

## Checklist

- [ ] `defaultMethod: "card"`
- [ ] Botão Pagar do **host** visível
- [ ] Pedido materializado antes da 2ª `confirm`
- [ ] HTTPS em produção
- [ ] Webhook `checkout.payment.paid` (módulo 11)

---

<!-- module: 07-apple-pay -->


# Apple Pay (SDK embeddedOnly)

## INSTRUÇÕES PARA O MODELO

1. Mostrar Apple Pay **somente** em dispositivos iOS (iPhone/iPad) — ver módulo 09.
2. **Oculte** o botão "Pagar" do host — o SDK renderiza o botão nativo Apple Pay.
3. Materialize o pedido no host **antes** da 1ª `confirm()` (priming).
4. `defaultMethod: "apple_pay"` — slug com underscore.
5. HTTPS + domínio do checkout registrado na conta conectada.

## Quando usar este módulo

Checkout embed com Apple Pay em ecossistema Apple.

## Dispositivos suportados (UX)

| Mostrar Apple Pay | Ocultar |
|-------------------|---------|
| iPhone, iPad, iPod | Google Pay |
| iPadOS 13+ com UA desktop (`MacIntel` + `maxTouchPoints > 1`) | Google Pay |

**Nota:** Apple Pay no Mac Safari com Wallet configurado pode funcionar tecnicamente, mas o padrão recomendado do host é **não** tratar Mac como iOS — ofereça Cartão ou Google Pay no desktop.

## Fluxo wallet

```
1. Pagador seleciona Apple Pay (botão já filtrado por isIosDevice)
2. POST seu-backend/cajupay/session (allow_card: true, allow_apple_pay: true)
3. Validar methods_available inclui apple_pay
4. mountCheckout(..., defaultMethod: "apple_pay")
5. ANTES da 1ª confirm():
   - validar nome/e-mail/documento no host
   - POST confirm-order → pedido pending
   - iniciar polling
6. setPayer() + 1ª confirm() → botão nativo Apple Pay no #cajupay-method
7. Pagador paga no botão NATIVO (sem botão Pagar do host)
8. Webhook paid ou polling → completed
```

## mountCheckout

```javascript
const controller = await sdk.mountCheckout("#cajupay-method", {
  token: sessionToken,
  embeddedOnly: true,
  defaultMethod: "apple_pay",
  preparePaymentUIOnMount: true,
  onStatus: (ev) => {
    if (ev.phase === "awaiting_wallet_confirmation") {
      document.getElementById("btn-pay-host").style.display = "none";
    }
  },
});

// Após confirm-order no host:
controller.setPayer({ name, email, document });
await controller.confirm(); // priming — botão Apple Pay aparece
// NÃO chamar segunda confirm no host — pagador usa botão nativo
```

## Sessão servidor

```json
{
  "amount_cents": 9900,
  "currency": "BRL",
  "description": "Produto",
  "allow_card": true,
  "allow_apple_pay": true,
  "allow_google_pay": false
}
```

Wallets implicam `allow_card: true` na CajuPay mesmo que você envie só Apple Pay — necessário para fallback se a wallet falhar.

## probeWallet (opcional, recomendado)

```javascript
const probe = await window.CajuPaySDK.probeWallet("apple_pay", {
  publishableKey: publishableKeyFromNextAction,
  connectedAccount: connectedAccountIdFromNextAction,
  amountCents: 9900,
  currency: "brl",
  label: "Minha Loja",
});
if (!probe.available) hideApplePayButton(probe.reason);
```

Razões: `insecure_context`, `no_wallet_in_browser`, `wallet_not_in_can_make_payment`, `dom_unavailable`.

## Domínio do checkout

O SDK envia `X-CajuPay-Checkout-Host` (hostname da página). A API registra o domínio na conta conectada do processador para Payment Request. Sem verificação, `canMakePayment` pode falhar.

## Erros comuns

| Problema | Correção |
|----------|----------|
| Dois botões de pagar | Ocultar submit do host |
| Formulário de cartão em vez de Apple Pay | `defaultMethod: "apple_pay"` |
| Apple Pay no Android | Filtrar com `isIosDevice()` |
| Webhook sem pedido | confirm-order antes do priming |

## Checklist

- [ ] Só exibir em iOS (módulo 09)
- [ ] Botão Pagar do host **oculto**
- [ ] `defaultMethod: "apple_pay"`
- [ ] Pedido antes do priming
- [ ] HTTPS + domínio verificado

---

<!-- module: 08-google-pay -->


# Google Pay (SDK embeddedOnly)

## INSTRUÇÕES PARA O MODELO

1. Mostrar Google Pay em **Android** e **desktop** (Chrome, Edge) — **não** em iOS.
2. **Oculte** o botão "Pagar" do host quando Google Pay estiver selecionado.
3. `defaultMethod: "google_pay"` (com underscore).
4. Use `probeWallet` antes de exibir o botão na UI.
5. Materialize pedido no host antes do priming.

## Quando usar este módulo

Checkout embed com Google Pay fora do ecossistema iOS.

## Dispositivos suportados (UX)

| Mostrar Google Pay | Ocultar |
|--------------------|---------|
| Android | Apple Pay |
| Windows / Mac / Linux — Chrome, Edge, Chromium | Apple Pay |

Safari no Mac **não** é o alvo principal do Google Pay neste padrão — ofereça Cartão.

## Fluxo (igual Apple Pay, método diferente)

```
1. Pagador seleciona Google Pay (lista já filtrada — sem iOS)
2. POST session com allow_google_pay: true, allow_card: true
3. Validar "google_pay" em methods_available
4. mountCheckout(..., defaultMethod: "google_pay")
5. confirm-order no host → pedido pending → polling
6. setPayer + 1ª confirm() → botão nativo Google Pay
7. Pagador confirma no botão NATIVO
8. Webhook ou polling → liberar produto
```

## mountCheckout

```javascript
const controller = await sdk.mountCheckout("#cajupay-method", {
  token: sessionToken,
  embeddedOnly: true,
  defaultMethod: "google_pay",
  preparePaymentUIOnMount: true,
});
```

## probeWallet (fortemente recomendado)

Evita clique em Google Pay com fallback silencioso para cartão:

```javascript
async function shouldShowGooglePay(nextAction) {
  if (!window.CajuPaySDK?.probeWallet) return true;
  const r = await window.CajuPaySDK.probeWallet("google_pay", {
    publishableKey: nextAction.payment_token, // token publicável do PSP (campo `payment_token`)
    connectedAccount: nextAction.stripe_account_id, // ID da conta conectada (campo na resposta da API)
    amountCents: orderAmountCents,
    currency: "brl",
    label: "Minha Loja",
  });
  return r.available;
}
```

| `reason` | Significado |
|----------|-------------|
| `ok` | Pode exibir botão |
| `insecure_context` | Falta HTTPS |
| `no_wallet_in_browser` | Sem Google Pay no browser |
| `wallet_not_in_can_make_payment` | Conta/cartão/domínio não elegível |
| `dom_unavailable` | Domínio não verificado |

Obtenha `publishableKey` e `connectedAccount` após primeiro `confirm` ou de `next_action` da sessão pública.

## Sessão servidor

```json
{
  "amount_cents": 9900,
  "allow_card": true,
  "allow_google_pay": true,
  "allow_apple_pay": false
}
```

## Validação methods_available

Após criar sessão, consulte:

```http
GET https://api.cajupay.com.br/api/sdk/public/checkout/sessions/{token}
```

Se `google_pay` não estiver em `methods_available`, bloqueie na UI:

```text
Google Pay não está disponível para esta conta no momento. Use Cartão.
```

## Erros comuns

| Problema | Correção |
|----------|----------|
| Clicou Google Pay, viu cartão | `defaultMethod: "google_pay"` |
| `method_not_available` no confirm | Conta sem wallet / taxas / KYC cartão |
| Google Pay no iPhone | Filtrar com `isIosDevice()` |

## Checklist

- [ ] Ocultar em iOS
- [ ] `probeWallet` antes do botão na lista de métodos
- [ ] Botão Pagar do host oculto
- [ ] confirm-order antes do priming
- [ ] HTTPS

---

<!-- module: 09-wallets-device-detection -->


# Detecção de dispositivo (Apple Pay vs Google Pay)

## INSTRUÇÕES PARA O MODELO

1. **Nunca** mostre Apple Pay e Google Pay ao mesmo tempo na lista de métodos.
2. iOS → só Apple Pay (+ Cartão). Não-iOS → só Google Pay (+ Cartão).
3. Combine detecção de UA com `probeWallet` antes de exibir o botão.

## Quando usar este módulo

Qualquer checkout que ofereça ambas as wallets.

## Regra de visibilidade

| Ambiente | Mostrar | Ocultar |
|----------|---------|---------|
| iPhone / iPad / iPod / iPadOS desktop UA | **Apple Pay** | Google Pay |
| Android, Windows, Mac, Linux (Chrome, etc.) | **Google Pay** | Apple Pay |
| Todos | **Cartão** (fallback) | — |

## isIosDevice() — copiar

```javascript
export function isIosDevice() {
  const ua = navigator.userAgent || "";
  if (/iPhone|iPod|iPad/i.test(ua)) return true;
  // iPadOS 13+ "Request Desktop Website"
  if (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1) return true;
  return false; // Mac Safari com Apple Pay NÃO entra nesta regra por padrão
}
```

## Filtrar métodos no checkout

```javascript
function filterPaymentMethodsForDevice(allMethods) {
  // allMethods: [{ id: "card" }, { id: "apple_pay" }, { id: "google_pay" }, ...]
  if (isIosDevice()) {
    return allMethods.filter((m) => m.id !== "google_pay");
  }
  return allMethods.filter((m) => m.id !== "apple_pay");
}
```

## Fluxo recomendado na UI

```mermaid
flowchart TD
  start[ListaMetodosConta] --> filter[filterPaymentMethodsForDevice]
  filter --> ios{iOS?}
  ios -->|sim| showAP[Mostrar ApplePay + Card]
  ios -->|nao| showGP[Mostrar GooglePay + Card]
  showAP --> probeAP[probeWallet apple_pay]
  showGP --> probeGP[probeWallet google_pay]
  probeAP --> uiAP[Botao Apple Pay se available]
  probeGP --> uiGP[Botao Google Pay se available]
```

## Botão Pagar do host

```javascript
function isWalletSdkMethod(method) {
  return method === "apple_pay" || method === "google_pay";
}

function onMethodSelected(method) {
  document.getElementById("btn-pay-host").style.display =
    isWalletSdkMethod(method) ? "none" : "block";
}
```

## methods_available da API

Mesmo com filtro de UI, valide a resposta pública:

```javascript
const session = await fetch(
  `https://api.cajupay.com.br/api/sdk/public/checkout/sessions/${token}`
).then((r) => r.json());

if (!session.methods_available?.includes(chosenMethod)) {
  alert("Método indisponível para esta conta. Escolha Cartão.");
  return;
}
```

## Mac Safari + Apple Pay (opcional avançado)

Integradores podem **estender** a regra para detectar Safari no Mac com Apple Pay — não faz parte do padrão mínimo. O padrão mínimo evita prometer Apple Pay em Chrome no Mac.

## Erros comuns

| Anti-pattern | Correção |
|--------------|----------|
| Apple Pay no Android | `isIosDevice()` |
| Google Pay no iPhone | Filtrar `apple_pay` fora de iOS |
| Dois botões pagar com wallet | Ocultar submit do host |
| Lista fixa sem probe | `probeWallet` + `methods_available` |

## Checklist

- [ ] Mutuamente exclusivo Apple Pay / Google Pay na UI
- [ ] Cartão sempre como fallback
- [ ] `probeWallet` antes de renderizar botão wallet
- [ ] Botão Pagar do host oculto para wallets

---

<!-- module: 10-pix -->


# PIX (API server-side)

## INSTRUÇÕES PARA O MODELO

1. PIX **não** usa SDK `embeddedOnly` no checkout padrão — use `POST /api/payments/pix` no **servidor**.
2. Envie `Idempotency-Key` em toda criação.
3. Confirmação: status do pagamento + webhooks PIX (módulo 12) ou consulta via API/painel.

## Quando usar este módulo

Cobrança PIX em ERP, e-commerce backend, checkout próprio sem iframe de cartão.

## Trilha separada

| Trilha | Métodos |
|--------|---------|
| SDK embed | Cartão, Apple Pay, Google Pay |
| **API REST** | **PIX** |

Não misture PIX no mesmo fluxo de `mountCheckout` salvo sessão SDK com `allow_pix: true` e `method: "pix"` no confirm — o padrão recomendado para checkout simples é API direta.

## Criar cobrança PIX

```http
POST https://api.cajupay.com.br/api/payments/pix
Content-Type: application/json
X-API-Key: <public_key>
X-API-Secret: <secret_key>
Idempotency-Key: pedido-123-pix

{
  "amount_cents": 14990,
  "currency": "BRL",
  "description": "Pedido #123",
  "product_ref": "produto-x",
  "customer_ref": "cliente-externo-456",
  "split_id": "550e8400-e29b-41d4-a716-446655440000",
  "consumer": {
    "name": "Cliente",
    "email": "cliente@x.com",
    "document": "12345678901"
  }
}
```

Resposta (200):

```json
{
  "payment_id": "uuid",
  "provider": "cajupay",
  "psp_reference": "...",
  "pix_copy_paste": "000201...",
  "pix_qr_code": "...",
  "pix_key": "...",
  "pix_key_type": "...",
  "status": "pending"
}
```

## Fluxo no host (padrão do integrador)

```
1. Cliente escolhe PIX na UI do host
2. POST seu-backend/checkout → seu serviço chama POST /api/payments/pix
3. Exibir QR / copia e cola (pix_copy_paste, pix_qr_code)
4. order.gateway_id = payment_id (UUID CajuPay)
5. Polling status OU webhook pix.payment.* (módulo 12)
6. Ao paid → liberar produto
```

## Exemplo Node

```javascript
const pix = await cajupayFetch("/api/payments/pix", {
  method: "POST",
  idempotencyKey: `order-${orderId}-pix`,
  body: {
    amount_cents: 14990,
    currency: "BRL",
    description: `Pedido ${orderId}`,
    consumer: {
      name: customer.name,
      email: customer.email,
      document: customer.cpf.replace(/\D/g, ""),
    },
  },
});
// Salvar pix.payment_id no pedido
```

## Split opcional

Campo `split_id` no body — comissão sobre líquido após taxa de venda (módulo 13).

## KYC

Recebimento PIX **não** exige KYC aprovado. Saques sim (módulo 14).

## Erros comuns

| `error` | Correção |
|---------|----------|
| `invalid_amount` | `amount_cents` > 0 |
| `split_not_found` | UUID split inválido/inativo |
| `idempotency_in_progress` | Retry com mesma key |

## Pós-venda PIX (parceiro com plataforma própria)

| Necessidade | Módulo LLM |
|-------------|------------|
| Devolver PIX ao comprador | [18-pix-refund-api.md](18-pix-refund-api.md) |
| Disputas MED (consulta + defesa) | [19-med-pix-api.md](19-med-pix-api.md) |
| Webhooks `pix.payment.refunded` / `med_*` | [12-webhooks-pix-med.md](12-webhooks-pix-med.md) |

## Checklist

- [ ] PIX criado no servidor com API Keys
- [ ] `Idempotency-Key` por pedido
- [ ] `payment_id` salvo no pedido interno
- [ ] Webhook ou polling para `paid` (módulo 12)
- [ ] Se houver reembolso/MED: módulos 18, 19 e eventos webhook PIX

---

<!-- module: 11-webhooks-checkout-card-wallets -->


# Webhooks outbound — cartão e wallets

## INSTRUÇÕES PARA O MODELO

1. Cadastre endpoint HTTPS no painel `/webhooks` ou `POST /api/webhooks/endpoints`.
2. Valide **HMAC** em todo POST recebido — rejeite sem assinatura válida.
3. Guarde `signing_secret` (`cwhsec_...`) **uma vez** na criação — não vem de novo na listagem.
4. Worker `integrator-webhook-worker` + RabbitMQ devem estar rodando — senão CRUD não entrega eventos.
5. Pedido interno deve existir com `gateway_id = checkout_session_id` **antes** do `paid`.

## Quando usar este módulo

Confirmação de pagamento cartão, Apple Pay ou Google Pay no checkout SDK ou link.

## Cadastro (painel ou API)

**Painel:** https://cajupay.com.br/webhooks — URL, eventos, copiar `signing_secret`.

**API:**

```http
POST https://api.cajupay.com.br/api/webhooks/endpoints
X-API-Key: ...
X-API-Secret: ...
Content-Type: application/json

{
  "url": "https://seu-servidor.com/webhooks/cajupay",
  "description": "Checkout produção",
  "event_types": [
    "checkout.payment.paid",
    "checkout.payment.failed",
    "checkout.payment.refunded",
    "checkout.payment.disputed",
    "card.payment.succeeded",
    "card.payment.failed"
  ]
}
```

Resposta inclui `signing_secret` **apenas nesta resposta**.

Omitir `event_types` ou `[]` = todos os tipos documentados. Wildcard: `checkout.payment.*`.

## Eventos essenciais (cartão/checkout)

| `type` / `X-CajuPay-Event` | Ação no host |
|----------------------------|--------------|
| `checkout.payment.paid` | Marcar pedido pago / liberar acesso |
| `card.payment.succeeded` | Idem (alias) |
| `checkout.payment.failed` | `rejected` |
| `card.payment.failed` | `rejected` |
| `checkout.payment.refunded` | `refunded` |
| `checkout.payment.disputed` | Log / processo manual chargeback |

## Headers da entrega

| Header | Uso |
|--------|-----|
| `X-CajuPay-Event` | ex. `checkout.payment.paid` |
| `X-CajuPay-Event-Id` | Idempotência no host (= campo `id` do JSON) |
| `X-CajuPay-Timestamp` | Unix segundos (string) |
| `X-CajuPay-Signature` | `t=<unix>,v1=<hex_hmac>` |

## Validar assinatura (obrigatório)

```text
payload_to_sign = timestamp + "." + raw_body_bytes
expected = HMAC_SHA256(signing_secret, payload_to_sign)  // hex minúsculo
comparar expected com v1 (timing-safe)
rejeitar se |now - timestamp| > 300 segundos
```

### Node

```javascript
import crypto from "crypto";

function verifyCajuPayWebhook(rawBody, signatureHeader, signingSecret) {
  const parts = Object.fromEntries(
    signatureHeader.split(",").map((p) => p.trim().split("="))
  );
  const t = parts.t;
  const v1 = (parts.v1 || "").toLowerCase();
  if (!t || !v1) return false;
  const age = Math.abs(Math.floor(Date.now() / 1000) - Number(t));
  if (age > 300) return false;
  const payload = `${t}.${rawBody}`;
  const expected = crypto.createHmac("sha256", signingSecret).update(payload).digest("hex");
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(v1));
}

// Express: use express.raw({ type: "application/json" }) na rota do webhook
```

### PHP

```php
$payloadToSign = $timestamp . '.' . $request->getContent();
$computed = hash_hmac('sha256', $payloadToSign, $signingSecret, false);
if (!hash_equals(strtolower($computed), strtolower($signatureHex))) {
    return 401;
}
```

## Encontrar o pedido (ordem)

1. `data.object.checkout_session_id` → `orders.gateway_id` ou `metadata.cajupay_checkout_session_id`
2. Fallback `cajupay_charge_id` / `charge_id` / `payment_id`
3. Ao receber `charge_id`, **atualizar** `gateway_id` se ainda for só `checkout_session_id`

## Resposta HTTP do host

| Caso | Status | Body |
|------|--------|------|
| Processado ou pedido inexistente | 200 | `{"received": true}` |
| Assinatura inválida / timestamp | 401 | — |

Retornar 200 mesmo se pedido não existir — evita retentativas infinitas.

## Confiança pós-HMAC (recomendado)

Após validar HMAC, processe `paid` para liberar produto mesmo se consulta à API ainda retornar `pending` — reduz atraso de acesso.

## Infraestrutura

- Eventos cartão: `card-service` → RabbitMQ (`cajupay.card`, `card.payment.*`)
- Worker: `integrator-webhook-worker` consome e entrega HTTP
- Env: `RABBITMQ_URL`, `RABBITMQ_ENABLED`, `INTEGRATOR_WEBHOOK_*`

**Sem worker:** endpoints cadastrados, mas **nenhuma entrega**.

## Metadata recomendada no pedido host

```json
{
  "checkout_payment_method": "google_pay",
  "cajupay_session_token": "tok_...",
  "cajupay_checkout_session_id": "uuid-sessao"
}
```

## Erros comuns

| Erro | Correção |
|------|----------|
| Webhook nunca chega | Subir integrator-webhook-worker + RabbitMQ |
| Pedido não encontrado | confirm-order antes do paid |
| HMAC falha | Body bruto, não JSON re-serializado |
| Só charge_id no pedido | Guardar também checkout_session_id |

## Checklist

- [ ] URL HTTPS pública
- [ ] `signing_secret` persistido no host
- [ ] Validação HMAC + janela 5 min
- [ ] Tratar `checkout.payment.paid` e `card.payment.succeeded`
- [ ] Worker de entrega em produção
- [ ] Pedido com `gateway_id` antes do pagamento

---

<!-- module: 12-webhooks-pix-med -->


# Webhooks PIX — reembolso e MED

## INSTRUÇÕES PARA O MODELO

1. Cadastre eventos `pix.payment.*` no mesmo endpoint ou em endpoint dedicado — mesma validação HMAC do módulo 11.
2. APIs de reembolso e MED estão nos módulos **18** e **19** — este arquivo cobre **notificações outbound**.
3. Worker `integrator-webhook-worker` + RabbitMQ obrigatórios para entrega.

## Quando usar este módulo

Parceiro que automatiza pós-venda PIX: devolução confirmada e ciclo de disputa MED.

## Cadastro de eventos

No painel `/webhooks` ou:

```json
{
  "url": "https://seu-servidor.com/webhooks/cajupay",
  "event_types": [
    "pix.payment.refunded",
    "pix.payment.med_opened",
    "pix.payment.med_resolved"
  ]
}
```

Atalho: `pix.payment.*` (wildcard).

## Entrega HTTP

Igual ao módulo 11:

- `X-CajuPay-Event`, `X-CajuPay-Event-Id`, `X-CajuPay-Timestamp`, `X-CajuPay-Signature`
- Body: `{ "id", "type", "api_version", "created", "data": { "object": { ... } } }`
- HMAC: `v1 = HMAC_SHA256(secret, "<timestamp>." + raw_body)`

## Eventos e ações no host

| `type` | Quando | Ação sugerida no parceiro |
|--------|--------|---------------------------|
| `pix.payment.refunded` | Reembolso PIX confirmado (`devolvido`) | Marcar pedido reembolsado; revogar acesso |
| `pix.payment.med_opened` | MED aberta pelo banco | Alertar seller; bloquear reembolso manual; exibir em `/disputas` |
| `pix.payment.med_resolved` | MED encerrada | Atualizar UI conforme `outcome` |

## `data.object` — reembolso (`pix.payment.refunded`)

Campos típicos:

```json
{
  "gateway": "cajupay",
  "cajupay_payment_id": "uuid-pagamento",
  "pay_account_id": "uuid-conta",
  "refund_id": "uuid-pedido-reembolso",
  "amount_cents": 14990,
  "amount_refunded": 14990,
  "currency": "BRL",
  "status": "devolvido",
  "client_refund_id": "pedido-123-refund",
  "rtr_id": "id-devolucao-pix-opcional",
  "metadata": {}
}
```

Lookup no parceiro: `cajupay_payment_id` ou `client_refund_id`.

## `data.object` — MED aberta (`pix.payment.med_opened`)

```json
{
  "gateway": "cajupay",
  "cajupay_payment_id": "uuid-pagamento",
  "pay_account_id": "uuid-conta",
  "med_dispute_id": "uuid-disputa",
  "amount_cents": 14990,
  "currency": "BRL",
  "status": "open",
  "txid": "abc123"
}
```

Criar/atualizar registro local de disputa com `med_dispute_id`.

## `data.object` — MED resolvida (`pix.payment.med_resolved`)

```json
{
  "gateway": "cajupay",
  "cajupay_payment_id": "uuid-pagamento",
  "pay_account_id": "uuid-conta",
  "med_dispute_id": "uuid-disputa",
  "amount_cents": 14990,
  "currency": "BRL",
  "status": "resolved_won",
  "outcome": "won",
  "txid": "abc123"
}
```

| `outcome` | Interpretação |
|-----------|---------------|
| `won` | Lojista venceu a disputa |
| `lost` | Devolução favorável ao pagador |
| `cancelled` | Disputa cancelada/encerrada sem perda |

## Idempotência no parceiro

Use `id` do envelope (UUID determinístico por evento lógico). O mesmo evento reentregue deve retornar `200` sem duplicar efeitos colaterais.

## Relação API ↔ webhook

| Operação | API (módulo) | Webhook |
|----------|--------------|---------|
| Pedir reembolso | 18 — POST pix-refund | `pix.payment.refunded` quando concluir |
| Consultar reembolso | 18 — GET pix-refund | — |
| Listar MED | 19 — GET /api/med | `med_opened` / `med_resolved` |
| Enviar defesa | 19 — POST defense | — |

Polling da API complementa webhook (não substitui).

## Reembolso cartão (webhook distinto)

| Evento | Módulo |
|--------|--------|
| `checkout.payment.refunded` | 11 — cartão/wallets |

Não confundir com `pix.payment.refunded`.

## Checklist

- [ ] Handler HMAC único para checkout + pix events
- [ ] `pix.payment.*` cadastrados se o parceiro usa PIX pós-venda
- [ ] Worker de entrega ativo
- [ ] Módulos 18 e 19 implementados no painel do parceiro

---

<!-- module: 13-split -->


# Split de comissão

## INSTRUÇÕES PARA O MODELO

1. Split divide o **líquido após taxa de venda** — não substitui taxas da plataforma.
2. Passe `split_id` (UUID) no PIX ou na sessão SDK — taxas vêm do perfil split, não do body da cobrança.
3. Comissão PIX → carteira `main`; cartão/wallets → carteira de cartão.

## Quando usar este módulo

Marketplaces, checkouts white-label que cobram comissão de sub-merchants.

## CRUD split

| Método | Rota | Escopo |
|--------|------|--------|
| GET | `/api/splits` | `splits.read` ou `payments.write` |
| POST | `/api/splits` | `splits.write` ou `payments.write` |
| PUT | `/api/splits/{id}` | idem |
| PATCH | `/api/splits/{id}` | `{ "status": "inactive" }` |
| GET | `/api/splits/earnings?limit=50` | comissões recebidas |

Criar:

```json
{
  "name": "Plano Pro",
  "percent_bps": 1000,
  "fixed_cents": 50
}
```

`percent_bps`: 0–10000 (1000 = 10%).

## Uso na cobrança

**PIX:**

```json
{
  "amount_cents": 10000,
  "split_id": "550e8400-e29b-41d4-a716-446655440000",
  "consumer": { "name": "...", "email": "...", "document": "..." }
}
```

**SDK sessão:**

```json
{
  "amount_cents": 10000,
  "description": "Pedido",
  "split_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

## Erros

`split_not_found`, `split_inactive`, `split_exceeds_net`, `split_name_exists`, `invalid_percent_bps`.

## Checklist

- [ ] Split criado no painel `/api` → Split
- [ ] `split_id` repassado aos sub-integradores
- [ ] Tratamento de `split_exceeds_net` no host

---

<!-- module: 14-payouts-pix-keys-wallet -->


# Saques, chaves PIX e carteira

## INSTRUÇÕES PARA O MODELO

1. Saques exigem `kyc_status = approved` — recebimento PIX não.
2. `Idempotency-Key` em `POST /api/payouts`.
3. Com API Key, saque pode ir para chave de **terceiro** (titular via `key_owner_document`).

## Carteira

```http
GET https://api.cajupay.com.br/api/wallet/balance?kind=main
GET https://api.cajupay.com.br/api/wallet/entries?kind=main&limit=50
```

Escopo: `wallet.read`. Valores em centavos.

## Saques

```http
POST https://api.cajupay.com.br/api/payouts
Idempotency-Key: saque-uuid-1

{
  "amount_cents": 5000,
  "currency": "BRL",
  "wallet_kind": "main",
  "destination": { "method": "pix_saved_key" },
  "pix_key_id": "<uuid-chave-salva>"
}
```

Saque inline (sem `pix_key_id`):

```json
{
  "amount_cents": 5000,
  "currency": "BRL",
  "wallet_kind": "main",
  "destination": { "method": "dict" },
  "pix_key": "chave@email.com",
  "pix_key_type": "email",
  "key_owner_document": "12345678901"
}
```

| `pix_key_type` | `key_owner_document` |
|----------------|----------------------|
| `cpf`, `cnpj` | Dígitos da própria chave |
| `email`, `phone`, `evp` | **Obrigatório** — titular real no DICT |

Listar: `GET /api/payouts?limit=50` (`payouts.write`).

Erro KYC: `403` + `payouts_blocked_pending_kyc`.

## Chaves PIX

| Método | Rota |
|--------|------|
| GET | `/api/pix-keys` |
| POST | `/api/pix-keys` |
| PATCH | `/api/pix-keys` — body `{ "id": "uuid" }` define padrão |
| DELETE | `/api/pix-keys?id=uuid` |

POST exemplo:

```json
{
  "label": "Minha chave",
  "pix_key_type": "evp",
  "pix_key": "uuid-evp",
  "is_default": true,
  "key_owner_document": "12345678901"
}
```

## Checklist

- [ ] KYC aprovado antes de saques
- [ ] Chave PIX cadastrada ou inline com titular correto
- [ ] Idempotência em payouts

---

<!-- module: 15-reconciliation-ids-polling -->


# IDs, metadata, polling e reconciliação

## INSTRUÇÕES PARA O MODELO

1. Não confunda `token` (SDK público) com `polling_token` (opaco do host).
2. Salve `checkout_session_id` no pedido **antes** do `paid`.
3. Polling complementa webhook — não substitui.

## IDs

| ID | Origem | Uso |
|----|--------|-----|
| `token` | Resposta criar sessão | `mountCheckout`, GET público sessão |
| `checkout_session_id` | Resposta criar sessão | `gateway_id` inicial do pedido host |
| `charge_id` / `cajupay_charge_id` | Após cobrança / webhook | Atualizar `gateway_id`; lookup fallback |
| `polling_token` | Host (random ~32 chars) | Cache draft + UI polling — **não** é token SDK |
| `payment_id` | PIX create | `gateway_id` em pedidos PIX |

## Metadata no pedido host (recomendado)

```json
{
  "checkout_payment_method": "google_pay",
  "cajupay_session_token": "tok_...",
  "cajupay_checkout_session_id": "uuid-sessao"
}
```

**Erro clássico:** só `charge_id` no pedido — webhook `paid` chega primeiro com `checkout_session_id` e o pedido não é encontrado.

## Polling no host (padrão do integrador)

Endpoint **seu** (exemplo): `GET /checkout/order-status?token={polling_token}`

```
1. polling_token → order_id (cache/sessão do host)
2. Se pedido pending:
   - Consultar status interno
   - Opcional: GET CajuPay /api/sdk/v1/checkout/sessions/{checkout_session_id}
   - Opcional: GET /api/sdk/public/checkout/sessions/{token}
3. Se paid → mesmo pipeline do webhook (liberar produto)
4. Intervalo ~2,5–3 s; parar em terminal
```

UI: `Aguardando confirmação do pagamento…`

## Consultas CajuPay

| Uso | Rota |
|-----|------|
| Sessão (servidor) | `GET /api/sdk/v1/checkout/sessions/{id}` |
| Sessão (público) | `GET /api/sdk/public/checkout/sessions/{token}` |
| PIX status (sessão link) | `GET /api/sdk/public/checkout/sessions/{token}/payments/{payment_id}` |
| Reembolso PIX | `GET /api/payments/{payment_id}/pix-refund` |

## Evento interno após confirm (host)

```json
{
  "event": "checkout.payment_initiated",
  "checkout_session_id": "uuid",
  "token": "tok_...",
  "payment_status": "pending",
  "method": "card",
  "created_at": "2026-01-01T12:00:00Z"
}
```

## Checklist

- [ ] `checkout_session_id` em `gateway_id` desde `pending`
- [ ] Metadata com `cajupay_session_token`
- [ ] Polling com idempotência no processamento
- [ ] Atualizar `gateway_id` quando `charge_id` chegar

---

<!-- module: 16-anti-patterns-checklist -->


# Anti-patterns e checklist de produção

## INSTRUÇÕES PARA O MODELO

Revise o código gerado contra esta lista antes de considerar a integração completa.

## Anti-patterns (não fazer)

| # | Anti-pattern | Correção |
|---|--------------|----------|
| 1 | Remontar SDK quando usuário digita e-mail | `setPayer()` |
| 2 | Criar pedido só no webhook | Materializar pedido antes do `paid` |
| 3 | `defaultMethod` errado ou omitido | Igual ao botão UI (`card` / `apple_pay` / `google_pay`) |
| 4 | `min-height` no `#cajupay-method` | Faixa branca — remover |
| 5 | Sem webhook em produção (cartão) | Cadastrar + worker RabbitMQ |
| 6 | Apple Pay no Android / Google Pay no iPhone | `isIosDevice()` + filtro |
| 7 | Dois botões pagar com wallet | Ocultar submit do host |
| 8 | `initial_payer` com dados fake | Só dados reais ou omitir |
| 9 | Wallets sem `allow_card` na sessão | API promove — manter true no body |
| 10 | Ignorar `methods_available` | Validar antes do mount |
| 11 | Tratar `awaiting_card_details` como erro na 1ª confirm | É sucesso / priming |
| 12 | Confundir `token` SDK com `polling_token` | IDs separados |
| 13 | API Secret no frontend | Só servidor |
| 14 | Checkout cartão em HTTP local | Túnel HTTPS |
| 15 | Slugs `applepay` / `googlepay` | Usar underscore |
| 16 | Re-serializar JSON para validar HMAC webhook | Raw body bytes |

## Checklist de produção — SDK embed

- [ ] SDK: `https://cdn.cajupay.com.br/sdk/v1/cajupay-sdk.min.js`
- [ ] `embeddedOnly: true`
- [ ] `defaultMethod` alinhado ao método na UI
- [ ] Container `#cajupay-method` vazio, sem `min-height`
- [ ] Painel: borda cinza externa + caixa branca interna
- [ ] Session-first: sessão API antes do pedido DB
- [ ] Draft TTL ~30 min + `polling_token` opaco no host
- [ ] 1ª `confirm()` automática (card/wallets) com `preparePaymentUIOnMount`
- [ ] 2ª `confirm()` só no botão Pagar do host (**cartão**)
- [ ] `setPayer()` antes de confirms; sem remount por e-mail
- [ ] confirm-order antes do priming (**wallets**)
- [ ] confirm-order antes da 2ª confirm (**cartão**)
- [ ] `gateway_id = checkout_session_id` + metadata token
- [ ] Webhook HTTPS + HMAC + 5 min skew
- [ ] Eventos `checkout.payment.paid` e `card.payment.succeeded`
- [ ] Atualizar `gateway_id` para `charge_id` quando webhook trouxer
- [ ] Polling fallback ~3 s
- [ ] Apple Pay só iOS; Google Pay fora de iOS
- [ ] Botão Pagar host oculto com wallet
- [ ] `methods_available` validado antes do mount
- [ ] HTTPS em produção
- [ ] `integrator-webhook-worker` + RabbitMQ ativos

## Checklist — só PIX

- [ ] `POST /api/payments/pix` no servidor
- [ ] `Idempotency-Key`
- [ ] `payment_id` no pedido
- [ ] Webhook `pix.payment.*` ou polling se necessário

## Validação final para IA

Ao entregar código ao usuário, confirme explicitamente:

1. Quais módulos deste pacote foram seguidos
2. Quais rotas são do **host** vs **CajuPay**
3. Se cartão/wallet: HTTPS e webhooks configurados

---

<!-- module: 17-brand-assets -->


# Assets de marca (logos)

## INSTRUÇÕES PARA O MODELO

Use URLs públicas estáveis. Não invente logos locais sem o parceiro hospedar cópia.

## Quando usar este módulo

Checkout UI do integrador que exibe marca CajuPay ou badges de wallet.

## CajuPay

| Uso | URL |
|-----|-----|
| Ícone / logo checkout | `https://storage.cajupay.com.br/icone-cajupay.png` |

Exemplo no painel do checkout:

```html
<img
  src="https://storage.cajupay.com.br/icone-cajupay.png"
  alt="Pagamento seguro CajuPay"
  width="32"
  height="32"
/>
```

## Apple Pay

Use o mark oficial Apple Pay (não alterar proporções/cores além das guidelines Apple).

Referências públicas (SVG/PNG):

- Apple Marketing Resources: https://developer.apple.com/apple-pay/marketing/
- Botão no checkout: preferir o botão **nativo** renderizado pelo SDK dentro de `#cajupay-method` — não duplicar com imagem estática clicável.

Se precisar de ícone na **lista de métodos** (antes do mount):

```html
<!-- Exemplo: badge na seleção de método — substitua pelo asset oficial Apple -->
<img src="https://upload.wikimedia.org/wikipedia/commons/b/b0/Apple_Pay_logo.svg" alt="Apple Pay" height="24" />
```

> Integrador deve baixar asset oficial da Apple para produção comercial.

## Google Pay

- Google Pay API brand guidelines: https://developers.google.com/pay/api/web/guides/brand-guidelines
- Badge "G" / Google Pay button: usar assets do kit Google ou o botão nativo do SDK.

```html
<!-- Exemplo ilustrativo — use assets oficiais Google em produção -->
<img src="https://www.gstatic.com/instantbuy/svg/light_gpay.svg" alt="Google Pay" height="24" />
```

## Boas práticas

| DO | DON'T |
|----|-------|
| Botão nativo SDK para pagamento wallet | Imagem estática que simula botão de pagar |
| Logo CajuPay discreto no cabeçalho do painel | Sugerir que o integrador é o processador de cartão |
| HTTPS para carregar imagens no checkout | Mixed content HTTP em página HTTPS |

## Checklist

- [ ] Logo CajuPay opcional no painel (URL storage)
- [ ] Wallets: botão nativo no slot SDK, não botão duplicado do host

---

<!-- module: 18-pix-refund-api -->


# Reembolso PIX (API merchant)

## INSTRUÇÕES PARA O MODELO

1. Reembolso PIX é **integral** (valor total do pagamento) — não há parcial via esta API.
2. Use o `payment_id` retornado em `POST /api/payments/pix` (UUID CajuPay).
3. Um pedido de reembolso por `payment_id`; `client_refund_id` opcional para correlacionar no sistema do parceiro.
4. Com MED **aberto** no pagamento, novo reembolso é bloqueado (`med_blocks_refund`).
5. Reembolso de **cartão** não usa estas rotas — veja webhook `checkout.payment.refunded` (módulo 11).

## Quando usar este módulo

Plataforma do parceiro que permite devolver PIX ao comprador pela API (ERP, painel seller, automação pós-venda).

## Autenticação

- `X-API-Key` + `X-API-Secret`
- Escopo: **`payments.write`**
- Base: `https://api.cajupay.com.br`

## Endpoints

Montados em `/api/payments/{payment_id}/pix-refund`:

| Método | Rota | Efeito |
|--------|------|--------|
| POST | `/api/payments/{payment_id}/pix-refund` | Cria ou retoma pedido de reembolso |
| GET | `/api/payments/{payment_id}/pix-refund` | Consulta estado (sem efeito colateral) |
| POST | `/api/payments/{payment_id}/pix-refund/retry` | Reenvia após falha ou saldo insuficiente |
| DELETE | `/api/payments/{payment_id}/pix-refund` | Cancela se status `pending_balance` |

## POST — iniciar reembolso

```http
POST https://api.cajupay.com.br/api/payments/550e8400-e29b-41d4-a716-446655440000/pix-refund
Content-Type: application/json
X-API-Key: gpk_...
X-API-Secret: gsk_...

{
  "client_refund_id": "pedido-123-refund"
}
```

`client_refund_id` (opcional): até 64 caracteres; caracteres `a-z`, `A-Z`, `0-9`, `-`, `_`. Identificador de correlação no PSP/OnlyUp.

### Resposta 200 (exemplo)

```json
{
  "id": "uuid-do-pedido-reembolso",
  "payment_id": "550e8400-e29b-41d4-a716-446655440000",
  "pay_account_id": "uuid-conta",
  "amount_cents": 14990,
  "status": "submitted",
  "client_refund_id": "pedido-123-refund",
  "rtr_id": "",
  "psp_status": "EM_PROCESSAMENTO",
  "last_error": "",
  "created_at": "2026-05-21T12:00:00Z",
  "updated_at": "2026-05-21T12:00:01Z",
  "psp_reference": "txid-ou-ref-psp",
  "pix_end_to_end_id": "E123...",
  "payment_amount_cents": 14990,
  "payment_status": "paid"
}
```

## Status do reembolso

| `status` | Significado para o integrador |
|----------|-------------------------------|
| `pending_balance` | Aguardando saldo na carteira para enviar ao PSP |
| `submitted` | Enviado ao provedor; aguardar confirmação |
| `devolvido` | Reembolso confirmado (terminal sucesso) |
| `failed` | Falha — ver `last_error`; pode usar retry |
| `cancelled` | Cancelado pelo merchant (DELETE) |

`psp_status` reflete status bruto do provedor quando disponível.

## GET — consultar

```http
GET https://api.cajupay.com.br/api/payments/{payment_id}/pix-refund
X-API-Key: ...
X-API-Secret: ...
```

- `200` — objeto `RefundRow` (mesmos campos do POST).
- `404` + `{ "error": "refund_not_found" }` — ainda não existe pedido para este pagamento.

## POST retry

```http
POST https://api.cajupay.com.br/api/payments/{payment_id}/pix-refund/retry
```

Sem body. Reutiliza o registro existente. Útil após `failed` ou `pending_balance` quando o saldo foi creditado.

## DELETE — cancelar

```http
DELETE https://api.cajupay.com.br/api/payments/{payment_id}/pix-refund
```

- `204` — cancelado.
- `409` + `{ "error": "nothing_to_cancel" }` — não há pedido em `pending_balance` para cancelar.

## Regras de elegibilidade

| Regra | Detalhe |
|-------|---------|
| Pagamento | Deve estar `paid` |
| Valor | Reembolso **integral** (`amount_cents` = valor do pagamento) |
| Janela | **30 dias** após criação do pagamento (`refund_window_expired`) |
| MED | Disputa MED **aberta** bloqueia (`med_blocks_refund`) |
| Provedor | Política da conta/PSP (ex.: fluxo OnlyUp); alguns erros são específicos do adquirente |
| E2E | Pode exigir `pix_end_to_end_id` resolvível (`missing_pix_end_to_end_id`) |

## Erros HTTP (`error` no JSON)

| HTTP | `error` | Ação |
|------|---------|------|
| 401 | `unauthorized` | Credenciais |
| 403 | `forbidden` | Escopo |
| 404 | `payment_not_found` | UUID inválido ou outra conta |
| 404 | `refund_not_found` | GET sem pedido prévio |
| 400 | `payment_not_paid` | Pagamento não está pago |
| 400 | `refund_window_expired` | Fora dos 30 dias |
| 400 | `med_blocks_refund` | Resolver MED antes |
| 400 | `invalid_client_refund_id` | Formato do ID |
| 400 | `missing_pix_end_to_end_id` | Falta identificador PIX no PSP |
| 400 | `refund_only_onlyup` | Conta/rota não suporta API refund |
| 409 | `refund_cancelled` / `refund_not_eligible:*` | Estado terminal ou inelegível |
| 409 | `nothing_to_cancel` | DELETE sem pending_balance |
| 400 | `refund_failed` | Erro genérico — ver logs CajuPay |

## Fluxo recomendado na plataforma do parceiro

```mermaid
sequenceDiagram
  participant UI as PainelParceiro
  participant API as BackendParceiro
  participant CP as CajuPayAPI
  participant WH as WebhookOutbound

  UI->>API: Solicitar devolucao pedido X
  API->>CP: POST payments/id/pix-refund
  CP-->>API: status submitted ou pending_balance
  loop Polling opcional
    API->>CP: GET payments/id/pix-refund
  end
  WH->>API: pix.payment.refunded HMAC
  API->>API: Marcar pedido reembolsado + estornar acesso
```

1. Guardar `payment_id` CajuPay no pedido desde a cobrança PIX.
2. `POST /pix-refund` com `client_refund_id` = id interno do reembolso.
3. Polling `GET .../pix-refund` a cada 3–10 s até `devolvido` ou `failed`.
4. Paralelamente, tratar webhook `pix.payment.refunded` (módulo 12) com mesma idempotência.
5. Em `failed`, exibir `last_error` e oferecer **Retry**.

## Exemplo Node (servidor)

```javascript
async function refundPixPayment(paymentId, clientRefundId) {
  const res = await fetch(
    `https://api.cajupay.com.br/api/payments/${paymentId}/pix-refund`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-API-Key": process.env.CAJUPAY_API_KEY,
        "X-API-Secret": process.env.CAJUPAY_API_SECRET,
      },
      body: JSON.stringify({ client_refund_id: clientRefundId }),
    }
  );
  const body = await res.json();
  if (!res.ok) throw new Error(body.error || res.statusText);
  return body;
}
```

## Reembolso cartão (não é esta API)

| Método | Como o parceiro é notificado |
|--------|----------------------------|
| Cartão / Apple Pay / Google Pay | Webhook `checkout.payment.refunded` com `cajupay_charge_id` |
| Ação | Atualizar pedido; estorno pode ser iniciado no painel CajuPay / fluxo card-service |

Não existe `POST /api/payments/{id}/card-refund` público equivalente ao PIX neste contrato.

## Checklist

- [ ] `payment_id` CajuPay persistido no pedido
- [ ] Escopo `payments.write`
- [ ] Tratar `med_blocks_refund` na UI
- [ ] Webhook `pix.payment.refunded` cadastrado (módulo 12)
- [ ] Idempotência no handler do parceiro (event `id` estável)

---

<!-- module: 19-med-pix-api -->


# MED PIX (consulta e defesa via API)

## INSTRUÇÕES PARA O MODELO

1. **MED** (Mecanismo Especial de Devolução) é aberto pelo **banco/PSP** — o integrador **não cria** disputa via API.
2. A API permite **listar**, **consultar** e **enviar defesa** (`multipart/form-data`).
3. Disputa aberta pode **bloquear saldo** e impedir reembolso PIX (`med_blocks_refund`).
4. Notifique o seller via webhook `pix.payment.med_opened` / `med_resolved` e/ou polling das rotas abaixo.

## Quando usar este módulo

Plataforma do parceiro com área “Disputas PIX / MED” para o lojista responder contestações de pagamento PIX.

## Autenticação

| Rota | Escopo |
|------|--------|
| `GET /api/med/summary` | `payments.write` **ou** `wallet.read` |
| `GET /api/med`, `GET /api/med/{id}`, `POST .../defense` | `payments.write` |

## GET /api/med/summary — contador abertas

```http
GET https://api.cajupay.com.br/api/med/summary
X-API-Key: ...
X-API-Secret: ...
```

Resposta:

```json
{ "open_count": 2 }
```

Use no dashboard do parceiro (badge de disputas pendentes).

## GET /api/med — listar disputas

```http
GET https://api.cajupay.com.br/api/med?limit=50
```

Resposta:

```json
{
  "data": [
    {
      "id": "uuid-disputa",
      "payment_id": "uuid-pagamento",
      "txid": "abc123",
      "status": "open",
      "amount_cents": 14990,
      "currency": "BRL",
      "created_at": "2026-05-21T10:00:00Z",
      "updated_at": "2026-05-21T10:05:00Z"
    }
  ]
}
```

`limit` padrão 50; query opcional.

## GET /api/med/{dispute_id} — detalhe

Retorno merchant-safe (sem payload bruto do webhook PSP):

```json
{
  "id": "uuid-disputa",
  "payment_id": "uuid-pagamento",
  "txid": "abc123",
  "e2eid": "E123456789...",
  "external_med_id": "id-psp-opcional",
  "status": "open",
  "amount_cents": 14990,
  "currency": "BRL",
  "defense_text": "",
  "defended_at": null,
  "dispute_created_at": "2026-05-21T10:00:00Z",
  "dispute_updated_at": "2026-05-21T10:05:00Z",
  "attachments": [],
  "payment": {
    "customer_ref": "cliente-456",
    "product_ref": "produto-x",
    "origin_type": "payment_link",
    "origin_id": "uuid-origem",
    "status": "paid",
    "pix_reference": "psp-ref",
    "payer_name": "Cliente",
    "payer_email": "c@x.com",
    "payer_document": "12345678901",
    "created_at": "...",
    "updated_at": "..."
  }
}
```

Erros: `404` + `{ "error": "not_found" }` se disputa não pertence à conta.

## Status de disputa

| `status` | UI sugerida no parceiro |
|----------|-------------------------|
| `open` | Aguardando defesa do lojista |
| `defense_submitted` | Defesa enviada; aguardar PSP |
| `resolved_won` | Disputa ganha (saldo liberado conforme regra) |
| `resolved_lost` | Disputa perdida |
| `cancelled` | Encerrada/cancelada |

Webhooks `med_resolved` trazem `outcome`: `won` | `lost` | `cancelled`.

## POST /api/med/{dispute_id}/defense — enviar defesa

Apenas com `status = open`. Caso contrário: `409` + `dispute_not_open`.

### multipart/form-data (recomendado)

```http
POST https://api.cajupay.com.br/api/med/{dispute_id}/defense
Content-Type: multipart/form-data
X-API-Key: ...
X-API-Secret: ...

text=Explicacao da venda e entrega do produto digital...
attachments[]=@comprovante1.pdf
attachments[]=@print2.png
```

| Campo | Obrigatório | Limite |
|-------|-------------|--------|
| `text` | **Sim** | Texto da defesa |
| `attachments[]` | Não | Máx. **10** arquivos; **8 MiB** cada |

Tipos de anexo validados pelo backend (imagens/PDF — seguir erros `defense_attachment_type_not_allowed`).

Resposta:

```json
{
  "ok": true,
  "defense_sent_to_acquirer": true
}
```

`defense_sent_to_acquirer: false` — defesa salva na CajuPay mas falha ao repassar ao adquirente (OnlyUp); o parceiro pode exibir aviso e tentar de novo conforme política.

### JSON alternativo (só texto, sem anexo)

```http
POST .../defense
Content-Type: application/json

{ "text": "Minha defesa sem anexos..." }
```

## Erros comuns (defesa)

| `error` | HTTP |
|---------|------|
| `not_found` | 404 |
| `dispute_not_open` | 409 |
| `invalid_multipart` | 400 |
| `defense_attachments_too_many` | 400 |
| `defense_attachment_invalid_size` | 400 |
| `defense_attachment_type_not_allowed` | 400 |
| `storage_not_configured` | 503 |
| `invalid_json` / `invalid_body` | 400 |

## Impacto financeiro e reembolso

| Situação | Efeito |
|----------|--------|
| MED aberta | Pode haver **hold** de saldo na carteira `main` |
| Reembolso PIX | Bloqueado enquanto MED aberta (`med_blocks_refund`) |
| MED resolvida `won` | Lojista mantém fundos (conforme liquidação) |
| MED resolvida `lost` | Devolução ao pagador via mecanismo bancário |

O parceiro deve sincronizar status do **pedido interno** com webhooks e GET de disputa.

## Fluxo na plataforma do parceiro

```mermaid
sequenceDiagram
  participant Banco as BancoPSP
  participant CP as CajuPay
  participant WH as Webhook
  participant P as PlataformaParceiro

  Banco->>CP: MED aberta
  CP->>WH: pix.payment.med_opened
  WH->>P: Notificar seller
  P->>P: GET /api/med/summary badge
  P->>CP: GET /api/med/id
  P->>CP: POST /api/med/id/defense multipart
  Banco->>CP: Resultado MED
  CP->>WH: pix.payment.med_resolved
  WH->>P: outcome won/lost/cancelled
```

## Exemplo curl (defesa com anexo)

```bash
curl -X POST "https://api.cajupay.com.br/api/med/DISPUTA_UUID/defense" \
  -H "X-API-Key: SUA_KEY" \
  -H "X-API-Secret: SUA_SECRET" \
  -F "text=Produto digital entregue; segue comprovante de acesso." \
  -F "attachments[]=@./comprovante.pdf"
```

## Exemplo Node (form-data)

```javascript
import FormData from "form-data";
import fs from "fs";

async function submitMedDefense(disputeId, text, filePaths) {
  const form = new FormData();
  form.append("text", text);
  for (const p of filePaths) {
    form.append("attachments[]", fs.createReadStream(p));
  }
  const res = await fetch(
    `https://api.cajupay.com.br/api/med/${disputeId}/defense`,
    {
      method: "POST",
      headers: {
        "X-API-Key": process.env.CAJUPAY_API_KEY,
        "X-API-Secret": process.env.CAJUPAY_API_SECRET,
        ...form.getHeaders(),
      },
      body: form,
    }
  );
  return res.json();
}
```

## Checklist

- [ ] Área “Disputas MED” no painel do parceiro
- [ ] Webhooks `pix.payment.med_opened` e `med_resolved`
- [ ] Bloquear botão “Reembolsar PIX” quando MED aberta
- [ ] Defesa só em disputas `open`
- [ ] Respeitar limites de anexos (10 × 8 MiB)

---

# Multi-moeda (Caju Global)

## INSTRUÇÕES PARA O MODELO

1. **PIX** permanece apenas em **BRL**.
2. **Caju Global** (cartão, boleto, Apple Pay, Google Pay) aceita qualquer moeda **ISO 4217** válida no campo `currency`.
3. `amount_cents` está sempre na **menor unidade** da moeda (centavos para BRL/USD; unidade inteira para JPY, KRW, etc.).
4. No webhook `checkout.payment.paid`, `currency` e `amount_cents` refletem o **pagamento efetivo** (sincronizado após confirmação).
5. Não converter valores para BRL na API — o integrador exibe na moeda recebida.

## Webhook `checkout.payment.paid` (USD)

```json
{
  "type": "checkout.payment.paid",
  "data": {
    "object": {
      "gateway": "cajupay",
      "checkout_session_id": "uuid-sessao",
      "cajupay_charge_id": "uuid-cobranca",
      "amount_cents": 1999,
      "fee_cents": 120,
      "net_cents": 1879,
      "currency": "usd"
    }
  }
}
```

Erros: `invalid_currency`, `pix_only_requires_brl`.
