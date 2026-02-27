@echo off
chcp 65001 >nul
echo ==========================================
echo  CORRECAO DE EVENTOS DUPLICADOS - EMAIL
echo ==========================================
echo.

set DB_NAME=mkt_privus
set DB_USER=root
set DB_PASS=root

echo [1/5] Verificando duplicados...
mysql -u%DB_USER% -p%DB_PASS% -e "SELECT CONCAT('Campanha #', email_campaign_id, ': ', COUNT(*) - COUNT(DISTINCT email_contact_id), ' duplicados de tipo ', event_type) as resumo FROM email_campaign_events WHERE event_type IN ('sent', 'failed') GROUP BY email_campaign_id, event_type HAVING COUNT(*) > COUNT(DISTINCT email_contact_id)" %DB_NAME%

echo.
echo [2/5] Removendo 'failed' duplicados (mantendo mais recente)...
mysql -u%DB_USER% -p%DB_PASS% -e "
CREATE TEMPORARY TABLE keep_failed AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='failed' 
GROUP BY email_campaign_id, email_contact_id;
DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_failed ks ON ece.id = ks.keep_id 
WHERE ece.event_type='failed' AND ks.keep_id IS NULL;
DROP TEMPORARY TABLE keep_failed;
SELECT CONCAT('Concluido. Linhas afetadas: ', ROW_COUNT()) as resultado;
" %DB_NAME%

echo.
echo [3/5] Removendo 'sent' duplicados (mantendo mais recente)...
mysql -u%DB_USER% -p%DB_PASS% -e "
CREATE TEMPORARY TABLE keep_sent AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='sent' 
GROUP BY email_campaign_id, email_contact_id;
DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_sent ks ON ece.id = ks.keep_id 
WHERE ece.event_type='sent' AND ks.keep_id IS NULL;
DROP TEMPORARY TABLE keep_sent;
SELECT CONCAT('Concluido. Linhas afetadas: ', ROW_COUNT()) as resultado;
" %DB_NAME%

echo.
echo [4/5] Removendo 'failed' que ja tem 'sent'...
mysql -u%DB_USER% -p%DB_PASS% -e "
DELETE failed FROM email_campaign_events failed
INNER JOIN email_campaign_events sent ON failed.email_campaign_id = sent.email_campaign_id 
AND failed.email_contact_id = sent.email_contact_id AND sent.event_type = 'sent'
WHERE failed.event_type = 'failed';
SELECT CONCAT('Concluido. Linhas afetadas: ', ROW_COUNT()) as resultado;
" %DB_NAME%

echo.
echo [5/5] Contagem final...
mysql -u%DB_USER% -p%DB_PASS% -e "
SELECT 
    ece.event_type,
    COUNT(*) as total,
    COUNT(DISTINCT ece.email_contact_id) as distintos
FROM email_campaign_events ece
WHERE ece.email_campaign_id = 4
GROUP BY ece.event_type;
" %DB_NAME%

echo.
echo ==========================================
echo  CORRECAO CONCLUIDA
echo ==========================================
echo.
pause
