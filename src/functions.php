<?php

declare(strict_types=1);

namespace Naf\Client;

use Naf\Client\Core\Client;

use function Naf\app;

function client(): Client
{
    return app()->container()->get(Client::class);
}
