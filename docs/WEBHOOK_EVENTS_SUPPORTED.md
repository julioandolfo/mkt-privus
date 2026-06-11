# Eventos de Webhook Suportados

## ✅ Nosso Sistema Recebe e Processa:

### Eventos de Email (Rastreamento)
| Evento SendPulse | Ação no Sistema | Atualiza Contador |
|------------------|-----------------|-------------------|
| `delivered` | Marca como entregue | ✅ Entregues +1 |
| `open` / `opened` | Registra abertura | ✅ Aberturas +1 |
| `click` / `clicked` | Registra clique | ✅ Cliques +1 |
| `hard_bounce` | Marca como bounced (hard) | ✅ Bounces +1 |
| `soft_bounce` | Marca como bounced (soft) | ✅ Bounces +1 |
| `spam` / `complaint` | Marca como spam | ✅ Reclamações +1 |
| `unsubscribe` | Cancela inscrição | ✅ Descadastros +1 |
| `subscribe` | Novo assinante | Log apenas |

### Eventos de Status
| Evento SendPulse | Ação no Sistema |
|------------------|-----------------|
| `status_change` | Log de mudança de status |

---

## 🔗 URL do Webhook

Configure no SendPulse:
```
https://seu-dominio.com/webhook/sendpulse
```

---

## 📊 O Que Vai Acontecer

Após configurar o webhook:

1. **Quando alguém abrir o email** → Contador "Aberturas" aumenta
2. **Quando alguém clicar em link** → Contador "Cliques" aumenta  
3. **Quando email for entregue** → Contador "Entregues" aumenta
4. **Se der bounce** → Contador "Bounces" aumenta
5. **Se marcar como spam** → Contador "Reclamações" aumenta

---

## ⚠️ Importante

### Os eventos são **retroativos apenas para novos envios**

Os 426 emails já enviados **NÃO** vão atualizar automaticamente porque o webhook não estava configurado na época do envio.

**Para atualizar os dados antigos**, você pode:
1. Aguardar novos envios (a partir de agora os dados vão entrar)
2. Ou usar a API do SendPulse para buscar histórico (opcional)

---

## 🧪 Testar se Está Funcionando

### Teste rápido via curl:
```bash
curl -X POST https://seu-dominio.com/webhook/sendpulse \
  -H "Content-Type: application/json" \
  -d '{
    "email": "teste@exemplo.com",
    "event": "delivered",
    "campaign_id": "1"
  }'
```

### Verificar logs:
```sql
-- Últimos webhooks recebidos
SELECT created_at, action, message, context
FROM system_logs
WHERE channel = 'webhook_email'
   OR action LIKE '%webhook%'
ORDER BY created_at DESC
LIMIT 10;
```

Ou execute: `check_webhook_delivery_fixed.sql`

---

## 📱 Eventos de SMS (também suportados)

Se você usa SMS pelo SendPulse, os mesmos webhooks funcionam:
- `delivered` (SMS entregue)
- `undelivered` / `failed` (SMS falhou)
- `clicked` (Clique em link do SMS)

---

## 🎯 Resumo

✅ **Sim, nosso sistema está 100% preparado** para receber todos os webhooks de:
- Rastreamento (abertura, clique)
- Entrega (delivered, bounce)
- Engajamento (spam, unsubscribe)
- Status (mudanças de estado)

🔄 **A partir de agora**, todos os novos envios terão rastreamento completo!
