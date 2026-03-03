# Problema: Imagem SVG Não Carrega no Email

## 🔍 O Problema

A URL que você mostrou:
```
https://ci3.googleusercontent.com/meips/...#https://mkt.privus.com.br/storage/email-assets/1/2026/03/email_kDhdIkjC1YERSANH_1772484319.svg
```

Mostra que:
1. ✅ A imagem foi salva corretamente no servidor: `email-assets/1/2026/03/email_kDhdIkjC1YERSANH_1772484319.svg`
2. ❌ Mas está em formato **SVG** que muitos clientes de email **não suportam**
3. ⚠️ O Gmail faz proxy da imagem (por isso a URL está duplicada)

## 🚨 Por Que SVG Não Funciona?

**Clientes de email que NÃO suportam SVG:**
- ❌ Gmail (Android e Web)
- ❌ Outlook (todas as versões)
- ❌ Yahoo Mail
- ✅ Alguns clientes Apple Mail (suporte parcial)

**Formatos suportados universalmente:**
- ✅ JPEG/JPG
- ✅ PNG
- ✅ GIF (animado ou não)

## 🔧 Logs Adicionados

Agora o sistema loga todo o processo de renderização:

```
render.start → render.css_inlined → render.images_embedded → render.complete
```

E no `embedImagesAsBase64`:
```
render.embed_images (resumo: X processadas, Y puladas, Z falhas)
render.convert_storage (cada imagem do storage)
render.base64_created (sucesso na conversão)
render.storage_not_found (imagem não encontrada)
render.base64_failed (erro na conversão)
```

## 📝 Como Verificar

Execute no phpMyAdmin:

```sql
-- Ver processo de renderização de um email específico
SELECT created_at, action, message, context
FROM system_logs
WHERE action LIKE 'render.%'
AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
ORDER BY created_at DESC;

-- Ver imagens convertidas para base64
SELECT created_at, action, 
    JSON_UNQUOTE(JSON_EXTRACT(context, '$.path')) as imagem,
    JSON_UNQUOTE(JSON_EXTRACT(context, '$.mime_type')) as formato
FROM system_logs
WHERE action = 'render.base64_created'
ORDER BY created_at DESC
LIMIT 10;
```

## 💡 Soluções Possíveis

### Opção 1: Usar Apenas Imagens JPEG/PNG no Editor (RECOMENDADO)

Ao adicionar imagens no editor, use apenas:
- 📷 Fotos: **JPEG**
- 🎨 Gráficos/Logos: **PNG**
- 🎬 Animações: **GIF**

**Evite SVG** em emails de marketing.

### Opção 2: Converter SVG para PNG Antes de Fazer Upload

Use ferramentas online como:
- https://cloudconvert.com/svg-to-png
- https://convertio.co/svg-png/

Ou softwares:
- Adobe Illustrator
- Inkscape (gratuito)
- Figma (exporta como PNG)

### Opção 3: Implementar Conversão Automática (Futuro)

Converter SVG para PNG automaticamente no servidor requer:
- Instalar `imagick` ou `gd` com suporte a SVG
- Código adicional para conversão
- Mais recursos do servidor

## ✅ Verificação Rápida

Para confirmar que o sistema está funcionando:

1. Crie uma nova campanha
2. Adicione uma imagem **JPEG ou PNG** (não SVG)
3. Salve a campanha
4. Envie um email de teste
5. Verifique se a imagem aparece corretamente

## 📊 Resumo

| Formato | Suporte Email | Recomendação |
|---------|---------------|--------------|
| JPEG | ✅ Excelente | ✅ Use para fotos |
| PNG | ✅ Excelente | ✅ Use para logos/gráficos |
| GIF | ✅ Bom | ✅ Use para animações simples |
| SVG | ❌ Limitado | ❌ **Evite em emails** |

---

**A imagem foi salva corretamente no servidor, mas SVG não é suportado pelos clientes de email. Use JPEG/PNG para garantir compatibilidade!** 📧✅
