<?php

declare(strict_types=1);

namespace Naf\Client\Exception;

use Psr\Http\Client\ClientExceptionInterface;

class ClientException extends \Exception implements ClientExceptionInterface
{

}