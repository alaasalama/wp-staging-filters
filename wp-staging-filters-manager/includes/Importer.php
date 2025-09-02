<?php
namespace WPSFM;

use DOMDocument;
use DOMXPath;

class Importer {
    const DOCS_URL = 'https://wp-staging.com/docs/actions-and-filters/';

    public function import_docs(&$message = null, &$error = null) {
        $resp = wp_remote_get(self::DOCS_URL, [
            'timeout' => 30,
            'redirection' => 5,
            'headers' => [ 'User-Agent' => 'WPSFM/0.1 (+wordpress)' ],
        ]);
        if (is_wp_error($resp)) {
            $error = 'Fetch failed: ' . esc_html($resp->get_error_message());
            return false;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            $error = 'Unexpected HTTP status: ' . esc_html((string)$code);
            return false;
        }
        $html = wp_remote_retrieve_body($resp);
        $items = $this->parse_docs_html($html);
        if (empty($items)) {
            $error = 'No snippets found in docs. The page structure might have changed.';
            return false;
        }
        update_option(\WPSFM_Plugin::OPTION_PARSED, $items);
        $message = sprintf('Imported %d snippets from docs.', count($items));
        return true;
    }

    public function parse_docs_html($html) {
        $items = [];
        if (empty($html)) return $items;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        if (!$loaded) return $items;
        $xpath = new DOMXPath($dom);
        $body = $xpath->query('//body')->item(0);
        if (!$body) return $items;

        $current = [];
        $headingTags = ['h1','h2','h3','h4','h5','h6'];

        $hasAncestor = function($node, $tag) {
            $tag = strtolower($tag);
            for ($p = $node->parentNode; $p; $p = $p->parentNode) {
                if ($p->nodeType === XML_ELEMENT_NODE && strtolower($p->nodeName) === $tag) return true;
            }
            return false;
        };

        $extractCode = function($container) use ($xpath) {
            $node = $xpath->query('.//pre/code', $container)->item(0);
            if ($node) return trim($node->textContent);
            $node = $xpath->query('.//pre', $container)->item(0);
            if ($node) return trim($node->textContent);
            $node = $xpath->query('.//code', $container)->item(0);
            if ($node) return trim($node->textContent);
            return '';
        };

        $path_from_levels = function($levels) {
            ksort($levels);
            return implode(' > ', array_values($levels));
        };

        $traverse = function($node) use (&$traverse, &$current, &$items, $headingTags, $xpath, $hasAncestor, $extractCode, $path_from_levels) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($node->nodeName);
                if (in_array($tag, $headingTags, true)) {
                    $level = intval(substr($tag, 1));
                    $text = trim($node->textContent);
                    if ($text !== '') {
                        $current[$level] = $text;
                        foreach (array_keys($current) as $k) { if ($k > $level) unset($current[$k]); }
                    }
                } elseif ($tag === 'details') {
                    $summaryNode = null;
                    foreach ($node->childNodes as $ch) {
                        if ($ch->nodeType === XML_ELEMENT_NODE && strtolower($ch->nodeName) === 'summary') { $summaryNode = $ch; break; }
                    }
                    $summary = $summaryNode ? trim($summaryNode->textContent) : '';
                    $code = $extractCode($node);
                    if ($summary !== '' && $code !== '') {
                        $path = $path_from_levels($current);
                        $fullPath = $path ? ($path . ' > ' . $summary) : $summary;
                        $items[] = [
                            'id' => sanitize_title($fullPath),
                            'title' => $summary,
                            'path' => $fullPath,
                            'code' => $code,
                        ];
                    }
                } elseif ($tag === 'figure') {
                    $class = $node->getAttribute('class');
                    if (strpos(' ' . $class . ' ', ' wp-block-code ') !== false) {
                        $code = $extractCode($node);
                        if ($code !== '') {
                            $path = $path_from_levels($current);
                            $title = $path !== '' ? end($current) : 'Snippet';
                            $finalPath = $path ?: 'Code Snippet';
                            $items[] = [
                                'id' => sanitize_title($finalPath),
                                'title' => $title ?: 'Snippet',
                                'path' => $finalPath,
                                'code' => $code,
                            ];
                        }
                    }
                } elseif ($tag === 'pre' || $tag === 'code') {
                    if ($hasAncestor($node, 'details')) {
                        // already handled
                    } else {
                        $code = trim($node->textContent);
                        if ($code !== '') {
                            $path = $path_from_levels($current);
                            $title = $path !== '' ? end($current) : 'Snippet';
                            $finalPath = $path ?: 'Code Snippet';
                            $items[] = [
                                'id' => sanitize_title($finalPath),
                                'title' => $title ?: 'Snippet',
                                'path' => $finalPath,
                                'code' => $code,
                            ];
                        }
                    }
                }
            }
            if ($node->hasChildNodes()) {
                foreach ($node->childNodes as $child) {
                    $traverse($child);
                }
            }
        };

        $traverse($body);

        $byPath = [];
        foreach ($items as $it) {
            $key = $it['path'];
            if (!isset($byPath[$key]) || strlen($it['code']) > strlen($byPath[$key]['code'])) {
                $byPath[$key] = $it;
            }
        }
        $filtered = array_values($byPath);

        // Drop the top heading item explicitly if present
        $filtered = array_values(array_filter($filtered, function($it){
            $drop = 'Actions and Filters – Customize WP Staging';
            return !(trim($it['title']) === $drop || trim($it['path']) === $drop);
        }));

        return $filtered;
    }
}

