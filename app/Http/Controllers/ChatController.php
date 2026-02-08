<?php

namespace App\Http\Controllers;

use App\Enums\AIModel;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AI\AIGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly AIGateway $aiGateway,
    ) {}

    /**
     * Lista de conversas do usuario
     */
    public function index(Request $request): Response
    {
        $conversations = $request->user()
            ->chatConversations()
            ->with(['messages' => fn($q) => $q->latest()->limit(1)])
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($conv) => [
                'id' => $conv->id,
                'title' => $conv->title,
                'model' => $conv->model,
                'is_pinned' => $conv->is_pinned,
                'updated_at' => $conv->updated_at->diffForHumans(),
                'last_message' => $conv->messages->first()?->content
                    ? \Illuminate\Support\Str::limit($conv->messages->first()->content, 80)
                    : null,
                'brand_id' => $conv->brand_id,
            ]);

        $models = collect(AIModel::cases())->map(fn($m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'provider' => $m->provider()->label(),
        ]);

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'models' => $models,
        ]);
    }

    /**
     * Exibe uma conversa especifica com mensagens
     */
    public function show(Request $request, ChatConversation $conversation): Response
    {
        $this->authorizeConversation($request, $conversation);

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn($msg) => [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'model' => $msg->model,
                'input_tokens' => $msg->input_tokens,
                'output_tokens' => $msg->output_tokens,
                'created_at' => $msg->created_at->format('H:i'),
            ]);

        $conversations = $request->user()
            ->chatConversations()
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'model', 'is_pinned', 'updated_at']);

        $models = collect(AIModel::cases())->map(fn($m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'provider' => $m->provider()->label(),
        ]);

        return Inertia::render('Chat/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'model' => $conversation->model,
                'is_pinned' => $conversation->is_pinned,
                'brand_id' => $conversation->brand_id,
            ],
            'messages' => $messages,
            'conversations' => $conversations,
            'models' => $models,
        ]);
    }

    /**
     * Cria nova conversa
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'model' => 'required|string',
        ]);

        $conversation = ChatConversation::create([
            'user_id' => $request->user()->id,
            'brand_id' => $request->user()->current_brand_id,
            'title' => $validated['title'] ?: 'Nova conversa',
            'model' => $validated['model'],
        ]);

        return redirect()->route('chat.show', $conversation);
    }

    /**
     * Envia mensagem e recebe resposta da IA (sincrona)
     */
    public function sendMessage(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate([
            'content' => 'required|string|max:50000',
            'model' => 'nullable|string',
        ]);

        // Atualizar modelo se mudou
        $model = $validated['model'] ?? $conversation->model;
        if ($model !== $conversation->model) {
            $conversation->update(['model' => $model]);
        }

        // Salvar mensagem do usuario
        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['content'],
        ]);

        // Buscar historico da conversa para contexto
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn($msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
            ])
            ->toArray();

        // Chamar IA
        $aiModel = AIModel::from($model);
        $brand = $request->user()->currentBrand;

        try {
            $response = $this->aiGateway->chat(
                model: $aiModel,
                messages: $history,
                brand: $brand,
                user: $request->user(),
                feature: 'chat',
            );

            // Salvar resposta da IA
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $response['content'],
                'model' => $response['model'],
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
            ]);

            // Atualizar titulo da conversa se for a primeira mensagem
            if ($conversation->messages()->count() <= 2 && $conversation->title === 'Nova conversa') {
                $this->generateTitle($conversation, $validated['content']);
            }

            $conversation->touch();

            return response()->json([
                'message' => [
                    'id' => $assistantMessage->id,
                    'role' => 'assistant',
                    'content' => $assistantMessage->content,
                    'model' => $assistantMessage->model,
                    'input_tokens' => $assistantMessage->input_tokens,
                    'output_tokens' => $assistantMessage->output_tokens,
                    'created_at' => $assistantMessage->created_at->format('H:i'),
                ],
                'user_message' => [
                    'id' => $userMessage->id,
                    'role' => 'user',
                    'content' => $userMessage->content,
                    'created_at' => $userMessage->created_at->format('H:i'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao processar mensagem: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Streaming de resposta da IA via SSE
     */
    public function streamMessage(Request $request, ChatConversation $conversation): StreamedResponse
    {
        $this->authorizeConversation($request, $conversation);

        $content = $request->input('content');
        $model = $request->input('model', $conversation->model);

        // Salvar mensagem do usuario
        $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
        ]);

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn($msg) => ['role' => $msg->role, 'content' => $msg->content])
            ->toArray();

        $aiModel = AIModel::from($model);
        $brand = $request->user()->currentBrand;

        return response()->stream(function () use ($aiModel, $history, $brand, $request, $conversation) {
            $provider = $aiModel->provider();

            try {
                // Para streaming, chamamos a API com stream=true
                // Por ora, usamos resposta sincrona e simulamos streaming
                $response = $this->aiGateway->chat(
                    model: $aiModel,
                    messages: $history,
                    brand: $brand,
                    user: $request->user(),
                    feature: 'chat',
                );

                // Salvar resposta
                $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $response['content'],
                    'model' => $response['model'],
                    'input_tokens' => $response['input_tokens'],
                    'output_tokens' => $response['output_tokens'],
                ]);

                // Enviar conteudo em chunks para simular streaming
                $words = explode(' ', $response['content']);
                $chunks = array_chunk($words, 3);

                foreach ($chunks as $chunk) {
                    echo "data: " . json_encode(['content' => implode(' ', $chunk) . ' ']) . "\n\n";
                    ob_flush();
                    flush();
                    usleep(30000); // 30ms entre chunks
                }

                echo "data: " . json_encode(['done' => true, 'tokens' => [
                    'input' => $response['input_tokens'],
                    'output' => $response['output_tokens'],
                ]]) . "\n\n";
                ob_flush();
                flush();

            } catch (\Exception $e) {
                echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Renomeia uma conversa
     */
    public function update(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'is_pinned' => 'sometimes|boolean',
        ]);

        $conversation->update($validated);

        return redirect()->back();
    }

    /**
     * Remove uma conversa
     */
    public function destroy(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);

        $conversation->delete();

        return redirect()->route('chat.index');
    }

    // ===== PRIVATE METHODS =====

    private function authorizeConversation(Request $request, ChatConversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403, 'Acesso negado.');
        }
    }

    private function generateTitle(ChatConversation $conversation, string $firstMessage): void
    {
        try {
            $titleResponse = $this->aiGateway->chat(
                model: AIModel::GPT4oMini,
                messages: [
                    ['role' => 'system', 'content' => 'Gere um titulo curto (max 5 palavras) para esta conversa baseado na primeira mensagem. Responda APENAS com o titulo, sem aspas ou pontuação extra.'],
                    ['role' => 'user', 'content' => $firstMessage],
                ],
                feature: 'title_generation',
            );

            $title = trim($titleResponse['content']);
            if ($title && strlen($title) < 100) {
                $conversation->update(['title' => $title]);
            }
        } catch (\Exception $e) {
            // Silenciar erro de geracao de titulo
        }
    }
}
