-- =====================================================
-- VERIFICAR E REVERTER SITUAÇÃO DO SVG
-- =====================================================

-- 1. Verificar o estado atual do asset
SELECT 
    'ESTADO ATUAL' as verificacao,
    ea.id,
    ea.file_name,
    ea.file_path,
    ea.mime_type,
    ea.source_url,
    ea.created_at,
    ea.updated_at
FROM email_assets ea
WHERE ea.file_path LIKE '%email_kDhdIkjC1YERSANH_1772484319%'
   OR ea.source_url LIKE '%email_kDhdIkjC1YERSANH_1772484319%';

-- 2. Verificar se existem tanto SVG quanto PNG no banco para o mesmo source_url
SELECT 
    'DUPLICATAS POSSIVEIS' as verificacao,
    source_url,
    COUNT(*) as total,
    GROUP_CONCAT(DISTINCT file_path SEPARATOR ' | ') as paths,
    GROUP_CONCAT(DISTINCT mime_type SEPARATOR ' | ') as mimes
FROM email_assets
WHERE source_url LIKE '%email_kDhdIkjC1YERSANH_1772484319%'
GROUP BY source_url;

-- 3. Verificar campanhas que usam este asset
SELECT 
    'CAMPANHAS AFETADAS' as verificacao,
    ec.id as campaign_id,
    ec.name as campaign_name,
    ec.status,
    CASE 
        WHEN ec.html_content LIKE '%email_kDhdIkjC1YERSANH_1772484319.svg%' THEN 'USA SVG'
        WHEN ec.html_content LIKE '%email_kDhdIkjC1YERSANH_1772484319.png%' THEN 'USA PNG'
        ELSE 'NAO USA'
    END as uso_atual
FROM email_campaigns ec
WHERE ec.html_content LIKE '%email_kDhdIkjC1YERSANH_1772484319%';