<?php

namespace Barzahlen\Request;

class PingRequest extends Request
{
    /**
     * @var string
     */
    protected $path = '/ping';

    /**
     * @var string
     */
    protected $method = 'GET';

}
