<?php

namespace System\Helpers;

use InvalidArgumentException;

/**
 * Class HtmlHelper
 *
 * Small helper utilities to generate safe HTML snippets.
 * - Uses SecurityHelper::escape() for escaping text and attributes.
 * - Lightweight: intended for templates and small helpers, not a full templating engine.
 */
class HtmlHelper
{
    /**
     * Escape text for HTML output.
     *
     * @param string $text
     * @return string
     */
    public static function escape(string $text): string
    {
        return SecurityHelper::escape($text);
    }

    /**
     * Build HTML attributes from an associative array.
     *
     * Example:
     *   attrs(['class' => 'btn', 'data-id' => 1]) => ' class="btn" data-id="1"'
     *
     * @param array $attrs
     * @return string
     */
    public static function attrs(array $attrs): string
    {
        if (empty($attrs)) {
            return '';
        }

        $parts = [];
        foreach ($attrs as $k => $v) {
            // boolean attributes (e.g., disabled)
            if (is_bool($v)) {
                if ($v) {
                    $parts[] = self::escape((string) $k);
                }
                continue;
            }

            if ($v === null) {
                continue;
            }

            $key = self::escape((string) $k);
            $val = self::escape((string) $v);
            $parts[] = sprintf('%s="%s"', $key, $val);
        }

        return $parts ? ' ' . implode(' ', $parts) : '';
    }

    /**
     * Create a script tag.
     *
     * @param string $src
     * @param array $attrs Additional attributes (e.g., ['defer' => true, 'type' => 'module'])
     * @return string
     */
    public static function script(string $src, array $attrs = []): string
    {
        $attrs['src'] = $src;
        // prefer defer by default if not explicitly set
        if (!array_key_exists('defer', $attrs)) {
            $attrs['defer'] = true;
        }
        return '<script' . self::attrs($attrs) . '></script>';
    }

    /**
     * Create a stylesheet link tag.
     *
     * @param string $href
     * @param array $attrs Additional attributes (e.g., ['media' => 'print'])
     * @return string
     */
    public static function style(string $href, array $attrs = []): string
    {
        $attrs = array_merge(['rel' => 'stylesheet', 'href' => $href], $attrs);
        return '<link' . self::attrs($attrs) . '>';
    }

    /**
     * Create an anchor tag.
     *
     * @param string $text
     * @param string $href
     * @param array $attrs
     * @return string
     */
    public static function a(string $text, string $href = '#', array $attrs = []): string
    {
        $attrs['href'] = $href;
        return '<a' . self::attrs($attrs) . '>' . self::escape($text) . '</a>';
    }

    /**
     * Create an img tag.
     *
     * @param string $src
     * @param string $alt
     * @param array $attrs
     * @return string
     */
    public static function img(string $src, string $alt = '', array $attrs = []): string
    {
        $attrs = array_merge(['src' => $src, 'alt' => $alt], $attrs);
        return '<img' . self::attrs($attrs) . '>';
    }

    /**
     * Create a generic tag.
     *
     * @param string $tag
     * @param string|null $content If null, tag is rendered self-closing when appropriate.
     * @param array $attrs
     * @return string
     */
    public static function tag(string $tag, ?string $content = null, array $attrs = []): string
    {
        if (trim($tag) === '') {
            throw new InvalidArgumentException('Tag name cannot be empty');
        }

        $tag = strtolower($tag);

        // void elements per HTML spec
        $void = [
            'area','base','br','col','embed','hr','img','input',
            'link','meta','param','source','track','wbr'
        ];

        if (in_array($tag, $void, true)) {
            return '<' . $tag . self::attrs($attrs) . '>';
        }

        return '<' . $tag . self::attrs($attrs) . '>' . ($content === null ? '' : self::escape($content)) . '</' . $tag . '>';
    }

    /**
     * Combine classes intelligently.
     *
     * Accepts array or string. Example:
     *  classList(['btn', 'btn-primary', null, 'active']) => 'btn btn-primary active'
     *
     * @param string|array|null $classes
     * @return string
     */
    public static function classList(string|array|null $classes): string
    {
        if ($classes === null || $classes === '') {
            return '';
        }

        if (is_string($classes)) {
            return trim($classes);
        }

        $clean = [];
        foreach ($classes as $c) {
            if ($c === null || $c === '') {
                continue;
            }
            if (is_array($c)) {
                $clean[] = self::classList($c);
            } else {
                $clean[] = trim((string) $c);
            }
        }

        return trim(implode(' ', array_filter($clean)));
    }

    /**
     * Convenience to produce a script tag that inlines small JS securely.
     *
     * Note: caller must ensure JS is safe. This escapes closing script tag sequences.
     *
     * @param string $js
     * @param array $attrs
     * @return string
     */
    public static function inlineScript(string $js, array $attrs = []): string
    {
        // Prevent </script> injection
        $safe = str_replace('</script>', '<\/script>', $js);
        return '<script' . self::attrs($attrs) . '>' . $safe . '</script>';
    }
}
