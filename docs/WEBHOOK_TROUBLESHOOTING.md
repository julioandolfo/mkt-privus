# Webhook Não Funcionando?

## 🔍 Diagnóstico Rápido

### Passo 1: Verificar se URL está acessível

Abra no navegador:
```
https://seu-dominio.com/webhook/sendpulse
```

**Esperado:** Página em branco ou erro 405 (Method Not Allowed) - isso é NORMAL pois só aceita POST.

**Se der erro 404:** URL incorreta ou rota não registrada.

---

### Passo 2: Verificar rota no Laravel

Execute:
```bash
php artisan route:list | grep webhook
```

**Esperado ver:**
```
POST   webhook/sendpulse  SendPulseWebhookController@handle
```

Se não aparecer, verifique `routes/web.php`:
```php
Route::post('/webhook/sendpulse', [SendPulseWebhookController::class, 'handle']);
```

---

### Passo 3: Verificar logs do Laravel

```bash
tail -f storage/logs/laravel.log | grep -i webhook
```

Ou no banco:
```sql
SELECT * FROM system_logs 
WHERE message LIKE '%webhook%' 
   OR action LIKE '%webhook%'
ORDER BY created_at DESC 
LIMIT 10;
```

---

### Passo 4: Testar manualmente

```bash
curl -X POST https://seu-dominio.com/webhook/sendpulse \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","event":"delivered","campaign_id":"1"}' \
  -v
```

**Esperado:** HTTP 200 com JSON `{"status":"ok"}`

---

## 🚨 Problemas Comuns

### 1. "Não recebo webhooks do SendPulse"

**Verifique no SendPulse:**
1. Acesse https://login.sendpulse.com → Settings → Webhooks
2. Confirme que a URL está exatamente:
   ```
   https://seu-dominio.com/webhook/sendpulse
   ```
3. Verifique se HTTPS está ativo (SendPulse não envia para HTTP)
4. Teste o webhook com botão "Test" no painel do SendPulse

### 2. "Recebo webhooks mas não atualiza no sistema"

**Causas possíveis:**
- Campanha não encontrada (ID diferente)
- Contato não encontrado (email diferente)
- Tipo de evento não mapeado

**Verifique logs:**
```sql
SELECT action, message, context 
FROM system_logs 
WHERE created_at >= NOW() - INTERVAL 1 HOUR
AND (
    message LIKE '%webhook%' 
    OR action LIKE '%webhook%'
    OR message LIKE '%SendPulse%'
)
ORDER BY created_at DESC;
```

### 3. "Entregues continua 0"

**Normal se:**
- Webhook foi configurado DEPOIS do envio
- Webhooks só funcionam para envios FUTUROS

**Solução:** Aguarde novos envios ou reenvie a campanha.

### 4. "Aberturas/Cliques não aparecem"

**Verifique:**
1. Links no email estão sendo reescritos para tracking?
2. Imagens estão com tracking pixel?
3. O email foi realmente aberto/clicado?

**Teste:** Envie um email para você mesmo e abra/clique.

---

## ✅ Checklist de Configuração

- [ ] URL do webhook configurada no SendPulse
- [ ] URL usa HTTPS (não HTTP)
- [ ] Rota `/webhook/sendpulse` existe no Laravel
- [ ] Controller `SendPulseWebhookController` existe
- [ ] Servidor aceita requisições POST externas
- [ ] Firewall não bloqueia (porta 443)
- [ ] Certificado SSL válido

---

## 🛠️ Comandos Úteis

```bash
# Verificar se rota existe
php artisan route:list | grep webhook

# Limpar cache de rotas
php artisan route:clear

# Verificar logs em tempo real
tail -f storage/logs/laravel.log | grep -i webhook

# Testar webhook localmente
php artisan tinker
>>> app(\Illuminate\Http\Request::class)->create('/webhook/sendpulse', 'POST', ['email' => 'test@test.com', 'event' => 'delivered']);
```

---

## 📞 Ainda com Problemas?

Execute o SQL:
```sql
-- Verificar últimos webhooks recebidos
SELECT * FROM system_logs 
WHERE created_at >= NOW() - INTERVAL 1 HOUR
AND action LIKE '%webhook%'
ORDER BY created_at DESC;
```

Se não aparecer NENHUM resultado, o webhook não está chegando ao servidor.
