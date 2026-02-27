-- ============================================================
-- DESCOBRIR ESTRUTURA DO BANCO
-- ============================================================

-- 1. Listar todas as tabelas
SHOW TABLES;

-- 2. Listar tabelas relacionadas a email
SHOW TABLES LIKE '%email%';

-- 3. Listar tabelas relacionadas a listas
SHOW TABLES LIKE '%list%';

-- 4. Listar tabelas de relacionamento (junction tables)
SHOW TABLES LIKE '%contact%';

-- 5. Estrutura da tabela email_campaign_events
DESCRIBE email_campaign_events;

-- 6. Estrutura da tabela email_campaigns
DESCRIBE email_campaigns;

-- 7. Verificar se existe tabela de relacionamento campanha-lista
-- (tente adivinhar baseado em convenções comuns)
DESCRIBE email_campaign_email_list;  -- tentativa 1
-- DESCRIBE email_campaign_list;     -- tentativa 2  
-- DESCRIBE campaign_list;           -- tentativa 3
-- DESCRIBE email_list_contact;      -- tentativa 4

-- 8. Contar registros em cada tabela relevante
SELECT 'email_campaigns' as tabela, COUNT(*) as registros FROM email_campaigns
UNION ALL
SELECT 'email_campaign_events', COUNT(*) FROM email_campaign_events
UNION ALL
SELECT 'email_contacts', COUNT(*) FROM email_contacts
UNION ALL
SELECT 'email_lists', COUNT(*) FROM email_lists;

-- 9. Verificar se há foreign keys ou relacionamentos
SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'mkt_privus'
AND TABLE_NAME LIKE '%email%'
AND REFERENCED_TABLE_NAME IS NOT NULL;
