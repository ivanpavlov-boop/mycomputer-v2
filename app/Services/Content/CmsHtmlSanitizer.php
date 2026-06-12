<?php

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

class CmsHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'div', 'em', 'h2', 'h3', 'h4', 'h5', 'h6',
        'i', 'img', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong', 'table', 'tbody', 'td',
        'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const BLOCKED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'meta', 'link'];

    private const GLOBAL_ATTRIBUTES = ['class', 'title'];

    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
    ];

    public function sanitize(?string $html): string
    {
        if (! is_string($html) || trim($html) === '') {
            return '';
        }

        if (! class_exists(DOMDocument::class)) {
            return strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li><a><img><h2><h3><h4><h5><h6><blockquote><code><pre>');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $root) {
            return '';
        }

        $this->sanitizeNode($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private function sanitizeNode(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            if (in_array($tag, self::BLOCKED_TAGS, true)) {
                $node->parentNode?->removeChild($node);

                return;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true) && $tag !== 'div') {
                $this->removeNodeKeepingChildren($node);

                return;
            }

            $this->sanitizeAttributes($node, $tag);
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->sanitizeNode($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim((string) $attribute->nodeValue);
            $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? []);

            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true) || $this->isUnsafeUrl($name, $value)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        if ($tag === 'a' && $element->hasAttribute('target') && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isUnsafeUrl(string $attribute, string $value): bool
    {
        if (! in_array($attribute, ['href', 'src'], true)) {
            return false;
        }

        $normalized = strtolower(preg_replace('/\s+/', '', html_entity_decode($value)));

        if (str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'vbscript:')) {
            return true;
        }

        if (str_starts_with($normalized, 'data:') && ! str_starts_with($normalized, 'data:image/')) {
            return true;
        }

        return false;
    }

    private function removeNodeKeepingChildren(DOMNode $node): void
    {
        $parent = $node->parentNode;

        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
