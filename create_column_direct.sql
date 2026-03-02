-- ============================================================
-- COMANDO DIRETO PARA CRIAR COLUNA
-- Use se tiver certeza que a coluna não existe
-- ============================================================

ALTER TABLE email_assets ADD COLUMN source_url TEXT NULL AFTER alt_text;

-- ============================================================
-- Se der erro de coluna já existente, ignore o erro
-- Se der sucesso, pronto!
-- ============================================================
