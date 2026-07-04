<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanitizador de HTML por allowlist para conteúdo rich-text (ex.: artigos de
 * blog) que será renderizado com v-html. Remove <script>/<style>/<iframe> e
 * afins, atributos de evento (on*) e URLs perigosas (javascript:, data: exceto
 * imagens), preservando a formatação legítima.
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'span', 'div', 'blockquote', 'pre', 'code',
        'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark', 'small',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    ];

    private const ALLOWED_ATTRS = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        '*' => ['class', 'id', 'style'],
    ];

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        // Envolve para preservar múltiplos nós de topo e forçar UTF-8.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__root__');
        if (!$root) {
            return '';
        }

        self::sanitizeNode($root);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        // Percorre uma cópia estática pois vamos mutar a árvore.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Remove a tag mas mantém os filhos (texto) quando fizer sentido;
                // para script/style/iframe descarta tudo.
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'base'], true)) {
                    $child->parentNode->removeChild($child);
                } else {
                    self::unwrap($child);
                }
                continue;
            }

            self::sanitizeAttributes($child, $tag);
            self::sanitizeNode($child);
        }
    }

    private static function sanitizeAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(
            self::ALLOWED_ATTRS['*'] ?? [],
            self::ALLOWED_ATTRS[$tag] ?? []
        );

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = $attr->nodeValue;

            // Nunca permite handlers de evento nem atributos fora da allowlist.
            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if (in_array($name, ['href', 'src'], true) && !self::isSafeUrlValue($value, $name === 'src')) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            // Neutraliza style com url()/expression() (vetores de exfiltração/JS).
            if ($name === 'style' && preg_match('/url\s*\(|expression\s*\(|javascript:/i', $value)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        // Links externos: força rel seguro.
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    private static function isSafeUrlValue(string $value, bool $isImage): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // Bloqueia esquemas perigosos independentemente de espaços/maiúsculas.
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if ($scheme === 'javascript' || $scheme === 'vbscript') {
            return false;
        }

        if ($scheme === 'data') {
            // data: só é aceito para imagens (data:image/...).
            return $isImage && preg_match('#^data:image/[a-z0-9.+-]+;#i', $value) === 1;
        }

        // Relativos, âncoras, mailto, tel, http(s) são aceitos.
        return true;
    }

    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
