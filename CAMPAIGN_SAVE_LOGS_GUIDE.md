# Logs de Salvamento de Campanhas

## ✅ Logs Implementados

Agora todas as operações de salvar/editar campanhas são logadas no sistema `/logs`.

### Criar Nova Campanha (`store`)

| Evento | Nível | Descrição | Dados Logados |
|--------|-------|-----------|---------------|
| `campaign.store.started` | info | Início do processo de criação | brand_id, user_id, is_draft, nome, status |
| `campaign.store.validated` | info | Validação passou | brand_id, campaign_name, has_html |
| `campaign.store.validation_failed` | error | Erro na validação | brand_id, user_id, mensagem de erro |
| `campaign.store.processing_images` | info | Iniciando processamento de imagens | brand_id, tamanho do HTML |
| `campaign.store.images_processed` | info | Imagens processadas | brand_id, tamanho original, tamanho final |
| `campaign.store.image_processing_failed` | error | Erro ao processar imagens | brand_id, mensagem de erro, stack trace |
| `campaign.store.created` | info | Campanha criada no banco | campaign_id, campaign_name, status |
| `campaign.store.lists_attached` | info | Listas vinculadas | campaign_id, quantidade de listas |
| `campaign.store.prepared` | info | Campanha preparada | campaign_id, total_recipients |
| `campaign.store.completed` | info | Processo finalizado | campaign_id, mensagem de sucesso |
| `campaign.store.failed` | error | Erro geral na criação | brand_id, user_id, mensagem de erro, stack trace |

### Atualizar Campanha (`update`)

| Evento | Nível | Descrição | Dados Logados |
|--------|-------|-----------|---------------|
| `campaign.update.started` | info | Início da atualização | campaign_id, campaign_name, brand_id, user_id |
| `campaign.update.cannot_edit` | warning | Tentativa de editar campanha bloqueada | campaign_id, campaign_status, user_id |
| `campaign.update.validated` | info | Validação passou | campaign_id, new_name, has_html |
| `campaign.update.validation_failed` | error | Erro na validação | campaign_id, mensagem de erro |
| `campaign.update.processing_images` | info | Iniciando processamento de imagens | campaign_id, tamanho do HTML |
| `campaign.update.images_processed` | info | Imagens processadas | campaign_id, tamanho original, tamanho final |
| `campaign.update.image_processing_failed` | error | Erro ao processar imagens | campaign_id, mensagem de erro |
| `campaign.update.saved` | info | Campanha atualizada no banco | campaign_id, new_name |
| `campaign.update.lists_attached` | info | Listas atualizadas | campaign_id, quantidade de listas |
| `campaign.update.completed` | info | Atualização finalizada | campaign_id, total_recipients |
| `campaign.update.failed` | error | Erro geral na atualização | campaign_id, mensagem de erro, stack trace |

---

## 🔍 Como Consultar os Logs

### No Banco de Dados (phpMyAdmin)

```sql
-- Últimas operações de salvar campanha
SELECT created_at, action, level, message, context
FROM system_logs
WHERE action LIKE 'campaign.store%' OR action LIKE 'campaign.update%'
ORDER BY created_at DESC
LIMIT 20;

-- Ver erros específicos de uma campanha
SELECT created_at, action, message, context
FROM system_logs
WHERE channel = 'email'
AND (
    context LIKE '%campaign_id": 123%' -- substitua 123 pelo ID
    OR action LIKE '%failed%'
)
ORDER BY created_at DESC;

-- Ver fluxo completo de criação de uma campanha
SELECT created_at, action, level, message
FROM system_logs
WHERE action LIKE 'campaign.store.%'
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at ASC;
```

### Na Página /logs do Sistema

Filtre por:
- **Canal**: `email`
- **Ação**: `campaign.store.*` ou `campaign.update.*`

---

## 🐛 Depuração de Problemas

### Fluxo Normal (Sucesso)

```
campaign.store.started → campaign.store.validated → 
campaign.store.processing_images → campaign.store.images_processed →
campaign.store.created → campaign.store.lists_attached → 
campaign.store.prepared → campaign.store.completed
```

### Se Houver Erro na Validação

```
campaign.store.started → campaign.store.validation_failed ❌
```

### Se Houver Erro no Processamento de Imagens

```
campaign.store.started → campaign.store.validated → 
campaign.store.processing_images → campaign.store.image_processing_failed ⚠️
→ (continua sem imagens processadas)
```

### Se Houver Erro no Salvamento

```
campaign.store.started → campaign.store.validated → 
campaign.store.processing_images → campaign.store.images_processed →
campaign.store.created → campaign.store.failed ❌
```

---

## 💡 Dicas

1. **Sempre verifique** `campaign.store.started` e `campaign.store.completed` para confirmar que o processo iniciou e terminou
2. Se faltar o `completed`, provavelmente houve um erro - procure o `failed`
3. Erros de processamento de imagens são logados mas **não impedem** o salvamento da campanha
4. Use os logs para rastrear performance: compare os timestamps entre eventos

---

## 📊 Exemplo de Análise

```sql
-- Ver tempo médio de processamento de imagens
SELECT 
    AVG(TIMESTAMPDIFF(SECOND, 
        (SELECT created_at FROM system_logs sl2 
         WHERE sl2.action = 'campaign.store.processing_images' 
         AND sl2.created_at >= sl1.created_at 
         ORDER BY sl2.created_at LIMIT 1),
        sl1.created_at
    )) as tempo_medio_segundos
FROM system_logs sl1
WHERE sl1.action = 'campaign.store.images_processed'
AND sl1.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

Agora você tem visibilidade completa do processo de salvamento de campanhas! ✅
