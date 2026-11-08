<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Suawave\CallbackRequest;
use Suawave\PrestashopRuntime;

class SuawaveCallbackModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;

    public function init(): void
    {
        parent::init();
        if (!PrestashopRuntime::isConfigured()) {
            $this->respond(503, 'not_configured');
        }
        $request = CallbackRequest::fromServer($_SERVER, (string) file_get_contents('php://input'));
        $outcome = PrestashopRuntime::processor()->process($request);
        $this->respond($outcome->httpStatus, $outcome->body);
    }

    private function respond(int $status, string $body): never
    {
        http_response_code($status);
        header('Content-Type: text/plain');
        echo $body;
        exit;
    }
}
