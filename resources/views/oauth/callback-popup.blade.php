<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autenticação OAuth</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .spinner {
            width: 48px;
            height: 48px;
            border: 3px solid rgba(99, 102, 241, 0.2);
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1.5rem;
        }
        .success-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 1.5rem;
            color: #22c55e;
        }
        .error-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 1.5rem;
            color: #ef4444;
        }
        h2 { font-size: 1.25rem; margin-bottom: 0.5rem; }
        p { font-size: 0.875rem; color: #94a3b8; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        @if($status === 'success')
            <svg class="success-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <h2>Autenticação concluída!</h2>
            <p>{{ $message ?? 'Contas encontradas. Esta janela será fechada automaticamente.' }}</p>
        @elseif($status === 'error')
            <svg class="error-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <h2>Erro na autenticação</h2>
            <p>{{ $message ?? 'Ocorreu um erro. Tente novamente.' }}</p>
        @else
            <div class="spinner"></div>
            <h2>Processando...</h2>
            <p>Aguarde enquanto finalizamos a autenticação.</p>
        @endif
    </div>

    <script>
        (function() {
            var data = {
                type: 'oauth_callback',
                status: @json($status),
                message: @json($message ?? ''),
                platform: @json($platform ?? ''),
                accountsCount: @json($accountsCount ?? 0),
                discoveryToken: @json($discoveryToken ?? null),
            };

            console.log('[OAuth Popup] Enviando mensagem para janela pai:', data);

            // Notificar a janela pai
            if (window.opener && !window.opener.closed) {
                window.opener.postMessage(data, '*');
                console.log('[OAuth Popup] Mensagem enviada com sucesso');
            } else {
                console.warn('[OAuth Popup] window.opener nao disponivel');
            }

            // Fechar popup após breve delay
            setTimeout(function() {
                window.close();

                // Fallback: se não conseguir fechar (restrições do browser), mostra mensagem
                setTimeout(function() {
                    document.querySelector('p').textContent = 'Pode fechar esta janela e voltar ao MKT Privus.';
                }, 500);
            }, {{ $status === 'error' ? 3000 : 1500 }});
        })();
    </script>
</body>
</html>
