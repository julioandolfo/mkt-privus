# Como Verificar se os Emails Serão Enviados

## Query Rápida
Execute o arquivo **`when_next_send.sql`** no phpMyAdmin para ver:

1. **Quantos jobs estão na fila**
2. **Quando o próximo job será executado**
3. **Se há jobs atrasados (problema)**
4. **Se o hourly_limit está bloqueando**

## Interpretando os Resultados

### ✅ Situação Normal
```
prontos_agora: 0
primeiro_job: 2026-02-27 15:30:00 (em 1 hora)
status: Pode enviar imediatamente
```
→ O sistema está respeitando o limite de 50/hr. Próximo envio no horário agendado.

---

### ⚠️ Jobs Atrasados (Problema)
```
prontos_agora: 5
status: Jobs PRONTOS - O worker deve processar em breve
```
→ Os jobs deveriam ter sido executados mas não foram. Possíveis causas:

1. **Queue Worker parado**
   - Verifique no Docker/Coolify se o container `worker` está rodando
   - Comando: `docker compose ps | grep worker`

2. **Worker não está ouvindo a fila 'email'**
   - Verifique o comando no docker-compose.yaml
   - Deve ter: `--queue=email,autopilot,default`

3. **Erro no worker**
   - Verifique logs: `docker logs <nome-do-worker>`

---

### ⏰ Aguardando Reset da Quota
```
sends_this_hour: 50
hourly_limit: 50
status_envio: Aguardando reset (XX min)
```
→ Já enviou 50 emails nesta hora. Aguardando reset automático.

---

### 🔧 Como Forçar o Envio Imediato (se necessário)

Se você quer enviar **agora** sem esperar:

```sql
-- Resetar o contador do provedor
UPDATE email_providers 
SET sends_this_hour = 0, 
    last_hour_reset_at = NOW()
WHERE id = (SELECT email_provider_id FROM email_campaigns WHERE id = 4);

-- Atualizar jobs para executar imediatamente
UPDATE jobs 
SET available_at = UNIX_TIMESTAMP()
WHERE queue = 'email';
```

Depois verifique se o worker está rodando.

---

## Comandos Úteis (se tiver acesso ao servidor)

```bash
# Ver logs do worker
docker logs mkt-privus-worker-1

# Reiniciar o worker
docker restart mkt-privus-worker-1

# Verificar se há jobs na fila
docker exec mkt-privus-worker-1 php artisan queue:status

# Processar um job manualmente (para teste)
docker exec mkt-privus-worker-1 php artisan queue:work --queue=email --once
```

## Onde Ver no Sistema

1. **Página da Campanha**: mostra "X de Y processados"
2. **/logs**: mostra eventos `batch.job.started` e `batch.job.completed`
3. **phpMyAdmin > jobs**: mostra a fila diretamente no banco

## Regras do Sistema

- **Com hourly_limit configurado**: Envia 50 emails, espera 1 hora, envia mais 50...
- **Sem hourly_limit**: Envia continuamente no ritmo configurado
- **Jobs atrasados**: São processados imediatamente quando o worker inicia
- **Falhas**: Após 3 tentativas, o job vai para `failed_jobs`
