# Guia de Armazenamento de Imagens - Email Marketing

## ✅ O que foi implementado

### 1. **Serviço de Processamento de Imagens** (`EmailImageService`)

Serviço dedicado para:
- ✅ Baixar imagens de URLs externas automaticamente
- ✅ Armazenar imagens localmente em `/storage/email-assets/`
- ✅ Converter URLs externas para URLs locais no HTML
- ✅ Evitar duplicatas (verifica se imagem já foi baixada)

### 2. **Processamento Automático ao Salvar Campanha**

Quando você salva ou atualiza uma campanha:
1. O HTML é analisado em busca de imagens
2. Imagens externas (http/https) são baixadas automaticamente
3. URLs são substituídas por caminhos locais `/storage/email-assets/...`
4. As imagens ficam armazenadas no servidor

### 3. **API para Processamento Manual**

Endpoints disponíveis:

```
POST /email/editor/process-images
{
  "html": "<html>...<img src='https://externo.com/img.jpg'>...</html>"
}

Resposta:
{
  "success": true,
  "html": "...<img src='/storage/email-assets/1/2024/02/img_abc.jpg'>...",
  "external_images_found": 1,
  "external_images": ["https://externo.com/img.jpg"]
}
```

```
POST /email/editor/download-image
{
  "url": "https://externo.com/imagem.jpg"
}

Resposta:
{
  "success": true,
  "url": "/storage/email-assets/1/2024/02/imagem_abc.jpg",
  "original_url": "https://externo.com/imagem.jpg"
}
```

### 4. **Editor Atualizado**

- Placeholder de imagem agora usa SVG inline (não depende de URL externa)
- Método `processExternalImages()` disponível no componente
- Upload de imagens continua funcionando normalmente

### 5. **Banco de Dados**

Nova coluna `source_url` na tabela `email_assets`:
- Guarda a URL original da imagem
- Permite identificar de onde veio
- Evita baixar a mesma imagem duas vezes

---

## 📁 Estrutura de Armazenamento

Imagens são armazenadas em:
```
/storage/app/public/email-assets/{brand_id}/{ano}/{mes}/{nome_unico}.{ext}
```

Acessíveis via:
```
https://seu-dominio.com/storage/email-assets/{brand_id}/{ano}/{mes}/{nome_unico}.{ext}
```

---

## 🔒 Benefícios

1. **Imagens sempre disponíveis**: Não dependem de serviços externos
2. **Emails mais confiáveis**: Clientes de email não bloqueiam imagens locais
3. **Backup completo**: Todas as imagens estão no seu servidor
4. **GDPR/LGPD compliance**: Dados não são enviados para terceiros
5. **Performance**: Imagens servidas da mesma origem do email

---

## 🚀 Fluxo de Funcionamento

### Cenário 1: Upload de Imagem
```
Usuário faz upload → Armazena em /storage/email-assets/ → URL local inserida no HTML
```

### Cenário 2: Copiar/Colar URL Externa
```
Usuário cola URL externa → Ao salvar campanha → Sistema baixa imagem → Substitui por URL local
```

### Cenário 3: Template com Imagens Externas
```
Template importado com imagens externas → Ao salvar/editar → Sistema processa todas → Converte para locais
```

---

## 🛠️ Como Usar

### No Editor (Automático)
1. Crie ou edite uma campanha
2. Adicione imagens (upload ou URL externa)
3. Salve a campanha
4. O sistema processa automaticamente!

### Via API (Manual)
```javascript
// No frontend, após salvar
const response = await axios.post('/email/editor/process-images', {
    html: editorHtml
});

if (response.data.success) {
    console.log(`${response.data.external_images_found} imagens processadas`);
    // Atualiza o HTML com as URLs locais
    editor.setHtml(response.data.html);
}
```

---

## 🔍 Monitoramento

Logs do sistema (`system_logs`):
- `image.downloaded` - Imagem baixada com sucesso
- `image.download_failed` - Falha ao baixar imagem
- `image.process_error` - Erro no processamento
- `image.empty_download` - Download retornou vazio

---

## ⚠️ Limitações e Cuidados

1. **Timeout de download**: 30 segundos por imagem
2. **Tamanho máximo**: Limitado pelo `upload_max_filesize` do PHP
3. **Formatos suportados**: jpg, png, gif, webp, svg
4. **URLs protegidas**: Imagens que requerem autenticação podem não funcionar

---

## 📝 Testar Funcionamento

```sql
-- Ver imagens armazenadas
SELECT id, file_name, source_url, file_path, created_at
FROM email_assets
WHERE source_url IS NOT NULL
ORDER BY created_at DESC;

-- Ver imagens baixadas hoje
SELECT COUNT(*) as total_baixadas_hoje
FROM email_assets
WHERE DATE(created_at) = CURDATE()
AND source_url IS NOT NULL;
```

---

## 🔄 Migração Necessária

Execute a migração para adicionar a coluna `source_url`:

```bash
php artisan migrate --path=database/migrations/2026_02_27_000002_add_source_url_to_email_assets.php
```

Ou em desenvolvimento:
```bash
php artisan migrate
```

---

## ✅ Checklist de Funcionamento

- [x] Upload de imagem cria asset local
- [x] Imagens externas são baixadas automaticamente ao salvar
- [x] URLs no HTML são substituídas por URLs locais
- [x] Placeholder no editor não usa URL externa
- [x] API de processamento disponível
- [x] Logs de monitoramento configurados
- [x] Prevenção de duplicatas (source_url)
