-- =====================================================
-- Query para verificar e ajudar a corrigir SVG em campanha
-- =====================================================

-- Verificar assets SVG da campanha
SELECT
    'ASSETS SVG ENCONTRADOS' as info,
    ea.id,
    ea.file_name,
    ea.file_path,
    ea.mime_type,
    ea.source_url
FROM email_assets ea
WHERE ea.file_path LIKE '%.svg'
   OR ea.mime_type = 'image/svg+xml';

-- Verificar se existem PNGs correspondentes
SELECT
    'POSSIVEIS PNG JA EXISTENTES' as info,
    REPLACE(ea.file_path, '.svg', '.png') as possible_png_path
FROM email_assets ea
WHERE ea.file_path LIKE '%.svg';

-- Atualizar asset para PNG se o arquivo existir (exemplo, ajuste os caminhos)
-- UPDATE email_assets
-- SET file_path = REPLACE(file_path, '.svg', '.png'),
--     mime_type = 'image/png'
-- WHERE file_path LIKE '%.svg'
--   AND file_path = 'email-assets/1/2026/03/email_kDhdIkjC1YERSANH_1772484319.svg';