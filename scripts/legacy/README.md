# Scripts legados de diagnóstico

Scripts SQL e PHP usados historicamente para depurar e corrigir problemas em
produção (campanhas travadas, conversão de SVG, eventos de webhook, etc.).

**Não execute em produção sem entender o que fazem.** As correções recorrentes
foram (ou devem ser) promovidas a comandos Artisan/jobs agendados — ver
`app/Console/Commands` (ex: `email:fix-stuck`) e `routes/console.php`.

Mantidos apenas como referência histórica.
