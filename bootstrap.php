<?php

declare(strict_types=1);

use Naf\Client\Core\Client;
use function Naf\app;

app()->container()->set(Client::class, fn() => new Client());