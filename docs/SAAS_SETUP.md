# Operação como SaaS

Este documento descreve a infraestrutura multi-tenant e de assinaturas adicionada
ao projeto, e como ativá-la. **Por padrão tudo fica desligado** (`BILLING_ENABLED=false`)
e o sistema se comporta como a instalação single-tenant original.

## 1. Isolamento de dados por marca

- O trait `App\Models\Concerns\BelongsToBrand` aplica um global scope que limita
  todas as queries de usuários autenticados à **marca ativa** (`users.current_brand_id`).
- Aplicado a todos os models de domínio com `brand_id` (campanhas, listas, contatos,
  templates, posts, blog, SMS, analytics, etc).
- Models com `brand_id` anulável (conexões "globais": `SocialAccount`,
  `AnalyticsConnection`, etc.) continuam exibindo registros com `brand_id NULL`
  (`$brandScopeIncludesNull = true`). **Atenção:** registros globais são visíveis
  para todos os usuários — vincule conexões a marcas para isolá-las.
- `Post` usa a pivô `post_brand` (posts cross-brand) na restrição do escopo.
- Contexto sem usuário autenticado (console, filas, webhooks) **não** é escopado:
  jobs devem sempre filtrar pelos IDs recebidos.
- Para consultas administrativas: `Model::withoutBrandScope()`.
- `BrandPolicy` protege as rotas de marcas (editar/excluir/assets/convites).

## 2. Assinaturas com Mercado Pago

Variáveis de ambiente:

```env
BILLING_ENABLED=true
BILLING_TRIAL_DAYS=14
MERCADOPAGO_ACCESS_TOKEN=APP_USR-...
MERCADOPAGO_PUBLIC_KEY=APP_USR-...
MERCADOPAGO_WEBHOOK_SECRET=...
```

Passos:

1. Crie a aplicação em <https://www.mercadopago.com.br/developers> com o produto
   **Assinaturas** (preapproval).
2. Configure o webhook no painel do MP apontando para
   `https://seu-dominio/webhook/mercadopago` (evento *Planos e assinaturas*) e
   copie o secret para `MERCADOPAGO_WEBHOOK_SECRET`.
3. Rode `php artisan db:seed --class=PlanSeeder` para criar os planos
   Starter/Pro/Business (edite os valores em `database/seeders/PlanSeeder.php`).
4. Ative `BILLING_ENABLED=true`.

Fluxo: o usuário escolhe um plano em `/billing` → o sistema cria um *preapproval*
pendente e redireciona ao checkout do MP → o webhook ativa a assinatura local
quando o MP autoriza (`authorized`).

### Limites de plano (`plans.limits`)

| Chave | Significado |
|---|---|
| `max_brands` | Marcas que o Owner pode criar |
| `max_users` | Assentos (membros únicos em todas as marcas do Owner) |
| `monthly_emails` | E-mails enviados por mês |
| `monthly_ai_tokens` | Tokens de IA (entrada+saída) por mês |

`null` = ilimitado. A "conta" é o usuário **Owner** da marca: o consumo de todos
os membros conta contra o plano dele (`App\Services\Billing\UsageService`).

Enforcement atual: criação de marca, convites (assentos), `AIGateway` (chat e
imagem) e início de campanhas de e-mail — excedendo o limite é lançada
`QuotaExceededException` (HTTP 429 em chamadas AJAX, redirect para `/billing`
em navegação).

## 3. Onboarding e convites

- O cadastro cria automaticamente a primeira marca (usuário como Owner), define
  a marca ativa e inicia um trial no primeiro plano ativo.
- Convites: `POST /brands/{brand}/invitations` (`email`, `role` ∈ admin/editor/viewer)
  envia e-mail com link de aceite (`/invitations/{token}`, expira em 7 dias).
  O convidado precisa estar logado com o mesmo e-mail do convite.
- Membros de marcas cujo Owner tem assinatura válida têm acesso sem assinatura própria.

## 4. Segurança

- Tokens OAuth (`social_accounts`, `analytics_connections`) criptografados em
  repouso (cast `encrypted`); a migration `2026_06_11_000003` criptografa dados
  pré-existentes de forma idempotente. **Não troque a `APP_KEY`** sem re-criptografar.
- Webhook SendPulse: configure `SENDPULSE_WEBHOOK_TOKEN` e registre a URL no
  painel do SendPulse como `https://seu-dominio/webhook/sendpulse?token=<valor>`.
- Webhook Mercado Pago: valida HMAC `x-signature` quando o secret está configurado.

## 5. Equipe (membros e convites)

A seção **Equipe** na edição da marca (`/brands/{id}/edit`) permite a
Owner/Admin: convidar por e-mail (papéis admin/editor/viewer), alterar papel,
remover membros e revogar convites pendentes. O papel Owner é imutável.

## 6. Operação

- `email:fix-stuck` roda a cada 15 minutos (campanhas travadas em "sending").
- `system:alert-failed-jobs` roda de hora em hora: registra em SystemLog e
  envia e-mail para `ADMIN_ALERT_EMAIL` (se configurado) quando novos jobs
  falham na fila.
- Scripts SQL/PHP de diagnóstico históricos foram movidos para
  `scripts/legacy/` (apenas referência; não usar em produção).

## 7. Pendências conhecidas (próximos passos)

- Tabela `settings` é global (não por marca/conta) — chaves de IA/OAuth são
  da plataforma, repassadas via limites de plano.
- Conexões/contas "globais" legadas (`brand_id NULL` e `user_id NULL`) seguem
  visíveis a todos; novas criações já registram o dono (`user_id`).
- Dados analíticos (`analytics_data_points`/`daily_summaries`) com
  `brand_id NULL` (conexões globais) seguem o comportamento legado.
- Observabilidade mais profunda (ex: Sentry/Horizon) recomendada conforme a
  base de clientes crescer.
