<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmailTemplateController extends Controller
{
    public function index()
    {
        $brandId = session('current_brand_id');

        $templates = EmailTemplate::forBrand($brandId)
            ->latest()
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'subject' => $t->subject,
                'category' => $t->category,
                'is_active' => $t->is_active,
                'thumbnail_path' => $t->thumbnail_path,
                'brand_id' => $t->brand_id,
                'created_at' => $t->created_at->format('d/m/Y'),
                'updated_at' => $t->updated_at->format('d/m/Y'),
            ]);

        return Inertia::render('Email/Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(Request $request)
    {
        $starterId = $request->query('starter');
        $template = null;

        if ($starterId) {
            $starters = self::getStarterTemplates();
            $starter = collect($starters)->firstWhere('id', $starterId);
            if ($starter) {
                $template = [
                    'id' => null,
                    'name' => $starter['name'],
                    'description' => $starter['description'],
                    'subject' => $starter['subject'] ?? '',
                    'html_content' => $starter['html_content'],
                    'mjml_content' => null,
                    'json_content' => null,
                    'category' => $starter['category'],
                    'is_active' => true,
                ];
            }
        }

        return Inertia::render('Email/Templates/Editor', [
            'template' => $template,
            'mode' => 'create',
            'starterTemplates' => self::getStarterTemplates(),
        ]);
    }

    /**
     * Retorna templates prontos (starter) para seleção
     */
    public static function getStarterTemplates(): array
    {
        return [
            [
                'id' => 'newsletter-clean',
                'name' => 'Newsletter Clean',
                'description' => 'Newsletter minimalista com cabeçalho, artigos e rodapé',
                'category' => 'newsletter',
                'subject' => 'Newsletter da Semana',
                'preview_color' => '#6366f1',
                'html_content' => '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:0;background:#f4f4f5;font-family:Inter,Arial,sans-serif;}a{color:#6366f1;}</style></head><body style="background:#f4f4f5;padding:20px 0;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
<!-- Header -->
<tr><td style="background:#1e1b4b;padding:30px 40px;text-align:center;">
<img src="https://placehold.co/140x45/6366f1/ffffff?text=LOGO" alt="Logo" style="height:40px;" />
</td></tr>
<!-- Hero -->
<tr><td style="padding:40px 40px 20px;">
<h1 style="color:#111827;font-size:26px;font-weight:700;margin:0 0 12px;line-height:1.3;">Novidades desta semana</h1>
<p style="color:#6b7280;font-size:16px;line-height:1.6;margin:0;">Confira as últimas novidades e conteúdos que preparamos para você.</p>
</td></tr>
<!-- Separador -->
<tr><td style="padding:0 40px;"><hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" /></td></tr>
<!-- Artigo 1 -->
<tr><td style="padding:10px 40px 20px;">
<img src="https://placehold.co/520x260/e0e7ff/4338ca?text=Artigo+1" style="width:100%;border-radius:8px;display:block;margin-bottom:16px;" />
<h2 style="color:#111827;font-size:20px;font-weight:600;margin:0 0 8px;">Título do Primeiro Artigo</h2>
<p style="color:#6b7280;font-size:14px;line-height:1.5;margin:0 0 16px;">Uma breve descrição do conteúdo deste artigo. Edite para personalizar com o seu texto.</p>
<a href="#" style="display:inline-block;padding:10px 24px;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;border-radius:6px;">Leia Mais</a>
</td></tr>
<!-- Artigo 2 -->
<tr><td style="padding:10px 40px 20px;">
<table width="100%" cellpadding="0" cellspacing="0"><tr>
<td width="160" valign="top"><img src="https://placehold.co/160x120/fef3c7/92400e?text=Artigo+2" style="width:100%;border-radius:8px;display:block;" /></td>
<td width="20"></td>
<td valign="top">
<h3 style="color:#111827;font-size:16px;font-weight:600;margin:0 0 6px;">Segundo Artigo</h3>
<p style="color:#6b7280;font-size:13px;line-height:1.4;margin:0;">Descrição breve do segundo artigo.</p>
</td>
</tr></table>
</td></tr>
<!-- Artigo 3 -->
<tr><td style="padding:10px 40px 20px;">
<table width="100%" cellpadding="0" cellspacing="0"><tr>
<td width="160" valign="top"><img src="https://placehold.co/160x120/dcfce7/166534?text=Artigo+3" style="width:100%;border-radius:8px;display:block;" /></td>
<td width="20"></td>
<td valign="top">
<h3 style="color:#111827;font-size:16px;font-weight:600;margin:0 0 6px;">Terceiro Artigo</h3>
<p style="color:#6b7280;font-size:13px;line-height:1.4;margin:0;">Descrição breve do terceiro artigo.</p>
</td>
</tr></table>
</td></tr>
<!-- Footer -->
<tr><td style="background:#f9fafb;padding:30px 40px;text-align:center;border-top:1px solid #e5e7eb;">
<p style="color:#9ca3af;font-size:12px;margin:0 0 8px;">© 2026 Sua Empresa. Todos os direitos reservados.</p>
<p style="color:#9ca3af;font-size:12px;margin:0;"><a href="#" style="color:#6366f1;text-decoration:underline;">Cancelar inscrição</a></p>
</td></tr>
</table>
</td></tr></table>
</body></html>',
            ],
            [
                'id' => 'promo-bold',
                'name' => 'Promoção Bold',
                'description' => 'Template de promoção com destaque visual forte e CTA',
                'category' => 'promotional',
                'subject' => 'Oferta Imperdível - Até 50% OFF',
                'preview_color' => '#dc2626',
                'html_content' => '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:20px 0;background:#111827;font-family:Inter,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;">
<!-- Header -->
<tr><td style="padding:20px 40px;text-align:center;">
<img src="https://placehold.co/120x40/ffffff/111827?text=LOGO" alt="Logo" style="height:36px;" />
</td></tr>
<!-- Hero Promo -->
<tr><td style="background:linear-gradient(135deg,#dc2626,#991b1b);padding:50px 40px;text-align:center;border-radius:16px 16px 0 0;">
<p style="color:#fca5a5;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px;">Oferta por tempo limitado</p>
<h1 style="color:#ffffff;font-size:42px;font-weight:800;margin:0 0 8px;line-height:1.1;">ATÉ 50% OFF</h1>
<p style="color:#fecaca;font-size:18px;margin:0 0 30px;">Em todos os produtos da loja</p>
<a href="#" style="display:inline-block;padding:16px 40px;background:#ffffff;color:#dc2626;font-size:18px;font-weight:700;text-decoration:none;border-radius:50px;box-shadow:0 4px 15px rgba(0,0,0,0.2);">APROVEITAR AGORA</a>
</td></tr>
<!-- Produtos -->
<tr><td style="background:#1f2937;padding:30px 20px;">
<table width="100%" cellpadding="0" cellspacing="0"><tr>
<td width="33%" style="padding:10px;" valign="top">
<table width="100%" style="background:#111827;border-radius:12px;overflow:hidden;"><tr><td>
<img src="https://placehold.co/180x180/374151/9ca3af?text=Prod+1" style="width:100%;display:block;" />
</td></tr><tr><td style="padding:12px;text-align:center;">
<p style="color:#e5e7eb;font-size:13px;margin:0 0 4px;font-weight:600;">Produto 1</p>
<p style="color:#f87171;font-size:16px;font-weight:700;margin:0;">R$ 79,90</p>
</td></tr></table>
</td>
<td width="33%" style="padding:10px;" valign="top">
<table width="100%" style="background:#111827;border-radius:12px;overflow:hidden;"><tr><td>
<img src="https://placehold.co/180x180/374151/9ca3af?text=Prod+2" style="width:100%;display:block;" />
</td></tr><tr><td style="padding:12px;text-align:center;">
<p style="color:#e5e7eb;font-size:13px;margin:0 0 4px;font-weight:600;">Produto 2</p>
<p style="color:#f87171;font-size:16px;font-weight:700;margin:0;">R$ 129,90</p>
</td></tr></table>
</td>
<td width="33%" style="padding:10px;" valign="top">
<table width="100%" style="background:#111827;border-radius:12px;overflow:hidden;"><tr><td>
<img src="https://placehold.co/180x180/374151/9ca3af?text=Prod+3" style="width:100%;display:block;" />
</td></tr><tr><td style="padding:12px;text-align:center;">
<p style="color:#e5e7eb;font-size:13px;margin:0 0 4px;font-weight:600;">Produto 3</p>
<p style="color:#f87171;font-size:16px;font-weight:700;margin:0;">R$ 199,90</p>
</td></tr></table>
</td>
</tr></table>
</td></tr>
<!-- Footer -->
<tr><td style="background:#1f2937;padding:25px 40px;text-align:center;border-radius:0 0 16px 16px;border-top:1px solid #374151;">
<p style="color:#6b7280;font-size:11px;margin:0 0 6px;">© 2026 Sua Empresa</p>
<p style="color:#6b7280;font-size:11px;margin:0;"><a href="#" style="color:#6366f1;text-decoration:underline;">Cancelar inscrição</a></p>
</td></tr>
</table>
</td></tr></table>
</body></html>',
            ],
            [
                'id' => 'welcome-minimal',
                'name' => 'Boas-Vindas',
                'description' => 'Email de boas-vindas limpo e acolhedor',
                'category' => 'welcome',
                'subject' => 'Bem-vindo(a)! Estamos felizes em ter você aqui',
                'preview_color' => '#059669',
                'html_content' => '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:20px 0;background:#f0fdf4;font-family:Inter,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
<!-- Header -->
<tr><td style="padding:30px 40px;text-align:center;background:#059669;">
<img src="https://placehold.co/130x42/ffffff/059669?text=LOGO" alt="Logo" style="height:38px;" />
</td></tr>
<!-- Emoji + Titulo -->
<tr><td style="padding:40px 40px 20px;text-align:center;">
<p style="font-size:48px;margin:0 0 16px;">👋</p>
<h1 style="color:#111827;font-size:28px;font-weight:700;margin:0 0 12px;">Bem-vindo(a), {{first_name}}!</h1>
<p style="color:#6b7280;font-size:16px;line-height:1.6;margin:0;">Estamos muito felizes em ter você conosco. Preparamos tudo para a melhor experiência.</p>
</td></tr>
<!-- Steps -->
<tr><td style="padding:10px 40px 30px;">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td style="padding:12px 0;border-bottom:1px solid #f3f4f6;">
<table width="100%"><tr>
<td width="40" valign="top"><div style="width:32px;height:32px;background:#ecfdf5;border-radius:50%;text-align:center;line-height:32px;color:#059669;font-weight:700;font-size:14px;">1</div></td>
<td style="padding-left:12px;"><p style="color:#111827;font-size:14px;font-weight:600;margin:0;">Complete seu perfil</p><p style="color:#6b7280;font-size:13px;margin:4px 0 0;">Adicione suas informações para personalizar a experiência.</p></td>
</tr></table>
</td></tr>
<tr><td style="padding:12px 0;border-bottom:1px solid #f3f4f6;">
<table width="100%"><tr>
<td width="40" valign="top"><div style="width:32px;height:32px;background:#ecfdf5;border-radius:50%;text-align:center;line-height:32px;color:#059669;font-weight:700;font-size:14px;">2</div></td>
<td style="padding-left:12px;"><p style="color:#111827;font-size:14px;font-weight:600;margin:0;">Explore os recursos</p><p style="color:#6b7280;font-size:13px;margin:4px 0 0;">Conheça todas as funcionalidades disponíveis.</p></td>
</tr></table>
</td></tr>
<tr><td style="padding:12px 0;">
<table width="100%"><tr>
<td width="40" valign="top"><div style="width:32px;height:32px;background:#ecfdf5;border-radius:50%;text-align:center;line-height:32px;color:#059669;font-weight:700;font-size:14px;">3</div></td>
<td style="padding-left:12px;"><p style="color:#111827;font-size:14px;font-weight:600;margin:0;">Comece a usar</p><p style="color:#6b7280;font-size:13px;margin:4px 0 0;">Tudo pronto! Aproveite ao máximo.</p></td>
</tr></table>
</td></tr>
</table>
</td></tr>
<!-- CTA -->
<tr><td style="padding:0 40px 40px;text-align:center;">
<a href="#" style="display:inline-block;padding:14px 36px;background:#059669;color:#ffffff;font-size:16px;font-weight:600;text-decoration:none;border-radius:8px;">Começar Agora</a>
</td></tr>
<!-- Footer -->
<tr><td style="background:#f9fafb;padding:25px 40px;text-align:center;border-top:1px solid #e5e7eb;">
<p style="color:#9ca3af;font-size:12px;margin:0 0 6px;">© 2026 Sua Empresa. Todos os direitos reservados.</p>
<p style="color:#9ca3af;font-size:12px;margin:0;"><a href="#" style="color:#059669;text-decoration:underline;">Cancelar inscrição</a></p>
</td></tr>
</table>
</td></tr></table>
</body></html>',
            ],
            [
                'id' => 'product-launch',
                'name' => 'Lançamento de Produto',
                'description' => 'Anúncio de novo produto com imagem hero e detalhes',
                'category' => 'marketing',
                'subject' => 'Novidade: Conheça nosso novo produto!',
                'preview_color' => '#7c3aed',
                'html_content' => '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:20px 0;background:#faf5ff;font-family:Inter,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
<!-- Header -->
<tr><td style="padding:20px 40px;text-align:center;">
<img src="https://placehold.co/130x42/7c3aed/ffffff?text=LOGO" alt="Logo" style="height:38px;" />
</td></tr>
<!-- Hero Image -->
<tr><td>
<img src="https://placehold.co/600x400/ddd6fe/5b21b6?text=Novo+Produto" style="width:100%;display:block;" />
</td></tr>
<!-- Content -->
<tr><td style="padding:35px 40px 20px;">
<p style="color:#7c3aed;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;margin:0 0 8px;">Lançamento</p>
<h1 style="color:#111827;font-size:28px;font-weight:700;margin:0 0 12px;line-height:1.2;">Nome do Novo Produto</h1>
<p style="color:#6b7280;font-size:15px;line-height:1.6;margin:0 0 20px;">Uma descrição envolvente do produto, destacando os principais benefícios e diferenciais. Edite este texto para contar a história do seu produto.</p>
</td></tr>
<!-- Features -->
<tr><td style="padding:0 40px 20px;">
<table width="100%" cellpadding="0" cellspacing="0">
<tr>
<td width="50%" style="padding:10px;" valign="top">
<div style="background:#faf5ff;border-radius:10px;padding:16px;text-align:center;">
<p style="font-size:24px;margin:0 0 6px;">⚡</p>
<p style="color:#111827;font-size:14px;font-weight:600;margin:0 0 4px;">Rápido</p>
<p style="color:#6b7280;font-size:12px;margin:0;">Performance incrível</p>
</div>
</td>
<td width="50%" style="padding:10px;" valign="top">
<div style="background:#faf5ff;border-radius:10px;padding:16px;text-align:center;">
<p style="font-size:24px;margin:0 0 6px;">🎨</p>
<p style="color:#111827;font-size:14px;font-weight:600;margin:0 0 4px;">Design</p>
<p style="color:#6b7280;font-size:12px;margin:0;">Visual moderno</p>
</div>
</td>
</tr>
</table>
</td></tr>
<!-- Price + CTA -->
<tr><td style="padding:10px 40px 35px;text-align:center;">
<p style="color:#7c3aed;font-size:32px;font-weight:800;margin:0 0 20px;">R$ 299,90</p>
<a href="#" style="display:inline-block;padding:16px 40px;background:#7c3aed;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;border-radius:50px;">Comprar Agora</a>
</td></tr>
<!-- Footer -->
<tr><td style="background:#f9fafb;padding:25px 40px;text-align:center;border-top:1px solid #e5e7eb;">
<p style="color:#9ca3af;font-size:12px;margin:0 0 6px;">© 2026 Sua Empresa</p>
<p style="color:#9ca3af;font-size:12px;margin:0;"><a href="#" style="color:#7c3aed;text-decoration:underline;">Cancelar inscrição</a></p>
</td></tr>
</table>
</td></tr></table>
</body></html>',
            ],
            [
                'id' => 'simple-text',
                'name' => 'Texto Simples',
                'description' => 'Email minimalista focado em texto, ideal para comunicações pessoais',
                'category' => 'marketing',
                'subject' => 'Uma mensagem especial para você',
                'preview_color' => '#374151',
                'html_content' => '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:20px 0;background:#f9fafb;font-family:Inter,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
<tr><td style="padding:40px;">
<p style="color:#6b7280;font-size:13px;margin:0 0 20px;">Sua Empresa</p>
<h1 style="color:#111827;font-size:24px;font-weight:600;margin:0 0 20px;line-height:1.3;">Olá, {{first_name}}!</h1>
<p style="color:#374151;font-size:15px;line-height:1.7;margin:0 0 16px;">Espero que esteja tudo bem com você. Estou escrevendo para compartilhar algo importante que acredito que vai te interessar.</p>
<p style="color:#374151;font-size:15px;line-height:1.7;margin:0 0 16px;">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Edite este parágrafo com seu conteúdo.</p>
<p style="color:#374151;font-size:15px;line-height:1.7;margin:0 0 24px;">Se tiver qualquer dúvida, basta responder este email que ficaremos felizes em ajudar.</p>
<a href="#" style="display:inline-block;padding:12px 28px;background:#111827;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;border-radius:6px;">Saiba Mais</a>
<p style="color:#374151;font-size:15px;line-height:1.7;margin:24px 0 0;">Abraços,<br /><strong>Equipe Sua Empresa</strong></p>
</td></tr>
<tr><td style="padding:20px 40px;text-align:center;border-top:1px solid #f3f4f6;">
<p style="color:#9ca3af;font-size:11px;margin:0;"><a href="#" style="color:#6b7280;text-decoration:underline;">Cancelar inscrição</a></p>
</td></tr>
</table>
</td></tr></table>
</body></html>',
            ],
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'subject' => 'nullable|string|max:255',
            'html_content' => 'nullable|string',
            'mjml_content' => 'nullable|string',
            'json_content' => 'nullable|array',
            'category' => 'nullable|in:marketing,transactional,newsletter,promotional,welcome',
        ]);

        $template = EmailTemplate::create([
            'brand_id' => session('current_brand_id'),
            'user_id' => Auth::id(),
            ...$validated,
        ]);

        return redirect()->route('email.templates.edit', $template)
            ->with('success', 'Template criado com sucesso!');
    }

    public function edit(EmailTemplate $template)
    {
        return Inertia::render('Email/Templates/Editor', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'subject' => $template->subject,
                'html_content' => $template->html_content,
                'mjml_content' => $template->mjml_content,
                'json_content' => $template->json_content,
                'category' => $template->category,
                'is_active' => $template->is_active,
            ],
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, EmailTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'subject' => 'nullable|string|max:255',
            'html_content' => 'nullable|string',
            'mjml_content' => 'nullable|string',
            'json_content' => 'nullable|array',
            'category' => 'nullable|in:marketing,transactional,newsletter,promotional,welcome',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Template salvo.']);
        }

        return redirect()->route('email.templates.index')
            ->with('success', 'Template atualizado!');
    }

    public function destroy(EmailTemplate $template)
    {
        $template->delete();
        return redirect()->route('email.templates.index')
            ->with('success', 'Template removido.');
    }

    /**
     * Duplicar template
     */
    public function duplicate(EmailTemplate $template)
    {
        $new = $template->replicate();
        $new->name = $template->name . ' (Cópia)';
        $new->save();

        return redirect()->route('email.templates.edit', $new)
            ->with('success', 'Template duplicado!');
    }
}
