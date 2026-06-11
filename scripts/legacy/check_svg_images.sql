-- ============================================================
-- VERIFICAR IMAGENS SVG NO SISTEMA
-- ============================================================

-- 1. Ver imagens SVG no storage
SELECT 
    'IMAGENS SVG ENCONTRADAS' as secao,
    id,
    file_name,
    file_path,
    source_url,
    mime_type,
    created_at
FROM email_assets
WHERE mime_type = 'image/svg+xml'
   OR file_name LIKE '%.svg'
   OR file_path LIKE '%.svg'
ORDER BY created_at DESC;

-- 2. Ver logs de SVG detectados
SELECT 
    'LOGS DE SVG' as secao,
    created_at,
    action,
    message,
    JSON_UNQUOTE(JSON_EXTRACT(context, '$.src')) as svg_url
FROM system_logs
WHERE action LIKE '%svg%'
AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;

-- 3. Contar SVGs por período
SELECT 
    'CONTAGEM DE SVGS' as secao,
    DATE(created_at) as data,
    COUNT(*) as quantidade
FROM email_assets
WHERE mime_type = 'image/svg+xml'
   OR file_name LIKE '%.svg'
GROUP BY DATE(created_at)
ORDER BY data DESC;

-- ============================================================
-- RECOMENDAÇÃO: Converter SVGs para PNG/JPEG
-- ============================================================
-- Use ferramentas como:
-- - https://cloudconvert.com/svg-to-png
-- - Inkscape (exportar como PNG)
-- - Adobe Illustrator (Save for Web)
-- - Figma (exportar frame como PNG)
-- ============================================================
