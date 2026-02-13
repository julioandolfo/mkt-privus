<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelar Inscrição</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #111827; color: #e5e7eb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1f2937; border-radius: 16px; padding: 48px; max-width: 480px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        h1 { font-size: 24px; margin-bottom: 16px; }
        p { color: #9ca3af; line-height: 1.6; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        .success { color: #34d399; }
        .error { color: #f87171; }
    </style>
</head>
<body>
    <div class="card">
        @if($success)
            <div class="icon success">✓</div>
            <h1>Inscrição Cancelada</h1>
            <p>Você foi removido da lista de envios com sucesso. Não receberá mais emails desta campanha.</p>
        @else
            <div class="icon error">✕</div>
            <h1>Link Inválido</h1>
            <p>O link utilizado não é válido ou já expirou. Se precisar de ajuda, entre em contato conosco.</p>
        @endif
    </div>
</body>
</html>
