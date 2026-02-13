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

    public function create()
    {
        return Inertia::render('Email/Templates/Editor', [
            'template' => null,
            'mode' => 'create',
        ]);
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
