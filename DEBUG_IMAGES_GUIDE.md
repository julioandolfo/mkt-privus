# Guia de Debug - Imagens Não Sendo Baixadas

## 🔍 Como Verificar o Que Está Acontecendo

### 1. Ver Logs no Sistema

Acesse `/logs` e filtre por:
- **Canal**: `email`
- **Ação**: `image.*`

Ou execute no banco:
```sql
-- Ver logs de processamento de imagens (últimos 30 min)
SELECT created_at, action, level, message, context
FROM system_logs
WHERE action LIKE 'image.%'
AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
ORDER BY created_at DESC;
```

### 2. Fluxo Esperado nos Logs

Quando você salva uma campanha com imagens externas, deve ver este fluxo:

```
campaign.update.started
image.process_start (mostra tamanho do HTML)
image.found_external (mostra quantas imagens encontrou)
image.download_start (para cada imagem externa)
image.download_step
image.downloading
image.downloaded / image.download_error
image.file_saved
image.asset_created
image.download_success
image.process_complete (resumo: X processadas, Y puladas, Z falhas)
campaign.update.saved
campaign.update.completed
```

### 3. Problemas Comuns e Logs

#### ❌ Imagem não é detectada
**Log esperado**: Não aparece `image.found_external` ou aparece com count=0

**Causa**: A imagem no HTML não está com `src` no formato esperado
**Verificação**:
```sql
-- Ver se há imagens no HTML da campanha
SELECT html_content 
FROM email_campaigns 
WHERE id = 123;  -- substitua pelo ID
-- Procure por <img src="..."> no resultado
```

#### ❌ Download falha
**Log esperado**: `image.download_error` ou `image.empty_download`

**Causas possíveis**:
- URL da imagem está quebrada/inacessível
- Servidor externo bloqueia download
- Timeout (imagem muito grande)

#### ❌ Erro ao salvar no storage
**Log esperado**: `image.save_failed`

**Causa**: Problema de permissão na pasta `/storage/app/public/email-assets/`

#### ❌ Erro ao criar registro no banco
**Log esperado**: `image.asset_create_failed`

**Causa**: Coluna `source_url` pode não existir ou outro erro de banco

### 4. Query de Diagnóstico Completo

Execute no phpMyAdmin:

```sql
-- Ver fluxo completo de uma tentativa de salvar campanha
SELECT 
    sl.created_at,
    sl.action,
    sl.level,
    sl.message,
    CASE 
        WHEN sl.context IS NOT NULL THEN 
            CONCAT(
                'count: ', JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.count')), ', ',
                'url: ', LEFT(JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.url')), 50)
            )
        ELSE ''
    END as detalhes
FROM system_logs sl
WHERE sl.channel = 'email'
AND (
    sl.action LIKE 'campaign.update.%'
    OR sl.action LIKE 'image.%'
)
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
ORDER BY sl.created_at ASC;
```

### 5. Teste Manual

Se quiser testar o processamento manualmente, crie um arquivo de teste:

```php
<?php
// test_image_download.php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Email\EmailImageService;

$service = app(EmailImageService::class);

// Teste com um HTML simples
$html = '<img src="https://placehold.co/600x300/6366f1/ffffff?text=Teste">';

echo "HTML original: $html\n\n";

$result = $service->processHtmlAndStoreImages($html, 1, null);

echo "HTML processado: $result\n";
```

Execute: `php test_image_download.php`

### 6. Verificar Estrutura do HTML

As imagens precisam estar no formato:
```html
<img src="https://..." ...>  <!-- URL externa -->
```

Não funciona com:
- CSS `background-image`
- Imagens em outros atributos
- URLs relativas sem `http/https`

### 7. Logs Específicos para Procurar

```sql
-- Ver apenas erros de imagens
SELECT created_at, message, context
FROM system_logs
WHERE action LIKE 'image.%'
AND level = 'error'
ORDER BY created_at DESC
LIMIT 10;

-- Ver imagens que foram baixadas com sucesso
SELECT created_at, message, 
    JSON_UNQUOTE(JSON_EXTRACT(context, '$.original_url')) as url,
    JSON_UNQUOTE(JSON_EXTRACT(context, '$.local_path')) as local
FROM system_logs
WHERE action = 'image.downloaded'
ORDER BY created_at DESC
LIMIT 10;
```

## 🎯 Próximos Passos

1. **Salve uma campanha** com imagens externas
2. **Verifique os logs** em `/logs` ou execute as queries acima
3. **Identifique onde o fluxo para** (qual log não aparece)
4. **Corrija o problema** baseado no tipo de erro

## 🆘 Se Não Aparecer Nenhum Log de Imagem

Se você não ver **nenhum** log com `action LIKE 'image.%'`, isso significa:

1. O `html_content` está vindo vazio do frontend
2. O processamento de imagens não está sendo chamado
3. Há um erro antes de chegar no `EmailImageService`

Verifique os logs `campaign.update.*` para ver até onde o processo chega.

---

Execute uma operação de salvar campanha e depois rode as queries acima para diagnosticar! 🔍
