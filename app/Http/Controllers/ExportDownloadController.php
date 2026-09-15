<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/** Implemented in the next commit; the named route has to exist to be signed. */
class ExportDownloadController
{
    public function __invoke(): Response
    {
        abort(501);
    }
}
