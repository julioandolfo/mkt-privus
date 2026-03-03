-- =====================================================
-- Verificar se conversao SVG esta funcionando
-- =====================================================

-- Verificar se a extensao PHP Imagick esta carregada
SELECT
    'PHP IMAGICK EXTENSION' as check_type,
    CASE
        WHEN @imagick_loaded IS NULL THEN 'Verificar no Laravel (php artisan tinker)'
        ELSE @imagick_loaded
    END as status;

-- Consulta para verificar assets SVG no banco
SELECT
    'SVG ASSETS ENCONTRADOS' as check_type,
    COUNT(*) as total_svg,
    GROUP_CONCAT(DISTINCT file_name SEPARATOR ', ') as arquivos
FROM email_assets
WHERE mime_type = 'image/svg+xml'
   OR file_path LIKE '%.svg'
   OR file_name LIKE '%.svg%';

-- Consulta para verificar campanhas com SVG
SELECT
    'CAMPANHAS COM SVG' as check_type,
    COUNT(*) as total,
    GROUP_CONCAT(DISTINCT id ORDER BY id SEPARATOR ', ') as campaign_ids
FROM email_campaigns
WHERE html_content LIKE '%.svg%'
   OR html_content LIKE '%svg%';