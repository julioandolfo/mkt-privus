# Configuração de Webhooks no SendPulse

## O Problema

O contador **"Entregues"** fica em 0 porque o sistema depende de **webhooks** do SendPulse para saber quando um email foi realmente entregue.

## Como Funciona

1. Nosso sistema envia o email para o SendPulse
2. SendPulse tenta entregar ao destinatário
3. SendPulse envia um **webhook** de volta informando se foi entregue, aberto, etc.
4. Nosso sistema recebe e registra o evento

## Configuração Necessária

### 1. URL do Webhook

A URL que deve ser configurada no SendPulse é:

```
https://seu-dominio.com/webhook/sendpulse
```

Ou em desenvolvimento local (usando ngrok):
```
https://seu-ngrok-id.ngrok.io/webhook/sendpulse
```

### 2. Eventos a Configurar no SendPulse

No painel do SendPulse, vá em:
**Settings → Webhooks** ou **API → Webhooks**

Configure os seguintes eventos:

| Evento SendPulse | Nosso Sistema | Descrição |
|------------------|---------------|-----------|
| `delivered` | Entregue | Email foi entregue com sucesso |
| `opened` | Abertura | Destinatário abriu o email |
| `clicked` | Clique | Destinatário clicou em link |
| `bounced` | Bounce | Email voltou (caixa cheia, etc) |
| `spam` | Spam | Marcado como spam |
| `unsubscribed` | Descadastro | Cancelou inscrição |

### 3. Verificar Se Está Funcionando

Execute no banco:
```sql
-- Ver se webhooks estão chegando
SELECT event_type, COUNT(*) 
FROM email_campaign_events 
WHERE email_campaign_id = 1 
AND event_type IN ('delivered', 'opened')
GROUP BY event_type;
```

Ou use o arquivo: `check_webhook_delivery.sql`

## Diagnóstico Rápido

### Entregues = 0 mas Enviados > 0

**Possíveis causas:**

1. **Webhooks não configurados**
   - Acesse https://login.sendpulse.com → Settings → Webhooks
   - Adicione a URL: `https://seu-dominio.com/webhook/sendpulse`
   - Selecione os eventos: delivered, opened, clicked

2. **URL inacessível**
   - Verifique se a URL está acessível publicamente
   - Teste: `curl https://seu-dominio.com/webhook/sendpulse`
   - Deve retornar 200 OK (não 404 ou 500)

3. **Problema no SSL/HTTPS**
   - SendPulse pode rejeitar URLs com certificado inválido
   - Use Let's Encrypt ou certificado válido

4. **Firewall bloqueando**
   - Verifique se o servidor aceita requisições externas
   - Porta 443 deve estar aberta

## Teste Manual do Webhook

Você pode simular um webhook para testar:

```bash
curl -X POST https://seu-dominio.com/webhook/sendpulse \
  -H "Content-Type: application/json" \
  -d '{
    "email": "teste@exemplo.com",
    "event": "delivered",
    "campaign_id": "1"
  }'
```

Ou use o Postman/Insomnia.

## Solução Alternativa (se não conseguir webhooks)

Se não for possível configurar webhooks, podemos considerar um email como "entregue" quando o SendPulse aceita o envio (status 200 da API):

```php
// No EmailCampaignService, quando envio é aceito:
if ($result['success']) {
    // Criar evento 'sent'
    EmailCampaignEvent::create([...]);
    
    // Também criar 'delivered' imediatamente (assumindo que foi entregue)
    // Isso é uma aproximação - nem sempre o email é de fato entregue
    EmailCampaignEvent::create([
        'event_type' => 'delivered',
        ...
    ]);
}
```

⚠️ **Aviso:** Isso é menos preciso que webhooks, pois o email pode ser rejeitado pelo servidor do destinatário depois.

## Logs de Webhook

Verifique os logs para ver se webhooks estão chegando:

```sql
-- Últimos webhooks recebidos
SELECT created_at, event, message, metadata
FROM system_logs
WHERE channel = 'webhook_email'
   OR source LIKE '%webhook%'
ORDER BY created_at DESC
LIMIT 10;
```

Ou use: `check_webhook_delivery.sql`

## Resumo

| Situação | Webhook Configurado? | Entregues |
|----------|---------------------|-----------|
| ❌ Não configurado | Não | Sempre 0 |
| ⚠️ Configurado mas com erro | Sim (erro) | 0 ou incompleto |
| ✅ Funcionando | Sim | > 0 (quase igual a enviados) |

## Próximos Passos

1. Configure o webhook no SendPulse
2. Verifique se a URL está acessível
3. Aguarde novos envios (webhooks não são retroativos)
4. Acompanhe em `/logs` do sistema
