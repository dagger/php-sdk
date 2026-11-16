<?php

declare(strict_types=1);

namespace Dagger;

/**
 * The global client, which generation writes for code from the built-in PHP
 * runtime or with the globalClient setting.
 *
 * @deprecated Use core().
 */
function dag(): Client
{
    return core();
}
