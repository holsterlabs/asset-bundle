<?php

declare(strict_types=1);

namespace Hl\AssetBundle;

/** Scans CSS tokens, leaving comments and strings untouched. No regex over entire declarations. */
final class CssRewriter
{
    public function rewrite(string $css, callable $url, callable $import): string
    {
        if (str_starts_with($css, "\xef\xbb\xbf")) {
            $css = substr($css, 3);
        }
        $output = '';
        $length = strlen($css);
        for ($i = 0; $i < $length;) {
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    throw new \InvalidArgumentException('Unterminated CSS comment.');
                }
                $output .= substr($css, $i, $end + 2 - $i);
                $i = $end + 2;
            } elseif ($css[$i] === '"' || $css[$i] === "'") {
                $start = $i;
                $this->quoted($css, $i);
                $output .= substr($css, $start, $i - $start);
            } elseif ($css[$i] === '@' && preg_match('/\G@charset\s+([\'"])[^\'"]*\1\s*;/i', $css, $match, 0, $i)) {
                $i += strlen($match[0]);
            } elseif ($css[$i] === '@' && preg_match('/\G@import\s+/i', $css, $match, 0, $i)) {
                $i += strlen($match[0]);
                $this->whitespace($css, $i);
                if (($css[$i] ?? '') === '"' || ($css[$i] ?? '') === "'") {
                    $value = $this->quoted($css, $i);
                } elseif (preg_match('/\Gurl\s*\(/i', $css, $match, 0, $i)) {
                    $i += strlen($match[0]);
                    $value = $this->urlValue($css, $i);
                } else {
                    throw new \InvalidArgumentException('Invalid CSS @import.');
                }
                $start = $i;
                $depth = 0;
                while ($i < $length) {
                    if ($css[$i] === '"' || $css[$i] === "'") {
                        $this->quoted($css, $i);
                        continue;
                    }
                    if ($css[$i] === ';' && $depth === 0) {
                        break;
                    }
                    $depth += $css[$i] === '(' ? 1 : ($css[$i] === ')' ? -1 : 0);
                    ++$i;
                }
                if ($i === $length) {
                    throw new \InvalidArgumentException('Unterminated CSS @import.');
                }
                $output .= $import($value, trim(substr($css, $start, $i - $start)));
                ++$i;
            } elseif (($i === 0 || !preg_match('/[a-z0-9_-]/i', $css[$i - 1])) && preg_match('/\Gurl\s*\(/i', $css, $match, 0, $i)) {
                $i += strlen($match[0]);
                $value = $url($this->urlValue($css, $i));
                $output .= 'url("'.str_replace(['\\', '"', "\n", "\r", "\f"], ['\\\\', '\\"', '\\a ', '\\d ', '\\c '], $value).'")';
            } else {
                $output .= $css[$i++];
            }
        }

        return $output;
    }

    private function urlValue(string $css, int &$i): string
    {
        $this->whitespace($css, $i);
        if (($css[$i] ?? '') === '"' || ($css[$i] ?? '') === "'") {
            $value = $this->quoted($css, $i);
            $this->whitespace($css, $i);
        } else {
            $start = $i;
            while ($i < strlen($css) && $css[$i] !== ')') {
                if ($css[$i] === '\\') {
                    ++$i;
                }
                ++$i;
            }
            $value = $this->decode(trim(substr($css, $start, $i - $start)));
        }
        if (($css[$i] ?? '') !== ')') {
            throw new \InvalidArgumentException('Unterminated CSS url().');
        }
        ++$i;

        return $value;
    }

    private function quoted(string $css, int &$i): string
    {
        $quote = $css[$i++];
        $start = $i;
        while ($i < strlen($css) && $css[$i] !== $quote) {
            if ($css[$i] === '\\') {
                ++$i;
            }
            ++$i;
        }
        if ($i >= strlen($css)) {
            throw new \InvalidArgumentException('Unterminated CSS string.');
        }
        $value = substr($css, $start, $i - $start);
        ++$i;

        return $this->decode($value);
    }

    private function whitespace(string $css, int &$i): void
    {
        while (isset($css[$i]) && ctype_space($css[$i])) {
            ++$i;
        }
    }

    private function decode(string $value): string
    {
        return preg_replace_callback('/\\\\(?:([0-9a-f]{1,6})\s?|(\r\n|[\n\r\f])|(.))/is', static function (array $match): string {
            if ($match[1] !== '') {
                $code = hexdec($match[1]);
                if ($code === 0 || $code > 0x10ffff || ($code >= 0xd800 && $code <= 0xdfff)) {
                    return "\xef\xbf\xbd";
                }

                return html_entity_decode('&#'.$code.';', ENT_NOQUOTES, 'UTF-8');
            }

            return $match[3] ?? '';
        }, $value);
    }
}
