<?php

namespace App\Http\Controllers;

use App\Models\SmsTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SmsTemplateController extends Controller
{
    public function index(Request $request)
    {
        $brandId = session('current_brand_id');

        $templates = SmsTemplate::where('brand_id', $brandId)
            ->when($request->category, fn($q, $c) => $q->where('category', $c))
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'body' => $t->body,
                'category' => $t->category,
                'is_active' => $t->is_active,
                'segments' => $t->segments,
                'char_count' => mb_strlen($t->body),
                'created_at' => $t->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Sms/Templates/Index', [
            'templates' => $templates,
            'filters' => $request->only('category', 'search'),
            'starterTemplates' => self::getStarterTemplates(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Sms/Templates/Create', [
            'starterTemplates' => self::getStarterTemplates(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'body' => 'required|string|max:1600',
            'category' => 'nullable|in:marketing,transactional,welcome,reminder',
        ]);

        SmsTemplate::create([
            'brand_id' => session('current_brand_id'),
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'body' => $validated['body'],
            'category' => $validated['category'] ?? 'marketing',
        ]);

        return redirect()->route('sms.templates.index')
            ->with('success', 'Template SMS criado com sucesso!');
    }

    public function update(Request $request, SmsTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'body' => 'required|string|max:1600',
            'category' => 'nullable|in:marketing,transactional,welcome,reminder',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return redirect()->route('sms.templates.index')
            ->with('success', 'Template atualizado!');
    }

    public function destroy(SmsTemplate $template)
    {
        $template->delete();

        return redirect()->route('sms.templates.index')
            ->with('success', 'Template removido!');
    }

    /**
     * Templates prontos para SMS
     */
    public static function getStarterTemplates(): array
    {
        return [
            [
                'id' => 'promo_flash',
                'name' => 'Promoção Relâmpago',
                'category' => 'marketing',
                'body' => '🔥 {{first_name}}, OFERTA RELÂMPAGO! Até 50% OFF em produtos selecionados. Só hoje! Acesse: [link] {{sms_optout}}',
            ],
            [
                'id' => 'welcome_new',
                'name' => 'Boas-Vindas',
                'category' => 'welcome',
                'body' => 'Olá {{first_name}}! Bem-vindo(a) à nossa comunidade! 🎉 Use o cupom BEMVINDO10 e ganhe 10% na primeira compra. {{sms_optout}}',
            ],
            [
                'id' => 'reminder_cart',
                'name' => 'Lembrete de Carrinho',
                'category' => 'reminder',
                'body' => 'Oi {{first_name}}, você esqueceu itens no carrinho! 🛒 Finalize sua compra e ganhe frete grátis: [link] {{sms_optout}}',
            ],
            [
                'id' => 'seasonal_sale',
                'name' => 'Campanha Sazonal',
                'category' => 'marketing',
                'body' => '{{first_name}}, aproveite nossa mega promoção! 🎁 Descontos imperdíveis em todo o site. Corre que é por tempo limitado! [link] {{sms_optout}}',
            ],
            [
                'id' => 'reactivation',
                'name' => 'Reativação',
                'category' => 'marketing',
                'body' => 'Sentimos sua falta, {{first_name}}! 😊 Preparamos uma oferta especial pra você: cupom VOLTEI15 com 15% OFF. [link] {{sms_optout}}',
            ],
        ];
    }
}
