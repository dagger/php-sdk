<?php

declare(strict_types=1);

namespace Dagger;

/**
 * The core API, in the session the module or program runs in.
 */
function core(): Client
{
    return Dagger::getClientInstance();
}

function dag(): Client
{
    return Dagger::getClientInstance();
}
