-- ============================================================
-- ADICIONAR COLUNA source_url - VERSÃO SIMPLES
-- Execute linha por linha no phpMyAdmin
-- ============================================================

-- PASSO 1: Verificar se coluna existe
-- Execute este SELECT primeiro:
SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'email_assets' 
AND COLUMN_NAME = 'source_url';

-- SE O SELECT ACIMA RETORNAR 1 LINHA:
-- → A coluna já existe, não execute o ALTER TABLE
-- → Pare aqui

-- SE O SELECT ACIMA NÃO RETORNAR NADA (vazio):
-- → Execute o comando abaixo (descomente a linha):
-- ALTER TABLE email_assets ADD COLUMN source_url TEXT NULL AFTER alt_text;

-- PASSO 2: Verificar se funcionou
-- Execute este SELECT para confirmar:
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'email_assets' 
AND COLUMN_NAME = 'source_url';

-- Se retornar a linha com source_url, deu certo!
-- Se não retornar nada, houve algum erro

-- ============================================================
-- OPCIONAL: Criar índice para melhor performance
-- (execute apenas se a coluna foi criada com sucesso)
-- ============================================================
-- CREATE INDEX idx_source_url ON email_assets(source_url(255));
