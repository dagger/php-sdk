<?php

/*
 * The case conversion in EngineNaming::enumMemberName is ported from
 * ToScreamingDelimited in iancoleman/strcase v0.3.0, under this notice:
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2015 Ian Coleman
 * Copyright (c) 2018 Ma_124, <github.com/Ma124>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, Subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or Substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(strict_types=1);

namespace Dagger\Module;

/**
 * @internal Public for the renderer and its tests only.
 */
final readonly class EngineNaming
{
    /**
     * The UTF-8 bytes of every non-ASCII rune Go's unicode.IsSpace accepts. PHP
     * matches them bytewise, as Go's byte loop reads the name after trimming.
     */
    private const UNICODE_SPACE = '(?:\xc2[\x85\xa0]|\xe1\x9a\x80|\xe2\x80[\x80-\x8a\xa8\xa9\xaf]|\xe2\x81\x9f|\xe3\x80\x80)+';

    /**
     * The name the engine gives an enum member on the API views before the
     * identifier-casing cut, which PhpSdk.targetEngineVersion keeps modules on.
     * This follows gqlEnumMemberName in dagger/dagger core/gqlformat.go, identical
     * at beta.17, beta.21 and beta.22, and ToScreamingSnake in strcase v0.3.0: a
     * rule that only approximates them refuses valid enums or misses collisions.
     * Check it again when that target crosses the cut or the engine's naming changes.
     *
     * The input is a PHP case name, [a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*. Bytes
     * from 0x80 can spell Unicode spaces, which the engine trims, so a name can
     * reach the ASCII member name of another. ASCII spaces, hyphens and dots cannot
     * occur in one, so their handling in strcase is left out.
     */
    public static function enumMemberName(string $name): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) === 1) {
            return $name;
        }

        // After the fast path, as in the engine: a trimmed A1 still becomes A_1.
        $name = preg_replace('/^' . self::UNICODE_SPACE . '|' . self::UNICODE_SPACE . '$/D', '', $name);

        $isCap = static fn(string $c) => $c >= 'A' && $c <= 'Z';
        $isLow = static fn(string $c) => $c >= 'a' && $c <= 'z';
        $isNum = static fn(string $c) => $c >= '0' && $c <= '9';

        $result = '';
        $length = strlen($name);
        for ($i = 0; $i < $length; $i++) {
            $c = $name[$i];
            $next = $i + 1 < $length ? $name[$i + 1] : null;
            $upper = $isLow($c) ? chr(ord($c) - 32) : $c;

            if (
                $next !== null
                && (($isCap($c) && ($isLow($next) || $isNum($next)))
                    || ($isLow($c) && ($isCap($next) || $isNum($next)))
                    || ($isNum($c) && ($isCap($next) || $isLow($next))))
            ) {
                if ($isCap($c) && $isLow($next) && $i > 0 && $isCap($name[$i - 1])) {
                    $result .= '_';
                }
                $result .= $upper;
                if ($isLow($c) || $isNum($c) || $isNum($next)) {
                    $result .= '_';
                }
                continue;
            }

            $result .= $upper;
        }

        return $result;
    }
}
